<?php

namespace App\Domain\Fanmulu;

use App\Domain\Sites\UrlSuffixCatalog;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

final class FanmuluSettings
{
    public static function defaults(): array
    {
        return ['enabled' => false, 'prefix' => '/f', 'links_per_page' => 3, 'max_pages' => 10000, 'actor_id' => null];
    }

    public static function fromSite(object|array|null $site): array
    {
        $raw = is_object($site) ? ($site->presentation_json ?? []) : ($site['presentation_json'] ?? $site ?? []);
        $presentation = Json::decode($raw);

        $settings = self::normalize($presentation['fanmulu'] ?? [], $presentation);

        return $settings + ['prefixes' => [$settings['prefix']], 'url_rule' => ['mode' => 'legacy']];
    }

    public static function normalize(array $settings, ?array $presentation = null): array
    {
        $hasSuffix = array_key_exists('suffix', $settings);
        $hasPrefixes = array_key_exists('prefixes', $settings);
        $hasUrlRule = array_key_exists('url_rule', $settings);
        $hasDisplayNamePools = array_key_exists('display_name_pool_ids', $settings);
        if (array_diff(array_keys($settings), [...array_keys(self::defaults()), 'suffix', 'prefixes', 'url_rule', 'display_name_pool_ids'])) {
            throw new \InvalidArgumentException('Cấu hình fanmulu có trường không hỗ trợ.');
        }
        $settings = array_replace(self::defaults(), $settings);
        $enabled = filter_var($settings['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new \InvalidArgumentException('Bật fanmulu phải là giá trị có hoặc không.');
        }
        if ($hasPrefixes && (! is_array($settings['prefixes']) || ! array_is_list($settings['prefixes']) || count($settings['prefixes']) > 20)) {
            throw new \InvalidArgumentException('Danh sách fanmulu nhận tối đa 20 đường dẫn gốc.');
        }
        $prefixes = [];
        foreach ([$settings['prefix'], ...($settings['prefixes'] ?? [])] as $value) {
            $prefix = is_string($value) ? rtrim(trim($value), '/') : '';
            if (strlen($prefix) > 120 || preg_match('~^/[A-Za-z0-9][A-Za-z0-9_-]*(?:/[A-Za-z0-9][A-Za-z0-9_-]*)*$~D', $prefix) !== 1) {
                throw new \InvalidArgumentException('Đường dẫn fanmulu cần dạng /f hoặc /seo/f, tối đa 120 ký tự.');
            }
            $prefixes[$prefix] = $prefix;
        }
        $prefixes = array_values($prefixes);
        if (count($prefixes) > 20) {
            throw new \InvalidArgumentException('Mỗi website dùng tối đa 20 đường dẫn gốc fanmulu, gồm đường dẫn chính.');
        }
        $reserved = ['admin', 'api', 'login', 'logout', 'password', 'up', 'readyz', 'build', 'storage', 'theme-assets', 'theme-bundles',
            'robots', 'sitemap', '__tram_public_html_cache', '__tram_public_runtime_html_cache', '__tram_ads'];
        $routes = $presentation['routes'] ?? ['home' => '/', 'list' => '/type', 'detail' => '/movie', 'play' => '/play', 'search' => '/search'];
        foreach ($prefixes as $index => $prefix) {
            if (in_array(strtolower(explode('/', ltrim($prefix, '/'))[0]), $reserved, true)) {
                throw new \InvalidArgumentException('Đường dẫn fanmulu được dành cho hệ thống.');
            }
            foreach (array_slice($prefixes, $index + 1) as $other) {
                if (str_starts_with($prefix, $other.'/') || str_starts_with($other, $prefix.'/')) {
                    throw new \InvalidArgumentException('Các đường dẫn fanmulu không được lồng nhau, ví dụ /f và /f/x.');
                }
            }
            foreach ($routes as $route) {
                if ($enabled && $route !== '/' && ($prefix === $route || str_starts_with($prefix, $route.'/') || str_starts_with($route, $prefix.'/'))) {
                    throw new \InvalidArgumentException('Đường dẫn fanmulu trùng hoặc chồng lên đường dẫn website.');
                }
            }
        }
        foreach (['links_per_page' => [0, 20], 'max_pages' => [1, 1000000]] as $key => [$min, $max]) {
            $value = filter_var($settings[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($value === false) {
                throw new \InvalidArgumentException($key.' phải là số nguyên từ '.$min.' đến '.$max.'.');
            }
            $settings[$key] = $value;
        }
        $actorId = $settings['actor_id'] === null ? null : filter_var($settings['actor_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($actorId === false || ($enabled && $actorId === null)) {
            throw new \InvalidArgumentException('Cần lưu tài khoản đã cho phép tự xuất bản fanmulu.');
        }

        $normalized = ['enabled' => $enabled, 'prefix' => $prefixes[0], 'links_per_page' => $settings['links_per_page'],
            'max_pages' => $settings['max_pages'], 'actor_id' => $actorId];
        if ($hasPrefixes) {
            $normalized['prefixes'] = $prefixes;
        }
        if ($hasSuffix) {
            $normalized['suffix'] = UrlSuffixCatalog::normalizeSuffix($settings['suffix']);
        }
        if ($hasUrlRule) {
            $normalized['url_rule'] = self::normalizeUrlRule($settings['url_rule']);
        }
        if ($hasDisplayNamePools) {
            $normalized['display_name_pool_ids'] = self::displayNamePoolIds($settings['display_name_pool_ids']);
        }

        return $normalized;
    }

    /** A nonempty explicit list separates fanmulu names from ordinary SEO assignments. */
    public static function displayNamePoolIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 1000) {
            throw new \InvalidArgumentException('Kho tên phim thay thế cần là danh sách tối đa 1.000 ID kho.');
        }
        $ids = [];
        foreach ($value as $id) {
            if ((! is_int($id) && (! is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1))
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException('ID kho tên phim thay thế phải là số nguyên dương.');
            }
            $id = (int) $id;
            if (in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('Không chọn trùng kho tên phim thay thế.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** Validate again under the site's configuration transaction before accepting a new selection. */
    public static function assertDisplayNamePools(array $ids, ?int $siteId, string $locale, bool $lock = false): void
    {
        $ids = self::displayNamePoolIds($ids);
        if ($ids === []) {
            return;
        }
        $query = DB::table('keyword_pools as pool')->whereIn('pool.id', $ids)->where('pool.status', 'active')->whereNull('pool.deleted_at')
            ->where(function ($scope) use ($siteId): void {
                $scope->where('pool.scope', 'shared');
                if ($siteId !== null) {
                    $scope->orWhere(fn ($owned) => $owned->where('pool.scope', 'site')->where('pool.owner_site_id', $siteId));
                }
            })
            ->whereExists(fn ($keywords) => $keywords->selectRaw('1')->from('keywords as keyword')
                ->whereColumn('keyword.pool_id', 'pool.id')->where(['keyword.status' => 'active', 'keyword.role' => 'primary', 'keyword.locale' => $locale])
                ->whereNull('keyword.deleted_at'));
        if ($lock) {
            $query->sharedLock();
        }
        if ($query->get(['pool.id'])->count() !== count($ids)) {
            throw new \InvalidArgumentException('Kho tên phim thay thế phải đang hoạt động, thuộc phạm vi được phép và có keyword tên chính đúng ngôn ngữ.');
        }
    }

    /** New URL rules never reinterpret already registered URLs or their pinned assignments. */
    public static function normalizeUrlRule(mixed $rule): array
    {
        if (! is_array($rule) || array_is_list($rule) || array_diff(array_keys($rule), ['mode', 'alphabet', 'length', 'group_length', 'separator', 'date_format', 'template'])
            || ! in_array($rule['mode'] ?? null, ['legacy', 'random', 'pattern', 'tokens'], true)) {
            throw new \InvalidArgumentException('Quy tắc URL fanmulu cần chọn kiểu hiện hữu, ngẫu nhiên hoặc ngày kèm ngẫu nhiên, không chứa trường khác.');
        }
        if ($rule['mode'] === 'legacy') {
            return ['mode' => 'legacy'];
        }
        if ($rule['mode'] === 'tokens') {
            if (array_diff(array_keys($rule), ['mode', 'template']) || ! is_string($rule['template'] ?? null)) {
                throw new \InvalidArgumentException('Kiểu tự ghép chỉ nhận mẫu URL bằng các thành phần đã hỗ trợ.');
            }
            self::templateParts($rule['template']);

            return ['mode' => 'tokens', 'template' => $rule['template']];
        }
        if (array_key_exists('template', $rule)) {
            throw new \InvalidArgumentException('Mẫu thành phần chỉ dùng cho kiểu tự ghép URL.');
        }
        if ($rule['mode'] === 'random' && array_key_exists('date_format', $rule)) {
            throw new \InvalidArgumentException('Kiểu ngẫu nhiên không chứa định dạng ngày.');
        }
        if ($rule['mode'] === 'pattern') {
            $rule += ['alphabet' => 'digits', 'length' => 8, 'date_format' => 'Ymd', 'separator' => '-'];
            if (! in_array($rule['alphabet'], ['alnum', 'letters', 'digits'], true)
                || ! in_array($rule['date_format'], ['Ymd', 'Y-m-d', 'Y/m/d', 'dmY', 'd-m-Y'], true)
                || ! in_array($rule['separator'], ['', '-', '_', '/'], true)
                || (array_key_exists('group_length', $rule) && $rule['group_length'] !== 0 && $rule['group_length'] !== '0')) {
                throw new \InvalidArgumentException('Kiểu ngày cần định dạng ngày hợp lệ và phần chữ/số ngẫu nhiên viết liền.');
            }
            $length = filter_var($rule['length'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 8, 'max_range' => 64]]);
            if ($length === false) {
                throw new \InvalidArgumentException('Phần ngẫu nhiên sau ngày dài từ 8 đến 64 ký tự.');
            }

            return ['mode' => 'pattern', 'alphabet' => $rule['alphabet'], 'length' => $length,
                'date_format' => $rule['date_format'], 'separator' => $rule['separator']];
        }
        $rule += ['alphabet' => 'alnum', 'length' => 16, 'group_length' => 0, 'separator' => '-'];
        if (! in_array($rule['alphabet'], ['alnum', 'letters', 'digits'], true)) {
            throw new \InvalidArgumentException('Bộ ký tự URL chỉ nhận chữ và số, chữ cái hoặc chữ số.');
        }
        $length = filter_var($rule['length'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 8, 'max_range' => 64]]);
        $groupLength = filter_var($rule['group_length'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 16]]);
        if ($length === false || $groupLength === false || $groupLength === 1) {
            throw new \InvalidArgumentException('Chuỗi URL dài từ 8 đến 64 ký tự; nhóm bằng 0 để viết liền hoặc từ 2 đến 16 ký tự.');
        }
        if (! in_array($rule['separator'], ['-', '_'], true)) {
            throw new \InvalidArgumentException('Dấu ngăn nhóm URL chỉ nhận dấu - hoặc _.');
        }

        return ['mode' => 'random', 'alphabet' => $rule['alphabet'], 'length' => $length, 'group_length' => $groupLength, 'separator' => $rule['separator']];
    }

    public static function matches(array $settings, string $requestPath): bool
    {
        return self::matchedPrefix($settings, $requestPath) !== null;
    }

    public static function matchedPrefix(array $settings, string $requestPath): ?string
    {
        if (empty($settings['enabled']) || strlen($requestPath) > 2000) {
            return null;
        }
        foreach ($settings['prefixes'] ?? [$settings['prefix']] as $prefix) {
            $rule = $settings['url_rule'] ?? ['mode' => 'legacy'];
            if (($rule['mode'] ?? null) === 'tokens') {
                $relative = str_starts_with($requestPath, $prefix.'/') ? substr($requestPath, strlen($prefix) + 1) : '';
                $suffix = $settings['suffix'] ?? '';
                if ($suffix !== '' && str_ends_with($relative, $suffix)) {
                    $relative = substr($relative, 0, -strlen($suffix));
                } elseif ($suffix !== '') {
                    continue;
                }
                $levels = substr_count($rule['template'] ?? '', '/');
                if (strlen($relative) <= 240 && preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+){0,'.$levels.'}$~D', $relative)) {
                    return $prefix;
                }

                continue;
            }
            if (($rule['mode'] ?? null) === 'pattern' && (($rule['date_format'] ?? null) === 'Y/m/d' || ($rule['separator'] ?? null) === '/')) {
                $relative = str_starts_with($requestPath, $prefix.'/') ? substr($requestPath, strlen($prefix) + 1) : '';
                $suffix = $settings['suffix'] ?? '';
                if ($suffix !== '' && str_ends_with($relative, $suffix)) {
                    $relative = substr($relative, 0, -strlen($suffix));
                } elseif ($suffix !== '') {
                    continue;
                }
                $levels = (($rule['date_format'] ?? null) === 'Y/m/d' ? 2 : 0) + (($rule['separator'] ?? null) === '/' ? 1 : 0);
                if (strlen($relative) <= 240 && preg_match('~^[A-Za-z0-9][A-Za-z0-9_-]*(?:/[A-Za-z0-9][A-Za-z0-9_-]*){0,'.$levels.'}$~D', $relative)) {
                    return $prefix;
                }

                continue;
            }
            if (preg_match('~^'.preg_quote($prefix, '~').'/[A-Za-z0-9][A-Za-z0-9_-]{0,239}'
                .preg_quote($settings['suffix'] ?? '', '~').'$~D', $requestPath) === 1) {
                return $prefix;
            }
        }

        return null;
    }

    /** Textareas are a form transport only; persisted configuration contains a validated list. */
    public static function fromForm(array $settings): array
    {
        if (array_key_exists('display_name_pool_ids_present', $settings)) {
            $settings['display_name_pool_ids'] ??= [];
            unset($settings['display_name_pool_ids_present']);
        }
        if (is_array($settings['url_rule'] ?? null) && array_key_exists('preset', $settings['url_rule'])) {
            $preset = $settings['url_rule']['preset'];
            if (! is_string($preset) || ! in_array($preset, ['legacy', 'letters', 'digits', 'alnum', 'date_letters', 'date_digits', 'date_alnum', 'custom'], true)) {
                throw new \InvalidArgumentException('Chọn kiểu URL có sẵn hợp lệ.');
            }
            unset($settings['url_rule']['preset']);
            $settings['url_rule']['mode'] = $preset === 'legacy' ? 'legacy' : ($preset === 'custom' ? 'tokens' : (str_starts_with($preset, 'date_') ? 'pattern' : 'random'));
            if ($preset !== 'legacy' && $preset !== 'custom') {
                $settings['url_rule']['alphabet'] = str_replace('date_', '', $preset);
            }
            if ($settings['url_rule']['mode'] !== 'pattern') {
                unset($settings['url_rule']['date_format']);
            } else {
                unset($settings['url_rule']['group_length']);
                if (array_key_exists('separator', $settings['url_rule']) && $settings['url_rule']['separator'] === null) {
                    $settings['url_rule']['separator'] = '';
                }
            }
            if ($preset === 'custom') {
                foreach (['alphabet', 'length', 'group_length', 'date_format', 'separator'] as $field) {
                    unset($settings['url_rule'][$field]);
                }
            } else {
                unset($settings['url_rule']['template']);
            }
        }
        if (($settings['url_rule']['mode'] ?? null) === 'pattern' && array_key_exists('separator', $settings['url_rule']) && $settings['url_rule']['separator'] === null) {
            $settings['url_rule']['separator'] = '';
        }
        if (array_key_exists('prefixes_text', $settings)) {
            $lines = preg_split('/\R/u', trim($settings['prefixes_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $settings['prefixes'] = [$settings['prefix'], ...array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''))];
            unset($settings['prefixes_text']);
        }

        return $settings;
    }

    /** @return list<array{type: string, value?: string, length?: int, format?: string}> */
    public static function templateParts(string $template): array
    {
        if ($template === '' || strlen($template) > 180 || str_starts_with($template, '/') || str_ends_with($template, '/') || str_contains($template, '//')) {
            throw new \InvalidArgumentException('Mẫu URL dài tối đa 180 ký tự, không bắt đầu/kết thúc bằng / hoặc có // .');
        }
        if (in_array('_scope', array_map('strtolower', explode('/', $template)), true)) {
            throw new \InvalidArgumentException('Thành phần _scope được dành cho điều hướng nội bộ; hãy chọn tên thư mục khác.');
        }
        $dateFormats = ['ngay' => 'd', 'thang' => 'm', 'nam' => 'Y', 'ngaythang' => 'dm', 'ngaythangnam' => 'dmY', 'namthangngay' => 'Ymd'];
        $parts = [];
        $randomCharacters = 0;
        $expandedLength = 0;
        foreach (preg_split('/(\{[^{}]*\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match('/^\{([1-9][0-9]?)(chuso|chucai)\}$/D', $part, $match)) {
                $length = (int) $match[1];
                if ($length > 64) {
                    throw new \InvalidArgumentException('Mỗi thành phần ngẫu nhiên nhận từ 1 đến 64 ký tự.');
                }
                $parts[] = ['type' => $match[2] === 'chuso' ? 'digits' : 'letters', 'length' => $length];
                $randomCharacters += $length;
                $expandedLength += $length;
            } elseif (preg_match('/^\{([^{}]+)\}$/D', $part, $match) && isset($dateFormats[$match[1]])) {
                $format = $dateFormats[$match[1]];
                $parts[] = ['type' => 'date', 'format' => $format];
                $expandedLength += match ($format) {
                    'Y', 'dm' => 4, 'dmY', 'Ymd' => 8, default => 2
                };
            } elseif (preg_match('~^[A-Za-z0-9_/-]+$~D', $part)) {
                $parts[] = ['type' => 'literal', 'value' => $part];
                $expandedLength += strlen($part);
            } else {
                throw new \InvalidArgumentException('Mẫu URL có thành phần không hỗ trợ. Chỉ dùng các nút thành phần và chữ, số, /, - hoặc _.');
            }
        }
        if ($randomCharacters < 1 || $randomCharacters > 64 || $expandedLength > 240) {
            throw new \InvalidArgumentException('Mẫu cần ít nhất một thành phần ngẫu nhiên, tổng tối đa 64 ký tự ngẫu nhiên để tránh trùng URL.');
        }

        return $parts;
    }
}
