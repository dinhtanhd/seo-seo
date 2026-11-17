<?php

namespace App\Domain\Keywords;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Rebuildable, versioned copies of active keyword rows used by the allocator. */
final class FirstVisitKeywordCache
{
    public const TTL = 600;

    private const REFRESH_AHEAD = 180;

    private const MAX_WARM_ROWS = 10_000;

    public static function databaseNamespace(): string
    {
        $connection = DB::connection();

        return Json::hash([$connection->getName(), $connection->getConfig('host'), $connection->getConfig('port'), $connection->getDatabaseName()]);
    }

    public static function relation(): int
    {
        return (int) DB::selectOne("SELECT to_regclass('keywords')::oid AS id")->id;
    }

    public static function encode(array $rows, string $version): ?array
    {
        $bytes = serialize($rows);
        if (count($rows) > 100_000 || strlen($bytes) > 33_554_432) {
            return null;
        }
        $blob = gzcompress($bytes, 1);

        return $blob === false ? null : ['version' => $version, 'blob' => $blob, 'hash' => hash('sha256', $bytes)];
    }

    public static function decode(mixed $entry, string $version, array $poolIds): ?array
    {
        if (! is_array($entry) || ($entry['version'] ?? null) !== $version || ! is_string($entry['blob'] ?? null)) {
            return null;
        }
        $bytes = @gzuncompress($entry['blob'], 33_554_432);
        if ($bytes === false || hash('sha256', $bytes) !== ($entry['hash'] ?? null)) {
            return null;
        }
        $rows = @unserialize($bytes, ['allowed_classes' => [\stdClass::class]]);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100_000) {
            return null;
        }
        foreach ($rows as $row) {
            if (! $row instanceof \stdClass || ! is_int($row->id ?? null) || ! in_array($row->pool_id ?? null, $poolIds, true)
                || ($row->status ?? null) !== 'active' || ! property_exists($row, 'deleted_at') || $row->deleted_at !== null) {
                return null;
            }
        }

        return $rows;
    }

    /** The request supplies its resolved roles and pools; a missing pool uses the ordinary SQL path. */
    public function warmedRows(int $siteId, string $locale, string $namespace, string $version, array $scope): ?array
    {
        $rolesByPool = [];
        foreach ($scope as $part) {
            foreach ($part['pools'] as $poolId) {
                $rolesByPool[$poolId][$part['role']] = true;
            }
        }
        $cache = Cache::store('file');
        $rows = [];
        foreach ($rolesByPool as $poolId => $roles) {
            $entry = $cache->get($this->poolKey($namespace, $siteId, $poolId, $locale));
            $keywords = self::decode($entry, $version, [$poolId]);
            if ($keywords === null) {
                return null;
            }
            foreach ($keywords as $keyword) {
                if ($keyword->locale !== $locale) {
                    return null;
                }
                if (isset($roles[$keyword->role])) {
                    $rows[] = $keyword;
                }
            }
        }
        usort($rows, fn ($a, $b): int => ($b->priority <=> $a->priority) ?: ($a->id <=> $b->id));

        return $rows;
    }

    public function warmSite(int $siteId): void
    {
        if (! config('publication_storage.shared_keyword_cache') || DB::transactionLevel() !== 0) {
            return;
        }

        DB::transaction(function () use ($siteId): void {
            // Read one committed version without holding locks on the configuration rows.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $site = DB::table('sites')->where(['id' => $siteId, 'status' => 'active', 'content_type' => 'movie'])->first();
            if (! $site || ($site->deleted_at ?? null) !== null) {
                return;
            }
            $fanmulu = FanmuluSettings::fromSite($site);
            if (! $fanmulu['enabled'] && ! app(SiteDeliverySettings::class)->enabled($siteId)) {
                return;
            }
            $epoch = DB::table('system_settings')->where('id', 1)->value('config_epoch');
            $namespace = self::databaseNamespace();
            $version = Json::hash([self::relation(), [(int) $epoch, (int) $site->config_epoch, (int) $site->row_version]]);
            $pools = DB::table('keyword_bindings')->where(['site_id' => $siteId, 'status' => 'active'])->distinct()->pluck('pool_id')->all();
            $poolIds = array_unique([...$pools, ...($fanmulu['enabled'] ? ($fanmulu['display_name_pool_ids'] ?? []) : [])]);
            $cache = Cache::store('file');
            foreach ($poolIds as $poolId) {
                $key = $this->poolKey($namespace, $siteId, $poolId, $site->locale);
                $entry = $cache->get($key);
                if (is_array($entry) && ($entry['version'] ?? null) === $version
                    && ($entry['expires_at'] ?? 0) > now()->timestamp + self::REFRESH_AHEAD) {
                    continue;
                }
                $rows = DB::table('keywords')->where(['pool_id' => $poolId, 'locale' => $site->locale, 'status' => 'active'])
                    ->whereNull('deleted_at')->limit(self::MAX_WARM_ROWS + 1)->get()->all();
                $payload = count($rows) <= self::MAX_WARM_ROWS ? self::encode($rows, $version) : null;
                // Oversize entries keep using the bounded, scoped request path.
                $cache->put($key, ($payload ?? ['version' => $version]) + ['expires_at' => now()->timestamp + self::TTL], self::TTL);
            }
        });
    }

    private function poolKey(string $namespace, int $siteId, int $poolId, string $locale): string
    {
        return 'first-visit-keyword-pool:'.$namespace.':'.$siteId.':'.$poolId.':'.hash('sha256', $locale);
    }
}
