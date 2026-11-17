<?php

namespace App\Domain\Publishing;

use App\Domain\Advertising\AdvertisingService;
use App\Domain\Comments\LiveMovieComments;
use App\Domain\Fanmulu\FanmuluLinks;
use App\Domain\Fanmulu\FanmuluUrlRegistry;
use App\Domain\Sites\SiteAppearance;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\ReviewedThemeRuntime;
use App\Domain\Themes\SystemFriendLinks;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PublicHtmlCache
{
    public const PLAIN_CSP = "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self' 'unsafe-inline'; script-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'";

    public const RUNTIME_CSP = "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'self'; connect-src 'self' https:; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'";

    public static function safePath(string $path): bool
    {
        return strlen($path) <= 2000
            && ! str_ends_with(strtolower($path), '.js')
            && preg_match('~^/(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*(?:\.[a-z][a-z0-9]{0,11})?)?$~D', $path) === 1
            && preg_match('~^/(?:admin|login|logout|api|up|readyz|theme-assets|theme-bundles|robots|sitemap|__tram_ads|__tram_public_cache_policy|__tram_public_html_cache|__tram_public_runtime_html_cache)(?:/|\.|$)~i', $path) !== 1;
    }

    private function root(): string
    {
        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/^[A-Za-z0-9_-]{1,63}$/D', $database) !== 1) {
            throw new RuntimeException('Tên database không phù hợp namespace cache public.');
        }

        return rtrim((string) config('public_html_cache.root'), '/').'/'.$database;
    }

    /** Read filesystem projections for this database and the site's exact domain inventory only. */
    public function stats(int $siteId): array
    {
        $root = $this->root();
        $summary = ['enabled' => (bool) config('public_html_cache.enabled'), 'available' => true,
            'file_count' => 0, 'bytes' => 0, 'hosts' => [], 'error' => null];
        $base = rtrim((string) config('public_html_cache.root'), '/');
        $unsafeRoot = is_link($base) || is_link($root) || (file_exists($base) && ! is_dir($base))
            || (file_exists($root) && ! is_dir($root)) || (is_dir($base) && ! is_readable($base)) || (is_dir($root) && ! is_readable($root));
        foreach (DB::table('site_domains')->where('site_id', $siteId)->orderByDesc('is_primary')->orderBy('id')->get() as $domain) {
            $host = ['host' => $domain->host_ascii, 'status' => $domain->status, 'is_primary' => (bool) $domain->is_primary,
                'eligible' => $this->hostAllowed($domain->host_ascii), 'available' => true, 'file_count' => 0, 'bytes' => 0, 'error' => null];
            if ($host['eligible']) {
                $directory = $root.'/'.$domain->host_ascii;
                try {
                    if ($unsafeRoot || is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
                        throw new RuntimeException('Đường dẫn cache không phải thư mục thông thường.');
                    }
                    if (is_dir($directory)) {
                        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                        foreach ($files as $file) {
                            if ($file->isLink()) {
                                throw new RuntimeException('Có liên kết tượng trưng trong thư mục cache.');
                            }
                            if ($file->isFile() && in_array($file->getFilename(), ['index.html', 'index.runtime.html', 'index.spider.html', 'index.runtime.spider.html',
                                'index.advertising.html', 'index.runtime.advertising.html', 'index.advertising.spider.html', 'index.runtime.advertising.spider.html'], true)) {
                                $host['file_count']++;
                                $host['bytes'] += $file->getSize();
                            }
                        }
                    }
                } catch (Throwable) {
                    $host['available'] = false;
                    $host['file_count'] = null;
                    $host['bytes'] = null;
                    $host['error'] = 'Không đọc đủ được thư mục cache. Kiểm tra quyền hoặc liên kết tượng trưng rồi tải lại.';
                    $summary['available'] = false;
                }
            }
            if ($host['available']) {
                $summary['file_count'] += $host['file_count'];
                $summary['bytes'] += $host['bytes'];
            }
            $summary['hosts'][] = $host;
        }
        if (! $summary['available']) {
            $summary['file_count'] = null;
            $summary['bytes'] = null;
            $summary['error'] = 'Số liệu cache chưa xác định đầy đủ; không hiển thị thành 0.';
        }
        if (($limit = $this->budgetLimit($siteId)) !== null) {
            try {
                $accounted = Cache::get($this->budgetKey($siteId));
            } catch (Throwable) {
                $accounted = null;
            }
            $accounted = $this->budgetCounterBytes($accounted);
            $summary['budget'] = ['limit_bytes' => $limit, 'accounted_bytes' => $accounted,
                'accounting' => 'conservative_cumulative_writes', 'exhausted' => $accounted === null ? null : $accounted >= $limit,
                'description' => 'Ngân sách ghi lũy kế kể từ lần quét gần nhất; có thể lớn hơn dung lượng file thực tế. Hết ngân sách vẫn phục vụ từ database.'];
        }

        return $summary;
    }

    private function hostAllowed(string $host): bool
    {
        $admin = array_map('trim', explode(',', (string) config('tram.admin_hosts', env('ADMIN_HOSTS', 'localhost,127.0.0.1,admin.localhost'))));

        return strlen($host) <= 253 && $host === strtolower($host)
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $host) === 1
            && ! in_array($host, $admin, true);
    }

    private function file(string $host, string $path, bool $runtime = false, bool $spider = false, bool $advertising = false): ?string
    {
        if (! $this->hostAllowed($host) || ! self::safePath($path)) {
            return null;
        }

        $name = 'index'.($runtime ? '.runtime' : '').($advertising ? '.advertising' : '').($spider ? '.spider' : '').'.html';

        $projection = preg_match('/\.[a-z][a-z0-9]{0,11}$/D', $path) === 1 ? '/.suffix'.$path : $path;

        return $this->root().'/'.$host.($projection === '/' ? '' : $projection).'/'.$name;
    }

    /** Caller holds the site and page locks before changing the live state. */
    public function invalidatePage(object $page, array $extraPaths = [], bool $catalog = true, ?iterable $hosts = null): void
    {
        if ($catalog) {
            app(PublicCatalogCache::class)->invalidate((int) $page->site_id);
        }
        foreach ($hosts ?? DB::table('site_domains')->where('site_id', $page->site_id)->pluck('host_ascii') as $host) {
            foreach (array_unique(array_merge([$page->canonical_path], $extraPaths)) as $path) {
                app(PublicRequestPolicyCache::class)->invalidate($host, $path);
                foreach ([false, true] as $runtime) {
                    foreach ([false, true] as $spider) {
                        foreach ([false, true] as $advertising) {
                            $file = $this->file($host, $path, $runtime, $spider, $advertising);
                            if ($file !== null && is_file($file) && ! unlink($file)) {
                                throw new RuntimeException('Không thể gỡ cache public trước khi đổi bản live.');
                            }
                        }
                    }
                }
            }
        }
    }

    /** Rename detaches every route atomically before a host is paused or reassigned. */
    public function invalidateSite(int $siteId): void
    {
        app(PublicCatalogCache::class)->invalidate($siteId);
        foreach (DB::table('site_domains')->where('site_id', $siteId)->pluck('host_ascii') as $host) {
            if (! $this->hostAllowed($host)) {
                continue;
            }
            app(PublicRequestPolicyCache::class)->invalidate($host);
            $directory = $this->root().'/'.$host;
            if (! is_dir($directory)) {
                continue;
            }
            $retired = $this->root().'-retired';
            $this->ensureDirectory($retired);
            $target = $retired.'/'.Str::uuid();
            if (! rename($directory, $target)) {
                throw new RuntimeException('Không thể vô hiệu cache website trước khi đổi trạng thái/domain.');
            }
            DB::afterCommit(function () use ($target): void {
                try {
                    (new Filesystem)->deleteDirectory($target);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
        if ($this->budgetLimit($siteId) !== null) {
            DB::afterCommit(fn () => $this->resetBudgetCounter($siteId));
        }
    }

    /** Detach this database's HTML projections atomically for a global decoration change. */
    public function invalidateAll(): void
    {
        $root = $this->root();
        if (is_link(dirname($root)) || is_link($root)) {
            throw new RuntimeException('Đường dẫn cache không được là liên kết tượng trưng.');
        }
        $retired = null;
        if (is_dir($root)) {
            $retired = $root.'-configuration-'.Str::uuid();
            if (! rename($root, $retired)) {
                throw new RuntimeException('Không thể vô hiệu cache HTML trước khi đổi cấu hình dùng chung.');
            }
        }
        $siteIds = array_map('intval', array_keys((array) config('public_html_cache.site_max_bytes', [])));
        // The global configuration lock fences new writers. Forget now even if this
        // transaction rolls back; the detached files no longer consume the active budget.
        foreach ($siteIds as $siteId) {
            $this->resetBudgetCounter($siteId);
        }
        $cleanup = function () use ($retired, $siteIds): void {
            if ($retired !== null) {
                try {
                    $this->deleteConfigurationCache($retired);
                } catch (Throwable $error) {
                    $this->reportCleanupFailure($error);
                }
            }
            foreach ($siteIds as $siteId) {
                try {
                    // A racing writer may have recreated the root. The budget lock
                    // makes forgetting safe: its next write rescans only current files.
                    $this->resetBudgetCounter($siteId);
                } catch (Throwable $error) {
                    $this->reportCleanupFailure($error);
                }
            }
        };
        DB::afterCommit($cleanup);
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack($cleanup);
        }
    }

    private function deleteConfigurationCache(string $directory): void
    {
        if (is_link(dirname($directory)) || is_link($directory)) {
            throw new RuntimeException('Không dọn cache cấu hình qua liên kết tượng trưng.');
        }
        if (is_dir($directory)) {
            (new Filesystem)->deleteDirectory($directory);
            if (is_dir($directory)) {
                throw new RuntimeException('Không thể dọn hết cache cấu hình đã vô hiệu.');
            }
        }
    }

    private function reportCleanupFailure(Throwable $error): void
    {
        try {
            report($error);
        } catch (Throwable) {
            // Cache cleanup must not replace the original commit or rollback failure.
        }
    }

    /** Hold site/page locks through rename; never project an uncommitted head. */
    public function rebuildPage(int $pageId, bool $includeSpider = false): int
    {
        if (! config('public_html_cache.enabled')) {
            return 0;
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Chỉ dựng cache public từ dữ liệu đã commit.');
        }

        return $this->materializeCommittedPage($pageId, $includeSpider)['projection_count'] ?? 0;
    }

    /**
     * Render once for both the cold response and its filesystem projections.
     * The returned HTML has no request-specific advertising or discovery links.
     *
     * @return array{html:string,snapshot:array,publication_id:int,html_hash:string}|null
     */
    public function materializePage(int $pageId, bool $includeSpider = false, ?object $committedPublication = null): ?array
    {
        if (! config('public_html_cache.enabled') || DB::transactionLevel() !== 0) {
            return null;
        }
        try {
            $result = $this->materializeCommittedPage($pageId, $includeSpider, $committedPublication);
            if ($result !== null) {
                unset($result['projection_count']);
            }

            return $result;
        } catch (Throwable $error) {
            // A broken projection must not prevent the public controller from
            // falling back to rendering the committed publication directly.
            report($error);

            return null;
        }
    }

    /** @return array{html:string,snapshot:array,publication_id:int,html_hash:string,projection_count:int}|null */
    private function materializeCommittedPage(int $pageId, bool $includeSpider, ?object $committedPublication = null): ?array
    {
        if ($committedPublication !== null && (int) $committedPublication->page_id !== $pageId) {
            $committedPublication = null;
        }
        $siteId = $committedPublication->site_id ?? DB::table('pages')->where('id', $pageId)->value('site_id');
        if ($siteId === null) {
            return null;
        }

        return DB::transaction(function () use ($siteId, $pageId, $includeSpider, $committedPublication): ?array {
            $system = DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->sharedLock()->first();
            $page = DB::table('pages')->where(['id' => $pageId, 'site_id' => $siteId])->sharedLock()->first();
            $domains = DB::table('site_domains')->where('site_id', $siteId)->get(['host_ascii', 'status']);
            if ($page) {
                $this->invalidatePage($page, catalog: false, hosts: $domains->pluck('host_ascii'));
            }
            if (! $site || ! $page || $site->status !== 'active' || $site->content_type !== 'movie'
                || $page->status !== 'live' || ! $page->current_publication_id
                || ! in_array($page->page_type, ['movie', 'home', 'category'], true) || ! self::safePath($page->canonical_path)) {
                return null;
            }
            // These routes list imported films dynamically in this delivery mode.
            // Projecting their historical publication would hide newly crawled URLs.
            if (in_array($page->page_type, ['home', 'category'], true) && app(SiteDeliverySettings::class)->enabled((int) $site->id)) {
                return null;
            }
            if (DB::table('page_redirects')->where(['site_id' => $site->id, 'from_path' => $page->canonical_path])->exists()) {
                return null;
            }
            try {
                app(FanmuluUrlRegistry::class)->find((int) $site->id, $page->canonical_path);
            } catch (Conflict $error) {
                if ($error->reason !== 'FANMULU_URL_PATH_COLLISION') {
                    throw $error;
                }

                return null;
            }
            // Publication payloads are immutable. The locked page decides whether these bytes are still live.
            $publication = $committedPublication !== null && (int) $committedPublication->id === (int) $page->current_publication_id
                ? $committedPublication
                : DB::table('page_publications')->where(['id' => $page->current_publication_id, 'page_id' => $page->id, 'site_id' => $site->id])->first();
            $snapshot = $publication ? Json::decode($publication->snapshot_json) : [];
            if (! $publication || ! empty($snapshot['page']['noindex'])) {
                return null;
            }
            if (! hash_equals($publication->html_hash, hash('sha256', $publication->html_body))) {
                throw new RuntimeException('Publication HTML không khớp SHA-256; không tạo cache.');
            }
            $renderedHtml = $publication->html_body;
            if (! FanmuluDetailArtifact::current($snapshot, $page, $site, (int) $system->config_epoch)) {
                $refreshArtifact = FanmuluDetailArtifact::exists($snapshot);
                $snapshot = FanmuluDetailArtifact::forRefresh($snapshot);
                $snapshot = app(LiveMovieContent::class)->overlay($snapshot);
                $appearance = app(SiteAppearance::class);
                $hasAppearance = $appearance->active((int) $site->id) !== null;
                if ($hasAppearance) {
                    $snapshot = $appearance->overlay($snapshot);
                }
                $snapshot = app(LiveMovieRecommendations::class)->overlay($snapshot);
                $snapshot = app(LiveMovieComments::class)->overlay($snapshot);
                app(LiveMovieRecommendations::class)->remember($snapshot, (int) $publication->id);
                if ($refreshArtifact || ! empty($snapshot['_live_content_changed']) || ! empty($snapshot['_live_recommendations_changed']) || ! empty($snapshot['_live_comments_changed']) || $hasAppearance) {
                    $renderedHtml = app(Renderer::class)->html($snapshot);
                }
            }
            $area = ($page->page_kind ?? 'regular') === 'fanmulu' ? 'fanmulu' : 'regular';
            $renderedHtml = app(SystemFriendLinks::class)->decorate($renderedHtml, $site, fanmulu: $area === 'fanmulu');
            $theme = $snapshot['theme'] ?? [];
            $runtime = is_array($theme) && ReviewedThemeRuntime::enabled($theme);
            $advertisingService = app(AdvertisingService::class);
            $base = $advertisingService->decorate($renderedHtml, $site, $area);
            $variants = [0 => $base];
            if ($includeSpider) {
                $spiderHtml = $area === 'regular'
                    ? app(FanmuluLinks::class)->append($renderedHtml, $site, $page, $page->canonical_path, userAgent: 'Baiduspider/2.0')
                    : $renderedHtml;
                $variants[1] = $advertisingService->decorate($spiderHtml, $site, $area);
            }
            $count = 0;
            $writes = [];
            foreach ($domains->where('status', 'active')->pluck('host_ascii') as $host) {
                foreach ($variants as $spider => $html) {
                    $advertising = $advertisingService->hasDecoration($html, $area);
                    $file = $this->file($host, $page->canonical_path, $runtime, (bool) $spider, $advertising);
                    if ($file !== null) {
                        $writes[$file] = $html;
                        $count++;
                    }
                }
            }

            $projected = $writes !== [] && $this->writeProjections((int) $site->id, $writes) ? $count : 0;

            return ['html' => $renderedHtml, 'snapshot' => $snapshot, 'publication_id' => (int) $publication->id,
                'html_hash' => hash('sha256', $renderedHtml), 'projection_count' => $projected];
        }, 3);
    }

    private function budgetLimit(int $siteId): ?int
    {
        $limits = config('public_html_cache.site_max_bytes', []);
        if (! is_array($limits) || ! array_key_exists($siteId, $limits)) {
            return null;
        }
        $value = filter_var($limits[$siteId], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        // A malformed configured cap must not accidentally enable unlimited writes.
        return $value === false ? 0 : $value;
    }

    private function budgetKey(int $siteId): string
    {
        return 'tram:public-html-budget:'.hash('sha256', $this->root()).':'.$siteId;
    }

    /** Redis may return an integer cache value as its canonical decimal string. */
    private function budgetCounterBytes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || strlen($value) > strlen((string) PHP_INT_MAX)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $bytes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $bytes === false ? null : $bytes;
    }

    /** Reserve cumulative bytes before writing; a failed write can overcount, never undercount. */
    private function writeProjections(int $siteId, array $writes): bool
    {
        $limit = $this->budgetLimit($siteId);
        if ($limit === null) {
            foreach ($writes as $file => $html) {
                $this->writeAtomic($file, $html);
            }

            return true;
        }
        $handle = $this->budgetLock($siteId);
        if ($handle === null) {
            return false;
        }
        try {
            try {
                $key = $this->budgetKey($siteId);
                $used = $this->budgetCounterBytes(Cache::rememberForever($key, fn (): int => $this->scanBudgetBytes($siteId)));
                if ($used === null) {
                    Cache::forget($key);
                    $used = $this->budgetCounterBytes(Cache::rememberForever($key, fn (): int => $this->scanBudgetBytes($siteId)));
                }
                $required = array_sum(array_map(strlen(...), $writes));
                if ($used === null || $required > $limit - $used
                    || ! Cache::forever($key, $used + $required)) {
                    return false;
                }
            } catch (Throwable) {
                // A missing/unavailable counter must not turn off the disk cap or fail a publication.
                return false;
            }
            foreach ($writes as $file => $html) {
                $this->writeAtomic($file, $html);
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** A filesystem lock survives Redis counter loss and never lives below a domain folder. */
    private function budgetLock(int $siteId): mixed
    {
        $directory = rtrim((string) config('public_html_cache.root'), '/').'-budget-locks';
        $file = $directory.'/'.hash('sha256', $this->budgetKey($siteId)).'.lock';
        $handle = null;
        try {
            if (is_link($directory) || is_link($file) || (file_exists($file) && ! is_file($file))) {
                return null;
            }
            $this->ensureDirectory($directory);
            if (is_link($directory) || ! is_dir($directory)) {
                return null;
            }
            $previous = umask(0002);
            try {
                $handle = fopen($file, 'c+b');
            } finally {
                umask($previous);
            }
            $opened = is_resource($handle) ? fstat($handle) : false;
            $named = lstat($file);
            if (! $opened || ! $named || ($named['mode'] & 0170000) !== 0100000
                || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                return null;
            }
            $deadline = microtime(true) + 10;
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return $handle;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            fclose($handle);
        } catch (Throwable) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return null;
    }

    private function scanBudgetBytes(int $siteId): int
    {
        $summary = $this->stats($siteId);
        if (! $summary['available']) {
            throw new RuntimeException('Không thể xác định dung lượng cache để áp dụng ngân sách.');
        }

        return $summary['bytes'];
    }

    private function resetBudgetCounter(int $siteId): void
    {
        $handle = $this->budgetLock($siteId);
        if ($handle === null) {
            return;
        }
        try {
            Cache::forget($this->budgetKey($siteId));
        } catch (Throwable) {
            // Keeping the old cumulative value is safe when the counter store is unavailable.
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writeAtomic(string $file, string $html): void
    {
        // Root CLI and www-data workers share directories via the storage setgid group.
        $this->ensureDirectory(dirname($file));
        $temporary = dirname($file).'/.'.Str::uuid().'.tmp';
        try {
            if (file_put_contents($temporary, $html, LOCK_EX) !== strlen($html) || ! chmod($temporary, 0644) || ! rename($temporary, $file)) {
                throw new RuntimeException('Không thể ghi cache HTML public.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        $previous = umask(0002);
        try {
            (new Filesystem)->ensureDirectoryExists($directory, 02775);
        } finally {
            umask($previous);
        }
    }

    public function rebuildSite(int $siteId): int
    {
        if (! config('public_html_cache.enabled')) {
            return 0;
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Chỉ dựng cache public từ dữ liệu đã commit.');
        }
        $enabled = DB::transaction(function () use ($siteId): bool {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            if (! $site || $site->status !== 'active' || $site->content_type !== 'movie') {
                return false;
            }
            $this->invalidateSite($siteId);

            return true;
        }, 3);
        if (! $enabled) {
            return 0;
        }
        $count = 0;
        DB::table('pages')->where('site_id', $siteId)->where('status', 'live')->orderBy('id')->chunkById(100, function ($pages) use (&$count): void {
            foreach ($pages as $page) {
                $count += $this->rebuildPage($page->id);
            }
        });

        return $count;
    }

    public function scheduleRefresh(int $siteId, ?int $pageId = null, bool $immediate = true): void
    {
        if (! config('public_html_cache.enabled')) {
            return;
        }
        $eventId = (string) Str::uuid();
        DB::table('outbox_events')->insert(['id' => $eventId, 'event_type' => 'public-cache.refresh', 'aggregate_id' => $pageId ?? $siteId,
            'payload_json' => Json::encode(['site_id' => $siteId, 'page_id' => $pageId])]);
        if ($pageId !== null && $immediate) {
            DB::afterCommit(function () use ($eventId): void {
                try {
                    $this->deliver($eventId);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
    }

    private function deliver(string $eventId): void
    {
        $event = DB::table('outbox_events')->where('id', $eventId)->whereNull('delivered_at')->first();
        if (! $event) {
            return;
        }
        $payload = Json::decode($event->payload_json);
        if ($payload['page_id'] !== null) {
            $this->rebuildPage((int) $payload['page_id']);
        } else {
            $this->rebuildSite((int) $payload['site_id']);
        }
        DB::table('outbox_events')->where('id', $eventId)->whereNull('delivered_at')->update(['delivered_at' => now(), 'attempts' => DB::raw('attempts+1')]);
    }

    public function deliverPending(int $limit = 100): array
    {
        $counts = ['delivered' => 0, 'failed' => 0];
        if (! config('public_html_cache.enabled')) {
            return $counts;
        }
        foreach (DB::table('outbox_events')->where('event_type', 'public-cache.refresh')->whereNull('delivered_at')->orderBy('created_at')->limit($limit)->pluck('id') as $id) {
            try {
                $this->deliver($id);
                $counts['delivered']++;
            } catch (Throwable $e) {
                DB::table('outbox_events')->where('id', $id)->increment('attempts');
                $counts['failed']++;
                report($e);
            }
        }

        return $counts;
    }

    public function warmPage(int $pageId, bool $includeSpider = false): void
    {
        if (DB::transactionLevel() !== 0) {
            return;
        }
        try {
            $this->rebuildPage($pageId, $includeSpider);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function purge(): void
    {
        $root = $this->root();
        if (is_link(dirname($root)) || is_link($root)) {
            throw new RuntimeException('Đường dẫn cache không được là liên kết tượng trưng.');
        }
        if (is_dir($root)) {
            $old = $root.'-purged-'.Str::uuid();
            if (! rename($root, $old)) {
                throw new RuntimeException('Không thể gỡ kho cache public.');
            }
            (new Filesystem)->deleteDirectory($old);
        }
        $retired = $root.'-retired';
        if (! is_link($retired)) {
            (new Filesystem)->deleteDirectory($retired);
        }
        // A killed worker cannot run transaction callbacks. Recover only this
        // database's exact configuration namespaces, never other databases or links.
        $prefix = basename($root).'-configuration-';
        if (is_dir(dirname($root))) {
            foreach (new \FilesystemIterator(dirname($root), \FilesystemIterator::SKIP_DOTS) as $entry) {
                $name = $entry->getFilename();
                if ($entry->isLink() || ! $entry->isDir() || ! str_starts_with($name, $prefix)
                    || ! Str::isUuid(substr($name, strlen($prefix)))) {
                    continue;
                }
                $this->deleteConfigurationCache($entry->getPathname());
            }
        }
        foreach (array_keys((array) config('public_html_cache.site_max_bytes', [])) as $siteId) {
            $this->resetBudgetCounter((int) $siteId);
        }
    }
}
