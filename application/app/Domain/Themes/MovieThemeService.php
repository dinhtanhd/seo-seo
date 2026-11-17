<?php

namespace App\Domain\Themes;

use App\Domain\Keywords\Allocator;
use App\Domain\Publishing\Renderer;
use App\Domain\Publishing\SnapshotStorage;
use App\Domain\Rules\RuleResolver;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MovieThemeService
{
    private function writer(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
    }

    public function save(array $data, int $actorId): object
    {
        $this->writer($actorId);
        $views = $data['views'] ?? $data['views_json'] ?? [];
        if (is_string($views)) {
            $views = Json::decode($views);
        }
        $css = $data['css'] ?? '';
        $name = $data['name'] ?? '';
        $slug = $data['slug'] ?? '';
        if (! is_string($name) || $name === '' || mb_strlen($name) > 200 || ! is_string($slug) || ! preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $slug) || ! is_array($views) || ! is_string($css)) {
            throw new \InvalidArgumentException('Tên, slug hoặc HTML/CSS giao diện không hợp lệ.');
        }
        $description = $data['description'] ?? '';
        if (! is_string($description) || mb_strlen($description) > 2000) {
            throw new \InvalidArgumentException('Mô tả giao diện tối đa 2.000 ký tự.');
        }
        $rawAssets = $data['assets'] ?? $data['assets_json'] ?? [];
        if (is_string($rawAssets)) {
            $rawAssets = Json::decode($rawAssets);
        }
        $runtime = $data['runtime'] ?? [];
        if (! array_key_exists('runtime', $data) && isset($data['id'])) {
            $currentRuntime = DB::table('movie_themes as t')->join('movie_theme_versions as v', 'v.id', '=', 't.current_version_id')->where('t.id', $data['id'])->value('v.runtime_json');
            $runtime = Json::decode($currentRuntime);
        }
        if (! is_array($runtime)) {
            throw new \InvalidArgumentException('Runtime giao diện phải là object.');
        }
        if ($runtime !== []) {
            ReviewedThemeRuntime::profile($runtime);
        }
        [$assets, $newAssets] = $this->assets($rawAssets, $runtime !== []);
        $runtime = ReviewedThemeRuntime::validate($runtime, $assets);
        app(MovieThemeCompiler::class)->validate($views, $css, $assets, $runtime);
        $hashData = ['views' => $views, 'css' => $css, 'assets' => $assets];
        if ($runtime !== []) {
            $hashData['runtime'] = $runtime;
        }
        $hash = Json::hash($hashData);

        return DB::transaction(function () use ($data, $actorId, $views, $css, $name, $slug, $description, $assets, $newAssets, $hash, $runtime) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $this->writer($actorId);
            $theme = isset($data['id']) ? DB::table('movie_themes')->where('id', $data['id'])->lockForUpdate()->first() : null;
            CatalogAvailability::assertWritable($theme, 'THEME_DELETED');
            if (isset($data['id']) && ! $theme) {
                abort(404);
            }
            if ($theme && (! isset($data['row_version']) || (int) $data['row_version'] !== $theme->row_version)) {
                throw new Conflict('THEME_VERSION_CONFLICT');
            }
            if (DB::table('movie_themes')->where('slug', $slug)->when($theme, fn ($q) => $q->where('id', '<>', $theme->id))->exists()) {
                throw new Conflict('THEME_SLUG_EXISTS');
            }
            foreach ($newAssets as $asset) {
                DB::table('theme_assets')->insertOrIgnore($asset);
            }
            $id = $theme?->id ?? DB::table('movie_themes')->insertGetId(['slug' => $slug, 'name' => $name, 'description' => $description, 'created_by' => $actorId]);
            $version = (DB::table('movie_theme_versions')->where('theme_id', $id)->max('version_no') ?? 0) + 1;
            $versionData = ['theme_id' => $id, 'version_no' => $version, 'views_json' => Json::encode($views), 'css' => $css, 'assets_json' => Json::encode($assets), 'hash' => $hash, 'created_by' => $actorId];
            if ($runtime !== []) {
                $versionData['runtime_json'] = Json::encode($runtime);
            }
            $vid = DB::table('movie_theme_versions')->insertGetId($versionData);
            DB::table('movie_themes')->where('id', $id)->update(['slug' => $slug, 'name' => $name, 'description' => $description, 'current_version_id' => $vid, 'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            Access::audit('theme.version.created', $actorId, null, ['theme_id' => $id, 'version_id' => $vid, 'hash' => $hash]);

            return DB::table('movie_themes')->find($id);
        }, 3);
    }

    private function assets(mixed $raw, bool $reviewed = false): array
    {
        if (! is_array($raw) || count($raw) > ($reviewed ? 300 : 80)) {
            throw new \InvalidArgumentException('Tối đa 80 assets mỗi giao diện.');
        }
        $assets = [];
        $fresh = [];
        $bytes = 0;
        foreach ($raw as $name => $entry) {
            if (! is_string($name) || ! ($reviewed ? ReviewedThemeRuntime::safeAssetName($name) : preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\.(png|jpg|jpeg|webp|gif|woff2)$~', $name)) || ! is_array($entry)) {
                throw new \InvalidArgumentException('Tên hoặc loại asset không hỗ trợ.');
            }
            if (isset($entry['hash']) && ! isset($entry['data_base64'])) {
                $existing = DB::table('theme_assets')->where('hash', $entry['hash'])->first();
                if (! $existing) {
                    throw new \InvalidArgumentException('Asset không tồn tại trong database.');
                }
                $hash = $existing->hash;
                $mime = $existing->mime;
                $size = $existing->size_bytes;
            } else {
                $data = is_string($entry['data_base64'] ?? null) ? base64_decode($entry['data_base64'], true) : false;
                if ($data === false || strlen($data) < 1 || strlen($data) > 2 * 1024 * 1024) {
                    throw new \InvalidArgumentException('Asset rỗng hoặc vượt 2 MB.');
                }
                $mime = $this->assetMime($name, $data, $reviewed);
                $hash = hash('sha256', $data);
                $size = strlen($data);
                if (! isset($fresh[$hash]) || ! str_starts_with($name, 'originals/')) {
                    $fresh[$hash] = ['hash' => $hash, 'mime' => $mime, 'data_base64' => base64_encode($data), 'size_bytes' => $size];
                }
            }
            if (isset($entry['hash']) && ! isset($entry['data_base64'])) {
                $existingBytes = base64_decode($existing->data_base64, true);
                if ($existingBytes === false || ! hash_equals($hash, hash('sha256', $existingBytes))) {
                    throw new \InvalidArgumentException('Asset lưu trữ không khớp nội dung hoặc loại file.');
                }
                $mime = $this->assetMime($name, $existingBytes, $reviewed);
            }
            $bytes += $size;
            if ($bytes > 20 * 1024 * 1024) {
                throw new \InvalidArgumentException('Assets vượt tổng 20 MB.');
            }
            $assets[$name] = ['hash' => $hash, 'mime' => $mime, 'size' => $size, 'path' => '/theme-assets/'.$hash];
        }

        return [$assets, array_values($fresh)];
    }

    private function assetMime(string $name, string $data, bool $reviewed): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if ($reviewed && str_starts_with($name, 'originals/')) {
            if (in_array($extension, ['html', 'htm', 'js', 'css', 'json', 'txt', 'map', 'svg'], true) && mb_check_encoding($data, 'UTF-8') && ! str_contains($data, "\0")) {
                return 'text/plain';
            }

            return 'application/octet-stream';
        }
        if ($reviewed && in_array($extension, ['css', 'js'], true)) {
            if (! mb_check_encoding($data, 'UTF-8') || str_contains($data, "\0")) {
                throw new \InvalidArgumentException('CSS/JS runtime phải là UTF-8, không có NUL.');
            }

            return $extension === 'css' ? 'text/css' : 'application/javascript';
        }
        $fonts = ['woff' => ['wOFF', 'font/woff'], 'woff2' => ['wOF2', 'font/woff2'], 'ttf' => ["\x00\x01\x00\x00", 'font/ttf'], 'otf' => ['OTTO', 'font/otf']];
        if (($reviewed || $extension === 'woff2') && isset($fonts[$extension])) {
            if (! str_starts_with($data, $fonts[$extension][0]) || strlen($data) < 12) {
                throw new \InvalidArgumentException('Header font không đúng loại file.');
            }

            return $fonts[$extension][1];
        }
        if ($reviewed && $extension === 'eot') {
            if (strlen($data) < 82 || substr($data, 34, 2) !== "\x4c\x50") {
                throw new \InvalidArgumentException('Header EOT không hợp lệ.');
            }

            return 'application/vnd.ms-fontobject';
        }
        if ($reviewed && $extension === 'svg') {
            $xml = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $valid = ! preg_match('/<!DOCTYPE|<!ENTITY|<\s*script\b|\bon[a-z]+\s*=|(?:href|src)\s*=\s*["\']\s*(?:https?:|javascript:|data:)/i', $data)
                && $xml->loadXML($data, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                && strtolower($xml->documentElement?->localName ?? '') === 'svg'
                && $this->staticSvgAsset($xml);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $valid) {
                throw new \InvalidArgumentException('Chỉ nhận SVG font hoặc biểu tượng tĩnh trong runtime được review.');
            }

            return 'image/svg+xml';
        }
        $expected = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        if ($reviewed && $extension === 'ico') {
            if (! str_starts_with($data, "\x00\x00\x01\x00")) {
                throw new \InvalidArgumentException('Header icon không hợp lệ.');
            }

            return 'image/x-icon';
        }
        if (! isset($expected[$extension]) || $mime !== $expected[$extension]) {
            throw new \InvalidArgumentException('MIME asset không khớp đuôi file: '.$name);
        }
        $dimensions = getimagesizefromstring($data);
        if (! $dimensions || $dimensions[0] > 8192 || $dimensions[1] > 8192 || $dimensions[0] * $dimensions[1] > 32000000) {
            throw new \InvalidArgumentException('Kích thước ảnh asset không hợp lệ hoặc vượt giới hạn.');
        }

        return $mime;
    }

    /** Static SVG icons and legacy fonts cannot load resources or embed active content. */
    private function staticSvgAsset(\DOMDocument $xml): bool
    {
        if ((new \DOMXPath($xml))->query('//processing-instruction()')->length > 0) {
            return false;
        }
        $tags = ['svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'title', 'desc', 'metadata', 'font', 'font-face', 'glyph', 'missing-glyph', 'hkern', 'vkern'];
        $attributes = ['xmlns', 'version', 'viewbox', 'width', 'height', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width',
            'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity',
            'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'x1', 'x2', 'y', 'y1', 'y2', 'points', 'transform', 'id', 'class', 'offset',
            'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'clip-rule', 'clip-path', 'preserveaspectratio', 'role', 'aria-hidden', 'focusable',
            'horiz-adv-x', 'horiz-origin-x', 'horiz-origin-y', 'vert-adv-y', 'vert-origin-x', 'vert-origin-y', 'font-family', 'font-weight', 'font-style',
            'font-stretch', 'units-per-em', 'ascent', 'descent', 'x-height', 'cap-height', 'bbox', 'underline-thickness', 'underline-position', 'unicode-range', 'panose-1', 'glyph-name', 'unicode', 'u1', 'u2', 'g1', 'g2', 'k'];
        $nodes = $xml->getElementsByTagName('*');
        if ($nodes->length > 2000) {
            return false;
        }
        foreach ($nodes as $node) {
            if (! in_array(strtolower($node->localName), $tags, true)) {
                return false;
            }
            foreach ($node->attributes as $attribute) {
                if (! in_array(strtolower($attribute->nodeName), $attributes, true)
                    || ($attribute->nodeName !== 'xmlns' && preg_match('~(?:javascript:|data:|https?:|[<>\\\\])~i', $attribute->value))
                    || preg_match('~url\s*\((?!\s*#[a-zA-Z0-9_-]+\s*\))~i', $attribute->value)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function cloneTheme(int $id, string $name, string $slug, int $actorId): object
    {
        $this->writer($actorId);
        $theme = $this->theme($id);
        $version = $this->version($id, null);

        $data = ['name' => $name, 'slug' => $slug, 'description' => $theme->description, 'views' => Json::decode($version->views_json), 'css' => $version->css, 'assets' => Json::decode($version->assets_json)];
        $runtime = Json::decode($version->runtime_json ?? null);
        if ($runtime !== []) {
            $data['runtime'] = $runtime;
        }

        return $this->save($data, $actorId);
    }

    public function setStatus(int $id, string $status, int $rowVersion, int $actorId): object
    {
        $this->writer($actorId);
        if (! in_array($status, ['active', 'archived'], true)) {
            throw new \InvalidArgumentException('Trạng thái giao diện không hợp lệ.');
        }

        return DB::transaction(function () use ($id, $status, $rowVersion, $actorId) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $theme = DB::table('movie_themes')->where('id', $id)->lockForUpdate()->first();
            CatalogAvailability::assertWritable($theme, 'THEME_DELETED');
            if (! $theme) {
                abort(404);
            }
            if ($theme->row_version !== $rowVersion) {
                throw new Conflict('THEME_VERSION_CONFLICT');
            }
            DB::table('movie_themes')->where('id', $id)->update(['status' => $status, 'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            Access::audit('theme.status.changed', $actorId, null, ['theme_id' => $id, 'status' => $status]);

            return DB::table('movie_themes')->find($id);
        }, 3);
    }

    public function theme(int $id): object
    {
        return CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->find($id) ?? abort(404);
    }

    public function version(int $id, ?int $versionId): object
    {
        return $this->versionForTheme($this->theme($id), $versionId);
    }

    private function versionForTheme(object $theme, ?int $versionId): object
    {
        return DB::table('movie_theme_versions')->where('theme_id', $theme->id)->where('id', $versionId ?? $theme->current_version_id)->first() ?? abort(404);
    }

    public function resolve(object $page): ?array
    {
        $site = DB::table('sites')->find($page->site_id);
        $id = $page->theme_id ?? $site->theme_id ?? null;
        if (! $id) {
            return null;
        }

        return $this->freeze((int) $id, null);
    }

    public function reference(object $page, ?object $site = null): ?array
    {
        $id = $page->theme_id ?? ($site === null ? DB::table('sites')->where('id', $page->site_id)->value('theme_id') : ($site->theme_id ?? null));
        if (! $id) {
            return null;
        }
        $version = CatalogAvailability::filter(DB::table('movie_themes as t')->join('movie_theme_versions as v', 'v.id', '=', 't.current_version_id')->where('t.id', $id), 'movie_themes', 't')->select('t.id', 'v.id as version_id', 'v.hash')->first();
        if (! $version) {
            throw new Conflict('THEME_VERSION_MISSING');
        }

        return (array) $version;
    }

    public function snapshot(array $reference): array
    {
        return $this->freeze((int) $reference['id'], (int) $reference['version_id']);
    }

    private function freeze(int $id, ?int $versionId): array
    {
        $theme = $this->theme($id);
        $version = $this->versionForTheme($theme, $versionId);

        $views = Json::decode($version->views_json);

        $snapshot = ['id' => $id, 'version_id' => $version->id, 'version_no' => $version->version_no, 'schema_version' => MovieThemePackage::schema($views), 'name' => $theme->name, 'slug' => $theme->slug, 'hash' => $version->hash, 'views' => $views, 'css' => $version->css, 'assets' => Json::decode($version->assets_json)];
        $runtime = Json::decode($version->runtime_json ?? null);
        if ($runtime !== []) {
            $snapshot['runtime'] = $runtime;
            $snapshot['assets'] = ReviewedThemeRuntime::freezeAssets($snapshot['assets'], $version->hash);
        }

        return $snapshot;
    }

    public function liveForSite(int $siteId): ?array
    {
        return $this->liveSnapshotForSite($siteId)['theme'] ?? null;
    }

    public function liveSnapshotForSite(int $siteId): ?array
    {
        $pages = DB::table('pages')->where('site_id', $siteId)->where('status', 'live')->whereNotNull('current_publication_id')->orderByRaw("CASE WHEN page_type='home' THEN 0 ELSE 1 END")->orderBy('id')->lazy(20);
        foreach ($pages as $page) {
            $snapshot = app(SnapshotStorage::class)->decode(DB::table('page_publications')->where('id', $page->current_publication_id)->value('snapshot_json'));
            // An existing legacy homepage explicitly keeps the legacy live appearance.
            if ($page->page_type === 'home') {
                return $snapshot;
            }
            if (isset($snapshot['theme']) || isset($snapshot['routing'])) {
                return $snapshot;
            }
        }

        return null;
    }

    public function preview(int $id, ?int $versionId, int $siteId, ?int $pageId, string $view, int $actorId): string
    {
        Access::site($actorId, $siteId);
        if (! in_array($view, [...MovieThemePackage::VIEWS, 'category'], true)) {
            throw new \InvalidArgumentException('Loại trang xem trước không hỗ trợ.');
        }
        $theme = $this->freeze($id, $versionId);
        $site = DB::table('sites')->find($siteId);
        $page = $pageId ? DB::table('pages')->where(['id' => $pageId, 'site_id' => $siteId])->first() : null;
        if ($pageId && ! $page) {
            abort(404);
        }
        if (in_array($view, ['detail', 'play'], true) && (! $page || ! $page->content_item_id)) {
            $page = DB::table('pages')->where('site_id', $siteId)->whereNotNull('working_content_revision_id')->orderBy('id')->first();
        }
        if (in_array($view, ['detail', 'play'], true) && ! $page) {
            throw new \InvalidArgumentException('Website cần ít nhất một phim để xem trước trang chi tiết.');
        }
        $entries = [];
        foreach (DB::table('pages')->where('site_id', $siteId)->whereNotNull('working_content_revision_id')->whereNotIn('status', ['archived', 'gone'])->orderBy('id')->limit(60)->get() as $item) {
            $entries[] = ['page_id' => $item->id, 'path' => $item->canonical_path, 'content' => Json::decode(DB::table('content_revisions')->where('id', $item->working_content_revision_id)->value('payload_json'))];
        }
        $snapshot = ['site' => (array) $site, 'page' => ['type' => $view, 'path' => $view === 'detail' ? $page->canonical_path : '/', 'noindex' => true], 'canonical_url' => rtrim($site->base_url ?? '', '/').($view === 'detail' ? $page->canonical_path : '/'), 'content' => [], 'aggregate' => ['entries' => $entries], 'assignments' => [], 'slots' => ['title' => $site->name, 'description' => '电影与剧集资料索引。', 'h1' => $site->name], 'managed_body' => '', 'related_links' => [], 'theme' => $theme];
        $snapshot['routing'] = SitePresentationService::profile($site);
        if (in_array($view, ['detail', 'play'], true)) {
            $content = Json::decode(DB::table('content_revisions')->where('id', $page->working_content_revision_id)->value('payload_json'));
            $resolved = app(RuleResolver::class)->resolve($page);
            $existing = $page->current_publication_id ? Json::decode(DB::table('page_publications')->where('id', $page->current_publication_id)->value('snapshot_json'))['assignments'] ?? [] : [];
            $allocator = new Allocator;
            $allocator->prepare($siteId);
            $simulation = [];
            $sequential = [];
            $assignments = $existing ?: $allocator->allocate($page, $content, $resolved, 'theme-preview', 'publish', [], $simulation, $sequential, readOnly: true);
            $snapshot = app(Renderer::class)->snapshot($page, $content, $assignments, $resolved, readOnlyVariables: true);
            $snapshot['theme'] = $theme;
            $snapshot['page']['noindex'] = true;
        }

        $play = $view === 'play' ? ['episode' => $snapshot['content']['episodes'][0] ?? [], 'detail_url' => $snapshot['page']['path'], 'can_play' => false, 'message' => '预览不打开或请求外部播放来源。'] : [];
        if (MovieThemeCatalog::supports($theme)) {
            $snapshot['theme_catalog'] = app(MovieThemeCatalog::class)->freeze($siteId, $snapshot['aggregate']['entries'] ?? [], $snapshot['routing']);
            if ($page?->category_id) {
                $snapshot['category'] = MovieThemeCatalog::category($snapshot['theme_catalog'], (int) $page->category_id) ?? [];
            }
        }

        $snapshot['friend_links'] = app(SystemFriendLinks::class)->links($siteId);
        $html = app(MovieThemeRenderer::class)->html($snapshot, $view, ['query' => '', 'total' => count($entries), 'next_url' => '', 'previous_url' => ''], $play);

        return app(SystemFriendLinks::class)->decorate($html, $site);
    }

    public function importZip(string $path, int $actorId, ?int $themeId = null, ?int $rowVersion = null): object
    {
        $this->writer($actorId);
        if (! is_file($path) || filesize($path) > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('ZIP không tồn tại hoặc vượt 20 MB.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Không đọc được ZIP giao diện.');
        }
        try {
            if ($zip->numFiles > 300) {
                throw new \InvalidArgumentException('ZIP tối đa 300 files; bộ thường vẫn giới hạn 100.');
            }
            $files = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f]~', $name)) {
                    throw new \InvalidArgumentException('ZIP chứa đường dẫn không an toàn.');
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if (($attr >> 16 & 0170000) === 0120000) {
                    throw new \InvalidArgumentException('ZIP không hỗ trợ symlink.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if ($stat['size'] > 2 * 1024 * 1024 || (($total += $stat['size']) > 20 * 1024 * 1024)) {
                    throw new \InvalidArgumentException('ZIP giải nén vượt ngân sách.');
                }
                if (isset($files[$name])) {
                    throw new \InvalidArgumentException('ZIP chứa file trùng.');
                }
                $content = $zip->getFromIndex($i, 2 * 1024 * 1024 + 1);
                if ($content === false || strlen($content) !== $stat['size']) {
                    throw new \InvalidArgumentException('ZIP file sai kích thước.');
                }
                $files[$name] = $content;
            }
        } finally {
            $zip->close();
        }
        $data = app(MovieThemePackage::class)->files($files);
        if ($themeId && $data['slug'] !== $this->theme($themeId)->slug) {
            throw new Conflict('THEME_SLUG_MISMATCH');
        }
        if ($themeId) {
            $data += ['id' => $themeId, 'row_version' => $rowVersion];
        }

        return $this->save($data, $actorId);
    }

    public function exportZip(int $id, ?int $versionId, int $actorId): string
    {
        abort_unless(DB::table('users')->where('id', $actorId)->exists(), 403);
        $theme = $this->theme($id);
        $version = $this->version($id, $versionId);
        $folder = storage_path('app/private/theme-exports');
        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }
        $path = $folder.'/'.Str::uuid().'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Không tạo được ZIP export.');
        }
        $views = Json::decode($version->views_json);
        $schema = MovieThemePackage::schema($views);
        $prefix = $schema === 2 ? $theme->slug.'/' : '';
        $manifest = ['schema_version' => $schema, 'name' => $theme->name, 'slug' => $theme->slug, 'description' => $theme->description, 'author' => 'Trạm SEO'];
        $runtime = Json::decode($version->runtime_json ?? null);
        if ($runtime !== []) {
            $manifest['runtime'] = $runtime;
        }
        foreach ($views as $view => $html) {
            $zip->addFromString($prefix.($schema === 2 ? 'html/' : '').$view.'.html', $html);
        }
        $zip->addFromString($prefix.($schema === 2 ? 'css/style.css' : 'theme.css'), $version->css);
        foreach (Json::decode($version->assets_json) as $name => $asset) {
            $assetPath = $runtime !== [] ? $name : ($schema === 1 ? 'assets/'.$name : (preg_match('~^(images|pics|fonts)/~', $name) ? $name : (str_ends_with($name, '.woff2') ? 'fonts/' : 'images/').$asset['hash'].'.'.pathinfo($name, PATHINFO_EXTENSION)));
            if ($schema === 2) {
                $manifest['assets'][$name] = $assetPath;
            }
            $bytes = base64_decode(DB::table('theme_assets')->where('hash', $asset['hash'])->value('data_base64'), true);
            if ($bytes === false || hash('sha256', $bytes) !== $asset['hash']) {
                throw new \RuntimeException('Không export được asset bị thiếu hoặc sai hash.');
            }
            $zip->addFromString($prefix.$assetPath, $bytes);
        }
        $zip->addFromString($prefix.'manifest.json', Json::encode($manifest));
        if ($schema === 2) {
            $zip->addFromString($prefix.'info.ini', 'schema_version=2'."\n".'slug="'.$theme->slug.'"'."\n".'name="'.addcslashes($theme->name, '\\"').'"'."\n");
        }
        if (! $zip->close()) {
            throw new \RuntimeException('Không hoàn thành ZIP export.');
        }
        chmod($path, 0600);

        return $path;
    }

    public function seed(int $actorId): array
    {
        $this->writer($actorId);
        $counts = ['installed' => 0, 'skipped' => 0];
        $canonical = glob(base_path('templates/*/manifest.json'));
        foreach ($canonical ?: glob(resource_path('movie-themes/*/manifest.json')) as $manifestPath) {
            $manifest = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            if (DB::table('movie_themes')->where('slug', $manifest['slug'])->exists()) {
                $counts['skipped']++;

                continue;
            }
            if ($canonical) {
                $this->importFolder($manifest['slug'], $actorId);
                $counts['installed']++;

                continue;
            }
            $folder = dirname($manifestPath);
            $views = [];
            foreach (['home', 'category', 'detail', 'search'] as $view) {
                $views[$view] = file_get_contents($folder.'/'.$view.'.html');
            }
            $this->save($manifest + ['views' => $views, 'css' => file_get_contents($folder.'/theme.css')], $actorId);
            $counts['installed']++;
        }

        return $counts;
    }

    public function folderInventory(int $actorId): array
    {
        abort_unless(DB::table('users')->where('id', $actorId)->exists(), 403);
        $inventory = [];
        foreach (glob(base_path('templates/*'), GLOB_ONLYDIR) as $folder) {
            $slug = basename($folder);
            $row = ['slug' => $slug, 'name' => $slug, 'schema_version' => null, 'path' => $folder, 'files' => [], 'views' => [], 'asset_count' => 0,
                'installed_theme_id' => DB::table('movie_themes')->where('slug', $slug)->value('id'), 'status' => 'invalid', 'error' => null];
            try {
                $package = app(MovieThemePackage::class)->folder($slug);
                [$assets] = $this->assets($package['assets'], ! empty($package['runtime']));
                $runtime = ReviewedThemeRuntime::validate($package['runtime'] ?? [], $assets);
                app(MovieThemeCompiler::class)->validate($package['views'], $package['css'], $assets, $runtime);
                $row = array_replace($row, ['name' => $package['name'], 'schema_version' => $package['schema_version'], 'files' => $package['files'], 'views' => array_keys($package['views']), 'asset_count' => count($assets), 'status' => 'valid']);
            } catch (\InvalidArgumentException|\JsonException $e) {
                $row['error'] = $e->getMessage();
            }
            $inventory[] = $row;
        }

        return $inventory;
    }

    public function importFolder(string $slug, int $actorId, ?int $themeId = null, ?int $rowVersion = null): object
    {
        $this->writer($actorId);
        $data = app(MovieThemePackage::class)->folder($slug);
        if ($themeId) {
            if ($this->theme($themeId)->slug !== $slug) {
                throw new Conflict('THEME_SLUG_MISMATCH');
            }
            $data += ['id' => $themeId, 'row_version' => $rowVersion];
        }

        return $this->save($data, $actorId);
    }

    public function upgradeViews(int $themeId, int $rowVersion, int $actorId): object
    {
        $this->writer($actorId);
        $theme = $this->theme($themeId);
        if ($theme->row_version !== $rowVersion) {
            throw new Conflict('THEME_VERSION_CONFLICT');
        }
        $version = $this->version($themeId, null);
        $views = Json::decode($version->views_json);
        if (MovieThemePackage::schema($views) === 2) {
            return $theme;
        }
        $views = ['home' => $views['home'], 'list' => $views['category'], 'detail' => $views['detail'], 'play' => MovieThemeRenderer::PLAY_FALLBACK, 'search' => $views['search']];

        return $this->save(['id' => $themeId, 'row_version' => $rowVersion, 'name' => $theme->name, 'slug' => $theme->slug, 'description' => $theme->description,
            'views' => $views, 'css' => $version->css, 'assets' => Json::decode($version->assets_json)], $actorId);
    }
}
