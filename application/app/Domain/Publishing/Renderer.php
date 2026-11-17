<?php

namespace App\Domain\Publishing;

use App\Domain\Fanmulu\FanmuluNavigation;
use App\Domain\Fanmulu\FanmuluPresentation;
use App\Domain\Fanmulu\FanmuluRuntime;
use App\Domain\Keywords\AssignmentPurpose;
use App\Domain\Rules\Html;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Rules\TemplateCompiler;
use App\Domain\Rules\TemplateVariableService;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeCatalog;
use App\Domain\Themes\MovieThemeRenderer;
use App\Domain\Themes\MovieThemeService;
use App\Domain\Themes\ReviewedThemeRuntime;
use Illuminate\Support\Facades\DB;

final class Renderer
{
    public function snapshot(object $page, array $content, array $assignments, array $resolved, bool $readOnlyVariables = false, bool $deferFanmuluRecommendations = false): array
    {
        $site = DB::table('sites')->find($page->site_id);
        $base = $site->base_url;
        if (! $base) {
            $host = DB::table('site_domains')->where(['site_id' => $site->id, 'is_primary' => true, 'status' => 'active'])->value('host_ascii');
            if (! $host) {
                throw new \InvalidArgumentException('Site thiếu domain chính');
            }$base = 'https://'.$host;
        }
        $routing = SitePresentationService::profile($site);
        $categoryKeys = SitePresentationService::categoryRouteKeys((int) $site->id, $routing);
        $path = SitePresentationService::pathForPage($page, $routing, $content, $categoryKeys);
        $category = $page->category_id ? DB::table('categories')->where(['id' => $page->category_id, 'site_id' => $site->id])->first() : null;
        $categorySnapshot = $category ? ['id' => $category->id, 'name' => $category->name, 'path' => SitePresentationService::categoryPath($category, $routing, $categoryKeys)] : [];
        SitePresentationService::assertAvailable($page, $path);
        SitePresentationService::virtualChanges($page, ['routing' => $routing]);
        $canonical = rtrim($base, '/').$path;
        $fanmulu = FanmuluPresentation::freeze($page, $content, $assignments);
        $customVariables = $this->planVariables($page, $resolved, readOnly: $readOnlyVariables);
        $presentation = ['content' => $content, 'fanmulu' => $fanmulu, 'assignments' => $assignments,
            'site' => ['name' => $site->name], 'category' => $categorySnapshot, 'custom_variables' => $customVariables];
        $displayContent = FanmuluPresentation::content($presentation);
        $outputs = $this->seoSlots($presentation, $resolved);
        $body = Html::sanitize($displayContent['body_html'] ?? '');
        $zone = is_string($resolved['managed_body_zone'] ?? null) ? $resolved['managed_body_zone'] : 'main-content';
        $body = '<div data-seo-zone="'.htmlspecialchars($zone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$body.'</div>';
        $policy = $resolved['slots']['managed_body'] ?? false;
        if ($policy !== false && ($policy['enabled'] ?? true) !== false) {
            $body = Html::anchors($body, array_values(array_filter($assignments, fn ($a) => $a['role'] === 'anchor')), $canonical, (int) ($policy['max_anchors'] ?? 3), (int) ($policy['per_keyword'] ?? 1));
        }
        $related = [];
        $relatedPolicy = $resolved['slots']['related_links'] ?? false;
        if ($relatedPolicy !== false && ($relatedPolicy['enabled'] ?? true) !== false) {
            foreach ($assignments as $a) {
                if ($a['role'] === 'anchor' && Html::safeUrl($a['target_url'] ?? '', true) && $a['target_url'] !== $canonical) {
                    $related[] = ['text' => $a['text'], 'url' => $a['target_url']];
                }
            }
        }

        $snapshot = ['schema_version' => 1, 'site' => ['id' => $site->id, 'name' => $site->name, 'locale' => $site->locale, 'base_url' => $base], 'routing' => $routing, 'category' => $categorySnapshot, 'page' => ['id' => $page->id, 'path' => $path, 'route_key' => SitePresentationService::routeKey($page, $content, $routing, $categoryKeys), 'category_id' => $page->category_id, 'type' => $page->page_type, 'noindex' => (bool) $page->noindex], 'canonical_url' => $canonical, 'content' => $content, 'content_revision_id' => $page->working_content_revision_id, 'aggregate' => Json::decode($page->input_snapshot_json), 'assignments' => $assignments, 'resolved' => $resolved, 'slots' => $outputs, 'managed_body' => $body, 'related_links' => $related, 'theme' => empty($resolved['theme']) ? null : app(MovieThemeService::class)->snapshot($resolved['theme'])];
        if ($customVariables !== []) {
            $snapshot['custom_variables'] = $customVariables;
        }
        if ($fanmulu !== null) {
            $snapshot['fanmulu'] = $fanmulu;
            $snapshot['page']['kind'] = 'fanmulu';
        }
        if (MovieThemeCatalog::supports($snapshot['theme'])) {
            $reviewed = ReviewedThemeRuntime::enabled($snapshot['theme']);
            if (in_array($page->page_type, ['home', 'category'], true)) {
                $snapshot['aggregate']['entries'] = app(MovieThemeCatalog::class)->publishedEntries($site->id, MovieThemeCatalog::references($snapshot['aggregate']));
            } elseif ($reviewed && $page->page_type === 'movie'
                && ! ($deferFanmuluRecommendations && app(SiteDeliverySettings::class)->enabled((int) $site->id))) {
                $snapshot['aggregate']['entries'] = app(MovieThemeCatalog::class)->runtimeEntries($site->id, $page->id);
            }
            $snapshot['theme_catalog'] = app(MovieThemeCatalog::class)->freeze($site->id, $snapshot['aggregate']['entries'] ?? [], $routing, $reviewed ? 10000 : 60, $categoryKeys);
            if ($categorySnapshot) {
                $snapshot['category'] = array_replace($categorySnapshot, MovieThemeCatalog::category($snapshot['theme_catalog'], $categorySnapshot['id']) ?? []);
            }
        } elseif ($categoryKeys !== [] || array_key_exists('suffixes', $routing)) {
            $snapshot['theme_catalog'] = app(MovieThemeCatalog::class)->freeze($site->id, $snapshot['aggregate']['entries'] ?? [], $routing, categoryKeys: $categoryKeys);
            if ($categorySnapshot) {
                $snapshot['category'] = array_replace($categorySnapshot, MovieThemeCatalog::category($snapshot['theme_catalog'], $categorySnapshot['id']) ?? []);
            }
        }
        if (FanmuluPresentation::version2($snapshot)) {
            $snapshot = app(FanmuluNavigation::class)->freeze($snapshot);
            $snapshot['aggregate']['entries'] = app(FanmuluNavigation::class)->prepareEntries($snapshot, $snapshot['aggregate']['entries'] ?? []);
            $snapshot['fanmulu']['navigation']['context_version'] = 3;
        }
        $this->validateBundle($snapshot, $resolved);

        if (array_filter($snapshot['aggregate']['entries'] ?? [], fn (array $entry): bool => isset($entry['_card']))) {
            $snapshot['_snapshot_cards'] = true;
        }

        return $snapshot;
    }

    /** Recompile SEO from the publication content and pins, leaving its body and navigation intact. */
    public function refreshSeo(array $snapshot, array $resolved, ?object $page = null): array
    {
        $frozen = $snapshot['resolved'] ?? [];
        $resolved['theme'] = $frozen['theme'] ?? null;
        $resolved['managed_body_zone'] = $frozen['managed_body_zone'] ?? 'main-content';
        foreach (array_unique(array_merge(array_keys($resolved['slots']), array_keys($frozen['slots'] ?? []))) as $slot) {
            if (str_starts_with($slot, 'allocation.') || in_array($slot, ['managed_body', 'related_links'])) {
                if (array_key_exists($slot, $frozen['slots'] ?? [])) {
                    $resolved['slots'][$slot] = $frozen['slots'][$slot];
                } else {
                    unset($resolved['slots'][$slot]);
                }
                if (isset($frozen['sources'][$slot])) {
                    $resolved['sources'][$slot] = $frozen['sources'][$slot];
                } else {
                    unset($resolved['sources'][$slot]);
                }
            }
        }
        if (method_exists(TemplateVariableService::class, 'referenced') && app(TemplateVariableService::class)->referenced($resolved) !== []) {
            $page ??= DB::table('pages')->find($snapshot['page']['id']);
            if (! $page || (int) $page->site_id !== (int) $snapshot['site']['id']) {
                throw new \InvalidArgumentException('Không tìm thấy trang để chốt biến template.');
            }
            $snapshot['custom_variables'] = $this->planVariables($page, $resolved, $snapshot['custom_variables'] ?? []);
        }
        $snapshot['slots'] = $this->seoSlots($snapshot, $resolved);
        $snapshot['resolved'] = $resolved;
        if (isset($resolved['seo_template_bundle'])) {
            $snapshot['seo_template_bundle'] = $resolved['seo_template_bundle'];
        } else {
            unset($snapshot['seo_template_bundle']);
        }
        $this->validateBundle($snapshot, $resolved);

        return $snapshot;
    }

    private function seoSlots(array $snapshot, array $resolved): array
    {
        $content = $snapshot['content'];
        $assignments = AssignmentPurpose::seo($snapshot['assignments']);
        $primary = collect($assignments)->firstWhere('role', 'primary');
        $secondary = collect($assignments)->where('role', 'secondary')->pluck('text')->implode('、');
        if (isset($snapshot['fanmulu']) && ($snapshot['fanmulu']['presentation_version'] ?? 2) < 3) {
            $secondary = $snapshot['fanmulu']['display_title'];
        }
        $displayContent = FanmuluPresentation::content($snapshot);
        $values = ['content.title' => $displayContent['title'] ?? null, 'content.genre' => $content['genre'] ?? null,
            'content.author' => $content['author'] ?? null, 'kw.primary' => $primary['text'] ?? null, 'kw.secondary' => $secondary,
            'site.name' => $snapshot['site']['name'], 'category.name' => $snapshot['category']['name'] ?? '影片目录']
            + SeoTemplateBundle::movieMetadataValues($content)
            + (empty($snapshot['custom_variables']) ? [] : app(TemplateVariableService::class)->values($snapshot['custom_variables']));
        $outputs = [];
        foreach ($resolved['slots'] as $slot => $config) {
            if (str_starts_with($slot, 'allocation.') || in_array($slot, ['managed_body', 'related_links'])) {
                continue;
            }
            if ($config === false || ($config['enabled'] ?? true) === false) {
                continue;
            }
            $expression = is_string($config) ? $config : ($config['template'] ?? '');
            $output = app(TemplateCompiler::class)->compile($expression, $values, is_array($config) ? ($config['fallbacks'] ?? []) : [], $slot === 'intro');
            $outputs[$slot] = $slot === 'intro' ? Html::sanitize($output) : $output;
        }

        return FanmuluPresentation::slots($snapshot, $outputs);
    }

    /** Select only while preparing an explicit publication, never while serving HTML. */
    private function planVariables(object $page, array $resolved, ?array $frozen = null, bool $readOnly = false): array
    {
        if (! method_exists(TemplateVariableService::class, 'planForPage')) {
            return [];
        }
        $service = app(TemplateVariableService::class);
        if ($service->referenced($resolved) === []) {
            return [];
        }
        if ($frozen === null && $page->current_publication_id) {
            $publication = DB::table('page_publications')->where(['id' => $page->current_publication_id, 'page_id' => $page->id, 'site_id' => $page->site_id])
                ->selectRaw("snapshot_json->'custom_variables' as custom_variables")->first();
            $frozen = Json::decode($publication?->custom_variables);
        }

        return $readOnly ? $service->previewForPage($page, $resolved, $frozen ?? []) : $service->planForPage($page, $resolved, $frozen ?? []);
    }

    private function validateBundle(array &$snapshot, array $resolved): void
    {
        if (isset($resolved['seo_template_bundle'])) {
            $snapshot['seo_template_bundle'] = $resolved['seo_template_bundle'];
            if ($snapshot['page']['type'] === 'home') {
                SeoTemplateBundle::dynamic($snapshot, 'search', ['q' => '']);
                SeoTemplateBundle::dynamic($snapshot, 'list', ['category' => $snapshot['category'] ?? []]);
            }
            if ($snapshot['page']['type'] === 'movie') {
                SeoTemplateBundle::dynamic($snapshot, 'play', ['episode' => ['title' => '预览集数', 'index' => 1]]);
            }
        }
    }

    public function html(array $snapshot): string
    {
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        if (! empty($snapshot['theme'])) {
            return app(MovieThemeRenderer::class)->html($snapshot);
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $v2 = FanmuluPresentation::version2($snapshot);
        $s = $v2 ? FanmuluPresentation::slots($snapshot, $snapshot['slots']) : $snapshot['slots'];
        $c = FanmuluPresentation::content($snapshot);
        $site = $snapshot['site'];
        $title = $e($s['title'] ?? $c['title'] ?? $site['name']);
        $canonical = $e($snapshot['canonical_url']);
        $html = '<!doctype html><html lang="'.$e($site['locale']).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><meta name="description" content="'.$e($s['description'] ?? '').'"><link rel="canonical" href="'.$canonical.'">';
        if (! empty($snapshot['page']['noindex'])) {
            $html .= '<meta name="robots" content="noindex,follow">';
        }
        if (isset($s['meta_keywords'])) {
            $html .= '<meta name="keywords" content="'.$e($s['meta_keywords']).'">';
        }
        $urls = FanmuluPresentation::urls($snapshot, SitePresentationService::urls($snapshot));
        $html .= '<style>'.self::css().'</style></head><body><header><a class="brand" href="'.$e($urls['home']).'">◈ '.$e($site['name']).'</a><nav><a href="'.$e($urls['home']).'">首页</a><a href="'.$e($urls['search']).'">影片索引</a></nav><form action="'.$e($urls['search']).'"><input name="q" aria-label="搜索影片" placeholder="搜索影片、导演…"><button>搜索</button></form></header><main>';
        if ($c && ! in_array($snapshot['page']['type'] ?? '', $v2 ? ['home', 'category', 'list', 'search'] : ['home', 'category'], true)) {
            $poster = $c['poster_url'] ?? $c['metadata']['poster_url'] ?? null;
            $html .= '<section class="hero"><div class="poster">'.($poster && Html::safeUrl($poster) ? '<img src="'.$e($poster).'" alt="'.$e($c['title']).'" loading="lazy">' : '<span>◈</span>').'</div><div class="info"><div class="eyebrow">影片资料 / '.$e($c['genre'] ?? '').'</div><h1>'.$e($s['h1'] ?? $c['title']).'</h1><div class="badges">';
            foreach (['year', 'area', 'language', 'remarks'] as $key) {
                if (! empty($c[$key])) {
                    $html .= '<span>'.$e($c[$key]).'</span>';
                }
            }
            $html .= '</div><dl>';
            foreach (['director' => '导演', 'actors' => '主演', 'author' => '作者', 'genre' => '类型'] as $key => $label) {
                if (! empty($c[$key])) {
                    $html .= '<dt>'.$label.'</dt><dd>'.$e(is_array($c[$key]) ? implode(' / ', $c[$key]) : $c[$key]).'</dd>';
                }
            }
            $html .= '</dl><p class="intro">'.($s['intro'] ?? '').'</p></div></section><section class="panel"><h2>剧情简介</h2>'.($v2 ? $c['body_html'] : $snapshot['managed_body']).'</section>';
            if (! empty($c['episodes'])) {
                $html .= '<section class="panel"><h2>分集资料</h2><div class="episodes">';
                foreach ($c['episodes'] as $index => $episode) {
                    $label = $e($episode['title'] ?? $episode['name'] ?? '');
                    $html .= $v2 || ! empty($snapshot['routing']['configured'])
                        ? '<a href="'.$e($v2 ? FanmuluNavigation::scopePath($snapshot, 'play', (string) ($index + 1))
                            : SitePresentationService::playPath($snapshot['routing'], $snapshot['page']['route_key'], $index + 1)).'">'.$label.'</a>'
                        : '<span>'.$label.'</span>';
                } $html .= '</div></section>';
            }
        } else {
            $html .= '<section class="catalog-head"><div class="eyebrow">电影 · 剧集 · 资料库</div><h1>'.$e($s['h1'] ?? $site['name']).'</h1><p>'.$e($s['description'] ?? '发现影片，了解故事与创作者。').'</p>';
            if (isset($snapshot['seo_template_bundle'], $s['intro'])) {
                $html .= '<div class="intro">'.$s['intro'].'</div>';
            }
            $html .= '</section><div class="grid">';
            $entries = $v2 ? app(MovieThemeRenderer::class)->context($snapshot, $snapshot['search'] ?? [])['movies']
                : ($snapshot['aggregate']['entries'] ?? []);
            foreach ($entries as $entry) {
                $item = $v2 ? $entry : FanmuluPresentation::content(isset($entry['content']) ? $entry : ['content' => $entry]);
                $path = $entry['path'] ?? '/';
                $poster = $item['poster_url'] ?? null;
                $html .= '<a class="card" href="'.$e($path).'">'.($poster && Html::safeUrl($poster) ? '<img loading="lazy" src="'.$e($poster).'" alt="'.$e($item['title'] ?? '').'">' : '<div class="placeholder">◈</div>').'<h2>'.$e($item['title'] ?? '').'</h2><p>'.$e(($item['year'] ?? '').' · '.($item['genre'] ?? '')).'</p></a>';
            }
            $html .= '</div>';
            if ($v2) {
                $search = $snapshot['search'] ?? [];
                $html .= '<nav class="pagination" aria-label="分页">';
                foreach (['previous_url' => '上一页', 'next_url' => '下一页'] as $key => $label) {
                    if (! empty($search[$key])) {
                        $html .= '<a href="'.$e(FanmuluPresentation::navigationUrl($search[$key], $snapshot)).'">'.$label.'</a>';
                    }
                }
                $html .= '</nav>';
            }
        }
        foreach ($s as $key => $value) {
            if (str_starts_with($key, 'h2.')) {
                $html .= '<h2 id="'.$e(substr($key, 3)).'">'.$e($value).'</h2>';
            }
        }
        $related = $v2 ? FanmuluPresentation::relatedLinks($snapshot) : $snapshot['related_links'];
        if ($related) {
            $html .= '<section class="panel"><h2>相关资料</h2>';
            foreach ($related as $link) {
                $html .= '<a class="tag" href="'.$e($link['url']).'">'.$e($link['text']).'</a>';
            } $html .= '</section>';
        }
        $html .= '</main><footer>'.$e($site['name']).' · 影片与创作者资料索引</footer></body></html>';

        if (! empty($snapshot['routing']['configured'])) {
            $urls = FanmuluPresentation::urls($snapshot, SitePresentationService::urls($snapshot));
            $html = str_replace(['href="/"', 'href="/search"', 'action="/search"'], ['href="'.$e($urls['home']).'"', 'href="'.$e($urls['search']).'"', 'action="'.$e($urls['search']).'"'], $html);
        }

        $html = FanmuluPresentation::navigationHtml($html, $snapshot);

        return FanmuluPresentation::enabled($snapshot) ? app(FanmuluRuntime::class)->attach($html, $snapshot) : $html;
    }

    public static function css(): string
    {
        return 'body{margin:0;background:#0d1523;color:#dfe7f2;font:16px/1.75 system-ui,"PingFang SC",sans-serif}*{box-sizing:border-box}a{color:inherit;text-decoration:none}header{display:flex;gap:32px;align-items:center;max-width:1200px;margin:auto;padding:24px;border-bottom:1px solid #273248}.brand{font-size:22px;font-weight:800;color:#66dbc5}nav{display:flex;gap:24px}form{margin-left:auto;display:flex}input{background:#182337;color:#fff;border:1px solid #34445e;padding:10px 14px;border-radius:8px 0 0 8px}button{background:#66dbc5;border:0;padding:10px 18px;border-radius:0 8px 8px 0;font-weight:700}main{max-width:1152px;margin:40px auto;padding:0 24px}.hero{display:grid;grid-template-columns:230px 1fr;gap:40px}.poster{border-radius:16px;overflow:hidden;background:#263c52;min-height:330px;display:grid;place-items:center;font-size:80px;color:#66dbc5}.poster img{width:100%;height:100%;object-fit:cover}.eyebrow{color:#66dbc5;font-size:13px;letter-spacing:2px}h1{font-size:40px;line-height:1.3;margin:18px 0}h2{font-size:21px}.badges span,.tag{display:inline-block;background:#233247;border-radius:6px;padding:3px 12px;margin:0 8px 8px 0;color:#b0c1d9}dl{display:grid;grid-template-columns:55px 1fr;gap:8px}dt{color:#8296af}dd{margin:0}.intro{color:#9eb0c6}.panel{background:#141f30;border:1px solid #27364c;border-radius:14px;padding:24px 30px;margin:30px 0}.panel h2{margin-top:0}.panel a{color:#66dbc5}.episodes{display:flex;gap:10px;flex-wrap:wrap}.episodes span{border:1px solid #324760;padding:6px 14px;border-radius:6px}.catalog-head{padding:20px 0 35px}.catalog-head p{color:#91a4bc}.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:24px}.card img,.placeholder{width:100%;height:265px;object-fit:cover;border-radius:12px;background:linear-gradient(135deg,#27415b,#182432)}.placeholder{display:grid;place-items:center;font-size:50px;color:#66dbc5}.card h2{font-size:17px;margin:10px 0 0}.card p{font-size:13px;color:#8296af;margin:0}footer{max-width:1104px;margin:50px auto;padding:24px;color:#71859c;border-top:1px solid #273248}@media(max-width:700px){header{flex-wrap:wrap;gap:14px}header form{width:100%;margin:0}input{flex:1;min-width:0}.hero{grid-template-columns:110px 1fr;gap:20px}.poster{min-height:170px}h1{font-size:26px}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.card img,.placeholder{height:230px}.panel{padding:20px}main{margin-top:24px}nav{font-size:14px}}';
    }
}
