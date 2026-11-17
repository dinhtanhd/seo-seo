<?php

namespace App\Domain\Themes;

/** Reviewed, versioned static resources; never enables scripts in user HTML. */
final class ReviewedThemeRuntime
{
    public static function enabled(array $theme): bool
    {
        return is_array($theme['runtime'] ?? null) && is_string($theme['runtime']['profile'] ?? null) && $theme['runtime']['profile'] !== '';
    }

    public static function profile(array $runtime): array
    {
        $profile = $runtime['profile'] ?? null;
        $profiles = config('theme-runtimes', []);
        if (! is_string($profile) || ! preg_match('/^[a-z][a-z0-9_-]{1,79}$/D', $profile) || ! is_array($profiles) || ! is_array($profiles[$profile] ?? null)) {
            throw new \InvalidArgumentException('Runtime giao diện chưa có hồ sơ tài nguyên được review.');
        }

        return $profiles[$profile];
    }

    public static function safeAssetName(string $name): bool
    {
        return strlen($name) <= 255 && preg_match('~^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$~D', $name)
            && ! preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $name);
    }

    public static function validate(array $runtime, array $assets): array
    {
        if ($runtime === []) {
            return [];
        }
        $profile = self::profile($runtime);
        $allowed = ['profile', 'stylesheets', 'scripts', 'view_stylesheets', 'view_scripts', 'icons', 'meta', 'viewport', 'body_classes'];
        if (array_diff(array_keys($runtime), $allowed)) {
            throw new \InvalidArgumentException('Runtime chứa trường không được hỗ trợ.');
        }
        foreach ($assets as $name => $asset) {
            if (! is_string($name) || ! self::safeAssetName($name)) {
                throw new \InvalidArgumentException('Tên tài nguyên runtime không hợp lệ.');
            }
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! str_starts_with($name, 'originals/') && in_array($extension, ['css', 'js'], true)) {
                self::resources([$name], $extension, $extension === 'css' ? 'stylesheets' : 'scripts', $profile, $assets);
            }
        }
        $out = ['profile' => $runtime['profile']];
        if (isset($runtime['body_classes'])) {
            $classes = $runtime['body_classes'];
            if (! is_array($classes) || ! array_is_list($classes) || count($classes) > 20) {
                throw new \InvalidArgumentException('Class body runtime cần là danh sách tối đa 20 tên.');
            }
            foreach ($classes as $class) {
                if (! is_string($class) || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $class)) {
                    throw new \InvalidArgumentException('Class body runtime không hợp lệ.');
                }
            }
            $out['body_classes'] = array_values(array_unique($classes));
        }
        foreach (['stylesheets' => 'css', 'scripts' => 'js'] as $key => $extension) {
            $out[$key] = self::resources($runtime[$key] ?? [], $extension, $key, $profile, $assets);
        }
        foreach (['view_stylesheets' => ['css', 'stylesheets'], 'view_scripts' => ['js', 'scripts']] as $key => [$extension, $registry]) {
            $views = $runtime[$key] ?? [];
            if (! is_array($views) || array_diff(array_keys($views), MovieThemePackage::VIEWS)) {
                throw new \InvalidArgumentException('Runtime chỉ cấu hình tài nguyên cho 5 loại trang.');
            }
            $out[$key] = [];
            foreach ($views as $view => $names) {
                $out[$key][$view] = self::resources($names, $extension, $registry, $profile, $assets);
            }
        }
        $icons = $runtime['icons'] ?? [];
        if (! is_array($icons) || ! array_is_list($icons) || count($icons) > 20) {
            throw new \InvalidArgumentException('Danh sách icon runtime không hợp lệ.');
        }
        $out['icons'] = [];
        foreach ($icons as $icon) {
            if (! is_array($icon) || array_diff(array_keys($icon), ['rel', 'asset', 'sizes', 'type'])
                || ! in_array($icon['rel'] ?? null, ['icon', 'shortcut icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon'], true)
                || ! is_string($icon['asset'] ?? null) || ! self::safeAssetName($icon['asset']) || ! isset($assets[$icon['asset']])
                || ! str_starts_with($assets[$icon['asset']]['mime'] ?? '', 'image/')) {
                throw new \InvalidArgumentException('Icon runtime phải tham chiếu ảnh trong bộ giao diện.');
            }
            foreach (['sizes', 'type'] as $field) {
                if (isset($icon[$field]) && (! is_string($icon[$field]) || strlen($icon[$field]) > 100 || preg_match('/[<>\x00-\x1f]/', $icon[$field]))) {
                    throw new \InvalidArgumentException('Thuộc tính icon runtime không hợp lệ.');
                }
            }
            $out['icons'][] = $icon;
        }
        $meta = $runtime['meta'] ?? [];
        if (! is_array($meta) || ! array_is_list($meta) || count($meta) > 20) {
            throw new \InvalidArgumentException('Danh sách meta runtime không hợp lệ.');
        }
        $out['meta'] = [];
        foreach ($meta as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['name', 'http-equiv', 'content'])
                || (isset($entry['name']) === isset($entry['http-equiv'])) || ! is_string($entry['content'] ?? null)
                || strlen($entry['content']) > 2000 || preg_match('/[<>\x00-\x1f]/', $entry['content'])) {
                throw new \InvalidArgumentException('Meta runtime không hợp lệ.');
            }
            if (isset($entry['name']) && (! is_string($entry['name']) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D', $entry['name']))) {
                throw new \InvalidArgumentException('Tên meta runtime không hợp lệ.');
            }
            if (isset($entry['http-equiv']) && (! is_string($entry['http-equiv']) || ! in_array(strtolower($entry['http-equiv']), ['x-ua-compatible', 'content-type', 'cache-control'], true))) {
                throw new \InvalidArgumentException('Runtime không được thay CSP hoặc tự chuyển hướng bằng meta.');
            }
            if (strtolower($entry['http-equiv'] ?? '') === 'cache-control' && ! in_array(strtolower(trim($entry['content'])), ['no-transform', 'no-siteapp'], true)) {
                throw new \InvalidArgumentException('Cache-Control meta runtime chỉ giữ no-transform hoặc no-siteapp.');
            }
            $out['meta'][] = $entry;
        }
        if (isset($runtime['viewport'])) {
            if (! is_string($runtime['viewport']) || $runtime['viewport'] === '' || strlen($runtime['viewport']) > 500 || preg_match('/[<>\x00-\x1f]/', $runtime['viewport'])) {
                throw new \InvalidArgumentException('Viewport runtime không hợp lệ.');
            }
            $out['viewport'] = $runtime['viewport'];
        }

        return $out;
    }

    private static function resources(mixed $names, string $extension, string $registry, array $profile, array $assets): array
    {
        if (! is_array($names) || ! array_is_list($names) || count($names) > 80 || count(array_unique($names, SORT_REGULAR)) !== count($names)) {
            throw new \InvalidArgumentException('Thứ tự tài nguyên runtime phải là danh sách không trùng.');
        }
        foreach ($names as $name) {
            $expected = is_string($name) ? ($profile[$registry][$name] ?? null) : null;
            if (! is_string($name) || ! self::safeAssetName($name) || str_starts_with($name, 'originals/')
                || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension || ! isset($assets[$name])
                || ! self::approvedHash($expected, $assets[$name]['hash'] ?? null)) {
                throw new \InvalidArgumentException('Tài nguyên runtime chưa được review đúng đường dẫn và SHA-256: '.(is_string($name) ? $name : '?'));
            }
        }

        return $names;
    }

    private static function approvedHash(mixed $expected, mixed $actual): bool
    {
        $approved = is_string($expected) ? [$expected] : $expected;
        if (! is_array($approved) || ! array_is_list($approved) || $approved === [] || count($approved) > 80 || ! is_string($actual)) {
            return false;
        }
        $matched = false;
        foreach ($approved as $hash) {
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                return false;
            }
            $matched = hash_equals($hash, $actual) || $matched;
        }

        return $matched;
    }

    public static function assetPath(string $versionHash, string $name): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $versionHash) || ! self::safeAssetName($name)) {
            throw new \InvalidArgumentException('Đường dẫn bundle runtime không hợp lệ.');
        }

        return '/theme-bundles/'.$versionHash.'/'.implode('/', array_map('rawurlencode', explode('/', $name)));
    }

    public static function freezeAssets(array $assets, string $versionHash): array
    {
        foreach ($assets as $name => &$asset) {
            $asset['path'] = self::assetPath($versionHash, $name);
        }
        unset($asset);

        return $assets;
    }

    public static function forView(array $runtime, string $view): array
    {
        if (! in_array($view, MovieThemePackage::VIEWS, true)) {
            throw new \InvalidArgumentException('View runtime không hợp lệ.');
        }
        $out = $runtime;
        foreach (['stylesheets', 'scripts'] as $key) {
            $out[$key] = array_values(array_unique([...($runtime[$key] ?? []), ...($runtime['view_'.$key][$view] ?? [])]));
        }

        return $out;
    }
}
