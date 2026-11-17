<?php

namespace App\Domain\Themes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Fixed file slots hold immutable publication bytes, never live heads or quota. */
final class PublicationProjectionCache
{
    private array $relations = [];

    private bool $available = true;

    public function enabled(): bool
    {
        return $this->available && (bool) config('publication_storage.shared_read_cache', false);
    }

    public function rememberMany(int $siteId, array $requests, callable $read): array
    {
        if (! $this->enabled()) {
            return $read($requests);
        }
        $connection = DB::connection();
        $namespace = hash('sha256', json_encode([$connection->getName(), $connection->getConfig('host'),
            $connection->getConfig('port'), $connection->getDatabaseName()]));
        $relation = $this->relations[$namespace] ??= (string) DB::selectOne("SELECT to_regclass('page_publications')::oid AS id")->id;
        $cache = Cache::store('file');
        $keys = $owners = [];
        foreach ($requests as $id => $request) {
            $identity = $siteId.':'.$request['page_id'].':'.$id;
            $keys[$id] = 'immutable-publication:'.$namespace.':'.(hexdec(substr(hash('sha256', $identity), 0, 4)) % 8192);
            $owners[$id] = hash('sha256', $relation.':'.$identity);
        }
        $lookup = function (array $wanted) use ($cache, $keys, $owners): array {
            $found = [];
            foreach ($wanted as $id => $request) {
                try {
                    $entry = $cache->get($keys[$id]);
                } catch (Throwable $error) {
                    $this->available = false;
                    report($error);
                    break;
                }
                if (! is_array($entry) || ($entry['owner'] ?? null) !== $owners[$id] || ! $this->valid($entry['data'] ?? null)) {
                    continue;
                }
                $found[$id] = $entry['data'];
            }

            return $found;
        };
        $found = $lookup($requests);
        $missing = array_diff_key($requests, $found);
        if ($missing === []) {
            return $found;
        }
        if (! $this->available || DB::transactionLevel() !== 0) {
            return $found + $read($missing);
        }
        $lock = null;
        try {
            $lock = $cache->lock('immutable-publication-fill:'.$namespace.':'.$siteId, 30);
            if (! $lock->get()) {
                // A concurrent fill may only cover some of the requested immutable rows.
                $lock = null;
            }
        } catch (Throwable $error) {
            $lock = null;
            $this->available = false;
            report($error);
        }
        try {
            $found += $lookup($missing);
            $missing = array_diff_key($requests, $found);
            if ($missing === []) {
                return $found;
            }
            $fresh = $read($missing);
            if ($this->available && $lock !== null) {
                foreach ($fresh as $id => $data) {
                    if (! $this->valid($data) || strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 65536) {
                        continue;
                    }
                    try {
                        $cache->put($keys[$id], ['owner' => $owners[$id], 'data' => $data], 600);
                    } catch (Throwable $error) {
                        $this->available = false;
                        report($error);
                        break;
                    }
                }
            }

            return $found + $fresh;
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable $error) {
                    report($error);
                }
            }
        }
    }

    private function valid(mixed $data): bool
    {
        if (! is_array($data) || ! is_string($data['snapshot_json'] ?? null) || strlen($data['snapshot_json']) > 65536) {
            return false;
        }
        $projection = json_decode($data['snapshot_json'], true);
        if (! is_array($projection) || ! array_key_exists('content', $projection)) {
            return false;
        }
        foreach (['path', 'content_fingerprint', 'category_fingerprint'] as $field) {
            if (! array_key_exists($field, $data) || (! is_string($data[$field]) && $data[$field] !== null)) {
                return false;
            }
        }

        return true;
    }
}
