<?php

namespace App\Domain\Themes;

/** Reads data-only theme packages without extracting or executing their files. */
final class MovieThemePackage
{
    public const LEGACY_VIEWS = ['home', 'category', 'detail', 'search'];

    public const VIEWS = ['home', 'list', 'detail', 'play', 'search'];

    public static function schema(array $views): int
    {
        $keys = array_keys($views);
        sort($keys);
        $legacy = self::LEGACY_VIEWS;
        $current = self::VIEWS;
        sort($legacy);
        sort($current);
        if ($keys === $legacy) {
            return 1;
        }
        if ($keys === $current) {
            return 2;
        }
        throw new \InvalidArgumentException('Cần đủ 5 view home/list/detail/play/search hoặc 4 view legacy home/category/detail/search.');
    }

    public function folder(string $slug): array
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $slug)) {
            throw new \InvalidArgumentException('Slug thư mục giao diện không hợp lệ.');
        }
        $root = base_path('templates');
        $folder = $root.'/'.$slug;
        if (is_link($root) || is_link($folder) || ! is_dir($folder)) {
            throw new \InvalidArgumentException('Thư mục giao diện không tồn tại hoặc là symlink.');
        }
        $files = [];
        $total = 0;
        $visited = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new \InvalidArgumentException('Thư mục giao diện chứa symlink hoặc file đặc biệt.');
            }
            $name = substr($entry->getPathname(), strlen($folder) + 1);
            $this->safeName($name);
            if (++$visited > 300 || $entry->getSize() > 2 * 1024 * 1024 || ($total += $entry->getSize()) > 20 * 1024 * 1024) {
                throw new \InvalidArgumentException('Thư mục giao diện vượt ngân sách 300 files/20 MB.');
            }
            if ($entry->getFilename() === '.DS_Store') {
                continue;
            }
            $bytes = file_get_contents($entry->getPathname());
            if ($bytes === false || strlen($bytes) !== $entry->getSize()) {
                throw new \InvalidArgumentException('Không đọc được file giao diện: '.$name);
            }
            $files[$name] = $bytes;
        }
        $package = $this->files($files);
        if ($package['slug'] !== $slug) {
            throw new \InvalidArgumentException('Slug manifest phải khớp tên thư mục giao diện.');
        }

        return $package + ['path' => $folder, 'files' => array_keys($files)];
    }

    public function safeName(string $name): void
    {
        if (str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f]~', $name)) {
            throw new \InvalidArgumentException('Giao diện chứa đường dẫn không an toàn.');
        }
    }

    public function files(array $files): array
    {
        foreach (array_keys($files) as $name) {
            $this->safeName($name);
        }
        $files = array_filter($files, fn ($name) => basename($name) !== '.DS_Store', ARRAY_FILTER_USE_KEY);
        // Accept a single common folder prefix, including templates/<slug>/ exports.
        while (! isset($files['manifest.json']) && ! isset($files['info.ini'])) {
            $first = array_key_first($files);
            $prefix = explode('/', $first ?? '')[0].'/';
            if (! $first || ! str_contains($first, '/') || count(array_filter(array_keys($files), fn ($name) => ! str_starts_with($name, $prefix)))) {
                throw new \InvalidArgumentException('Giao diện thiếu manifest.json hoặc info.ini ở gốc.');
            }
            $files = array_combine(array_map(fn ($name) => substr($name, strlen($prefix)), array_keys($files)), array_values($files));
        }
        if (isset($files['manifest.json'])) {
            $manifest = json_decode($files['manifest.json'], true, 32, JSON_THROW_ON_ERROR);
        } else {
            $manifest = @parse_ini_string($files['info.ini'], false, INI_SCANNER_RAW);
            if (is_array($manifest)) {
                $manifest['schema_version'] = isset($manifest['schema_version']) ? (preg_match('/^[12]$/', $manifest['schema_version']) ? (int) $manifest['schema_version'] : null) : 2;
            }
        }
        if (! is_array($manifest) || ! in_array($manifest['schema_version'] ?? null, [1, 2], true)) {
            throw new \InvalidArgumentException('Manifest cần schema_version=1 hoặc 2.');
        }
        if (! is_string($manifest['slug'] ?? null) || ! preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $manifest['slug']) || ! is_string($manifest['name'] ?? null) || $manifest['name'] === '' || ! mb_check_encoding($manifest['name'], 'UTF-8') || mb_strlen($manifest['name']) > 200 || ! is_string($manifest['description'] ?? '') || mb_strlen($manifest['description'] ?? '') > 2000) {
            throw new \InvalidArgumentException('Tên, slug hoặc mô tả manifest không hợp lệ.');
        }
        $runtime = $manifest['runtime'] ?? [];
        if (! is_array($runtime)) {
            throw new \InvalidArgumentException('Runtime manifest phải là object.');
        }
        if ($runtime !== []) {
            ReviewedThemeRuntime::profile($runtime);
        }
        if (count($files) > ($runtime === [] ? 100 : 300)) {
            throw new \InvalidArgumentException('Số file vượt ngân sách của loại giao diện.');
        }
        $schema = $manifest['schema_version'];
        $views = [];
        $allowed = ['manifest.json', 'info.ini'];
        foreach ($schema === 1 ? self::LEGACY_VIEWS : self::VIEWS as $view) {
            $path = $schema === 1 ? $view.'.html' : 'html/'.$view.'.html';
            $views[$view] = $files[$path] ?? throw new \InvalidArgumentException('Giao diện thiếu '.$path);
            $allowed[] = $path;
        }
        $cssPath = $schema === 1 ? 'theme.css' : 'css/style.css';
        $css = $files[$cssPath] ?? throw new \InvalidArgumentException('Giao diện thiếu '.$cssPath);
        $allowed[] = $cssPath;
        $assets = [];
        $assetMap = $manifest['assets'] ?? [];
        if (! is_array($assetMap)) {
            throw new \InvalidArgumentException('Manifest assets phải là bảng tên asset và đường dẫn file.');
        }
        foreach ($assetMap as $path) {
            if (is_string($path) && $this->archivedContentMedia($path)) {
                throw new \InvalidArgumentException('Manifest asset không được tham chiếu ảnh hoặc dữ liệu phim nguồn trong '.$path.'.');
            }
        }
        $files = array_filter($files, fn ($name) => ! $this->archivedContentMedia($name), ARRAY_FILTER_USE_KEY);
        foreach ($files as $name => $bytes) {
            if (in_array($name, $allowed, true)) {
                continue;
            }
            if ($schema === 1) {
                if (! str_starts_with($name, 'assets/')) {
                    throw new \InvalidArgumentException('Giao diện chứa file không hỗ trợ: '.$name);
                }
                $assets[substr($name, 7)] = ['data_base64' => base64_encode($bytes)];
            } else {
                if ($runtime === [] && ! preg_match('~^(images|pics|fonts)/~', $name)) {
                    throw new \InvalidArgumentException('Giao diện chứa file không hỗ trợ: '.$name);
                }
                $names = array_keys($assetMap, $name, true);
                foreach ($names ?: [$name] as $assetName) {
                    if (! is_string($assetName) || isset($assets[$assetName])) {
                        throw new \InvalidArgumentException('Tên asset trùng hoặc không hợp lệ.');
                    }
                    $assets[$assetName] = ['data_base64' => base64_encode($bytes)];
                }
            }
        }
        foreach ($assetMap as $name => $path) {
            if (! is_string($name) || ! is_string($path) || ($runtime === [] && ! preg_match('~^(images|pics|fonts)/~', $path)) || ! isset($files[$path]) || ! isset($assets[$name])) {
                throw new \InvalidArgumentException('Manifest asset không tồn tại hoặc sai đường dẫn.');
            }
        }

        $result = ['schema_version' => $schema, 'name' => $manifest['name'] ?? '', 'slug' => $manifest['slug'] ?? '', 'description' => $manifest['description'] ?? '', 'views' => $views, 'css' => $css, 'assets' => $assets, 'files' => array_keys($files)];
        if ($runtime !== []) {
            $references = [];
            foreach ($assets as $name => $asset) {
                $bytes = base64_decode($asset['data_base64'], true);
                $references[$name] = ['hash' => hash('sha256', $bytes), 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)];
            }
            $result['runtime'] = ReviewedThemeRuntime::validate($runtime, $references);
        }

        return $result;
    }

    private function archivedContentMedia(string $name): bool
    {
        return (bool) preg_match('~^originals/(?:source-tree/)?(?:uploads?|vod|posters|movie-media)/~i', $name);
    }
}
