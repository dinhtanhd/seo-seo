<?php

namespace App\Http\Controllers;

use App\Domain\Advertising\AdvertisingService;
use App\Domain\Comments\LiveMovieComments;
use App\Domain\Fanmulu\ChineseSearchSpider;
use App\Domain\Fanmulu\FanmuluLinks;
use App\Domain\Fanmulu\FanmuluNavigation;
use App\Domain\Fanmulu\FanmuluPresentation;
use App\Domain\Fanmulu\FanmuluService;
use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Fanmulu\FanmuluUrlRegistry;
use App\Domain\Ingestion\CanonicalRecord;
use App\Domain\Playback\MoviePlayer;
use App\Domain\Publishing\CatalogMetadata;
use App\Domain\Publishing\CatalogPagination;
use App\Domain\Publishing\FanmuluDetailArtifact;
use App\Domain\Publishing\LiveMovieCatalogQuery;
use App\Domain\Publishing\LiveMovieContent;
use App\Domain\Publishing\LiveMovieRecommendations;
use App\Domain\Publishing\OnDemandCatalog;
use App\Domain\Publishing\OnDemandPublisher;
use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\Renderer;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Publishing\SnapshotStorage;
use App\Domain\Rules\Html;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Sites\SiteAppearance;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeCatalog;
use App\Domain\Themes\MovieThemeRenderer;
use App\Domain\Themes\ReviewedThemeRuntime;
use App\Domain\Themes\SystemFriendLinks;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class TramPublicController extends Controller
{
    private function site(Request $request)
    {
        if ($request->attributes->get('admin_host')) {
            return null;
        }
        $site = $request->attributes->get('public_site');
        abort_unless($site, 404);

        return $site;
    }

    public function show(Request $request, string $path = '')
    {
        if ($request->attributes->get('admin_host')) {
            return redirect('/admin');
        }
        $site = $this->site($request);
        // Route parameters are decoded by Laravel; persisted keys retain rawurlencode IDs.
        $path = $this->requestPath($request);
        abort_if(str_contains($path, '\\') || str_contains($path, '//') || preg_match('/[\x00-\x1f\x7f]/', $path), 404);
        try {
            // A reserved fanmulu URL must never serve a regular page moved here later.
            $registered = app(FanmuluUrlRegistry::class)->find((int) $site->id, $path);
        } catch (Conflict $error) {
            return $this->fanmuluUnavailable();
        }
        $redirect = DB::table('page_redirects')->where('site_id', $site->id)->where('from_path', $path)->first();
        if ($redirect) {
            if ($registered !== null) {
                return $this->fanmuluUnavailable();
            }
            abort_unless(str_starts_with($redirect->to_path, '/') && ! str_starts_with($redirect->to_path, '//')
                && ! str_contains($redirect->to_path, '\\') && ! preg_match('/[\x00-\x1f\x7f]/', $redirect->to_path), 404);

            return $this->publicRedirect($request, $redirect->to_path, $redirect->status_code);
        }
        $page = DB::table('pages')->where('site_id', $site->id)->where('canonical_path', $path)->first();
        if ($page && in_array($page->status, ['archived', 'gone'], true)) {
            abort(410);
        }
        if (! $page && str_contains($path, '/_scope/')) {
            return $this->fanmuluScope($request, $site, $path);
        }
        $committedPublication = null;
        if ((! $page || ($page->page_kind === 'fanmulu' && $page->status === 'draft'))
            && $request->isMethod('GET') && (FanmuluSettings::matches(FanmuluSettings::fromSite($site), $path) || FanmuluNavigation::looksLikeIntentPath($path)
                || $registered !== null)) {
            try {
                $page = app(FanmuluService::class)->resolve((int) $site->id, $path, $committedPublication);
            } catch (Conflict $error) {
                $status = match ($error->reason) {
                    'FANMULU_SITE_UNAVAILABLE', 'FANMULU_DISABLED_OR_PATH_INVALID', 'FANMULU_SOURCE_UNAVAILABLE', 'FANMULU_PATH_ALREADY_USED' => 404,
                    'FANMULU_PAGE_UNAVAILABLE' => 410,
                    default => 503,
                };

                return response('专题暂不可用，请稍后重试。', $status)->header('Content-Type', 'text/plain; charset=UTF-8')
                    ->header('Cache-Control', 'no-store')->header('Retry-After', '60');
            }
        }
        $onDemand = ($page->page_kind ?? null) !== 'fanmulu' && app(SiteDeliverySettings::class)->enabled((int) $site->id);
        if ($page && $onDemand && $page->page_type === 'movie' && $page->page_kind === 'regular' && ! $page->current_publication_id) {
            $page = $this->firstPublication($request, $site, $page);
        }
        if ($page && ! ($onDemand && in_array($page->page_type, ['home', 'category'], true))) {
            abort_unless($page->status === 'live' && $page->current_publication_id, 404);
            $publication = $committedPublication && (int) $committedPublication->id === (int) $page->current_publication_id ? $committedPublication : null;

            $rendered = null;
            if ($request->isMethod('GET') && $request->server('TRAM_PUBLIC_CACHE_ELIGIBLE') === '1') {
                $rendered = app(PublicHtmlCache::class)->materializePage($page->id, ChineseSearchSpider::matches($request->userAgent()), $publication);
            }
            if ($rendered !== null && ($rendered['publication_id'] !== (int) $page->current_publication_id || ($rendered['snapshot']['page']['path'] ?? null) !== $path)) {
                $rendered = null;
            }
            if ($rendered === null) {
                $publication ??= DB::table('page_publications')->where('site_id', $site->id)->where('page_id', $page->id)->where('id', $page->current_publication_id)->first();
                abort_unless($publication, 404);
            }
            $snapshot = $rendered['snapshot'] ?? Json::decode($publication->snapshot_json);
            $html = $rendered['html'] ?? $publication->html_body;
            $hash = $rendered['html_hash'] ?? $publication->html_hash;
            if ($rendered === null && ! FanmuluDetailArtifact::current($snapshot, $page, $site)) {
                $refreshArtifact = FanmuluDetailArtifact::exists($snapshot);
                $snapshot = FanmuluDetailArtifact::forRefresh($snapshot);
                $snapshot = app(LiveMovieContent::class)->overlay($snapshot);
                $snapshot = app(SiteAppearance::class)->overlay($snapshot);
                $snapshot = app(LiveMovieRecommendations::class)->overlay($snapshot);
                $snapshot = app(LiveMovieComments::class)->overlay($snapshot);
                if ($refreshArtifact || ! empty($snapshot['_live_content_changed']) || ! empty($snapshot['_live_recommendations_changed']) || ! empty($snapshot['_live_comments_changed']) || app(SiteAppearance::class)->active((int) $site->id) !== null) {
                    $html = app(Renderer::class)->html($snapshot);
                    $hash = hash('sha256', $html);
                }
                if ($request->isMethod('GET')) {
                    app(LiveMovieRecommendations::class)->remember($snapshot, (int) $publication->id);
                }
            }

            return $this->htmlResponse($request, $html, $hash,
                noindex: ! empty($snapshot['page']['noindex']), theme: $snapshot['theme'] ?? [], site: $site, page: $page);
        }
        if ($play = $this->play($request, $site, $path)) {
            return $play;
        }
        $live = app(SitePresentationService::class)->publicLive($site->id);
        $profile = $live['routing'] ?? SitePresentationService::defaults();
        if ($path === SitePresentationService::endpointPath($profile, 'search')) {
            return $this->gallery($request, $site, 'search', $live);
        }
        if ($path === SitePresentationService::endpointPath($profile, 'home')) {
            return $this->gallery($request, $site, 'home', $live);
        }
        if ($path === SitePresentationService::endpointPath($profile, 'list')) {
            return $this->gallery($request, $site, 'list', $live);
        }
        if (($key = $this->categoryRouteKey($path, $profile)) !== null) {
            return $this->gallery($request, $site, 'list', $live, $key);
        }
        $plain = $profile;
        unset($plain['suffixes']['list']);
        $key = $this->categoryRouteKey($path, $plain);
        if ($key !== null && ctype_digit($key)) {
            return $this->gallery($request, $site, 'list', $live, $key);
        }
        foreach (DB::table('page_publications')->where('site_id', $site->id)->whereRaw("snapshot_json->'page'->>'type' = 'home'")
            ->selectRaw("DISTINCT snapshot_json->'routing' AS routing")->get() as $historical) {
            if (($key = $this->categoryRouteKey($path, SitePresentationService::profile(Json::decode($historical->routing)))) !== null) {
                return $this->gallery($request, $site, 'list', $live, $key);
            }
        }
        abort(404);
    }

    private function fanmuluScope(Request $request, object $site, string $path)
    {
        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);
        [$rootPath, $tail] = explode('/_scope/', $path, 2);
        abort_unless($rootPath !== '' && strlen($path) <= 2000
            && preg_match('~^(home|list|search|play)(?:/([A-Za-z0-9_-]{1,120}))?(?:\.[a-z][a-z0-9]{0,11})?$~D', $tail, $match), 404);
        $publication = DB::table('pages as p')->join('page_publications as pp', function ($join): void {
            $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
        })->where('p.site_id', $site->id)->where('p.canonical_path', $rootPath)->where('p.page_kind', 'fanmulu')
            ->where('p.page_type', 'movie')->where('p.status', 'live')->first(['pp.id', 'pp.snapshot_json']);
        abort_unless($publication, 404);
        $snapshot = app(LiveMovieContent::class)->overlay(app(SnapshotStorage::class)->decode($publication->snapshot_json));
        $snapshot['_fanmulu_publication_id'] = (int) $publication->id;
        abort_unless(FanmuluNavigation::enabled($snapshot), 404);
        $view = $match[1];
        $key = $match[2] ?? null;
        abort_unless($path === FanmuluNavigation::scopePath($snapshot, $view, $key), 404);
        $request->validate(['site_id' => 'prohibited', 'root' => 'prohibited', 'root_path' => 'prohibited', 'publication_id' => 'prohibited',
            'page' => 'nullable|integer|min:1|max:1000000']);
        if ($view === 'play') {
            abort_unless($key !== null && preg_match('/^[1-9][0-9]{0,5}$/D', $key) && (int) $key <= 100000, 404);

            return $this->playSnapshot($request, $snapshot, (int) $key, $path);
        }
        abort_if(in_array($view, ['home', 'search'], true) && $key !== null, 404);
        if ($key !== null) {
            abort_unless($view === 'list' && preg_match('/^(?:[a-z0-9]+(?:-[a-z0-9]+)*-)?([1-9][0-9]{0,18})$/D', $key, $category)
                && filter_var($category[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        }
        $live = ['routing' => SitePresentationService::profile($snapshot['routing'] ?? null), 'snapshot' => $snapshot];

        return $this->gallery($request, $site, $view, $live, $key, $snapshot);
    }

    private function firstPublication(Request $request, object $site, object $page): object
    {
        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);
        try {
            return app(OnDemandPublisher::class)->resolve((int) $site->id, (int) $page->id);
        } catch (Conflict $error) {
            abort(response('影片暂不可用，请稍后重试。', 503)->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('Cache-Control', 'no-store')->header('Retry-After', '30')->header('X-Tram-Reason', $error->reason));
        } catch (QueryException $error) {
            if (! in_array($error->getCode(), ['55P03', '40P01', '40001'], true)) {
                throw $error;
            }
            abort(response('影片正在准备，请稍后重试。', 503)->header('Cache-Control', 'no-store')->header('Retry-After', '2'));
        }
    }

    private function requestPath(Request $request): string
    {
        return explode('?', $request->getRequestUri(), 2)[0];
    }

    private function publicRedirect(Request $request, string $path, int $status = 301): RedirectResponse
    {
        // A public /index.php URL can be Symfony's base URL; never prepend it to another route.
        return new RedirectResponse($request->getSchemeAndHttpHost().$path.$this->querySuffix($request), $status);
    }

    private function categoryRouteKey(string $path, array $profile): ?string
    {
        $pattern = '~^'.preg_quote($profile['routes']['list'], '~').'/((?:[a-z0-9]+(?:-[a-z0-9]+)*-)?[1-9][0-9]{0,18})'
            .preg_quote(SitePresentationService::suffix($profile, 'list'), '~').'$~D';

        return preg_match($pattern, $path, $match) ? $match[1] : null;
    }

    private function querySuffix(Request $request): string
    {
        $query = http_build_query($request->query(), '', '&', PHP_QUERY_RFC3986);

        return $query === '' ? '' : '?'.$query;
    }

    private function htmlResponse(Request $request, string $html, ?string $hash = null, bool $noindex = false, array $theme = [], ?object $site = null, ?object $page = null, bool $hasPlayer = false, ?string $advertisingArea = null)
    {
        $settings = $site ? FanmuluSettings::fromSite($site) : null;
        $headWithoutLinks = $request->isMethod('HEAD') && ChineseSearchSpider::matches($request->userAgent()) && $settings && $settings['enabled'] && $settings['links_per_page'] > 0
            && in_array($settings['url_rule']['mode'], ['random', 'pattern', 'tokens'], true) && ($page->page_kind ?? 'regular') === 'regular';
        if (! $noindex && $site && ! $headWithoutLinks) {
            $variant = app(FanmuluLinks::class)->append($html, $site, $page, $this->requestPath($request), userAgent: $request->userAgent());
            if ($variant !== $html) {
                $html = $variant;
                $hash = null;
            }
        }
        $csp = ReviewedThemeRuntime::enabled($theme) ? PublicHtmlCache::RUNTIME_CSP : PublicHtmlCache::PLAIN_CSP;
        if ($hasPlayer) {
            $csp = "default-src 'self'; img-src 'self' https: data:; media-src 'self' https: blob:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'self'; connect-src 'self' https:; worker-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'";
        }
        $advertisingSite = $request->attributes->get('public_site') ?? $site;
        if ($advertisingSite) {
            $advertisingArea ??= ($page->page_kind ?? 'regular') === 'fanmulu' ? 'fanmulu' : 'regular';
            $advertising = app(AdvertisingService::class);
            $decorated = app(SystemFriendLinks::class)->decorate($html, $advertisingSite, fanmulu: $advertisingArea === 'fanmulu');
            $decorated = $advertising->decorate($decorated, $advertisingSite, $advertisingArea);
            if ($decorated !== $html) {
                $html = $decorated;
                $hash = null;
            }
            $csp = $advertising->csp($csp, $advertisingSite, $advertisingArea);
        }
        $response = response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('ETag', '"'.($hash ?? hash('sha256', $html)).'"')
            ->header('Cache-Control', 'no-store')
            ->header('Vary', 'User-Agent')
            ->header('X-Tram-Cache', $request->server('TRAM_PUBLIC_CACHE_ELIGIBLE') === '1' ? 'MISS' : 'BYPASS')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', $csp);
        if ($noindex) {
            $response->header('X-Robots-Tag', 'noindex,follow');
        }
        if ($headWithoutLinks) {
            // Dynamic discovery URLs are issued by GET; avoid a misleading entity tag on a read-only HEAD.
            $response->headers->remove('ETag');
        } else {
            $response->isNotModified($request);
        }

        return $response;
    }

    private function fanmuluUnavailable()
    {
        return response('专题暂不可用，请稍后重试。', 503)->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store')->header('Retry-After', '60');
    }

    private function gallery(Request $request, object $site, string $view, ?array $live, ?string $category = null, ?array $scope = null)
    {
        if ($scope !== null) {
            $scope = app(SiteAppearance::class)->overlay($scope);
            $live = ['routing' => SitePresentationService::profile($scope['routing'] ?? null), 'snapshot' => $scope];
        }
        $categoryKey = $category;
        if ($category !== null) {
            preg_match('/([1-9][0-9]{0,18})$/D', $category, $categoryId);
            $category = $categoryId[1] ?? null;
            abort_unless($category !== null, 404);
        }
        $onDemand = app(SiteDeliverySettings::class)->enabled((int) $site->id);
        $routing = $live['routing'] ?? SitePresentationService::profile($site);
        $q = DB::table('pages')->join('page_publications', 'page_publications.id', '=', 'pages.current_publication_id')
            ->where('pages.site_id', $site->id)->where('page_publications.site_id', $site->id)->where('pages.status', 'live')
            ->whereIn('pages.page_type', ['movie', 'novel'])->where('pages.page_kind', 'regular')->select('pages.id', 'pages.content_item_id', 'pages.canonical_path', 'page_publications.snapshot_json', 'page_publications.activated_at');
        $categorySnapshot = [];
        if ($category !== null) {
            $q->where(function ($query) use ($category) {
                $query->whereRaw("page_publications.snapshot_json->'page'->>'category_id' = ?", [$category])
                    ->orWhere(function ($descendants) use ($category) {
                        $descendants->whereRaw("page_publications.snapshot_json->'theme_catalog'->'schema_version' = '1'::jsonb")
                            ->whereRaw("page_publications.snapshot_json->'category'->'ancestor_ids' @> ?::jsonb", [Json::encode([(int) $category])]);
                    });
            });
            $catalogCategory = MovieThemeCatalog::category($live['snapshot']['theme_catalog'] ?? [], (int) $category);
            if (! $catalogCategory && $onDemand) {
                $storedCategory = DB::table('categories')->where('site_id', $site->id)->find((int) $category);
                if ($storedCategory) {
                    $catalogCategory = ['id' => $storedCategory->id, 'name' => $storedCategory->name,
                        'path' => SitePresentationService::categoryPath($storedCategory, $routing)];
                }
            }
            $member = $catalogCategory ? null : (clone $q)->first();
            abort_unless($member || $catalogCategory, 404);
            $memberSnapshot = $member ? Json::decode($member->snapshot_json) : [];
            $categorySnapshot = $catalogCategory ?? MovieThemeCatalog::category($memberSnapshot['theme_catalog'] ?? [], (int) $category) ?? $memberSnapshot['category'] ?? [];
            $canonicalKey = (string) $category;
            $frozenKey = $categorySnapshot['route_key'] ?? SitePresentationService::keyFromPath((string) ($categorySnapshot['path'] ?? ''), $routing, 'list');
            if (! empty($routing['configured']) && in_array($routing['category_key'] ?? 'id', ['pinyin_id', 'pinyin_compact_id'], true)
                && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*-'.preg_quote($category, '/').'$/D', $frozenKey)) {
                $canonicalKey = $frozenKey;
            }
            if ($categoryKey !== (string) $category && $categoryKey !== $canonicalKey) {
                abort_if($scope !== null, 404);
                $historical = DB::table('page_publications')->where('site_id', $site->id)->where(function ($query) use ($category, $request): void {
                    $query->where(function ($entry) use ($category, $request): void {
                        $entry->whereRaw("snapshot_json->'category'->>'id' = ?", [$category])
                            ->whereRaw("snapshot_json->'category'->>'path' = ?", [$this->requestPath($request)]);
                    })->orWhereRaw("snapshot_json->'theme_catalog'->'categories' @> ?::jsonb", [Json::encode([['id' => (int) $category, 'path' => $this->requestPath($request)]])]);
                })->exists();
                abort_unless($historical, 404);
            }
            $categoryPath = SitePresentationService::categoryPathForKey($routing, $canonicalKey);
            $requestedCategoryPath = $scope !== null ? SitePresentationService::categoryPathForKey($routing, $categoryKey) : $this->requestPath($request);
            if ($requestedCategoryPath !== $categoryPath) {
                return $this->publicRedirect($request, $scope !== null ? FanmuluNavigation::scopePath($scope, 'list', $canonicalKey) : $categoryPath);
            }
        }
        if ($request->has('q')) {
            $request->validate(['q' => 'nullable|string|max:100']);
        }
        foreach (['genre', 'area', 'year', 'language'] as $field) {
            if ($request->filled($field)) {
                $request->validate([$field => 'string|max:80']);
            }
        }
        $metadata = app(CatalogMetadata::class);
        $indexed = ! $onDemand && $metadata->available();
        $effective = app(LiveMovieCatalogQuery::class)->attach($q, 'pages', 'page_publications', 'gallery', $indexed);
        $metadata->filters($q, $effective['fields'],
            $request->only(['q', 'genre', 'area', 'year']), $effective['grams']);
        if ($request->filled('language')) {
            $q->whereRaw($effective['language'].' = ?', [$request->input('language')]);
        }
        $fanmuluResults = false;
        $fanmuluMovies = null;
        if ($scope !== null && $view === 'search' && $request->filled('q')) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($request->input('q'))).'%';
            $fm = DB::table('pages as p')->join('page_publications as pp', function ($join): void {
                $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
            })->join('page_keyword_assignments as a', function ($join): void {
                $join->on('a.publication_id', '=', 'pp.id')->on('a.page_id', '=', 'p.id')->where('a.role', 'primary')->where('a.ordinal', 0);
                $join->where(function ($purpose): void {
                    $purpose->where('a.purpose', 'fanmulu_title')->orWhere(function ($legacy): void {
                        $legacy->where('a.purpose', 'seo')->whereRaw("COALESCE((pp.snapshot_json->'fanmulu'->>'presentation_version')::int, 1) < 3");
                    });
                });
            })->where('p.site_id', $site->id)->where('p.page_kind', 'fanmulu')->where('p.page_type', 'movie')->where('p.status', 'live')
                ->where('a.text_snapshot', 'ilike', $pattern);
            $fmEffective = app(LiveMovieCatalogQuery::class)->attach($fm, 'p', 'pp', 'fm_gallery', $metadata->available());
            $metadata->filters($fm, $fmEffective['fields'], $request->only(['genre', 'area', 'year']), $fmEffective['grams']);
            if ($request->filled('language')) {
                $fm->whereRaw($fmEffective['language'].' = ?', [$request->input('language')]);
            }
            $fields = [];
            foreach (['title', 'poster_url', 'year', 'genre', 'area', 'language', 'actors', 'director', 'directors', 'remarks', 'score', 'hits', 'hits_week', 'hits_month', 'source_updated_at'] as $field) {
                $fields[] = "'".$field."', pp.snapshot_json->'content'->'".$field."'";
            }
            $fields[] = "'body_html', left(pp.snapshot_json->'content'->>'body_html', 4096)";
            $fields[] = "'episodes', CASE WHEN jsonb_typeof(pp.snapshot_json->'content'->'episodes') = 'array' AND pp.snapshot_json->'content'->'episodes' <> '[]'::jsonb THEN '[{}]'::jsonb ELSE '[]'::jsonb END";
            $fm->select('p.id', 'p.canonical_path', 'p.content_item_id', 'p.source_page_id', 'pp.activated_at', 'a.text_snapshot as assigned_keyword', 'a.keyword_id as assigned_keyword_id')
                ->selectRaw("jsonb_build_object('content', jsonb_build_object(".implode(', ', $fields)."), 'page', pp.snapshot_json->'page',
                    'category', pp.snapshot_json->'category', 'routing', pp.snapshot_json->'routing', 'fanmulu', pp.snapshot_json->'fanmulu') AS snapshot_json");
            $fanmuluMovies = app(CatalogPagination::class)->paginate($fm, 24, (int) $site->id, 'p.id', 'pp.activated_at')->withQueryString();
            $fanmuluResults = $fanmuluMovies->total() > 0;
        }
        $movies = $fanmuluResults ? $fanmuluMovies : ($onDemand
            ? app(OnDemandCatalog::class)->paginate((int) $site->id, $category === null ? null : (int) $category, $request->only(['q', 'genre', 'area', 'year', 'language']))->withQueryString()
            : app(CatalogPagination::class)->paginate($q, 24, (int) $site->id, 'pages.id', 'page_publications.activated_at')->withQueryString());

        if (! $onDemand || $fanmuluResults) {
            $cards = $movies->getCollection()->map(function ($movie): array {
                $stored = Json::decode($movie->snapshot_json);

                return ['page_id' => (int) $movie->id, 'path' => $movie->canonical_path, 'content' => $stored['content'] ?? [],
                    'content_revision_id' => $stored['content_revision_id'] ?? null, 'fanmulu' => $stored['fanmulu'] ?? null];
            })->all();
            $cards = app(LiveMovieContent::class)->overlayEntries((int) $site->id, $cards);
            foreach ($movies->getCollection() as $index => $movie) {
                $stored = Json::decode($movie->snapshot_json);
                $stored['content'] = $cards[$index]['content'];
                $movie->snapshot_json = Json::encode($stored);
            }
        }

        $liveSnapshot = $live['snapshot'] ?? null;
        $theme = $liveSnapshot['theme'] ?? null;
        $liveSite = $liveSnapshot['site'] ?? ['id' => $site->id, 'name' => $site->name, 'locale' => $site->locale, 'base_url' => $site->base_url];
        $path = $view === 'list' && $category !== null ? $categoryPath : SitePresentationService::endpointPath($routing, $view);
        if ($scope !== null) {
            $path = FanmuluNavigation::scopePath($scope, $view, $category !== null ? $canonicalKey : null);
            $movies->withPath($path);
        }
        $canonicalUrl = rtrim($liveSite['base_url'], '/').$path;
        $urls = array_combine(SitePresentationService::TYPES, array_map(fn ($type) => SitePresentationService::endpointPath($routing, $type), SitePresentationService::TYPES));
        $query = is_string($request->input('q')) ? $request->input('q') : '';
        $hasQuery = $theme ? $request->filled('q') : (bool) $query;
        $snapshot = ['site' => $liveSite, 'page' => ['type' => $view, 'path' => $path, 'noindex' => $view === 'search', 'category_id' => $category],
            'canonical_url' => $canonicalUrl, 'routing' => $routing,
            'category' => array_replace($categorySnapshot, ['id' => $category ?? '', 'path' => $path]), 'content' => [], 'aggregate' => ['entries' => []], 'assignments' => [], 'managed_body' => '', 'related_links' => [], 'theme' => $theme,
            'slots' => ['title' => ($hasQuery ? $query.' - ' : '').$liveSite['name'].($theme ? '' : ' · 影片资料库'), 'description' => $theme ? '电影、剧集与创作者资料索引。' : '电影、剧集与创作者资料索引',
                'h1' => $hasQuery ? '搜索：'.$query : ($theme ? $liveSite['name'] : ($view === 'list' ? '影片目录' : '发现影片，走近故事'))]];
        if ($scope !== null) {
            $snapshot['fanmulu'] = $scope['fanmulu'];
            $snapshot['assignments'] = $scope['assignments'] ?? [];
            $snapshot['content'] = $scope['content'] ?? [];
            $snapshot['managed_body'] = $scope['managed_body'] ?? '';
            $snapshot['page'] = array_replace($scope['page'], $snapshot['page']);
            $snapshot['page']['noindex'] = $view === 'search' || ! empty($scope['page']['noindex']);
            $snapshot['slots'] = $scope['slots'] ?? [];
        }
        $hasSeoBundle = isset($liveSnapshot['seo_template_bundle']);
        if ($hasSeoBundle) {
            $snapshot['seo_template_bundle'] = $liveSnapshot['seo_template_bundle'];
        }
        if (isset($liveSnapshot['custom_variables'])) {
            $snapshot['custom_variables'] = $liveSnapshot['custom_variables'];
        }
        if (isset($liveSnapshot['theme_catalog'])) {
            $snapshot['theme_catalog'] = $liveSnapshot['theme_catalog'];
        }
        $snapshot['slots'] = SeoTemplateBundle::dynamic($snapshot, $view, ['q' => $query, 'category' => $snapshot['category']]);
        if ($scope !== null) {
            $snapshot['slots'] = FanmuluPresentation::slots($snapshot, $snapshot['slots']);
        }
        if ($theme || $scope !== null) {
            $movieRows = $movies->getCollection();
            $homeSectionsSampled = false;
            if ($view === 'home' && $site->content_type === 'movie') {
                $homeRows = $movieRows->all();
                $includedIds = array_fill_keys(array_map(fn ($movie): int => (int) $movie->id, $homeRows), true);
                $rootIds = [];
                foreach ($snapshot['theme_catalog']['categories'] ?? [] as $catalogCategory) {
                    $id = (int) ($catalogCategory['id'] ?? 0);
                    if ($id > 0 && $id === (int) ($catalogCategory['root_id'] ?? 0)) {
                        $rootIds[$id] = $id;
                    }
                }
                $catalog = app(OnDemandCatalog::class);
                $sectionLimit = MovieThemeRenderer::homeSectionLimit($theme ?? []);
                foreach ($rootIds as $rootId) {
                    foreach ($catalog->sample((int) $site->id, $rootId, $sectionLimit, $request->only(['q', 'genre', 'area', 'year', 'language']), $onDemand) as $movie) {
                        if (! isset($includedIds[(int) $movie->id])) {
                            $homeRows[] = $movie;
                            $includedIds[(int) $movie->id] = true;
                        }
                    }
                }
                $movieRows = collect($homeRows);
                $homeSectionsSampled = $rootIds !== [];
            }
            $entries = $movieRows->map(function ($movie) use ($fanmuluResults) {
                $snapshot = json_decode($movie->snapshot_json, true);
                $key = $snapshot['page']['route_key'] ?? SitePresentationService::keyFromPath($movie->canonical_path, SitePresentationService::profile($snapshot['routing'] ?? null), 'detail');

                $entry = ['page_id' => (int) $movie->id, 'content_item_id' => $movie->content_item_id ?? null,
                    'path' => $movie->canonical_path, 'content' => $snapshot['content'] ?? [], 'category' => $snapshot['category'] ?? [], 'route_key' => $key,
                    'play_path' => ! empty($snapshot['content']['episodes']) ? SitePresentationService::playPath(SitePresentationService::profile($snapshot['routing'] ?? null), $key, 1) : ''];
                if ($fanmuluResults) {
                    $entry['fanmulu_link'] = ['path' => $movie->canonical_path, 'text' => $movie->assigned_keyword,
                        'keyword_id' => (int) $movie->assigned_keyword_id, 'source_page_id' => (int) $movie->source_page_id];
                }

                return $entry;
            })->all();
            if ($scope !== null && ! $fanmuluResults) {
                $entries = app(FanmuluNavigation::class)->prepareEntries($snapshot, $entries);
            }
            $snapshot['aggregate'] = ['entries' => $entries];
            if ($homeSectionsSampled) {
                $snapshot['aggregate']['home_sections_sampled'] = true;
            }
            if ($scope !== null && ! $fanmuluResults && $request->isMethod('GET')) {
                app(FanmuluNavigation::class)->remember($snapshot, (int) $scope['_fanmulu_publication_id'], $entries);
            }
            $pagination = [];
            foreach (array_unique([1, ...range(max(1, $movies->currentPage() - 2), min($movies->lastPage(), $movies->currentPage() + 2)), $movies->lastPage()]) as $number) {
                $pagination[] = ['url' => $movies->url($number), 'label' => (string) $number, 'current' => $number === $movies->currentPage()];
            }
            $searchContext = ['filters' => $request->only(['q', 'genre', 'area', 'year', 'language']), 'query' => $request->input('q', ''), 'total' => $movies->total(), 'next_url' => $movies->nextPageUrl() ?? '',
                'previous_url' => $movies->previousPageUrl() ?? '', 'pagination' => $pagination];
            if ($scope !== null && $view === 'search' && $request->filled('q')) {
                $searchContext['inventory'] = $fanmuluResults ? 'fanmulu' : 'source_metadata';
                $searchContext['message'] = $fanmuluResults ? '已创建专题的固定关键词匹配。' : '未找到已创建的关键词专题，以下按影片资料搜索。';
            }
            if ($scope !== null) {
                $snapshot['search'] = $searchContext;
            }
            $html = $theme
                ? app(MovieThemeRenderer::class)->html($snapshot, $view, $searchContext)
                : app(Renderer::class)->html($snapshot);
            if (isset($searchContext['message'])) {
                $notice = '<p class="fanmulu-search-inventory" data-search-inventory="'.$searchContext['inventory'].'">'.e($searchContext['message']).'</p>';
                $position = stripos($html, '</h1>');
                $html = $position === false ? $notice.$html : substr_replace($html, $notice, $position + 5, 0);
            }

            return $this->htmlResponse($request, $html, noindex: ! empty($snapshot['page']['noindex']), theme: $theme ?? [], site: $scope === null ? $site : null, advertisingArea: $scope === null ? 'regular' : 'fanmulu');
        }

        $site = (object) $liveSite;
        $slots = $snapshot['slots'];

        return $this->htmlResponse($request, view('public.gallery', compact('site', 'movies', 'view', 'urls', 'canonicalUrl', 'slots', 'hasSeoBundle'))->render(), noindex: $view === 'search', site: $this->site($request), advertisingArea: $scope === null ? 'regular' : 'fanmulu');
    }

    private function play(Request $request, object $site, string $path)
    {
        if (! preg_match('~^(/.+)/([^/]+)/([1-9][0-9]{0,5})(?:\.[a-z][a-z0-9]{0,11})?$~D', $path, $match) || (int) $match[3] > 100000) {
            return null;
        }
        if (! preg_match('#^[A-Za-z0-9_.~%-]+$#D', $match[2]) || preg_match('/%(?![A-Fa-f0-9]{2})/', $match[2])) {
            return null;
        }
        $profile = SitePresentationService::profile($site);
        $detailPath = SitePresentationService::detailPath($profile, $match[2]);
        $candidate = null;
        if ($path === SitePresentationService::playPath($profile, $match[2], (int) $match[3])) {
            $candidate = DB::table('pages')->where('site_id', $site->id)->where('canonical_path', $detailPath)->where('page_type', 'movie')->first();
            if ($candidate) {
                abort_if(in_array($candidate->status, ['archived', 'gone'], true), 410);
                if (! $candidate->current_publication_id && $candidate->page_kind === 'regular' && app(SiteDeliverySettings::class)->enabled((int) $site->id)) {
                    $record = Json::decode(DB::table('content_revisions')->where('id', $candidate->working_content_revision_id)->value('payload_json'));
                    abort_unless(isset($record['episodes'][(int) $match[3] - 1]), 404);
                    $candidate = $this->firstPublication($request, $site, $candidate);
                }
            }
        }
        if ($candidate && $candidate->status === 'live' && $candidate->current_publication_id) {
            $query = DB::table('page_publications')->where('id', $candidate->current_publication_id)->where('page_id', $candidate->id)
                ->where('site_id', $site->id)->get(['page_id', 'snapshot_json']);
        } else {
            $query = collect();
            if (! $candidate && preg_match('/^fm-([1-9][0-9]{0,18})$/D', $match[2], $fanmuluMatch)
                && ($fanmuluId = filter_var($fanmuluMatch[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) !== false) {
                $query = DB::table('pages as p')->join('page_publications as pp', function ($join): void {
                    $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
                })->where('p.id', $fanmuluId)->where('p.site_id', $site->id)->where('p.page_kind', 'fanmulu')
                    ->where('p.page_type', 'movie')->where('p.status', 'live')->get(['pp.page_id', 'pp.snapshot_json']);
            }
            if ($query->isEmpty()) {
                $query = DB::table('pages as p')->join('page_publications as pp', 'pp.id', '=', 'p.current_publication_id')
                    ->where('p.site_id', $site->id)->where('pp.site_id', $site->id)->where('p.status', 'live')->where('p.page_type', 'movie')
                    ->where(function ($query) use ($match, $site) {
                        $query->whereRaw($this->routeKeySql('pp').' = ?', [$match[2]])
                            ->orWhereExists(function ($history) use ($match, $site) {
                                $history->selectRaw('1')->from('page_publications as historical')->whereColumn('historical.page_id', 'p.id')
                                    ->where('historical.site_id', $site->id)
                                    ->whereRaw($this->routeKeySql('historical').' = ?', [$match[2]]);
                            });
                    })->get(['pp.page_id', 'pp.snapshot_json']);
            }
        }
        foreach ($query as $publication) {
            $snapshot = app(LiveMovieContent::class)->overlay(app(SnapshotStorage::class)->decode($publication->snapshot_json));
            $profile = SitePresentationService::profile($snapshot['routing'] ?? null);
            $key = $snapshot['page']['route_key'] ?? SitePresentationService::keyFromPath($snapshot['page']['path'], $profile, 'detail');
            $expectedPath = SitePresentationService::playPath($profile, $key, (int) $match[3]);
            if ($path !== $expectedPath) {
                foreach (DB::table('page_publications as history')->where('site_id', $site->id)->where('page_id', $publication->page_id)
                    ->whereRaw($this->routeKeySql('history').' = ?', [$match[2]])->get(['snapshot_json']) as $historical) {
                    $old = Json::decode($historical->snapshot_json);
                    $oldProfile = SitePresentationService::profile($old['routing'] ?? null);
                    $oldKey = $old['page']['route_key'] ?? SitePresentationService::keyFromPath($old['page']['path'], $oldProfile, 'detail');
                    if ($path === SitePresentationService::playPath($oldProfile, $oldKey, (int) $match[3])) {
                        abort_unless(isset($old['content']['episodes'][(int) $match[3] - 1], $snapshot['content']['episodes'][(int) $match[3] - 1]), 404);

                        return $this->publicRedirect($request, FanmuluNavigation::enabled($snapshot)
                            ? FanmuluNavigation::scopePath($snapshot, 'play', (string) $match[3]) : $expectedPath);
                    }
                }

                continue;
            }

            if (FanmuluNavigation::enabled($snapshot)) {
                $episodes = $snapshot['content']['episodes'] ?? [];
                abort_unless(is_array($episodes) && array_is_list($episodes)
                    && isset($episodes[(int) $match[3] - 1]) && is_array($episodes[(int) $match[3] - 1]), 404);

                return $this->publicRedirect($request, FanmuluNavigation::scopePath($snapshot, 'play', (string) $match[3]));
            }

            return $this->playSnapshot($request, $snapshot, (int) $match[3], $path);
        }

        return null;
    }

    private function playSnapshot(Request $request, array $snapshot, int $index, string $path)
    {
        $snapshot = FanmuluDetailArtifact::forRefresh($snapshot);
        $snapshot = app(LiveMovieContent::class)->overlay($snapshot);
        $snapshot = app(SiteAppearance::class)->overlay($snapshot);
        $snapshot = app(LiveMovieRecommendations::class)->overlay($snapshot);
        $snapshot = app(LiveMovieComments::class)->overlay($snapshot);
        if ($request->isMethod('GET') && isset($snapshot['_fanmulu_publication_id'])) {
            app(LiveMovieRecommendations::class)->remember($snapshot, (int) $snapshot['_fanmulu_publication_id']);
        }
        $displayContent = FanmuluPresentation::content($snapshot);
        $profile = SitePresentationService::profile($snapshot['routing'] ?? null);
        $key = $snapshot['page']['route_key'] ?? SitePresentationService::keyFromPath($snapshot['page']['path'], $profile, 'detail');
        $episodes = $snapshot['content']['episodes'] ?? [];
        abort_unless(is_array($episodes) && array_is_list($episodes) && isset($episodes[$index - 1]) && is_array($episodes[$index - 1]), 404);
        $scoped = FanmuluNavigation::enabled($snapshot);
        if ($scoped) {
            $path = FanmuluNavigation::scopePath($snapshot, 'play', (string) $index);
        }
        $episode = $displayContent['episodes'][$index - 1] ?? $episodes[$index - 1];
        $episode['title'] = is_scalar($episode['title'] ?? null) ? (string) $episode['title'] : '第'.$index.'集';
        try {
            $sourceUrl = CanonicalRecord::dataUrl($episode['source_link'] ?? $episode['source_url'] ?? null) ?? '';
        } catch (\InvalidArgumentException) {
            $sourceUrl = '';
        }
        $mode = $profile['player']['mode'];
        $canPlay = $sourceUrl !== '' && Html::safeUrl($sourceUrl);
        if ($mode === 'native') {
            $canPlay = $canPlay && in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_HOST)), $profile['player']['allowed_hosts'], true);
        }
        $play = ['index' => $index, 'episode' => $episode, 'play_url' => $path, 'source_url' => $canPlay ? $sourceUrl : '', 'mode' => $mode,
            'can_play' => $canPlay, 'message' => $canPlay ? '来源链接尚未在服务器验证。' : '此集没有已允许的来源链接。',
            'detail_url' => $scoped ? FanmuluNavigation::rootPath($snapshot) : $snapshot['page']['path'],
            'previous_url' => $index > 1 ? ($scoped ? FanmuluNavigation::scopePath($snapshot, 'play', (string) ($index - 1)) : SitePresentationService::playPath($profile, $key, $index - 1)) : '',
            'next_url' => $index < count($episodes) ? ($scoped ? FanmuluNavigation::scopePath($snapshot, 'play', (string) ($index + 1)) : SitePresentationService::playPath($profile, $key, $index + 1)) : ''];
        if ($scoped) {
            $play['navigation_url'] = FanmuluNavigation::rootPath($snapshot);
            $play['navigation_label'] = '返回详情';
            $play['noscript_message'] = '需要启用 JavaScript 才能使用 HLS 播放器。';
        }
        $snapshot['page'] = array_replace($snapshot['page'], ['type' => 'play', 'path' => $path, 'noindex' => true]);
        $snapshot['routing'] = $profile;
        $snapshot['canonical_url'] = rtrim($snapshot['site']['base_url'], '/').$path;
        $snapshot['slots'] = array_replace($snapshot['slots'] ?? [], ['title' => ($displayContent['title'] ?? '').' - '.($episode['title'] ?? '第'.$index.'集'),
            'h1' => ($displayContent['title'] ?? '').' · '.($episode['title'] ?? '第'.$index.'集')]);
        $snapshot['slots'] = SeoTemplateBundle::dynamic($snapshot, 'play', ['episode' => array_replace($episode, ['index' => $index]), 'category' => $snapshot['category'] ?? []]);
        if ($scoped) {
            $snapshot['slots'] = FanmuluPresentation::slots($snapshot, $snapshot['slots']);
        }
        $snapshot['play'] = $play;
        $html = ! empty($snapshot['theme']) ? app(MovieThemeRenderer::class)->html($snapshot, 'play')
            : view('public.play', ['snapshot' => array_replace($snapshot, ['content' => $displayContent]), 'play' => $play])->render();
        $player = app(MoviePlayer::class)->attach($html, $play, $snapshot);
        if ($scoped) {
            $player['html'] = FanmuluPresentation::navigationHtml($player['html'], $snapshot);
        }

        return $this->htmlResponse($request, $player['html'], noindex: true, theme: $snapshot['theme'] ?? [], hasPlayer: $player['enabled'], advertisingArea: $scoped ? 'fanmulu' : 'regular');
    }

    private function routeKeySql(string $alias): string
    {
        $path = "regexp_replace({$alias}.snapshot_json->'page'->>'path', '^.*/', '')";
        $suffix = "COALESCE({$alias}.snapshot_json->'routing'->'suffixes'->>'detail', '')";

        return "COALESCE({$alias}.snapshot_json->'page'->>'route_key', CASE WHEN {$suffix} <> '' AND right({$path}, length({$suffix})) = {$suffix}"
            ." THEN left({$path}, length({$path}) - length({$suffix})) ELSE {$path} END)";
    }

    public function themeAsset(Request $request, string $hash)
    {
        $asset = DB::table('theme_assets')->where('hash', $hash)->first();
        abort_unless($asset, 404);
        $data = base64_decode($asset->data_base64, true);
        abort_unless($data !== false && hash('sha256', $data) === $hash, 500);
        $response = response($data)->header('Content-Type', $asset->mime)->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'public, max-age=31536000, immutable')->header('ETag', '"'.$hash.'"');
        $response->isNotModified($request);

        return $response;
    }

    public function themeBundleAsset(Request $request, string $bundle, string $path)
    {
        abort_unless(ReviewedThemeRuntime::safeAssetName($path), 404);
        $version = DB::table('movie_theme_versions')->where('hash', $bundle)->whereNotNull('runtime_json')->first(['assets_json', 'runtime_json']);
        abort_unless($version && Json::decode($version->runtime_json) !== [], 404);
        $entry = Json::decode($version->assets_json)[$path] ?? null;
        abort_unless(is_array($entry) && preg_match('/^[a-f0-9]{64}$/D', $entry['hash'] ?? ''), 404);
        $asset = DB::table('theme_assets')->where('hash', $entry['hash'])->first();
        abort_unless($asset, 404);
        $data = base64_decode($asset->data_base64, true);
        abort_unless($data !== false && hash_equals($entry['hash'], hash('sha256', $data)), 500);
        $response = response($data)->header('Content-Type', $entry['mime'])->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'public, max-age=31536000, immutable')->header('Access-Control-Allow-Origin', '*')->header('ETag', '"'.$entry['hash'].'"');
        if (str_starts_with($path, 'originals/')) {
            $response->header('Content-Disposition', 'attachment');
        }
        $response->isNotModified($request);

        return $response;
    }

    public function search(Request $request)
    {
        $site = $this->site($request);
        abort_unless($site, 404);

        return $this->show($request);
    }

    public function robots(Request $request)
    {
        $site = $this->site($request);
        if (! $site) {
            return response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $live = app(SitePresentationService::class)->publicLive($site->id);
        $profile = $live['routing'] ?? SitePresentationService::defaults();
        $base = $live['snapshot']['site']['base_url'] ?? $site->base_url;
        $body = $site->robots_index ? "User-agent: *\nAllow: /\nDisallow: ".SitePresentationService::endpointPath($profile, 'search')."\nDisallow: ".$profile['routes']['play']."\nSitemap: ".rtrim($base, '/')."/sitemap.xml\n" : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(Request $request, ?int $chunk = null)
    {
        $site = $this->site($request);
        abort_unless($site, 404);
        $size = min(50000, max(1, (int) config('tram.sitemap_chunk_size', 50000)));
        $live = app(SitePresentationService::class)->publicLive($site->id);
        $base = $live['snapshot']['site']['base_url'] ?? $site->base_url;
        $query = DB::table('pages')->join('page_publications', 'page_publications.id', '=', 'pages.current_publication_id')->where('pages.site_id', $site->id)->where('pages.status', 'live')->whereRaw("COALESCE((page_publications.snapshot_json->'page'->>'noindex')::boolean, false) = false");
        $total = (clone $query)->count();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $escape = fn ($value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        if ($chunk === null && $total > $size) {
            $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            for ($i = 1; $i <= (int) ceil($total / $size); $i++) {
                $xml .= '<sitemap><loc>'.$escape(rtrim($base, '/').'/sitemap-'.$i.'.xml').'</loc></sitemap>';
            }
            $xml .= '</sitemapindex>';
        } else {
            $chunk ??= 1;
            abort_unless($chunk >= 1 && ($total === 0 && $chunk === 1 || $chunk <= (int) ceil($total / $size)), 404);
            $pages = $query->leftJoin('content_revisions as sitemap_revision', function ($join): void {
                $join->on('sitemap_revision.id', '=', 'pages.serving_content_revision_id')
                    ->on('sitemap_revision.content_item_id', '=', 'pages.content_item_id');
            })->selectRaw('GREATEST(page_publications.activated_at, sitemap_revision.created_at) AS activated_at')
                ->selectRaw("page_publications.snapshot_json->>'canonical_url' AS canonical_url")
                ->orderBy('pages.id')->offset(($chunk - 1) * $size)->limit($size)->get();
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($pages as $page) {
                $xml .= '<url><loc>'.$escape($page->canonical_url).'</loc><lastmod>'.date('c', strtotime($page->activated_at)).'</lastmod></url>';
            }
            $xml .= '</urlset>';
        }

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
