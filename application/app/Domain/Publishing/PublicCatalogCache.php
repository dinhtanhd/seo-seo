<?php

namespace App\Domain\Publishing;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Small, bounded count projections; no film HTML, episodes or keyword state. */
final class PublicCatalogCache
{
    private ?bool $epochAvailable = null;

    private function namespace(int $siteId): string
    {
        return 'public-catalog:'.hash('sha256', (string) config('database.connections.pgsql.database')).':'.$siteId;
    }

    public function count(int $siteId, ?int $categoryId, callable $read, ?string $scope = null): int
    {
        return $this->remember($siteId, $categoryId, $read, $scope, 'count');
    }

    /** @return array{live:int,draft:int} */
    public function counts(int $siteId, string $scope, callable $read): array
    {
        return $this->remember($siteId, null, $read, $scope, 'branches');
    }

    private function remember(int $siteId, ?int $categoryId, callable $read, ?string $scope, string $kind): mixed
    {
        if (app()->environment('testing') || DB::transactionLevel() !== 0) {
            return $read();
        }
        $cache = Cache::store('file');
        $namespace = $this->namespace($siteId);
        $this->epochAvailable ??= Schema::hasColumn('sites', 'public_content_epoch');
        $epoch = $this->epochAvailable ? (string) (DB::table('sites')->where('id', $siteId)->value('public_content_epoch') ?? 0) : '0';
        // Reuse one file per category, including after invalidation. Old generations
        // must not leave an unbounded collection of expired files on disk.
        // Fixed slots also bound search terms and policy versions to 256 files/site.
        $key = $namespace.($scope === null ? ':count:'.($categoryId ?? 'all') : ':scope:'.(hexdec(substr($scope, 0, 4)) % 256));
        $lookup = function () use ($cache, $namespace, $key, $kind, $scope, $epoch): array {
            // A count computed during an older revision can never become a hit
            // after commit, even if an afterCommit cache invalidation was lost.
            $generation = $epoch.':'.$cache->rememberForever($namespace.':generation', fn () => (string) Str::uuid());
            $entry = $cache->get($key);
            $value = is_array($entry) ? ($entry['value'] ?? null) : null;
            $valid = $kind === 'count' ? is_int($value) : (is_array($value) && is_int($value['live'] ?? null) && is_int($value['draft'] ?? null));
            $hit = is_array($entry) && ($entry['generation'] ?? null) === $generation && ($entry['scope'] ?? null) === $scope
                && ($entry['kind'] ?? null) === $kind && $valid;

            return compact('hit', 'value', 'generation');
        };
        $entry = $lookup();
        if ($entry['hit']) {
            return $entry['value'];
        }
        try {
            return $cache->lock($key.':lock', 30)->block(15, function () use ($cache, $key, $scope, $kind, $read, $lookup): mixed {
                $entry = $lookup();
                if ($entry['hit']) {
                    return $entry['value'];
                }
                $value = $read();
                $cache->put($key, ['generation' => $entry['generation'], 'scope' => $scope, 'kind' => $kind, 'value' => $value], (int) config('public_on_demand.catalog_ttl', 60));

                return $value;
            });
        } catch (LockTimeoutException) {
            $entry = $lookup();

            return $entry['hit'] ? $entry['value'] : $read();
        }
    }

    public function invalidate(int $siteId): void
    {
        if (app()->environment('testing')) {
            return;
        }
        DB::afterCommit(function () use ($siteId): void {
            try {
                Cache::store('file')->forever($this->namespace($siteId).':generation', (string) Str::uuid());
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }
}
