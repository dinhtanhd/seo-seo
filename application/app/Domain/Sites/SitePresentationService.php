<?php

namespace App\Domain\Sites;

use App\Domain\Advertising\AdvertisingScriptPaths;
use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Fanmulu\FanmuluUrlRegistry;
use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\SnapshotStorage;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SitePresentationService
{
    public const TYPES = ['home', 'list', 'detail', 'play', 'search'];

    public static function defaults(): array
    {
        return ['schema_version' => 1, 'configured' => false,
            'movie_key' => 'source_id',
            'routes' => ['home' => '/', 'list' => '/type', 'detail' => '/movie', 'play' => '/play', 'search' => '/search'],
            'views' => array_combine(self::TYPES, self::TYPES),
            'player' => ['mode' => 'external_link', 'allowed_hosts' => []], 'fanmulu' => FanmuluSettings::defaults()];
    }

    public static function profile(object|array|null $site): array
    {
        if (is_array($site) && ! isset($site['presentation_json']) && ! isset($site['routes']) && ! isset($site['schema_version']) && ! array_key_exists('suffixes', $site)) {
            return self::defaults();
        }
        $raw = is_object($site) ? ($site->presentation_json ?? []) : ($site['presentation_json'] ?? $site ?? []);
        $data = Json::decode($raw);

        return $data === [] ? self::defaults() : self::normalize($data);
    }

    public static function normalize(array $data): array
    {
        $defaults = self::defaults();
        $movieKey = $data['movie_key'] ?? 'source_id';
        if (! in_array($movieKey, ['source_id', 'pinyin_id', 'pinyin_compact_id'], true)) {
            throw new \InvalidArgumentException('Định danh phim cần là ID nguồn hoặc pinyin kèm ID nguồn.');
        }
        $categoryKey = $data['category_key'] ?? 'id';
        if (! in_array($categoryKey, ['id', 'pinyin_id', 'pinyin_compact_id'], true)) {
            throw new \InvalidArgumentException('Định danh danh mục cần là ID hoặc pinyin kèm ID.');
        }
        $routes = $data['routes'] ?? $defaults['routes'];
        $views = $data['views'] ?? $defaults['views'];
        if (! is_array($routes) || array_diff(array_keys($routes), self::TYPES) || array_diff(self::TYPES, array_keys($routes))
            || ! is_array($views) || array_diff(array_keys($views), self::TYPES) || array_diff(self::TYPES, array_keys($views))) {
            throw new \InvalidArgumentException('Cần cấu hình đủ home/list/detail/play/search.');
        }
        foreach ($routes as $type => &$path) {
            if (! is_string($path)) {
                throw new \InvalidArgumentException('Đường dẫn '.$type.' phải là chuỗi.');
            }
            $path = rtrim(trim($path), '/') ?: '/';
            if (strlen($path) > 120 || ! preg_match('~^/(?:[A-Za-z0-9][A-Za-z0-9_-]*(?:/[A-Za-z0-9][A-Za-z0-9_-]*)*)?$~D', $path)
                || ($type !== 'home' && $path === '/')) {
                throw new \InvalidArgumentException('Đường dẫn '.$type.' cần dạng /video hoặc /phim/list, không có query/hash.');
            }
            $first = strtolower(explode('/', ltrim($path, '/'))[0]);
            if (in_array($first, ['admin', 'api', 'login', 'logout', 'password', 'up', 'readyz', 'theme-assets', 'theme-bundles', 'robots', 'sitemap', '__tram_public_html_cache', '__tram_public_runtime_html_cache', '__tram_ads'], true)) {
                throw new \InvalidArgumentException('Đường dẫn '.$path.' được dành cho hệ thống.');
            }
        }
        unset($path);
        foreach ($routes as $type => $path) {
            foreach ($routes as $other => $target) {
                if ($type === $other || $path === '/' || $target === '/') {
                    continue;
                }
                if ($path === $target || str_starts_with($path, $target.'/') || str_starts_with($target, $path.'/')) {
                    throw new \InvalidArgumentException('Đường dẫn '.$type.' và '.$other.' trùng hoặc chồng lên nhau.');
                }
            }
        }
        foreach ($views as $type => &$view) {
            if (! is_string($view) || ! in_array($view, [...self::TYPES, 'category'], true)) {
                throw new \InvalidArgumentException('File HTML cho '.$type.' không hỗ trợ.');
            }
            if ($view === 'category') {
                $view = 'list';
            }
        }
        unset($view);
        $player = $data['player'] ?? $defaults['player'];
        if (! is_array($player) || ! in_array($player['mode'] ?? 'external_link', ['external_link', 'native'], true)
            || ! is_array($player['allowed_hosts'] ?? [])) {
            throw new \InvalidArgumentException('Cấu hình nguồn phát không hợp lệ.');
        }
        $hosts = [];
        foreach ($player['allowed_hosts'] ?? [] as $host) {
            if (! is_string($host) || strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/iD', $host)) {
                throw new \InvalidArgumentException('Allowlist nguồn phát cần hostname công khai chính xác.');
            }
            $hosts[] = strtolower($host);
        }

        $profile = ['schema_version' => 1, 'configured' => (bool) ($data['configured'] ?? true), 'movie_key' => $movieKey, 'routes' => $routes, 'views' => $views,
            'player' => ['mode' => $player['mode'] ?? 'external_link', 'allowed_hosts' => array_values(array_unique($hosts))]];
        if (array_key_exists('category_key', $data)) {
            $profile['category_key'] = $categoryKey;
        }
        if (array_key_exists('suffixes', $data)) {
            if (! is_array($data['suffixes']) || array_diff(array_keys($data['suffixes']), self::TYPES)) {
                throw new \InvalidArgumentException('Đuôi URL chỉ hỗ trợ home/list/detail/play/search.');
            }
            $profile['suffixes'] = array_fill_keys(self::TYPES, '');
            foreach ($data['suffixes'] as $type => $suffix) {
                $profile['suffixes'][$type] = UrlSuffixCatalog::normalizeSuffix($suffix);
            }
            $endpoints = array_map(fn ($type) => self::endpointPath($profile, $type), ['home', 'list', 'search']);
            if (count(array_unique($endpoints)) !== count($endpoints)) {
                throw new \InvalidArgumentException('Đường dẫn trang chủ, danh sách và tìm kiếm sau khi thêm đuôi bị trùng.');
            }
        }
        $profile['fanmulu'] = FanmuluSettings::normalize($data['fanmulu'] ?? [], $profile);

        return $profile;
    }

    private function owner(int $actorId, int $siteId): bool
    {
        return DB::table('users')->where('id', $actorId)->value('is_global_admin')
            || DB::table('site_memberships')->where(['user_id' => $actorId, 'site_id' => $siteId, 'role' => 'owner'])->exists();
    }

    public function live(int $siteId): ?array
    {
        $snapshot = app(MovieThemeService::class)->liveSnapshotForSite($siteId);
        if (! $snapshot) {
            return $this->publicLive($siteId);
        }
        $snapshot = app(SiteAppearance::class)->overlay($snapshot);

        return ['routing' => self::profile($snapshot['routing'] ?? null), 'theme_id' => $snapshot['theme']['id'] ?? null,
            'version_id' => $snapshot['theme']['version_id'] ?? null, 'snapshot' => $snapshot];
    }

    /** Match live appearance selection while projecting out movie bodies and aggregate cards in PostgreSQL. */
    public function publicLive(int $siteId): ?array
    {
        $pages = DB::table('pages')->where('site_id', $siteId)->where('status', 'live')->whereNotNull('current_publication_id');
        $home = (clone $pages)->where('page_type', 'home')->orderBy('id')->first(['id', 'current_publication_id']);
        $projection = "(snapshot_json - ARRAY['aggregate','content','managed_body','_catalog_card']::text[]) || CASE WHEN jsonb_typeof(snapshot_json->'theme_catalog') = 'object' THEN jsonb_build_object('theme_catalog', (snapshot_json->'theme_catalog') - 'entry_categories') ELSE '{}'::jsonb END AS presentation";
        $storage = app(SnapshotStorage::class);
        $snapshot = null;
        if ($home) {
            $snapshot = $storage->presentation(DB::table('page_publications')->where(['id' => $home->current_publication_id,
                'page_id' => $home->id, 'site_id' => $siteId])->selectRaw($projection)->value('presentation'));
        } else {
            foreach ((clone $pages)->orderBy('id')->lazyById(20, 'id') as $page) {
                $candidate = $storage->presentation(DB::table('page_publications')->where(['id' => $page->current_publication_id,
                    'page_id' => $page->id, 'site_id' => $siteId])->selectRaw($projection)->value('presentation'));
                if (isset($candidate['theme']) || isset($candidate['routing'])) {
                    $snapshot = $candidate;

                    break;
                }
            }
        }
        $appearance = ! $snapshot ? app(SiteAppearance::class)->active($siteId) : null;
        if (! $snapshot && $appearance !== null) {
            $site = DB::table('sites')->find($siteId);
            if ($site) {
                $profile = self::profile($appearance['routing'] ?? $site);
                $snapshot = ['site' => ['id' => (int) $site->id, 'name' => $site->name, 'locale' => $site->locale, 'base_url' => $site->base_url],
                    'routing' => $profile, 'page' => ['type' => 'home', 'path' => self::endpointPath($profile, 'home')]];
            }
        }
        if (! $snapshot) {
            return null;
        }
        $snapshot = app(SiteAppearance::class)->overlay($snapshot);

        return ['routing' => self::profile($snapshot['routing'] ?? null), 'theme_id' => $snapshot['theme']['id'] ?? null,
            'version_id' => $snapshot['theme']['version_id'] ?? null, 'snapshot' => $snapshot];
    }

    public function configuration(int $siteId, int $actorId): array
    {
        Access::site($actorId, $siteId);
        $site = DB::table('sites')->find($siteId) ?? abort(404);
        $presentation = self::profile($site);
        $live = $this->publicLive($siteId);
        $currentVersion = $site->theme_id ? DB::table('movie_themes')->where('id', $site->theme_id)->value('current_version_id') : null;
        $appearancePending = ! $live || (int) $site->theme_id !== (int) $live['theme_id']
            || (int) $currentVersion !== (int) $live['version_id']
            || Json::hash($presentation['views']) !== Json::hash($live['routing']['views'])
            || Json::hash($presentation['player']) !== Json::hash($live['routing']['player']);
        $routeFields = array_flip(['movie_key', 'category_key', 'routes', 'suffixes']);
        $routesPending = $live && Json::hash(array_intersect_key($presentation, $routeFields)) !== Json::hash(array_intersect_key($live['routing'], $routeFields));

        return ['site' => $site, 'row_version' => (int) $site->row_version, 'theme_id' => $site->theme_id, 'presentation' => $presentation,
            'live' => $live, 'pending' => $appearancePending || $routesPending,
            'appearance_pending' => $appearancePending, 'routes_pending' => (bool) $routesPending,
            'can_write' => $this->owner($actorId, $siteId)];
    }

    public function save(int $siteId, array $data, int $actorId): object
    {
        Access::site($actorId, $siteId);
        abort_unless($this->owner($actorId, $siteId), 403);
        $profile = self::normalize(array_replace($data, ['configured' => true]));
        $themeId = empty($data['theme_id']) ? null : filter_var($data['theme_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($themeId === false) {
            throw new \InvalidArgumentException('ID giao diện không hợp lệ.');
        }

        return DB::transaction(function () use ($siteId, $data, $actorId, $profile, $themeId) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            abort_unless($this->owner($actorId, $siteId), 403);
            if (! isset($data['row_version']) || (int) $data['row_version'] !== (int) $site->row_version) {
                throw new Conflict('SITE_PRESENTATION_VERSION_CONFLICT');
            }
            if (! array_key_exists('category_key', $data) && array_key_exists('category_key', Json::decode($site->presentation_json))) {
                $profile['category_key'] = self::profile($site)['category_key'];
            }
            $current = self::profile($site);
            if (! array_key_exists('suffixes', $data) && array_key_exists('suffixes', $current)) {
                $profile['suffixes'] = $current['suffixes'];
            } elseif (array_key_exists('suffixes', $data)) {
                $profile['suffixes'] = array_replace($current['suffixes'] ?? array_fill_keys(self::TYPES, ''), $data['suffixes']);
            }
            $profile = self::normalize($profile);
            if (array_key_exists('suffixes', $data)) {
                foreach ($profile['suffixes'] as $type => $suffix) {
                    app(UrlSuffixCatalog::class)->assertSelectable($suffix, $current['suffixes'][$type] ?? '', 'suffixes.'.$type);
                }
            }
            if ($themeId !== null) {
                $theme = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->find($themeId);
                if (! $theme || ($theme->status !== 'active' && (int) $site->theme_id !== $themeId)) {
                    throw new \InvalidArgumentException('Giao diện phải đang hoạt động hoặc là lựa chọn hiện hữu.');
                }
            }
            $profile['fanmulu'] = FanmuluSettings::normalize(Json::decode($site->presentation_json)['fanmulu'] ?? [], $profile);
            app(AdvertisingScriptPaths::class)->assertRoutesAvailable($profile);
            $storedProfile = array_replace(Json::decode($site->presentation_json), $profile);
            DB::table('sites')->where('id', $siteId)->update(['theme_id' => $themeId, 'presentation_json' => Json::encode($storedProfile),
                'row_version' => DB::raw('row_version+1'), 'config_epoch' => DB::raw('config_epoch+1'), 'updated_at' => now()]);
            Access::audit('site.presentation.saved', $actorId, $siteId, ['theme_id' => $themeId, 'presentation' => $profile]);

            return DB::table('sites')->find($siteId);
        }, 3);
    }

    public function saveFanmulu(int $siteId, array $settings, int $rowVersion, int $actorId): object
    {
        Access::site($actorId, $siteId);
        abort_unless($this->owner($actorId, $siteId), 403);

        return DB::transaction(function () use ($siteId, $settings, $rowVersion, $actorId): object {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            abort_unless($this->owner($actorId, $siteId), 403);
            if ($rowVersion !== (int) $site->row_version) {
                throw new Conflict('SITE_PRESENTATION_VERSION_CONFLICT');
            }
            $presentation = self::profile($site);
            $currentFanmulu = FanmuluSettings::fromSite($site);
            if (! array_key_exists('prefixes', $settings) && array_key_exists('prefixes', Json::decode($site->presentation_json)['fanmulu'] ?? [])) {
                $settings['prefixes'] = $currentFanmulu['prefixes'];
            }
            if (! array_key_exists('url_rule', $settings) && array_key_exists('url_rule', Json::decode($site->presentation_json)['fanmulu'] ?? [])) {
                $settings['url_rule'] = $currentFanmulu['url_rule'];
            }
            if (! array_key_exists('suffix', $settings) && array_key_exists('suffix', $currentFanmulu)) {
                $settings['suffix'] = $currentFanmulu['suffix'];
            }
            if (! array_key_exists('display_name_pool_ids', $settings) && array_key_exists('display_name_pool_ids', $currentFanmulu)) {
                $settings['display_name_pool_ids'] = $currentFanmulu['display_name_pool_ids'];
            }
            if (array_key_exists('suffix', $settings)) {
                app(UrlSuffixCatalog::class)->assertSelectable($settings['suffix'], $currentFanmulu['suffix'] ?? '', 'fanmulu.suffix');
            }
            $settings['actor_id'] = $actorId;
            $settings = FanmuluSettings::normalize($settings, $presentation);
            FanmuluSettings::assertDisplayNamePools($settings['display_name_pool_ids'] ?? [], $siteId, $site->locale, true);
            $stored = array_replace($presentation, Json::decode($site->presentation_json), ['fanmulu' => $settings]);
            app(AdvertisingScriptPaths::class)->assertRoutesAvailable($stored);
            app(PublicHtmlCache::class)->invalidateSite($siteId);
            DB::table('sites')->where('id', $siteId)->update(['presentation_json' => Json::encode($stored),
                'row_version' => DB::raw('row_version+1'), 'config_epoch' => DB::raw('config_epoch+1'), 'updated_at' => now()]);
            Access::audit('site.fanmulu.saved', $actorId, $siteId, ['fanmulu' => $settings]);

            return DB::table('sites')->find($siteId);
        }, 3);
    }

    public static function movieKey(int|string $sourceId, string $externalId, array $content, array $profile): string
    {
        $id = $sourceId.'-'.rawurlencode($externalId);
        if (empty($profile['configured']) || ($profile['movie_key'] ?? 'source_id') === 'source_id') {
            return $id;
        }
        $slug = self::pinyinSlug((string) ($content['title'] ?? ''), $profile['movie_key'] === 'pinyin_compact_id');

        return ($slug === '' ? 'movie' : $slug).'-'.$id;
    }

    public static function categoryKey(object|array $category, array $profile, ?array $previous = null, ?string $previousMode = null): string
    {
        $category = (array) $category;
        $id = (string) ($category['id'] ?? '');
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new \InvalidArgumentException('ID danh mục không hợp lệ.');
        }
        if (empty($profile['configured']) || ($profile['category_key'] ?? 'id') === 'id') {
            return $id;
        }
        $previousKey = $previous['route_key'] ?? basename((string) ($previous['path'] ?? ''));
        if ($previousMode === ($profile['category_key'] ?? 'id') && (string) ($previous['id'] ?? '') === $id
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*-'.preg_quote($id, '/').'$/D', $previousKey)) {
            return $previousKey;
        }
        $slug = self::categoryPinyinSlug((string) ($category['name'] ?? ''), $profile['category_key'] === 'pinyin_compact_id');

        return ($slug === '' ? 'category' : $slug).'-'.$id;
    }

    private static function categoryPinyinSlug(string $text, bool $compact): string
    {
        // Only the pure conversion is shared. The caller still checks frozen route keys.
        static $recent = [];
        $text = mb_substr($text, 0, 500);
        $identity = hash('sha256', INTL_ICU_VERSION."\0".(int) $compact."\0".$text);
        if (isset($recent[$identity])) {
            return $recent[$identity];
        }
        if (count($recent) >= 256) {
            $recent = [];
        }

        return $recent[$identity] = self::sharedCategoryPinyinSlug($text, $compact, $identity);
    }

    private static function sharedCategoryPinyinSlug(string $text, bool $compact, string $identity): string
    {
        if (Cache::getFacadeRoot() === null) {
            return self::pinyinSlug($text, $compact);
        }
        try {
            $cache = Cache::store('file');
            // Fixed slots bound disk use even when many categories are renamed.
            $key = 'category-pinyin-v1:'.substr($identity, 0, 3);
            $lookup = function () use ($cache, $key, $identity, $compact): ?string {
                $entry = $cache->get($key);
                $slug = is_array($entry) && ($entry['identity'] ?? null) === $identity ? ($entry['slug'] ?? null) : null;

                return is_string($slug) && preg_match($compact ? '/^[a-z0-9]{0,120}$/D' : '/^[a-z0-9-]{0,120}$/D', $slug) ? $slug : null;
            };
            $lock = $cache->lock($key.':lock', 5);
            $deadline = hrtime(true) + 150_000_000;
            do {
                $hit = $lookup();
                if ($hit !== null) {
                    return $hit;
                }
                if ($lock->get()) {
                    try {
                        $hit = $lookup();
                        if ($hit !== null) {
                            return $hit;
                        }
                        $slug = self::pinyinSlug($text, $compact);
                        $cache->put($key, ['identity' => $identity, 'slug' => $slug], 86_400);

                        return $slug;
                    } finally {
                        $lock->release();
                    }
                }
                usleep(5_000);
            } while (hrtime(true) < $deadline);
        } catch (\Throwable $error) {
            report($error);
        }

        return self::pinyinSlug($text, $compact);
    }

    private static function pinyinSlug(string $text, bool $compact): string
    {
        static $transliterator;
        $transliterator ??= \Transliterator::create('Han-Latin; Latin-ASCII; Lower()');
        $latin = $transliterator?->transliterate(mb_substr($text, 0, 500));
        if (! is_string($latin)) {
            throw new \RuntimeException('Không tạo được pinyin; kiểm tra PHP intl/ICU.');
        }

        return $compact
            ? substr(preg_replace('/[^a-z0-9]+/', '', $latin), 0, 120)
            : trim(substr(trim(preg_replace('/[^a-z0-9]+/', '-', $latin), '-'), 0, 120), '-');
    }

    /** Resolve from published references before looking at names for a newly used key. */
    public static function categoryRouteKeys(int $siteId, array $profile): array
    {
        if (empty($profile['configured']) || ($profile['category_key'] ?? 'id') === 'id') {
            return [];
        }
        $categories = CatalogAvailability::filter(DB::table('categories')->where('site_id', $siteId), 'categories')->orderBy('id')->get()->keyBy('id');
        $mode = $profile['category_key'];
        $frozen = [];
        $remember = function (array $reference, array $referenceProfile) use (&$frozen, $categories): void {
            $id = $reference['id'] ?? null;
            if (! is_int($id) || ! $categories->has($id) || isset($frozen[$id])) {
                return;
            }
            $key = $reference['route_key'] ?? self::keyFromPath((string) ($reference['path'] ?? ''), $referenceProfile, 'list');
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*-'.preg_quote((string) $id, '/').'$/D', $key)) {
                $reference['route_key'] = $key;
                $frozen[$id] = $reference;
            }
        };
        $live = app(self::class)->publicLive($siteId)['snapshot'] ?? [];
        $liveProfile = self::profile($live['routing'] ?? null);
        if (! empty($liveProfile['configured']) && ($liveProfile['category_key'] ?? 'id') === $mode) {
            foreach ($live['theme_catalog']['categories'] ?? [] as $reference) {
                $remember($reference, $liveProfile);
            }
            $remember($live['category'] ?? [], $liveProfile);
        }
        if (count($frozen) < $categories->count()) {
            $missing = $categories->keys()->diff(array_keys($frozen))->values()->all();
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            // Filter current owned pages before detoasting any historical routing JSON.
            $references = DB::select(<<<SQL
WITH candidates AS MATERIALIZED (
    SELECT p.category_id, pp.id AS publication_id, pp.snapshot_json
    FROM (
        SELECT id, site_id, category_id, current_publication_id FROM pages
        WHERE status = 'live' AND page_kind = 'regular' AND page_type = 'category'
        UNION ALL
        SELECT id, site_id, category_id, current_publication_id FROM pages
        WHERE status = 'live' AND page_kind = 'regular' AND page_type = 'movie'
    ) p
    JOIN page_publications pp ON pp.id = p.current_publication_id AND pp.page_id = p.id AND pp.site_id = p.site_id
    WHERE p.site_id = ? AND p.category_id IN ({$placeholders})
)
SELECT DISTINCT ON (category_id) snapshot_json->'category' AS category, snapshot_json->'routing' AS routing
FROM candidates
WHERE snapshot_json->'routing'->>'category_key' = ?
ORDER BY category_id, publication_id
SQL, [$siteId, ...$missing, $mode]);
            foreach ($references as $reference) {
                $remember(Json::decode($reference->category), self::profile(Json::decode($reference->routing)));
            }
        }

        return $categories->mapWithKeys(fn ($category) => [$category->id => self::categoryKey($category, $profile, $frozen[$category->id] ?? null, $mode)])->all();
    }

    public static function categoryPath(object|array $category, array $profile, array $keys = []): string
    {
        $id = (int) ((array) $category)['id'];

        return self::categoryPathForKey($profile, $keys[$id] ?? self::categoryKey($category, $profile));
    }

    public static function suffix(array $profile, string $type): string
    {
        return ! empty($profile['configured']) ? ($profile['suffixes'][$type] ?? '') : '';
    }

    public static function endpointPath(array $profile, string $type): string
    {
        $path = $profile['routes'][$type];
        $suffix = self::suffix($profile, $type);

        return ($type === 'home' && $path === '/' && $suffix !== '' ? '/index' : $path).$suffix;
    }

    public static function categoryPathForKey(array $profile, string $key): string
    {
        return $profile['routes']['list'].'/'.$key.self::suffix($profile, 'list');
    }

    public static function detailPath(array $profile, string $key): string
    {
        return $profile['routes']['detail'].'/'.$key.self::suffix($profile, 'detail');
    }

    public static function keyFromPath(string $path, array $profile, string $type): string
    {
        $suffix = self::suffix($profile, $type);
        if ($suffix !== '' && str_ends_with($path, $suffix)) {
            $path = substr($path, 0, -strlen($suffix));
        }

        return basename($path);
    }

    public static function routeKey(object $page, array $content = [], ?array $profile = null, array $categoryKeys = []): string
    {
        if (($page->page_kind ?? 'regular') === 'fanmulu') {
            return 'fm-'.$page->id;
        }
        if (($page->page_type ?? '') === 'home') {
            return (string) $page->id;
        }
        if (! empty($page->content_item_id)) {
            $item = DB::table('content_items')->find($page->content_item_id);
            if ($item) {
                $profile ??= self::profile(DB::table('sites')->find($page->site_id));
                if (($page->page_type ?? '') === 'movie') {
                    // A title update must not silently rename a URL already published in the same mode.
                    $previous = ! empty($page->current_publication_id)
                        ? Json::decode(DB::table('page_publications')->where('id', $page->current_publication_id)->value('snapshot_json')) : [];
                    $old = self::profile($previous['routing'] ?? null);
                    $mode = ! empty($profile['configured']) ? $profile['movie_key'] : 'source_id';
                    $oldMode = ! empty($old['configured']) ? $old['movie_key'] : 'source_id';
                    if ($mode === $oldMode && ! empty($previous['page']['route_key'])) {
                        return $previous['page']['route_key'];
                    }
                    if ($content === [] && ! empty($page->working_content_revision_id)) {
                        $content = Json::decode(DB::table('content_revisions')->where('id', $page->working_content_revision_id)->value('payload_json'));
                    }

                    return self::movieKey($item->source_connector_id ?? 'content', $item->external_id, $content, $profile);
                }

                return ($item->source_connector_id ?? 'content').'-'.rawurlencode($item->external_id);
            }
        }
        if (($page->page_type ?? '') === 'category') {
            $profile ??= self::profile(DB::table('sites')->find($page->site_id));
            $id = (int) ($page->category_id ?? $page->id);
            if (empty($profile['configured']) || ($profile['category_key'] ?? 'id') === 'id') {
                return (string) $id;
            }
            $categoryKeys = $categoryKeys ?: self::categoryRouteKeys((int) $page->site_id, $profile);

            return $categoryKeys[$id] ?? (string) $id;
        }

        return basename($page->canonical_path ?? '/') ?: (string) ($page->id ?? '');
    }

    public static function pathForPage(object $page, ?array $profile = null, array $content = [], array $categoryKeys = []): string
    {
        if (($page->page_kind ?? 'regular') === 'fanmulu') {
            return $page->canonical_path;
        }
        $profile ??= self::profile(DB::table('sites')->find($page->site_id));
        if (! $profile['configured']) {
            return $page->canonical_path;
        }

        return match ($page->page_type) {
            'home' => self::endpointPath($profile, 'home'),
            'category' => self::categoryPathForKey($profile, self::routeKey($page, $content, $profile, $categoryKeys)),
            'movie' => self::detailPath($profile, self::routeKey($page, $content, $profile)),
            default => $page->canonical_path,
        };
    }

    public static function playPath(array $profile, string $key, int $index): string
    {
        if ($index < 1 || $index > 100000 || ! preg_match('#^[A-Za-z0-9_.~%-]+$#D', $key) || preg_match('/%(?![A-Fa-f0-9]{2})/', $key)) {
            throw new \InvalidArgumentException('Định danh tập/URL không hợp lệ.');
        }

        return $profile['routes']['play'].'/'.$key.'/'.$index.self::suffix($profile, 'play');
    }

    public static function urls(array $snapshot): array
    {
        $profile = self::profile($snapshot['routing'] ?? null);
        $play = '';
        if (! empty($snapshot['content']['episodes']) && ! empty($snapshot['page']['route_key'])) {
            $play = self::playPath($profile, $snapshot['page']['route_key'], 1);
        }

        return ['home' => self::endpointPath($profile, 'home'), 'list' => self::endpointPath($profile, 'list'), 'search' => self::endpointPath($profile, 'search'),
            'detail' => $snapshot['page']['path'] ?? '/', 'play' => $play];
    }

    public static function assertAvailable(object $page, string $path): void
    {
        app(AdvertisingScriptPaths::class)->assertPagePathAvailable($path);
        app(FanmuluUrlRegistry::class)->assertPathWriteAvailable((int) $page->site_id, $path, $page->canonical_path);
        if (DB::table('pages')->where('site_id', $page->site_id)->where('canonical_path', $path)->where('id', '<>', $page->id)->exists()) {
            throw new Conflict('PATH_ALREADY_USED');
        }
        $redirect = DB::table('page_redirects')->where(['site_id' => $page->site_id, 'from_path' => $path])->first();
        if ($redirect && $redirect->to_path !== $page->canonical_path) {
            throw new Conflict('PATH_ALREADY_USED');
        }
    }

    /** Caller holds site then page locks; URL and publication activate in one transaction. */
    public static function activatePath(object $page, string $path): void
    {
        if ($path === $page->canonical_path) {
            return;
        }
        self::assertAvailable($page, $path);
        DB::table('page_redirects')->where(['site_id' => $page->site_id, 'from_path' => $path])->delete();
        DB::table('page_redirects')->where('site_id', $page->site_id)->where('to_path', $page->canonical_path)->update(['to_path' => $path]);
        DB::table('page_redirects')->updateOrInsert(['site_id' => $page->site_id, 'from_path' => $page->canonical_path], ['to_path' => $path, 'status_code' => 301]);
    }

    public static function virtualChanges(object $page, array $snapshot): array
    {
        if ($page->page_type !== 'home') {
            return [];
        }
        $previous = $page->current_publication_id ? Json::decode(DB::table('page_publications')->where('id', $page->current_publication_id)->value('snapshot_json')) : [];
        $old = self::profile($previous['routing'] ?? null);
        $new = self::profile($snapshot['routing'] ?? null);
        $changes = [];
        foreach (['search', 'list'] as $type) {
            $from = self::endpointPath($old, $type);
            $to = self::endpointPath($new, $type);
            if ($from === $to) {
                continue;
            }
            if ($from === ($snapshot['page']['path'] ?? self::pathForPage($page, $new))
                || $to === self::endpointPath($old, $type === 'search' ? 'list' : 'search')) {
                throw new Conflict('PATH_ALREADY_USED');
            }
            if (DB::table('pages')->where('site_id', $page->site_id)->whereIn('canonical_path', [$from, $to])->exists()) {
                throw new Conflict('PATH_ALREADY_USED');
            }
            $target = DB::table('page_redirects')->where(['site_id' => $page->site_id, 'from_path' => $to])->first();
            if ($target && $target->to_path !== $from) {
                throw new Conflict('PATH_ALREADY_USED');
            }
            $changes[] = [$from, $to];
        }

        return $changes;
    }

    /** Freeze virtual endpoints with the home publication, in the same transaction. */
    public static function activateVirtualRoutes(object $page, array $snapshot): void
    {
        foreach (self::virtualChanges($page, $snapshot) as [$from, $to]) {
            DB::table('page_redirects')->where(['site_id' => $page->site_id, 'from_path' => $to])->delete();
            DB::table('page_redirects')->where('site_id', $page->site_id)->where('to_path', $from)->update(['to_path' => $to]);
            DB::table('page_redirects')->updateOrInsert(['site_id' => $page->site_id, 'from_path' => $from], ['to_path' => $to, 'status_code' => 301]);
        }
    }
}
