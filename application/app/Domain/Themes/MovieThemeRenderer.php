<?php

namespace App\Domain\Themes;

use App\Domain\Fanmulu\FanmuluNavigation;
use App\Domain\Fanmulu\FanmuluPresentation;
use App\Domain\Fanmulu\FanmuluRuntime;
use App\Domain\Keywords\AssignmentPurpose;
use App\Domain\Publishing\SnapshotStorage;
use App\Domain\Rules\Html;
use App\Domain\Sites\SitePresentationService;

final class MovieThemeRenderer
{
    public const PLAY_FALLBACK = '<main class="theme-play"><nav aria-label="导航"><a href="{{url.home}}">首页</a> · <a href="{{player.detail_url}}">影片详情</a></nav><h1>{{seo.h1}}</h1><h2>{{player.title}}</h2><section class="play-source">{{#if player.can_play}}<p>播放来源：外部网站。请确认来源及使用权限。</p><a href="{{player.source_url}}" rel="noopener noreferrer" target="_blank">打开来源链接</a>{{else}}<p>{{player.message}}</p>{{/if}}</section><nav aria-label="分集导航">{{#if player.previous_url}}<a href="{{player.previous_url}}">上一集</a>{{/if}} {{#if player.next_url}}<a href="{{player.next_url}}">下一集</a>{{/if}}</nav><section><h2>分集目录</h2><ul>{{#each episodes}}<li><a href="{{episode.play_url}}">{{episode.title}}</a></li>{{else}}<li>暂无分集资料。</li>{{/each}}</ul></section></main>';

    public static function homeSectionLimit(array $theme): int
    {
        return self::homeMarker($theme, 'section-layout', 'balanced') ? 16 : 12;
    }

    private static function homeMarker(array $theme, string $name, string $value): bool
    {
        return is_string($theme['views']['home'] ?? null)
            && (bool) preg_match('/\bdata-tram-'.preg_quote($name, '/').'\s*=\s*(["\'])'.preg_quote($value, '/').'\1/i', $theme['views']['home']);
    }

