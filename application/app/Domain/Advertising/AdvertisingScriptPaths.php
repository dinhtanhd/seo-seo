<?php

namespace App\Domain\Advertising;

use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AdvertisingScriptPaths
{
    public const DEFAULTS = ['regular' => '/__tram_ads/regular.js', 'fanmulu' => '/__tram_ads/fanmulu.js'];

    private static bool $available = false;

    public static function available(): bool
    {
        return self::$available || (self::$available = Schema::hasColumn('advertising_settings', 'configuration_json'));
    }

    public function configuration(?object $row = null): array
    {
        if ($row !== null) {
            return Json::decode($row->configuration_json ?? null);
        }

        return self::available() ? Json::decode(DB::table('advertising_settings')->where('id', 1)->value('configuration_json')) : [];
    }

    public function settings(?array $configuration = null): array
    {
        $configuration ??= $this->configuration();
        $paths = array_replace(self::DEFAULTS, $configuration['js_paths'] ?? []);
        $aliases = [];
        foreach (self::DEFAULTS as $area => $default) {
            $aliases[$area] = array_values(array_unique([$default, ...($configuration['js_aliases'][$area] ?? []), $paths[$area]]));
        }

        return ['regular_js_path' => $paths['regular'], 'fanmulu_js_path' => $paths['fanmulu'], 'js_aliases' => $aliases];
    }

    /** Caller holds the global settings write lock while checking and committing paths. */
    public function merge(array $configuration, array $input): array
    {
        $current = $this->settings($configuration);
        $paths = [];
        $aliases = $current['js_aliases'];
        foreach (self::DEFAULTS as $area => $default) {
            $field = $area.'_js_path';
            $paths[$area] = array_key_exists($field, $input) ? $input[$field] : $current[$field];
            $this->syntax($paths[$area], $field, $area);
            if ($paths[$area] !== $current[$field]) {
                $this->assertAvailable($paths[$area], $field);
            }
            $aliases[$area] = array_values(array_unique([...$aliases[$area], $paths[$area]]));
            if (count($aliases[$area]) > 100) {
                throw ValidationException::withMessages([$field => 'Đã giữ 100 URL JS để tương thích. Không thể thêm URL khác vì các URL cũ phải tiếp tục hoạt động.']);
            }
        }
        if (array_intersect($aliases['regular'], $aliases['fanmulu']) !== []) {
            throw ValidationException::withMessages(['fanmulu_js_path' => 'Hai khu vực cần dùng URL JS khác nhau; không dùng lại URL hiện tại hoặc URL cũ của khu vực kia.']);
        }

        return array_replace($configuration, ['js_paths' => $paths, 'js_aliases' => $aliases]);
    }

    public function area(string $path): ?string
    {
        foreach ($this->settings()['js_aliases'] as $area => $aliases) {
            if (in_array($path, $aliases, true)) {
                return $area;
            }
        }

        return null;
    }

    public function assertRoutesAvailable(array $profile): void
    {
        foreach ($this->settings()['js_aliases'] as $aliases) {
            foreach ($aliases as $path) {
                if ($this->collides($path, $profile)) {
                    throw ValidationException::withMessages(['routes' => 'Đường dẫn website hoặc fanmulu trùng vùng URL JS quảng cáo đã cấp: '.$path]);
                }
            }
        }
    }

    public function assertPagePathAvailable(string $path): void
    {
        if (! str_ends_with($path, '.js')) {
            return;
        }
        if ($this->area($path) !== null) {
            throw new Conflict('PATH_RESERVED_FOR_ADVERTISING', 'URL này đang được giữ cho JS quảng cáo, gồm cả URL cũ còn hoạt động.');
        }
    }

    private function syntax(mixed $path, string $field, string $area): void
    {
        if (! is_string($path) || strlen($path) > 240 || preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.js$~D', $path) !== 1) {
            throw ValidationException::withMessages([$field => 'Điền đường dẫn đầy đủ bắt đầu / và kết thúc .js, tối đa 240 ký tự; không có khoảng trắng, query, dấu % hoặc ../.']);
        }
        $first = strtolower(explode('/', ltrim($path, '/'))[0]);
        if (in_array($first, ['admin', 'api', 'login', 'logout', 'password', 'up', 'readyz', 'build', 'storage', 'theme-assets', 'theme-bundles',
            'robots', 'sitemap', '__tram_public_cache_policy', '__tram_public_html_cache', '__tram_public_runtime_html_cache', '__tram_domain_gate'], true)
            || (str_starts_with($first, '__tram') && $path !== self::DEFAULTS[$area])) {
            throw ValidationException::withMessages([$field => 'Đường dẫn này được dành cho hệ thống. Chọn thư mục và tên file JS khác.']);
        }
    }

    private function assertAvailable(string $path, string $field): void
    {
        if (file_exists(public_path(ltrim($path, '/')))
            || DB::table('pages')->where('canonical_path', $path)->exists()
            || DB::table('page_redirects')->where('from_path', $path)->orWhere('to_path', $path)->exists()
            || DB::table('fanmulu_url_registry')->where('path', $path)->exists()) {
            throw ValidationException::withMessages([$field => 'Đường dẫn đã được dùng bởi tài nguyên, URL website hoặc fanmulu.']);
        }
        $sites = DB::table('sites')->selectRaw("id, presentation_json->'routes' AS routes_json, presentation_json->'configured' AS configured_json,
            presentation_json->'suffixes' AS suffixes_json, presentation_json#>>'{fanmulu,prefix}' AS prefix,
            presentation_json#>'{fanmulu,prefixes}' AS prefixes_json,
            live_appearance_json#>'{routing,routes}' AS live_routes_json,
            live_appearance_json#>'{routing,configured}' AS live_configured_json,
            live_appearance_json#>'{routing,suffixes}' AS live_suffixes_json,
            live_appearance_json#>>'{routing,fanmulu,prefix}' AS live_prefix,
            live_appearance_json#>'{routing,fanmulu,prefixes}' AS live_prefixes_json")->orderBy('id')->cursor();
        foreach ($sites as $site) {
            foreach ([
                ['routes' => Json::decode($site->routes_json), 'configured' => $site->configured_json === 'true', 'suffixes' => Json::decode($site->suffixes_json),
                    'fanmulu' => ['prefix' => $site->prefix ?? '/f', 'prefixes' => Json::decode($site->prefixes_json)]],
                ['routes' => Json::decode($site->live_routes_json), 'configured' => $site->live_configured_json === 'true', 'suffixes' => Json::decode($site->live_suffixes_json),
                    'fanmulu' => ['prefix' => $site->live_prefix ?? '/f', 'prefixes' => Json::decode($site->live_prefixes_json)]],
            ] as $profile) {
                if ($this->collides($path, $profile)) {
                    throw ValidationException::withMessages([$field => 'Đường dẫn nằm trong vùng URL phim hoặc fanmulu của website #'.$site->id.'.']);
                }
            }
        }
        // Old virtual play/list/search routes remain reachable through publication
        // fallback after the website chooses a different route profile. Read only
        // the small routing object, never movie bodies, themes or catalog cards.
        $historical = DB::table('page_publications')->selectRaw("DISTINCT CASE
            WHEN jsonb_typeof(snapshot_json->'routing') = 'object' THEN snapshot_json->'routing'
            ELSE '{}'::jsonb END AS routing_json")->cursor();
        foreach ($historical as $publication) {
            if ($this->collides($path, Json::decode($publication->routing_json))) {
                throw ValidationException::withMessages([$field => 'Đường dẫn nằm trong vùng URL lịch sử của website. Các URL cũ vẫn được giữ để người dùng truy cập.']);
            }
        }
    }

    private function collides(string $path, array $profile): bool
    {
        $routes = array_replace(SitePresentationService::defaults()['routes'], $profile['routes'] ?? []);
        $resolved = array_replace(SitePresentationService::defaults(), $profile, ['routes' => $routes]);
        foreach (['home', 'list', 'search'] as $role) {
            if ($path === SitePresentationService::endpointPath($resolved, $role)) {
                return true;
            }
        }
        $prefixes = [...array_values($routes), $profile['fanmulu']['prefix'] ?? '/f', ...($profile['fanmulu']['prefixes'] ?? [])];
        foreach ($prefixes as $prefix) {
            if (! is_string($prefix) || $prefix === '/' || $prefix === '') {
                continue;
            }
            $prefix = rtrim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix.'/') || $path === $prefix.'.js') {
                return true;
            }
        }

        return false;
    }
}
