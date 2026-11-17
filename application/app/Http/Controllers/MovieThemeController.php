<?php

namespace App\Http\Controllers;

use App\Domain\Publishing\SharedConfigApply;
use App\Domain\Support\Access;
use App\Domain\Support\AdminSiteLabels;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeCompiler;
use App\Domain\Themes\MovieThemeService;
use App\Domain\Themes\ReviewedThemeRuntime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MovieThemeController extends Controller
{
    private function context(Request $request, bool $withPreview = false): array
    {
        $management = ['globalManagement' => true, 'managementLabel' => 'Kho giao diện phim dùng chung'];
        if (! $withPreview) {
            return ['sites' => collect(), 'site' => null, 'scope' => ''] + $management;
        }
        $request->validate(['site_id' => 'nullable|integer|min:1']);
        $sites = CatalogAvailability::filter(DB::table('sites'), 'sites')->when(! $request->user()->is_global_admin, fn ($q) => $q->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))->orderBy('id')->get();
        $sites = app(AdminSiteLabels::class)->sortSites($sites);
        $preferred = $sites->first(fn ($website) => $website->status === 'active' && $website->content_type === 'movie')
            ?? $sites->firstWhere('status', 'active') ?? $sites->first();
        $siteId = $request->integer('site_id') ?: $preferred?->id;
        $site = $siteId ? CatalogAvailability::filter(DB::table('sites'), 'sites')->find($siteId) : null;
        if ($siteId) {
            abort_unless($site, 404);
            Access::site($request->user()->id, (int) $siteId);
        }

        return ['sites' => $sites, 'site' => $site, 'scope' => $site ? '?site_id='.$site->id : ''] + $management;
    }

    private function editorUrl(Request $request, int $id): string
    {
        return '/admin/themes/'.$id.($request->integer('site_id') > 0 ? '?site_id='.$request->integer('site_id') : '');
    }

    private function write(Request $request): void
    {
        abort_unless($request->user()->is_global_admin, 403, 'Chỉ quản trị toàn hệ thống được sửa kho giao diện.');
    }

    private function variables(array $assets = []): array
    {
        $groups = [];
        foreach (MovieThemeCompiler::TOKENS as $token) {
            $group = match (strtok($token, '.')) {
                'site' => 'Website', 'seo', 'kw' => 'SEO và keyword', 'content' => 'Dữ liệu phim',
                'movie' => 'Một phim trong danh sách', 'episode' => 'Một tập phim', 'link' => 'Link liên quan', 'friend_link' => 'Liên kết website cùng hệ thống',
                'search' => 'Tìm kiếm và phân trang', 'player' => 'Trang phát phim', default => 'Trang và URL',
            };
            $groups[$group]['{{'.$token.'}}'] = $token;
        }
        $groups['Vòng lặp và điều kiện'] = [
            '{{#each movies}}{{movie.title}}{{else}}暂无影片{{/each}}' => 'Danh sách phim, có nhánh rỗng',
            '{{#each episodes}}{{episode.title}}{{/each}}' => 'Danh sách tập phim',
            '{{#each related_links}}{{link.text}}{{/each}}' => 'Danh sách liên kết liên quan',
            '{{#each friend_links}}<a href="{{friend_link.url}}">{{friend_link.text}}</a>{{/each}}' => '友情链接 · tối đa 10 domain chính đang hoạt động, không gồm website hiện tại',
            '{{#if content.poster_url}}<img src="{{content.poster_url}}" alt="{{content.title}}">{{else}}暂无海报{{/if}}' => 'Poster có dữ liệu hoặc nhánh thay thế',
        ];
        foreach ($assets as $name => $asset) {
            $groups['Asset trong gói giao diện']['{{asset.'.$name.'}}'] = $name.' — '.($asset['mime'] ?? 'asset');
        }

        return $groups;
    }

    public function index(Request $request, MovieThemeService $service)
    {
        $context = $this->context($request);
        $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,archived']);
        $status = $request->query->has('status') ? (string) $request->query('status') : 'active';
        $themes = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->leftJoin('movie_theme_versions', 'movie_theme_versions.id', '=', 'movie_themes.current_version_id')->select('movie_themes.*', 'movie_theme_versions.version_no')->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('movie_themes.name', 'ilike', '%'.$request->input('q').'%')->orWhere('slug', 'ilike', '%'.$request->input('q').'%')))->when($status !== '', fn ($q) => $q->where('movie_themes.status', $status))->orderBy('movie_themes.id')->paginate(12);

        $folders = $service->folderInventory($request->user()->id);
        $folderVersions = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->whereIn('id', array_filter(array_column($folders, 'installed_theme_id')))->pluck('row_version', 'id');
        $folders = array_values(array_filter($folders, fn ($folder) => $folder['installed_theme_id'] === null || $folderVersions->has($folder['installed_theme_id'])));
        $folders = array_map(fn ($folder) => $folder + ['installed_row_version' => $folderVersions[$folder['installed_theme_id']] ?? null], $folders);

        return view('admin.themes.index', $context + compact('themes', 'folders', 'status'));
    }

    public function create(Request $request)
    {
        $this->write($request);
        $views = ['home' => '<section><h1>{{seo.h1}}</h1>{{#each movies}}<article><a href="{{movie.path}}">{{movie.title}}</a></article>{{else}}<p>暂无影片</p>{{/each}}</section>', 'list' => '<section><h1>{{seo.h1}}</h1>{{#each movies}}<article><a href="{{movie.path}}">{{movie.title}}</a></article>{{else}}<p>暂无影片</p>{{/each}}</section>', 'detail' => '<article><h1>{{seo.h1}}</h1><p>{{content.genre}} · {{content.year}}</p>{{#if content.poster_url}}<img src="{{content.poster_url}}" alt="{{content.title}}">{{/if}}<section>{{content.body_html}}</section></article>', 'play' => '<section><h1>{{seo.h1}}</h1><p>{{content.title}}</p>{{#each episodes}}<p>{{episode.title}}</p>{{else}}<p>暂无播放信息</p>{{/each}}</section>', 'search' => '<section><h1>搜索 {{search.query}}</h1><p>{{search.total}} 个结果</p>{{#each movies}}<a href="{{movie.path}}">{{movie.title}}</a>{{else}}<p>没有找到影片</p>{{/each}}</section>'];
        $css = 'body{font:16px/1.7 system-ui,sans-serif;background:#f4f6f8;color:#21343b}main{max-width:1100px;margin:auto;padding:24px}a{color:#057c79}img{max-width:100%;height:auto}article{padding:20px;background:white;border-radius:12px;margin:18px 0}';
        $theme = $version = null;
        $variableGroups = $this->variables();
        $viewLabels = $this->viewLabels($views);
        $folder = null;
        $assets = [];

        return view('admin.themes.editor', $this->context($request) + compact('theme', 'version', 'views', 'css', 'variableGroups', 'viewLabels', 'folder', 'assets') + ['affectedSiteCount' => 0, 'affectedPageCount' => 0]);
    }

    public function show(Request $request, int $id, MovieThemeService $service)
    {
        $context = $this->context($request, true);
        $theme = $service->theme($id);
        $request->validate(['version_id' => 'nullable|integer', 'page_id' => 'nullable|integer', 'view' => 'nullable|in:home,list,category,detail,play,search']);
        $versions = DB::table('movie_theme_versions')->where('theme_id', $id)->orderByDesc('version_no')->get();
        $versionId = $request->integer('version_id') ?: $theme->current_version_id;
        $version = $versions->firstWhere('id', $versionId);
        abort_unless($version, 404);
        $views = Json::decode($version->views_json);
        $css = $version->css;
        $pages = $context['site'] ? DB::table('pages')->where('site_id', $context['site']->id)->whereNotIn('status', ['archived', 'gone'])->orderBy('id')->get(['id', 'canonical_path']) : collect();
        if ($request->filled('page_id')) {
            abort_unless($pages->contains('id', $request->integer('page_id')), 403);
        }
        $params = ['site_id' => $context['site']?->id, 'version_id' => $version->id, 'page_id' => $request->integer('page_id') ?: null, 'view' => $request->input('view', 'detail')];
        $previewUrl = $context['site'] ? '/admin/themes/'.$id.'/preview?'.http_build_query(array_filter($params, fn ($v) => $v !== null)) : null;
        $assets = Json::decode($version->assets_json);
        $variableGroups = $this->variables($assets);
        $viewLabels = $this->viewLabels($views);
        $folder = collect($service->folderInventory($request->user()->id))->firstWhere('slug', $theme->slug);

        $counts = DB::table('pages as p')->join('sites as s', 's.id', '=', 'p.site_id')->where('p.status', 'live')->whereNotNull('p.current_publication_id')->where('s.status', 'active')
            ->whereRaw('COALESCE(p.theme_id, s.theme_id) = ?', [$id])
            ->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('s.id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))
            ->selectRaw('count(*) as pages, count(distinct s.id) as sites')->first();

        return view('admin.themes.editor', $context + compact('theme', 'versions', 'version', 'views', 'css', 'pages', 'previewUrl', 'variableGroups', 'viewLabels', 'folder', 'assets') + ['affectedSiteCount' => (int) $counts->sites, 'affectedPageCount' => (int) $counts->pages]);
    }

    private function viewLabels(array $views): array
    {
        return array_key_exists('list', $views) ? ['home' => 'Trang chủ', 'list' => 'Danh sách phim', 'detail' => 'Chi tiết phim', 'play' => 'Phát phim', 'search' => 'Tìm kiếm'] : ['home' => 'Trang chủ', 'category' => 'Danh mục (legacy)', 'detail' => 'Chi tiết phim', 'search' => 'Tìm kiếm'];
    }

    public function importFolder(Request $request, string $slug, MovieThemeService $service)
    {
        $this->write($request);
        $data = $request->validate(['theme_id' => 'nullable|integer|min:1', 'row_version' => 'nullable|required_with:theme_id|integer|min:1']);
        $theme = $service->importFolder($slug, $request->user()->id, $data['theme_id'] ?? null, $data['row_version'] ?? null);

        return redirect($this->editorUrl($request, (int) $theme->id))->with('status', 'Đã nhập folder thành phiên bản trong kho. Chọn trong cấu hình website và bấm Lưu & áp dụng.');
    }

    public function upgrade(Request $request, int $id, MovieThemeService $service)
    {
        $this->write($request);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $theme = $service->upgradeViews($id, $data['row_version'], $request->user()->id);

        return redirect($this->editorUrl($request, (int) $theme->id))->with('status', 'Đã tạo phiên bản năm trang, giữ HTML/CSS và assets cũ. Snapshot live vẫn giữ phiên bản đã xuất bản.');
    }

    public function save(Request $request, MovieThemeService $service, ?int $id = null)
    {
        $this->write($request);
        $request->validate(['views' => 'required|array']);
        $viewKeys = array_key_exists('list', $request->input('views')) || array_key_exists('play', $request->input('views')) ? ['home', 'list', 'detail', 'play', 'search'] : ['home', 'category', 'detail', 'search'];
        $rules = ['row_version' => $id ? 'required|integer|min:1' : 'nullable|integer', 'edit_version_id' => 'nullable|integer', 'slug' => 'required|string|min:2|max:80|regex:/^[a-z][a-z0-9_-]{1,79}$/', 'name' => 'required|string|max:200', 'description' => 'nullable|string|max:1000', 'views' => 'required|array:'.implode(',', $viewKeys), 'css' => 'nullable|string|max:200000', 'intent' => 'nullable|in:apply,draft'];
        foreach ($viewKeys as $key) {
            $rules['views.'.$key] = 'required|string|max:200000';
        }
        $data = $request->validate($rules);
        if ($id) {
            $data['id'] = $id;
            $versionId = $data['edit_version_id'] ?? DB::table('movie_themes')->where('id', $id)->value('current_version_id');
            $sourceVersion = DB::table('movie_theme_versions')->where('theme_id', $id)->where('id', $versionId)->first();
            abort_unless($sourceVersion, 404);
            $data['assets'] = Json::decode($sourceVersion->assets_json);
            $data['runtime'] = Json::decode($sourceVersion->runtime_json ?? null);
        }
        $theme = $service->save($data, $request->user()->id);

        $response = redirect($this->editorUrl($request, (int) $theme->id))->with('status', ($data['intent'] ?? 'draft') === 'apply'
            ? 'Đã lưu giao diện và yêu cầu áp dụng cho các trang đang phục vụ.'
            : 'Đã lưu phiên bản giao diện trong kho. Chưa yêu cầu áp dụng cho website.');
        if (($data['intent'] ?? 'draft') === 'apply') {
            $response->with('shared_apply_result', app(SharedConfigApply::class)->theme($theme->id, $request->user()->id));
        }

        return $response;
    }

    public function clone(Request $request, int $id, MovieThemeService $service)
    {
        $this->write($request);
        $data = $request->validate(['name' => 'required|string|max:200', 'slug' => 'required|string|min:2|max:80|regex:/^[a-z][a-z0-9_-]{1,79}$/']);
        $theme = $service->cloneTheme($id, $data['name'], $data['slug'], $request->user()->id);

        return redirect($this->editorUrl($request, (int) $theme->id))->with('status', 'Đã tạo bản sao giao diện độc lập.');
    }

    public function status(Request $request, int $id, MovieThemeService $service)
    {
        $this->write($request);
        $data = $request->validate(['status' => 'required|in:active,archived', 'row_version' => 'required|integer|min:1']);
        $service->setStatus($id, $data['status'], $data['row_version'], $request->user()->id);

        return back()->with('status', 'Đã cập nhật trạng thái thư viện. Snapshot đã xuất bản giữ nguyên.');
    }

    public function import(Request $request, MovieThemeService $service, ?int $id = null)
    {
        $this->write($request);
        $data = $request->validate(['file' => 'required|file|max:10240', 'row_version' => $id ? 'required|integer|min:1' : 'nullable|integer']);
        $theme = $service->importZip($request->file('file')->getRealPath(), $request->user()->id, $id, $data['row_version'] ?? null);

        return redirect($this->editorUrl($request, (int) $theme->id))->with('status', 'Đã kiểm tra và nhập gói giao diện. Chưa gán hoặc xuất bản website.');
    }

    public function export(Request $request, int $id, MovieThemeService $service)
    {
        $this->context($request);
        $request->validate(['version_id' => 'nullable|integer']);
        $versionId = $request->integer('version_id') ?: null;
        $path = $service->exportZip($id, $versionId, $request->user()->id);
        $theme = $service->theme($id);
        $version = $service->version($id, $versionId);

        return response()->download($path, $theme->slug.'-v'.$version->version_no.'.zip')->deleteFileAfterSend();
    }

    public function preview(Request $request, int $id, MovieThemeService $service)
    {
        $this->context($request, true);
        $data = $request->validate(['site_id' => 'required|integer', 'version_id' => 'nullable|integer', 'page_id' => 'nullable|integer', 'view' => 'required|in:home,list,category,detail,play,search']);
        $html = $service->preview($id, $data['version_id'] ?? null, $data['site_id'], $data['page_id'] ?? null, $data['view'], $request->user()->id);

        $version = $service->version($id, $data['version_id'] ?? null);
        $reviewed = ReviewedThemeRuntime::enabled(['runtime' => Json::decode($version->runtime_json ?? null)]);
        $csp = $reviewed
            ? "sandbox allow-scripts; default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; font-src 'self' data:; script-src 'self'; media-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'"
            : "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src 'self' https: data:; font-src 'self'; script-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'";

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', $csp);
    }
}