    public function context(array $snapshot, array $search = [], array $play = [], bool $allEntries = false): array
    {
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        $play = $play ?: ($snapshot['play'] ?? []);
        $v2 = FanmuluPresentation::version2($snapshot) && FanmuluNavigation::enabled($snapshot);
        $content = FanmuluPresentation::content($snapshot);
        $slots = $v2 ? FanmuluPresentation::slots($snapshot, $snapshot['slots'] ?? []) : ($snapshot['slots'] ?? []);
        $site = $snapshot['site'];
        $profile = SitePresentationService::profile($snapshot['routing'] ?? null);
        $routes = array_combine(SitePresentationService::TYPES, array_map(fn ($type) => SitePresentationService::endpointPath($profile, $type), SitePresentationService::TYPES));
        if ($v2) {
            $routes = FanmuluPresentation::urls($snapshot, $routes);
        }
        $pagePath = $snapshot['page']['path'] ?? '/';
        $routeKey = $snapshot['page']['route_key'] ?? SitePresentationService::keyFromPath($pagePath, $profile, 'detail');
        $snapshot['page']['route_key'] = $routeKey;
        $content['body_html'] = $content['body_html'] ?? '';
        $content['poster_url'] = $content['poster_url'] ?? $content['metadata']['poster_url'] ?? '';
        $content['director'] = $content['director'] ?? $content['directors'] ?? '';
        foreach (['actors', 'director'] as $key) {
            if (is_array($content[$key] ?? null)) {
                $content[$key] = implode(' / ', $content[$key]);
            }
        }
        $score = $content['score'] ?? $content['metadata']['score'] ?? $content['metadata']['vod_score'] ?? null;
        $content['score'] = is_numeric($score) && is_finite((float) $score) && (float) $score >= 0 && (float) $score <= 10 ? (string) (float) $score : '';
        $content['body_excerpt'] = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($content['body_html']))), 0, 180);
        $content['body_excerpt'] = FanmuluPresentation::ensurePrimaryText($content['body_excerpt'], $snapshot);
        $content['updated_at'] = is_scalar($content['source_updated_at'] ?? null) ? (string) $content['source_updated_at'] : '';
        foreach (['genre', 'area', 'year'] as $field) {
            $content[$field.'_url'] = $routes['list'].'?'.http_build_query([$field => $content[$field] ?? ''], '', '&', PHP_QUERY_RFC3986);
        }
        $catalog = $snapshot['theme_catalog'] ?? [];
        $entries = $snapshot['aggregate']['entries'] ?? [];
        if ($catalog && ! $allEntries) {
            $sampledHome = ($snapshot['page']['type'] ?? '') === 'home' && ($snapshot['aggregate']['home_sections_sampled'] ?? false) === true;
            $entries = array_slice($entries, 0, ReviewedThemeRuntime::enabled($snapshot['theme'] ?? []) || $sampledHome ? 10000 : 60);
        }
        $movies = $this->projectMovies($snapshot, $entries, $catalog, $profile);
        $home = ($snapshot['page']['type'] ?? '') === 'home';
        $sectionLimit = $home ? self::homeSectionLimit($snapshot['theme'] ?? []) : 12;
        $navigation = [];
        $sections = [];
        $categories = $catalog['categories'] ?? [];
        if ($v2) {
            foreach ($categories as &$category) {
                $category['path'] = FanmuluPresentation::navigationUrl($category['path'], $snapshot);
            }
            unset($category);
        }
        $children = fn (int $id): array => array_map(fn ($category) => ['name' => $category['name'], 'path' => $category['path']],
            array_values(array_filter($categories, fn ($category) => $category['parent_id'] === $id)));
        foreach ($categories as $category) {
            if ($category['id'] !== $category['root_id']) {
                continue;
            }
            $index = count($navigation) + 1;
            $navigation[] = ['name' => $category['name'], 'path' => $category['path'],
                'current' => in_array($category['id'], $snapshot['category']['ancestor_ids'] ?? [$snapshot['category']['id'] ?? null], true)];
            $sections[] = ['category_id' => $category['id'], 'name' => $category['name'], 'path' => $category['path'], 'is_first' => $index === 1,
                'icon_url' => $snapshot['theme']['assets']['default3/assets/icon/icon_'.$index.'.png']['path'] ?? $snapshot['theme']['assets']['images/icon_'.$index.'.png']['path'] ?? $snapshot['theme']['assets']['images/icon_'.$index.'.svg']['path'] ?? $snapshot['theme']['assets']['icon_'.$index.'.png']['path'] ?? '',
                'children' => $children($category['id']),
                'movies' => $this->numbered(array_slice(array_values(array_filter($movies, fn ($movie) => $movie['root_id'] === $category['id'])), 0, $sectionLimit))];
        }
        if (! $sections && ($catalog || $movies)) {
            $sections[] = ['name' => '电影', 'path' => $routes['list'], 'is_first' => true,
                'icon_url' => $snapshot['theme']['assets']['images/icon_1.png']['path'] ?? $snapshot['theme']['assets']['icon_1.png']['path'] ?? '', 'children' => [], 'movies' => $this->numbered(array_slice($movies, 0, $sectionLimit))];
        }
        $uncategorizedMovies = [];
        $rootIds = array_column($sections, 'category_id');
        if (($snapshot['page']['type'] ?? '') === 'home' && $rootIds !== []) {
            $uncategorizedMovies = array_values(array_filter($movies, fn ($movie) => ! in_array($movie['root_id'] ?? null, $rootIds, true)));
            if ($uncategorizedMovies !== []) {
                $sections[] = ['category_id' => null, 'name' => '未分类', 'path' => $routes['list'], 'is_first' => false,
                    'icon_url' => '', 'children' => [], 'movies' => $this->numbered(array_slice($uncategorizedMovies, 0, $sectionLimit))];
            }
        }
        if ($home && (self::homeMarker($snapshot['theme'] ?? [], 'section-layout', 'balanced')
            || self::homeMarker($snapshot['theme'] ?? [], 'hide-empty-sections', 'true'))) {
            $sections = array_values(array_filter($sections, fn (array $section): bool => $section['movies'] !== []));
            foreach ($sections as $index => &$section) {
                $section['is_first'] = $index === 0;
            }
            unset($section);
        }
        $categorySections = [];
        $categoryIcon = $snapshot['theme']['assets']['default3/assets/icon/icon_27.png']['path'] ?? $snapshot['theme']['assets']['images/icon_27.png']['path'] ?? $snapshot['theme']['assets']['icon_27.png']['path'] ?? '';
        $currentId = (int) ($snapshot['category']['id'] ?? 0);
        $childCategories = $currentId ? array_values(array_filter($categories, fn ($category) => $category['parent_id'] === $currentId)) : [];
        foreach ($childCategories as $category) {
            $categorySections[] = ['category_id' => $category['id'], 'name' => $category['name'], 'path' => $category['path'], 'is_first' => $categorySections === [], 'icon_url' => $categoryIcon, 'children' => $children($category['id']),
                'movies' => $this->numbered(array_values(array_filter($movies, fn ($movie) => in_array($category['id'], $movie['ancestor_ids'], true))))];
        }
        if (! $categorySections) {
            $currentCategory = MovieThemeCatalog::category($catalog, $currentId);
            $sectionPath = $currentCategory['path'] ?? $routes['list'];
            $categorySections[] = ['name' => $currentCategory['name'] ?? $snapshot['category']['name'] ?? '电影', 'path' => $v2 ? FanmuluPresentation::navigationUrl($sectionPath, $snapshot) : $sectionPath,
                'is_first' => true, 'icon_url' => $categoryIcon, 'children' => [], 'movies' => $this->numbered($movies)];
        }
        foreach ([$sections, $categorySections] as $groupIndex => $groups) {
            foreach ($groups as &$group) {
                $matching = $groupIndex === 0 && array_key_exists('category_id', $group) && $group['category_id'] === null
                    ? $uncategorizedMovies
                    : array_values(array_filter($movies, fn ($movie) => $groupIndex === 0
                        ? $movie['root_id'] === ($group['category_id'] ?? null)
                        : in_array($group['category_id'] ?? null, $movie['ancestor_ids'], true)));
                if (! $matching) {
                    $matching = $group['movies'];
                }
                $group['extra_movies'] = $this->numbered(array_slice($matching, 0, 20));
                $group['weekly_movies'] = $this->ranked($matching, 'hits_week', 20);
                $group['rank_label'] = $this->hasMetric($matching, 'hits_week') ? '周榜单' : '最新收录';
                [$group['sidebar_movies'], $group['sidebar_extra_movies']] = $this->compactSidebar($matching, count($group['movies']));
            }
            unset($group);
            if ($groupIndex === 0) {
                $sections = $groups;
            } else {
                $categorySections = $groups;
            }
        }
        $rankSections = [];
        foreach ($sections as $group) {
            $matching = $group['extra_movies'];
            $group['movies'] = $this->ranked($matching, 'hits', 20);
            $group['rank_label'] = $this->hasMetric($matching, 'hits') ? '总排行榜' : '最新收录';
            $group['featured_movies'] = array_slice($group['movies'], 0, 1);
            $group['rank_movies'] = array_slice($group['movies'], 1, 10);
            $rankSections[] = $group;
        }
        $relatedMovies = array_values(array_filter($movies, fn ($movie) => $movie['path'] !== $pagePath));
        $genreRecommendations = $relatedMovies;
        $actorRecommendations = [];
        $tabs = $snapshot['_live_recommendation_tabs'] ?? null;
        if (is_array($tabs)) {
            $genreRecommendations = $this->projectMovies($snapshot, $tabs['genre'] ?? [], $catalog, $profile);
            $actorRecommendations = $this->projectMovies($snapshot, $tabs['actors'] ?? [], $catalog, $profile);
            $genreRecommendations = array_values(array_filter($genreRecommendations, fn ($movie) => $movie['path'] !== $pagePath));
            $actorRecommendations = array_values(array_filter($actorRecommendations, fn ($movie) => $movie['path'] !== $pagePath));
        }
        [$compactWeeklyMovies, $compactExtraMovies] = $this->compactSidebar($relatedMovies, min(12, count($relatedMovies)));
        $episodes = [];
        foreach ($content['episodes'] ?? [] as $index => $episode) {
            if (! is_array($episode)) {
                continue;
            }
            $episodes[] = array_replace($episode, ['play_url' => $v2 ? FanmuluNavigation::scopePath($snapshot, 'play', (string) ($index + 1))
                : ($routeKey === '' ? '' : SitePresentationService::playPath($profile, $routeKey, $index + 1))]);
        }
        $canPlay = ($play['can_play'] ?? false) === true;
        $sourceUrl = $canPlay && is_string($play['source_url'] ?? null) && Html::safeUrl($play['source_url']) ? $play['source_url'] : '';
        $player = ['title' => $play['episode']['title'] ?? '', 'mode' => $play['mode'] ?? 'external_link',
            'can_play' => $canPlay && $sourceUrl !== '', 'source_url' => $sourceUrl,
            'message' => $play['message'] ?? '暂无经过确认的播放来源。', 'play_url' => $play['play_url'] ?? '',
            'previous_url' => $play['previous_url'] ?? '', 'next_url' => $play['next_url'] ?? '', 'detail_url' => $play['detail_url'] ?? $pagePath];
        $assignments = AssignmentPurpose::seo($snapshot['assignments'] ?? []);
        if ($v2) {
            $player['title'] = FanmuluPresentation::text((string) $player['title'], $snapshot);
            $player['detail_url'] = FanmuluNavigation::rootPath($snapshot);
            foreach (['play_url', 'previous_url', 'next_url'] as $key) {
                if ($player[$key] !== '') {
                    $player[$key] = FanmuluPresentation::navigationUrl($player[$key], $snapshot);
                }
            }
            foreach (['next_url', 'previous_url'] as $key) {
                if (! empty($search[$key])) {
                    $search[$key] = FanmuluPresentation::navigationUrl($search[$key], $snapshot);
                }
            }
            foreach ($search['pagination'] ?? [] as $index => $item) {
                if (is_array($item) && is_string($item['url'] ?? null)) {
                    $search['pagination'][$index]['url'] = FanmuluPresentation::navigationUrl($item['url'], $snapshot);
                }
            }
        }
        $urls = FanmuluPresentation::urls($snapshot, array_replace(SitePresentationService::urls($snapshot), ['detail' => $play['detail_url'] ?? $pagePath]));
        $currentCategory = ($snapshot['category'] ?? []) + ['id' => '', 'name' => '', 'path' => $pagePath];
        if ($v2) {
            $currentCategory['path'] = FanmuluPresentation::navigationUrl($currentCategory['path'], $snapshot);
        }

        return ['site' => $site, 'page' => ($snapshot['page'] ?? []) + ['canonical_url' => $snapshot['canonical_url'] ?? ''],
            'seo' => $slots + ['title' => $content['title'] ?? $site['name'], 'h1' => $content['title'] ?? $site['name'], 'description' => '', 'intro' => ''],
            'content' => $content, 'movies' => $movies, 'episodes' => $episodes, 'related_links' => FanmuluPresentation::relatedLinks($snapshot),
            'friend_links' => is_array($snapshot['friend_links'] ?? null) ? $snapshot['friend_links'] : [],
            'navigation' => $navigation, 'sections' => $sections, 'category_sections' => $categorySections, 'featured_movies' => $this->numbered(array_slice($movies, 0, 10)),
            'compact_featured_movies' => $this->numbered(array_slice($movies, 0, 2)),
            'rank_sections' => ($snapshot['page']['type'] ?? '') === 'search' ? array_slice($rankSections, 0, 2) : $rankSections,
            'recommended_movies' => $this->numbered(array_slice($genreRecommendations, 0, 12)),
            'actor_recommended_movies' => $this->numbered(array_slice($actorRecommendations, 0, 12)),
            'comments' => $this->comments($snapshot), 'extra_movies' => $this->numbered(array_slice($relatedMovies, 0, 20)),
            'compact_weekly_movies' => $compactWeeklyMovies, 'compact_extra_movies' => $compactExtraMovies,
            'weekly_movies' => $this->ranked($relatedMovies, 'hits_week', 10), 'monthly_movies' => $this->ranked($relatedMovies, 'hits_month', 10),
            'pagination' => $search['pagination'] ?? [],
            'filter_groups' => $this->filterGroups($snapshot, $movies, $search, $routes),
            'url' => $urls,
            'category' => $currentCategory, 'player' => $player,
            'genres' => array_map(fn ($genre) => ['name' => $genre, 'url' => $routes['search'].'?q='.rawurlencode($genre)], ['剧情', '悬疑', '动作', '喜剧', '科幻', '动画']),
            'kw' => ['primary' => collect($assignments)->firstWhere('role', 'primary')['text'] ?? '', 'secondary' => $v2 && ! FanmuluPresentation::separateSeo($snapshot) ? $snapshot['fanmulu']['display_title'] : collect($assignments)->where('role', 'secondary')->pluck('text')->implode('、')],
            'search' => $search + ['query' => '', 'total' => count($movies), 'next_url' => '', 'previous_url' => '']];
    }

    public function html(array $snapshot, ?string $view = null, array $search = [], array $play = []): string
    {
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        $theme = $snapshot['theme'];
        $view ??= match ($snapshot['page']['type'] ?? 'movie') {
            'home' => 'home', 'category', 'list' => 'list', 'search' => 'search', 'play' => 'play', default => 'detail'
        };
        $role = $view === 'category' ? 'list' : $view;
        if (! in_array($role, MovieThemePackage::VIEWS, true)) {
            throw new \InvalidArgumentException('View giao diện không hỗ trợ.');
        }
        $chosen = $snapshot['routing']['views'][$role] ?? $role;
        if ($chosen === 'list' && ! isset($theme['views']['list']) && isset($theme['views']['category'])) {
            $chosen = 'category';
        }
        $fragment = $theme['views'][$chosen] ?? ($role === 'play' ? self::PLAY_FALLBACK : throw new \InvalidArgumentException('Giao diện thiếu view '.$chosen));
        $compiler = app(MovieThemeCompiler::class);
        $context = $this->context($snapshot, $search, $play);
        $runtime = ReviewedThemeRuntime::validate($theme['runtime'] ?? [], $theme['assets'] ?? []);
        if (in_array($role, ['detail', 'play'], true)) {
            $fragment = app(MovieRecommendationTabs::class)->adapt($fragment);
            if (array_key_exists('_live_movie_comments', $snapshot)) {
                $fragment = app(MovieRecommendationTabs::class)->comments($fragment);
            }
        }
        $body = $compiler->render($fragment, $context, $theme['assets'] ?? [], $runtime);
        if (! FanmuluPresentation::version2($snapshot) && ! empty($snapshot['routing']['configured'])) {
            $body = $this->legacyUrls($body, $context['url']);
        }
        if ($role === 'detail' && MovieThemePackage::schema($theme['views']) === 2 && ! preg_match('/\{\{\s*episode\.play_url\s*\}\}/', $fragment)) {
            $links = '';
            foreach ($context['episodes'] as $episode) {
                if ($episode['play_url'] !== '') {
                    $links .= '<a href="'.htmlspecialchars($episode['play_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.htmlspecialchars((string) ($episode['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</a>';
                }
            }
            if ($links !== '') {
                $navigation = '<nav class="content-panel episode-panel theme-play-navigation" aria-label="分集播放来源"><h2>分集播放来源</h2><div class="episodes">'.$links.'</div></nav>';
                $close = strripos($body, '</main>');
                $body = $close === false ? $body.$navigation : substr_replace($body, $navigation, $close, 0);
            }
        }
        $css = $compiler->renderCss($theme['css'], $theme['assets'] ?? []);
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $viewport = $runtime['viewport'] ?? 'width=device-width,initial-scale=1';
        $html = '<!doctype html><html lang="'.$escape($snapshot['site']['locale'] ?? 'zh-CN').'"><head><meta charset="utf-8"><meta name="viewport" content="'.$escape($viewport).'"><title>'.$escape($context['seo']['title']).'</title><meta name="description" content="'.$escape($context['seo']['description']).'"><link rel="canonical" href="'.$escape($snapshot['canonical_url']).'">';
        if (! empty($snapshot['page']['noindex']) || $view === 'search') {
            $html .= '<meta name="robots" content="noindex,follow">';
        }
        if (isset($context['seo']['meta_keywords'])) {
            $html .= '<meta name="keywords" content="'.$escape($context['seo']['meta_keywords']).'">';
        }
        if ($runtime !== []) {
            foreach ($runtime['meta'] ?? [] as $meta) {
                $attribute = isset($meta['name']) ? 'name' : 'http-equiv';
                $html .= '<meta '.$attribute.'="'.$escape($meta[$attribute]).'" content="'.$escape($meta['content']).'">';
            }
            foreach ($runtime['icons'] ?? [] as $icon) {
                $html .= '<link rel="'.$escape($icon['rel']).'" href="'.$escape($theme['assets'][$icon['asset']]['path']).'"';
                foreach (['sizes', 'type'] as $attribute) {
                    if (isset($icon[$attribute])) {
                        $html .= ' '.$attribute.'="'.$escape($icon[$attribute]).'"';
                    }
                }
                $html .= '>';
            }
            $resources = ReviewedThemeRuntime::forView($runtime, $chosen);
            foreach ($resources['stylesheets'] as $name) {
                $asset = $theme['assets'][$name];
                $html .= '<link rel="stylesheet" href="'.$escape($asset['path']).'" type="text/css" integrity="sha256-'.base64_encode(hex2bin($asset['hash'])).'" crossorigin="anonymous">';
            }
            foreach ($resources['scripts'] as $name) {
                $asset = $theme['assets'][$name];
                $html .= '<script src="'.$escape($asset['path']).'" integrity="sha256-'.base64_encode(hex2bin($asset['hash'])).'" crossorigin="anonymous"></script>';
            }
        }
        if ($css !== '' || $runtime === []) {
            $html .= '<style>'.$css.'</style>';
        }
        $bodyClass = empty($runtime['body_classes']) ? '' : ' class="'.$escape(implode(' ', $runtime['body_classes'])).'"';
        $html .= '</head><body'.$bodyClass.' data-theme="'.$escape($theme['slug']).'" data-theme-version="'.$escape($theme['version_id']).'">'.$body.'</body></html>';
        $dom = new \DOMDocument;
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if ($dom->getElementsByTagName('h1')->length !== 1) {
            throw new \InvalidArgumentException('Giao diện sau render phải có đúng một H1.');
        }

        $html = FanmuluPresentation::navigationHtml($html, $snapshot);

        return FanmuluPresentation::enabled($snapshot) ? app(FanmuluRuntime::class)->attach($html, $snapshot) : $html;
    }

    /** Apply the same immutable card projection to catalog and individual recommendation lists. */
    private function projectMovies(array $snapshot, array $entries, array $catalog, array $profile): array
    {
        $movies = [];
        $entryCategories = array_column($catalog['entry_categories'] ?? [], null, 'path');
        foreach ($entries as $entry) {
            if ((! empty($snapshot['_snapshot_cards']) || ! empty($entry['_catalog_card_frozen'])) && is_array($entry['_card'] ?? null)) {
                $movies[] = FanmuluPresentation::card($snapshot, $entry['_card'], $entry) + ['rank' => count($movies) + 1, 'rank_class' => ''];

                continue;
            }
            $movie = FanmuluPresentation::content(isset($entry['content']) ? $entry : ['content' => $entry]);
            $category = $entry['category'] ?? $entryCategories[$entry['path'] ?? '/'] ?? [];
            $actors = $movie['actors'] ?? '';
            $director = $movie['director'] ?? $movie['directors'] ?? '';
            $moviePath = $entry['path'] ?? '/';
            $card = ['title' => $movie['title'] ?? '', 'path' => $entry['path'] ?? '/', 'poster_url' => $movie['poster_url'] ?? $movie['metadata']['poster_url'] ?? '', 'year' => $movie['year'] ?? '', 'genre' => $movie['genre'] ?? '',
                'actors' => is_array($actors) ? implode(' / ', $actors) : $actors, 'remarks' => $movie['remarks'] ?? '', 'root_id' => $category['root_id'] ?? null, 'ancestor_ids' => $category['ancestor_ids'] ?? [],
                'director' => is_array($director) ? implode(' / ', $director) : $director, 'area' => $movie['area'] ?? '',
                'language' => $movie['language'] ?? '', 'body_excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($movie['body_html'] ?? ''))), 0, 160),
                'score' => $this->score($movie), 'rank' => count($movies) + 1, 'rank_class' => '',
                'hits_week' => $this->metric($movie, 'hits_week'), 'hits_month' => $this->metric($movie, 'hits_month'), 'hits' => $this->metric($movie, 'hits'),
                'play_url' => $entry['play_path'] ?? (! empty($movie['episodes']) && $moviePath !== '/' ? SitePresentationService::playPath($profile, $entry['route_key'] ?? SitePresentationService::keyFromPath($moviePath, $profile, 'detail'), 1) : '')];
            $movies[] = FanmuluPresentation::card($snapshot, $card, $entry);
        }

        return $this->numbered($this->uniqueMovies($movies));
    }

    /** Sample rows are labelled explicitly and remain escaped text in the theme compiler. */
    private function comments(array $snapshot): array
    {
        $comments = [];
        foreach (array_slice($snapshot['_live_movie_comments'] ?? [], 0, 20) as $comment) {
            if (! is_array($comment) || ($comment['is_sample'] ?? false) !== true
                || ! is_string($comment['text'] ?? null) || trim($comment['text']) === '') {
                continue;
            }
            $comments[] = ['text' => $comment['text'], 'author' => is_string($comment['author'] ?? null) ? $comment['author'] : '示例评论'];
        }

        return $comments;
    }

    /** Build bounded, data-backed filter choices without querying the full catalogue. */
    private function filterGroups(array $snapshot, array $movies, array $search, array $routes): array
    {
        $filters = array_filter(is_array($search['filters'] ?? null) ? $search['filters'] : [], fn ($value): bool => is_string($value) && $value !== '');
        $filters = array_intersect_key($filters, array_flip(['q', 'genre', 'area', 'year', 'language']));
        $base = in_array($snapshot['page']['type'] ?? '', ['list', 'category'], true)
            ? ($snapshot['page']['path'] ?? $routes['list']) : $routes['list'];
        $defaults = ['genre' => ['剧情', '动作', '喜剧', '爱情', '科幻', '悬疑', '动画', '纪录'],
            'area' => ['中国大陆', '中国香港', '中国台湾', '日本', '韩国', '美国'], 'year' => [], 'language' => ['国语', '汉语普通话', '粤语', '英语', '日语', '韩语']];
        $groups = [];
        foreach (['genre' => '类型', 'area' => '地区', 'year' => '年份', 'language' => '语言'] as $field => $label) {
            $values = $defaults[$field];
            foreach ($movies as $movie) {
                $value = $movie[$field] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $values[] = trim((string) $value);
                }
            }
            if (isset($filters[$field])) {
                $values[] = $filters[$field];
            }
            $values = array_values(array_unique($values));
            if ($field === 'year') {
                rsort($values, SORT_NATURAL);
            }
            $values = array_slice($values, 0, 30);
            if (isset($filters[$field]) && ! in_array($filters[$field], $values, true)) {
                $values[array_key_last($values)] = $filters[$field];
            }
            $without = $filters;
            unset($without[$field]);
            $url = fn (array $query): string => $base.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            $options = [['name' => '全部', 'url' => $url($without), 'current' => ! isset($filters[$field])]];
            foreach ($values as $value) {
                $options[] = ['name' => (string) $value, 'url' => $url(array_replace($without, [$field => (string) $value])),
                    'current' => ($filters[$field] ?? null) === (string) $value];
            }
            $groups[] = ['name' => $label, 'is_sort' => false, 'options' => $options];
        }
        $groups[] = ['name' => '排序', 'is_sort' => true, 'options' => [['name' => '最新收录', 'url' => $base.($filters === [] ? '' : '?'.http_build_query($filters, '', '&', PHP_QUERY_RFC3986)), 'current' => true]]];

        return $groups;
    }

    private function score(array $content): string
    {
        $score = $content['score'] ?? $content['metadata']['score'] ?? $content['metadata']['vod_score'] ?? null;

        return is_numeric($score) && is_finite((float) $score) && (float) $score >= 0 && (float) $score <= 10 ? (string) (float) $score : '';
    }

    private function metric(array $content, string $key): ?int
    {
        $value = $content[$key] ?? $content['metadata'][$key] ?? $content['metadata']['vod_'.$key] ?? null;

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false && $value !== null ? (int) $value : null;
    }

    private function hasMetric(array $movies, string $key): bool
    {
        return $movies !== [] && count(array_filter($movies, fn ($movie) => $movie[$key] !== null)) === count($movies);
    }

    /** Keep separate keyword destinations and source variants even when their titles match. */
    private function uniqueMovies(array $movies): array
    {
        $seen = [];
        $unique = [];
        foreach ($movies as $movie) {
            $path = $movie['path'] ?? null;
            if (is_string($path) && $path !== '') {
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
            }
            $unique[] = $movie;
        }

        return $unique;
    }

    private function numbered(array $movies, bool $highlight = false): array
    {
        $movies = array_values($movies);
        foreach ($movies as $index => &$movie) {
            $movie['rank'] = $index + 1;
            $movie['rank_class'] = $highlight ? (['badge-first', 'badge-second', 'badge-third'][$index] ?? '') : '';
        }
        unset($movie);

        return $movies;
    }

    /** @return array{0: array, 1: array} */
    private function compactSidebar(array $movies, int $mainCount): array
    {
        $limit = min(8, (int) ceil($mainCount / 2));
        if ($limit < 1) {
            return [[], []];
        }
        $posters = $this->ranked($movies, 'hits_week', $limit);
        $posterPaths = array_fill_keys(array_column($posters, 'path'), true);
        $remaining = array_values(array_filter($movies, fn (array $movie): bool => ! isset($posterPaths[$movie['path']])));

        return [$posters, $this->numbered(array_slice($remaining, 0, $limit))];
    }

    private function ranked(array $movies, string $key, int $limit): array
    {
        if ($this->hasMetric($movies, $key)) {
            usort($movies, fn ($a, $b) => ($b[$key] <=> $a[$key]) ?: strcmp($a['path'], $b['path']));
        }

        return $this->numbered(array_slice($movies, 0, $limit), true);
    }

    private function legacyUrls(string $body, array $urls): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="theme-routing-root">'.$body.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@href or @action]') as $node) {
            foreach (['href', 'action'] as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }
                $path = $node->getAttribute($attribute);
                if ($path === '/') {
                    $node->setAttribute($attribute, $urls['home']);
                } elseif ($path === '/search' || str_starts_with($path, '/search?')) {
                    $node->setAttribute($attribute, $urls['search'].substr($path, 7));
                }
            }
        }
        $html = '';
        foreach ($dom->getElementById('theme-routing-root')->childNodes as $node) {
            $html .= $dom->saveHTML($node);
        }

        return $html;
    }
}
