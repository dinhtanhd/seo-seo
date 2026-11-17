<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\BaiduService;
use App\Domain\Ingestion\ContentFileParser;
use App\Domain\Ingestion\CrawlService;
use App\Domain\Ingestion\ImportService;
use App\Domain\Ingestion\SiteSourceBindingService;
use App\Domain\Keywords\KeywordImportService;
use App\Domain\Keywords\KeywordService;
use App\Domain\Keywords\SitePoolBindingService;
use App\Domain\Publishing\CatalogMetadata;
use App\Domain\Publishing\CatalogPagination;
use App\Domain\Publishing\OnDemandEligibility;
use App\Domain\Publishing\PageLifecycleService;
use App\Domain\Publishing\Planner;
use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\Publisher;
use App\Domain\Publishing\QuickApply;
use App\Domain\Publishing\SharedConfigApply;
use App\Domain\Rules\ConfigService;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Sites\MovieCategoryPresetService;
use App\Domain\Support\ActivityRecordService;
use App\Domain\Support\AdminSiteLabels;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Support\MetadataFilterForm;
use App\Domain\Support\RuleAdminSummary;
use App\Domain\Support\RuleConfigurationForm;
use App\Domain\Support\SiteDirectorySummary;
use App\Domain\Support\SourceConfigurationForm;
use App\Domain\Support\TemplateConfigurationForm;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TramAdminController extends Controller
{
    private function siteQuery(Request $request): Builder
    {
        $q = CatalogAvailability::filter(DB::table('sites'), 'sites');
        if (! $request->user()->is_global_admin) {
            $q->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id'));
        }

        return $q;
    }

    private function sites(Request $request)
    {
        return app(AdminSiteLabels::class)->sortSites($this->siteQuery($request)->orderBy('id')->get());
    }

    private function site(Request $request, ?int $id = null, string $permission = 'read')
    {
        if ($id === null) {
            if ($request->has('site_id')) {
                $id = (int) $request->input('site_id');
            } else {
                $sites = $this->sites($request);
                $preferred = $sites->first(fn ($candidate) => $candidate->status === 'active' && $candidate->content_type === 'movie') ?? $sites->firstWhere('status', 'active') ?? $sites->first();
                $id = (int) $preferred?->id;
            }
        }
        if (! $id) {
            return null;
        }
        $site = DB::table('sites')->find($id);
        abort_unless($site, 404);
        if (! $request->user()->is_global_admin) {
            $role = DB::table('site_memberships')->where('user_id', $request->user()->id)->where('site_id', $id)->value('role');
            $allowed = $permission === 'owner' ? ['owner'] : ($permission === 'write' ? ['owner', 'operator'] : ['owner', 'operator', 'viewer']);
            abort_unless(in_array($role, $allowed, true), 403, 'Bạn không có quyền thao tác website này.');
        }

        abort_if(($site->deleted_at ?? null) !== null, 410, 'Website đã được xóa. Khôi phục website trước khi thao tác.');

        return $site;
    }

    private function requireSite(Request $request, ?int $id = null, string $permission = 'read')
    {
        $site = $this->site($request, $id, $permission);
        abort_unless($site, 422, 'Hãy tạo hoặc chọn một website.');

        return $site;
    }

    private function view(Request $request, string $name, array $data = [])
    {
        $labels = ['keywords' => 'Kho keyword dùng chung', 'pools' => 'Pool & nhóm dùng chung', 'templates' => 'Template SEO dùng chung', 'sources' => 'Nguồn nội dung dùng chung', 'keyword-import' => 'Nhập keyword', 'usage' => 'Nơi sử dụng keyword', 'account' => 'Tài khoản quản trị'];
        if (isset($labels[$name])) {
            $data = array_replace($data, ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => $labels[$name]]);
        }
        $lists = ['dashboard' => 'Tổng quan domain', 'pages' => 'Trang & tác phẩm', 'rules' => 'Ngoại lệ SEO & keyword nâng cao', 'batches' => 'Lịch sử áp dụng', 'activity' => 'Lịch sử & vận hành', 'baidu' => 'Baidu & trạng thái index'];
        if (isset($lists[$name])) {
            $data += ['globalManagement' => true, 'managementLabel' => $lists[$name]];
        }
        $site = array_key_exists('site', $data) ? $data['site'] : $this->site($request);

        if (! array_key_exists('sites', $data)) {
            $data['sites'] = $this->sites($request);
        }

        return view('admin.'.$name, $data + ['site' => $site, 'scope' => $site ? '?site_id='.$site->id : '']);
    }

    private function pools(Request $request)
    {
        $q = DB::table('keyword_pools');
        if (! $request->user()->is_global_admin) {
            $ids = $this->sites($request)->pluck('id');
            $q->where(fn ($q) => $q->whereIn('owner_site_id', $ids)->orWhereIn('id', DB::table('keyword_bindings')->whereIn('site_id', $ids)->select('pool_id')));
        }

        return $q->orderBy('name')->get();
    }

    private function pool(Request $request, int $id, bool $write = false)
    {
        $pool = $this->pools($request)->firstWhere('id', $id);
        abort_unless($pool, 403);
        if ($write && ! $request->user()->is_global_admin) {
            abort_unless($pool->scope === 'site' && $pool->owner_site_id, 403, 'Kho dùng chung cần quản trị toàn hệ thống.');
            $this->requireSite($request, (int) $pool->owner_site_id, 'write');
        }

        return $pool;
    }

    private function poolWritePermissions(Request $request, Collection $pools): array
    {
        $writeSiteIds = DB::table('site_memberships')->where('user_id', $request->user()->id)->whereIn('role', ['owner', 'operator'])->pluck('site_id')->all();

        return $pools->mapWithKeys(fn ($pool) => [$pool->id => $request->user()->is_global_admin || ($pool->scope === 'site' && in_array($pool->owner_site_id, $writeSiteIds, true))])->all();
    }

    private function bump(int $siteId): void
    {
        DB::table('sites')->where('id', $siteId)->increment('config_epoch');
    }

    private function audit(Request $request, string $action, ?int $siteId = null, array $after = []): void
    {
        DB::table('audit_events')->insert(['actor_id' => $request->user()->id, 'action' => $action, 'site_id' => $siteId, 'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE), 'correlation_id' => (string) Str::uuid(), 'created_at' => now()]);
    }

    private function jsonInput(Request $request, string $key, mixed $default = []): mixed
    {
        $raw = $request->input($key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([$key => 'JSON chưa hợp lệ.']);
        }
    }

    public function dashboard(Request $request)
    {
        $request->validate(['site_id' => 'nullable|integer|min:1']);
        $sites = $this->sites($request);
        $site = $request->filled('site_id') ? $this->requireSite($request) : null;
        $scope = $site ? [$site->id] : $sites->where('status', '!=', 'archived')->pluck('id')->all();
        $pages = DB::table('pages')->whereIn('site_id', $scope);
        $stats = ['pages' => (clone $pages)->count(), 'live' => (clone $pages)->where('status', 'live')->whereNotNull('current_publication_id')->count(), 'draft' => (clone $pages)->where('status', 'draft')->count(), 'conflict' => DB::table('publish_batch_items')->whereIn('site_id', $scope)->where('status', 'capacity_conflict')->count(), 'waiting' => DB::table('publish_batch_items')->whereIn('site_id', $scope)->whereIn('status', ['pending', 'claimed', 'rendering', 'activating'])->count()];
        $recentPages = (clone $pages)->orderByDesc('updated_at')->limit(8)->get();
        $sourceRuns = DB::table('ingest_runs')->whereIn('connector_id', DB::table('source_site_bindings')->whereIn('site_id', $scope)->select('connector_id'))->orderByDesc('id')->limit(5)->get();

        return $this->view($request, 'dashboard', compact('stats', 'recentPages', 'sourceRuns', 'sites', 'site'));
    }

    public function websites(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'status' => 'nullable|in:active,paused,archived', 'content_type' => 'nullable|in:movie,novel', 'per_page' => 'nullable|integer|in:25,50,100', 'page' => 'nullable|integer|min:1']);
        $filters = ['q' => trim($data['q'] ?? ''), 'status' => $data['status'] ?? '', 'content_type' => $data['content_type'] ?? '', 'per_page' => (int) ($data['per_page'] ?? 25)];
        $query = $this->siteQuery($request);
        if ($filters['q'] !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('sites.name', 'ilike', $pattern)->orWhere('sites.base_url', 'ilike', $pattern)
                ->orWhereRaw('CAST(sites.id AS TEXT) ILIKE ?', [$pattern])->orWhereExists(fn ($domain) => $domain->selectRaw('1')->from('site_domains')
                ->whereColumn('site_domains.site_id', 'sites.id')->where('site_domains.host_ascii', 'ilike', $pattern)));
        }
        foreach (['status', 'content_type'] as $field) {
            if ($filters[$field] !== '') {
                $query->where('sites.'.$field, $filters[$field]);
            }
        }
        $primaryHost = DB::table('site_domains')->whereColumn('site_domains.site_id', 'sites.id')->where('is_primary', true)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('host_ascii')->orderBy('id')->limit(1)->select('host_ascii');
        $websites = $query->select(['sites.id', 'sites.name', 'sites.base_url', 'sites.locale', 'sites.status', 'sites.content_type', 'sites.row_version', 'sites.theme_id'])->selectSub($primaryHost, 'admin_primary_domain')
            ->orderBy('admin_primary_domain')->orderBy('sites.base_url')
            ->orderBy('sites.id')->paginate($filters['per_page'])->appends($filters);
        $visibleIds = $websites->pluck('id')->all();
        $websiteSummaries = app(SiteDirectorySummary::class)->forSites($websites->getCollection());
        $domains = DB::table('site_domains')->whereIn('site_id', $visibleIds)->orderByDesc('is_primary')->orderBy('host_ascii')->orderBy('id')->get()->groupBy('site_id');
        $ownerIds = $request->user()->is_global_admin ? [] : DB::table('site_memberships')->where('user_id', $request->user()->id)->where('role', 'owner')->whereIn('site_id', $visibleIds)->pluck('site_id')->all();
        $canManage = $websites->getCollection()->mapWithKeys(fn ($site) => [$site->id => $request->user()->is_global_admin || in_array($site->id, $ownerIds, true)])->all();

        return view('admin.sites', compact('websites', 'domains', 'canManage', 'filters', 'websiteSummaries') + ['websiteDeletionAvailable' => Schema::hasTable('site_delete_operations'), 'site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Quản lý nhiều website']);
    }

    public function createSite(Request $request): View
    {
        abort_unless($request->user()->is_global_admin, 403);

        return $this->siteEditor($request);
    }

    public function editSite(Request $request, int $id): View
    {
        return $this->siteEditor($request, $this->requireSite($request, $id));
    }

    private function siteEditor(Request $request, ?object $site = null): View
    {
        $data = $request->validate(['tab' => 'nullable|in:settings,members,categories,pools,sources']);
        $tab = $data['tab'] ?? 'settings';
        $domains = $site ? DB::table('site_domains')->where('site_id', $site->id)->orderByDesc('is_primary')->orderBy('host_ascii')->orderBy('id')->get() : collect();
        $members = $site ? DB::table('site_memberships')->join('users', 'users.id', '=', 'site_memberships.user_id')->where('site_id', $site->id)->select('site_memberships.*', 'users.name', 'users.email')->orderBy('users.name')->orderBy('users.id')->get() : collect();
        $categories = $site ? CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $site->id)->orderBy('name')->orderBy('id')->get() : collect();
        $movieCategoryPresetState = $site && $site->content_type === 'movie' && $tab === 'categories' ? app(MovieCategoryPresetService::class)->state($site->id) : null;
        $templates = CatalogAvailability::filter(DB::table('templates'), 'templates')->leftJoin('template_versions as tv', 'tv.id', '=', 'templates.current_version_id')
            ->where(fn ($q) => $q->where(fn ($active) => $active->where('templates.status', 'active')->where('tv.schema_version', 2))->orWhere('templates.id', $site?->template_id))
            ->orderBy('templates.name')->orderBy('templates.id')->get(['templates.*', 'tv.schema_version']);
        $themes = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $site?->theme_id))->orderBy('name')->orderBy('id')->get();
        $canEdit = $request->user()->is_global_admin || ($site && DB::table('site_memberships')->where('user_id', $request->user()->id)->where('site_id', $site->id)->where('role', 'owner')->exists());
        $sitePools = collect();
        $sitePoolGroups = collect();
        $websitePoolBindings = collect();
        $websiteKeywordLimits = collect();
        $websiteSourceBindings = collect();
        $availableSources = collect();
        if ($site && $tab === 'pools') {
            $sitePools = $this->pools($request)->filter(fn ($pool) => $pool->deleted_at === null && ($pool->scope === 'shared' || (int) $pool->owner_site_id === $site->id));
            $sitePoolGroups = DB::table('keyword_groups')->whereIn('pool_id', $sitePools->pluck('id'))->whereNull('deleted_at')->orderBy('name')->get();
            $websitePoolBindings = DB::table('keyword_bindings as b')->join('keyword_pools as p', 'p.id', '=', 'b.pool_id')->leftJoin('keyword_groups as g', 'g.id', '=', 'b.group_id')->where('b.site_id', $site->id)->select('b.*', 'p.name as pool_name', 'p.status as pool_status', 'p.deleted_at as pool_deleted_at', 'g.name as group_name')->orderByDesc('b.priority')->orderBy('b.id')->get();
            $websiteKeywordLimits = DB::table('site_keyword_limits as l')->join('keywords as k', 'k.id', '=', 'l.keyword_id')->where('l.site_id', $site->id)->select('l.*', 'k.original_text', 'k.default_max_active_pages')->orderBy('k.normalized_text')->get();
        }
        if ($site && $tab === 'sources') {
            $websiteSourceBindings = DB::table('source_site_bindings as b')->join('source_connectors as s', 's.id', '=', 'b.connector_id')->where('b.site_id', $site->id)->whereNull('b.deleted_at')->select('b.*', 's.name', 's.kind', 's.status as source_status')->orderBy('s.name')->orderBy('b.id')->get();
            $availableSources = DB::table('source_connectors')->whereNull('deleted_at')->whereIn('status', ['active', 'paused'])->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('id', $websiteSourceBindings->pluck('connector_id')))->orderBy('name')->get(['id', 'name', 'kind', 'status']);
        }

        return view('admin.site-edit', compact('site', 'domains', 'members', 'categories', 'templates', 'themes', 'canEdit', 'tab', 'sitePools', 'sitePoolGroups', 'websitePoolBindings', 'websiteKeywordLimits', 'websiteSourceBindings', 'availableSources', 'movieCategoryPresetState') + ['sites' => $site ? collect([$site]) : collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Cấu hình domain']);
    }

    public function siteGroups(Request $request): View
    {
        abort_unless($request->user()->is_global_admin, 403);
        $sites = $this->sites($request);
        $siteGroups = DB::table('site_groups')->orderBy('name')->orderBy('id')->get();
        $groupMembers = DB::table('site_group_members')->whereIn('group_id', $siteGroups->pluck('id'))->orderBy('site_id')->get()->groupBy('group_id');

        return view('admin.site-groups', compact('sites', 'siteGroups', 'groupMembers') + ['site' => null, 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Nhóm website']);
    }

    public function saveSite(Request $request)
    {
        $data = $request->validate(['intent' => 'nullable|in:apply,draft', 'id' => 'nullable|integer', 'row_version' => 'nullable|integer|required_with:id', 'template_id' => 'nullable|integer', 'theme_id' => 'nullable|integer', 'name' => 'required|string|max:200', 'locale' => 'required|string|max:16', 'domain' => 'required|string|max:253', 'base_url' => 'required|url:http,https|max:500', 'content_type' => 'required|in:movie,novel', 'status' => 'required|in:active,paused,archived']);
        if (! empty($data['id'])) {
            $this->requireSite($request, (int) $data['id'], 'owner');
        } else {
            abort_unless($request->user()->is_global_admin, 403);
        }
        $host = strtolower(rtrim(trim($data['domain']), '.'));
        if (function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $host)) {
            throw ValidationException::withMessages(['domain' => 'Nhập hostname hợp lệ, không kèm scheme, cổng hoặc đường dẫn.']);
        }
        if (strtolower(parse_url($data['base_url'], PHP_URL_HOST) ?? '') !== $host) {
            throw ValidationException::withMessages(['base_url' => 'Hostname canonical phải trùng domain.']);
        }
        if (! empty($data['template_id'])) {
            $currentTemplateId = ! empty($data['id']) ? DB::table('sites')->where('id', $data['id'])->value('template_id') : null;
            abort_unless(CatalogAvailability::filter(DB::table('templates'), 'templates')->where('id', $data['template_id'])->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $currentTemplateId))->exists(), 422);
        }
        if (! empty($data['theme_id'])) {
            $currentThemeId = ! empty($data['id']) ? DB::table('sites')->where('id', $data['id'])->value('theme_id') : null;
            abort_unless($data['content_type'] === 'movie' && CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where('id', $data['theme_id'])->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $currentThemeId))->exists(), 422, 'Giao diện phim phải đang active hoặc là giao diện hiện tại của website.');
        }
        $id = DB::transaction(function () use ($data, $host, $request) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $friendLinksChanged = empty($data['id']);
            $siteStatusChanged = false;
            if (! empty($data['id'])) {
                DB::table('sites')->where('id', $data['id'])->lockForUpdate()->first();
            }
            if (! empty($data['template_id'])) {
                $currentTemplateId = ! empty($data['id']) ? DB::table('sites')->where('id', $data['id'])->value('template_id') : null;
                abort_unless(CatalogAvailability::filter(DB::table('templates'), 'templates')->where('id', $data['template_id'])->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $currentTemplateId))->exists(), 422, 'Template đã archive; hãy chọn lại.');
            }
            if (! empty($data['theme_id'])) {
                $currentThemeId = ! empty($data['id']) ? DB::table('sites')->where('id', $data['id'])->value('theme_id') : null;
                abort_unless(CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where('id', $data['theme_id'])->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $currentThemeId))->exists(), 422, 'Giao diện đã archive; hãy chọn lại.');
            }
            $record = array_intersect_key($data, array_flip(['name', 'locale', 'base_url', 'content_type', 'template_id', 'theme_id']));
            $record['updated_at'] = now();
            if (! empty($data['id'])) {
                $id = (int) $data['id'];
                $current = DB::table('sites')->find($id);
                foreach (['name', 'base_url', 'content_type', 'status'] as $field) {
                    $friendLinksChanged = $friendLinksChanged || $data[$field] !== $current->$field;
                }
                if (isset($data['row_version']) && (int) $current->row_version !== (int) $data['row_version']) {
                    abort(409, 'Website đã thay đổi, hãy tải lại.');
                } if ($current->status !== $data['status']) {
                    $siteStatusChanged = true;
                    app(PageLifecycleService::class)->setSiteStatus($id, $data['status'], $request->user()->id);
                } DB::table('sites')->where('id', $id)->update($record + ['row_version' => DB::raw('row_version+1')]);
                $this->bump($id);
            } else {
                $id = DB::table('sites')->insertGetId($record + ['created_at' => now(), 'config_epoch' => 1, 'status' => $data['status']]);
                DB::table('site_memberships')->insert(['site_id' => $id, 'user_id' => $request->user()->id, 'role' => 'owner']);
                if ($data['content_type'] === 'movie') {
                    app(MovieCategoryPresetService::class)->initialize($id, $request->user()->id);
                }
                if (($data['content_type'] === 'movie' && ($data['intent'] ?? 'draft') === 'apply') || (! empty($data['template_id']) && (int) DB::table('template_versions')->where('id', CatalogAvailability::filter(DB::table('templates'), 'templates')->where('id', $data['template_id'])->value('current_version_id'))->value('schema_version') === 2)) {
                    DB::table('pages')->insert(['site_id' => $id, 'page_type' => 'home', 'canonical_path' => '/', 'input_snapshot_json' => Json::encode(['entries' => []]), 'input_hash' => Json::hash(['entries' => []])]);
                }
            }
            $existing = DB::table('site_domains')->where('host_ascii', $host)->first();
            if ($existing && (int) $existing->site_id !== $id) {
                throw ValidationException::withMessages(['domain' => 'Domain đã thuộc website khác.']);
            }
            $primaryDomain = DB::table('site_domains')->where('site_id', $id)->where('is_primary', true)->where('status', 'active')->first();
            $friendLinksChanged = $friendLinksChanged || ! $primaryDomain || $primaryDomain->host_ascii !== $host;
            if ($friendLinksChanged && ! $siteStatusChanged) {
                app(PublicHtmlCache::class)->invalidateAll();
            }
            app(PublicHtmlCache::class)->invalidateSite($id);
            DB::table('site_domains')->where('site_id', $id)->where('is_primary', true)->update(['is_primary' => false]);
            DB::table('site_domains')->updateOrInsert(['site_id' => $id, 'host_ascii' => $host], ['is_primary' => true, 'status' => 'active', 'created_at' => now()]);
            app(PublicHtmlCache::class)->scheduleRefresh($id);
            $this->audit($request, 'site.saved', $id, ['name' => $data['name'], 'domain' => $host]);

            return $id;
        });

        if (($data['intent'] ?? 'draft') === 'apply' && $data['status'] === 'active') {
            $ids = DB::table('pages')->where('site_id', $id)->whereNotIn('status', ['archived', 'gone'])->orderBy('id')->pluck('id')->all();
            if ($ids !== []) {
                $version = DB::table('sites')->where('id', $id)->value('row_version');
                $batch = app(QuickApply::class)->apply($id, $ids, $request->user()->id, 'website:'.$id.':v'.$version);

                return redirect('/admin/sites/'.$id.'/cache')->with('apply_batch_id', $batch->id)->with('status', 'Đã lưu website. Xem kết quả áp dụng và cache bên dưới.');
            }
        }

        return redirect('/admin/sites/'.$id.'/edit')->with('status', 'Đã lưu website và canonical. Cấu hình DNS/TLS chưa được kiểm tra.');
    }

    public function membership(Request $request)
    {
        $data = $request->validate(['site_id' => 'required|integer', 'email' => 'required|email', 'name' => 'required|string|max:200', 'role' => 'required|in:owner,operator,viewer', 'password' => 'nullable|string|min:12|max:200']);
        $this->requireSite($request, (int) $data['site_id'], 'owner');
        DB::transaction(function () use ($data, $request) {
            DB::table('sites')->where('id', $data['site_id'])->lockForUpdate()->first();
            $user = DB::table('users')->where('email', $data['email'])->first();
            if (! $user) {
                if (empty($data['password'])) {
                    throw ValidationException::withMessages(['password' => 'Tài khoản mới cần mật khẩu tối thiểu 12 ký tự.']);
                } $uid = DB::table('users')->insertGetId(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'is_global_admin' => false, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $uid = $user->id;
            }
            $previous = DB::table('site_memberships')->where('user_id', $uid)->where('site_id', $data['site_id'])->first();
            abort_if($previous?->role === 'owner' && $data['role'] !== 'owner' && ! $request->user()->is_global_admin && DB::table('site_memberships')->where('site_id', $data['site_id'])->where('role', 'owner')->count() <= 1, 422, 'Không thể hạ quyền owner cuối của site.');
            DB::table('site_memberships')->updateOrInsert(['user_id' => $uid, 'site_id' => $data['site_id']], ['role' => $data['role']]);
            $this->audit($request, 'membership.saved', (int) $data['site_id'], ['user_id' => $uid, 'role' => $data['role']]);
        });

        return back()->with('status', 'Đã lưu quyền theo website.');
    }

    public function account(Request $request)
    {
        return $this->view($request, 'account');
    }

    public function accountPassword(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => 'required|string|min:12|max:200|confirmed']);
        DB::transaction(function () use ($request, $data) {
            $request->user()->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '<>', $request->session()->getId())->delete();
            $this->audit($request, 'account.password.changed', null, ['sessions_revoked' => true]);
        });
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $request->user()->password);

        return back()->with('status', 'Đã đổi mật khẩu và thu hồi các phiên khác.');
    }

    public function revokeMembership(Request $request)
    {
        $data = $request->validate(['site_id' => 'required|integer', 'user_id' => 'required|integer', 'revoke_membership' => 'nullable|boolean']);
        $site = $this->requireSite($request, (int) $data['site_id'], 'owner');
        $user = DB::table('users')->find($data['user_id']);
        abort_unless($user, 404);
        abort_unless(! $user->is_global_admin || $request->user()->is_global_admin, 403);
        $membership = DB::table('site_memberships')->where('site_id', $site->id)->where('user_id', $user->id)->first();
        abort_unless($membership, 404);
        DB::transaction(function () use ($site, $membership, $user, $request) {
            DB::table('sites')->where('id', $site->id)->lockForUpdate()->first();
            if ($request->boolean('revoke_membership')) {
                abort_if($membership->role === 'owner' && ! $request->user()->is_global_admin && DB::table('site_memberships')->where('site_id', $site->id)->where('role', 'owner')->count() <= 1, 422, 'Không thể thu hồi owner cuối của site.');
                DB::table('site_memberships')->where('id', $membership->id)->delete();
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit($request, 'membership.sessions.revoked', $site->id, ['user_id' => $user->id, 'membership_revoked' => $request->boolean('revoke_membership')]);
        });

        return back()->with('status', 'Đã thu hồi phiên đăng nhập'.($request->boolean('revoke_membership') ? ' và quyền website.' : '.'));
    }

    public function saveSiteGroup(Request $request)
    {
        abort_unless($request->user()->is_global_admin, 403);
        $data = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:200', 'status' => 'required|in:active,paused,archived', 'site_ids' => 'nullable|array', 'site_ids.*' => 'integer|distinct']);
        foreach ($data['site_ids'] ?? [] as $siteId) {
            $this->requireSite($request, (int) $siteId, 'owner');
        }
        DB::transaction(function () use ($data, $request) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            if (! empty($data['id'])) {
                abort_unless(DB::table('site_groups')->where('id', $data['id'])->exists(), 404);
                $id = $data['id'];
                DB::table('site_groups')->where('id', $id)->update(['name' => $data['name'], 'status' => $data['status']]);
            } else {
                $id = DB::table('site_groups')->insertGetId(['name' => $data['name'], 'status' => $data['status']]);
            }
            DB::table('site_group_members')->where('group_id', $id)->delete();
            foreach ($data['site_ids'] ?? [] as $siteId) {
                DB::table('site_group_members')->insert(['group_id' => $id, 'site_id' => $siteId]);
            }
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            $this->audit($request, 'site_group.saved', null, ['group_id' => $id]);
        });

        return back()->with('status', 'Đã lưu nhóm website. Quyền site và URL không đổi.');
    }

    public function saveCategory(Request $request)
    {
        $data = $request->validate(['id' => 'nullable|integer', 'site_id' => 'required|integer', 'row_version' => 'nullable|integer|min:1', 'name' => 'required|string|max:200', 'parent_id' => 'nullable|integer', 'external_key' => 'nullable|string|max:200', 'genre' => 'nullable|string|max:200']);
        $site = $this->requireSite($request, (int) $data['site_id'], 'owner');
        if (! empty($data['id'])) {
            abort_unless(CatalogAvailability::filter(DB::table('categories')->where('site_id', $site->id)->where('id', $data['id']), 'categories')->exists(), 404);
        }
        DB::transaction(function () use ($data, $site, $request) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $site->id)->lockForUpdate()->first();
            if (! empty($data['id'])) {
                $current = DB::table('categories')->where('id', $data['id'])->where('site_id', $site->id)->lockForUpdate()->first() ?? abort(404);
                CatalogAvailability::assertWritable($current, 'CATEGORY_DELETED');
                abort_if(isset($data['row_version']) && (int) $data['row_version'] !== (int) $current->row_version, 409, 'Danh mục đã thay đổi. Hãy tải lại trước khi lưu.');
            }
            $parent = $data['parent_id'] ?? null;
            $seen = [];
            while ($parent) {
                abort_if(in_array($parent, $seen) || (int) $parent === (int) ($data['id'] ?? 0), 422, 'Category không được tạo chu trình.');
                $seen[] = $parent;
                $row = CatalogAvailability::filter(DB::table('categories')->where('site_id', $site->id)->where('id', $parent), 'categories')->first();
                abort_unless($row, 422, 'Category cha phải thuộc cùng site.');
                $parent = $row->parent_id;
            }
            $record = array_intersect_key($data, array_flip(['site_id', 'name', 'parent_id', 'external_key', 'genre']));
            if (! empty($data['id'])) {
                $id = $data['id'];
                DB::table('categories')->where('id', $id)->update($record + ['row_version' => DB::raw('row_version+1')]);
            } else {
                $id = DB::table('categories')->insertGetId($record);
            }
            $this->bump($site->id);
            $this->audit($request, 'category.saved', $site->id, ['category_id' => $id]);
        });

        return back()->with('status', 'Đã lưu category. Rule nháp kế thừa theo cây mới; URL/live giữ nguyên.');
    }

    public function keywords(Request $request)
    {
        $pools = $this->pools($request);
        $poolCanWrite = $this->poolWritePermissions($request, $pools);
        $writablePools = $pools->filter(fn ($pool) => $poolCanWrite[$pool->id]);
        $q = DB::table('keywords')->join('keyword_pools', 'keyword_pools.id', '=', 'keywords.pool_id')->whereIn('pool_id', $pools->pluck('id'))->select('keywords.*', 'keyword_pools.name as pool_name');
        if ($request->filled('q')) {
            $q->where('normalized_text', 'like', '%'.$request->input('q').'%');
        }
        foreach (['role', 'status', 'pool_id'] as $filter) {
            if ($request->filled($filter)) {
                $q->where('keywords.'.$filter, $request->input($filter));
            }
        }
        $keywords = $q->orderBy('keywords.id')->paginate(25)->withQueryString();
        $site = $this->site($request);
        $usage = DB::table('page_keyword_assignments')->join('pages', 'pages.current_publication_id', '=', 'page_keyword_assignments.publication_id')->where('pages.status', 'live')->whereIn('pages.site_id', $site ? [$site->id] : $this->sites($request)->pluck('id'))->whereIn('keyword_id', $keywords->pluck('id'))->selectRaw('keyword_id, count(distinct pages.id) as total')->groupBy('keyword_id')->pluck('total', 'keyword_id');
        $held = DB::table('keyword_capacity_claims')->where('status', 'held')->whereIn('site_id', $site ? [$site->id] : $this->sites($request)->pluck('id'))->whereIn('keyword_id', $keywords->pluck('id'))->selectRaw('keyword_id, count(distinct page_id) as total')->groupBy('keyword_id')->pluck('total', 'keyword_id');
        $groups = DB::table('keyword_groups')->whereIn('pool_id', $pools->pluck('id'))->get();
        $imports = DB::table('keyword_import_runs')->whereIn('pool_id', $pools->pluck('id'))->when(! $request->user()->is_global_admin, fn ($q) => $q->where('actor_id', $request->user()->id))->orderByDesc('id')->limit(10)->get();

        return $this->view($request, 'keywords', compact('pools', 'keywords', 'usage', 'held', 'groups', 'imports', 'poolCanWrite', 'writablePools'));
    }

    public function importKeywords(Request $request, KeywordImportService $service)
    {
        $data = $request->validate(['pool_id' => 'required|integer', 'text' => 'nullable|string|max:2000000', 'file' => 'nullable|file|max:20480', 'format' => 'nullable|in:auto,txt,csv,json', 'role' => 'required|in:primary,secondary,anchor', 'locale' => 'required|string|max:16', 'dry_run' => 'nullable|boolean']);
        $this->pool($request, (int) $data['pool_id'], true);
        $text = $request->hasFile('file') ? file_get_contents($request->file('file')->getRealPath()) : ($data['text'] ?? '');
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['text' => 'File phải dùng UTF-8 hợp lệ.']);
        }
        $format = $data['format'] ?? 'auto';
        if ($format === 'auto') {
            $header = str_getcsv(strtok($text, "\r\n") ?: '', ',', '"', '');
            $format = preg_match('/^\s*[\[{]/', $text) ? 'json' : (in_array('text', $header, true) ? 'csv' : 'txt');
        }
        $rows = KeywordService::parse($text, $format);
        $run = $service->queueImport((int) $data['pool_id'], $rows, ['role' => $data['role'], 'locale' => $data['locale']], $request->user()->id, $request->input('idempotency_key', (string) Str::uuid()), $request->boolean('dry_run'));

        return redirect('/admin/keyword-imports/'.$run->id)->with('status', $request->boolean('dry_run') ? 'Đã xếp kiểm tra dữ liệu vào queue; chưa thay đổi kho.' : 'Đã xếp import keyword vào queue. Kết quả từng dòng được lưu bền vững.');
    }

    public function keywordImportReport(Request $request, int $id, KeywordImportService $service)
    {
        $report = $service->report($id, $request->user()->id, $request->integer('page', 1));
        $run = $report['run'];
        $rows = $report['rows'];
        $pool = $this->pool($request, (int) $run->pool_id);

        return $this->view($request, 'keyword-import', compact('run', 'rows', 'pool'));
    }

    public function applyKeywordImport(Request $request, int $id, KeywordImportService $service)
    {
        $report = $service->report($id, $request->user()->id);
        $run = $report['run'];
        $this->pool($request, (int) $run->pool_id, true);
        abort_unless($run->dry_run && in_array($run->status, ['succeeded', 'partial']), 422, 'Chỉ tiếp nhận dry-run đã xử lý xong.');
        $event = DB::table('outbox_events')->where('event_type', 'keyword-import.requested')->where('aggregate_id', $id)->whereNotNull('payload_json->rows')->first();
        abort_unless($event, 404);
        $payload = json_decode($event->payload_json, true);
        $next = $service->queueImport($run->pool_id, $payload['rows'], $payload['defaults'], $request->user()->id, $request->input('idempotency_key', (string) Str::uuid()), false);

        return redirect('/admin/keyword-imports/'.$next->id)->with('status', 'Đã xác nhận import từ dữ liệu dry-run được lưu. Worker sẽ kiểm tra và lưu từng dòng.');
    }

    public function retryKeywordImport(Request $request, int $id, KeywordImportService $service)
    {
        $service->retry($id, $request->user()->id);

        return back()->with('status', 'Đã xếp retry từ cursor đã commit; không nhập lại dòng đã xử lý.');
    }

    public function downloadKeywordImport(Request $request, int $id, KeywordImportService $service)
    {
        $service->report($id, $request->user()->id);
        $rows = DB::table('keyword_import_rows')->where('run_id', $id)->orderBy('row_no');
        if ($request->boolean('errors_only')) {
            $rows->where('status', 'error');
        }

        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['line', 'status', 'normalized_text', 'error']);
            foreach ($rows->cursor() as $row) {
                fputcsv($stream, [$row->row_no, $row->status, KeywordService::spreadsheetCell($row->normalized_text ?? ''), KeywordService::spreadsheetCell($row->error ?? '')]);
            } fclose($stream);
        }, 'keyword-import-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function updateKeyword(Request $request, int $id, KeywordService $service)
    {
        $keyword = DB::table('keywords')->find($id);
        abort_unless($keyword, 404);
        $this->pool($request, (int) $keyword->pool_id, true);
        $data = $request->validate(['weight' => 'required|integer|min:1', 'status' => 'required|in:active,paused,archived', 'default_max_active_pages' => 'nullable|integer|min:0', 'target_url' => 'nullable|string|max:2000', 'priority' => 'nullable|integer', 'original_text' => 'nullable|string|max:255', 'eligibility_json' => 'nullable|string|max:20000', 'row_version' => 'nullable|integer']);
        foreach (['priority', 'original_text', 'eligibility_json'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                unset($data[$field]);
            }
        }
        if ($request->boolean('eligibility_form_present')) {
            $data['eligibility_json'] = MetadataFilterForm::fromRequest($request, 'eligibility_form', Json::decode($keyword->eligibility_json));
        } elseif ($request->has('eligibility_json')) {
            $data['eligibility_json'] = $this->jsonInput($request, 'eligibility_json');
        }
        $service->update($id, $data, $request->user()->id);

        return back()->with('status', 'Đã lưu phiên bản keyword. Snapshot và các trang live giữ nguyên.');
    }

    public function changeKeywordRole(Request $request, int $id, KeywordService $service)
    {
        $keyword = DB::table('keywords')->find($id);
        abort_unless($keyword, 404);
        $this->pool($request, (int) $keyword->pool_id, true);
        $data = $request->validate(['role' => 'required|in:primary,secondary,anchor', 'target_url' => 'nullable|string|max:2000', 'row_version' => 'required|integer']);
        abort_if($data['role'] === $keyword->role, 422, 'Chọn vai trò khác để tạo keyword đích.');
        $target = $service->update($id, $data, $request->user()->id);

        return back()->with('status', 'Đã tạo/khôi phục keyword đích #'.$target->id.' và archive keyword cũ. Assignment live giữ vai trò cũ đến khi reallocate rõ ràng.');
    }

    public function keywordOptions(Request $request)
    {
        $data = $request->validate(['scope_type' => 'nullable|in:global', 'site_id' => 'required_unless:scope_type,global|nullable|integer|min:1', 'role' => 'required|in:primary,secondary,anchor', 'q' => 'nullable|string|max:100']);
        $bindings = DB::table('keyword_bindings')->where('status', 'active');
        if (($data['scope_type'] ?? null) === 'global') {
            abort_unless($request->user()->is_global_admin, 403);
        } else {
            $site = $this->requireSite($request, (int) $data['site_id']);
            $bindings->where('site_id', $site->id);
        }
        $query = DB::table('keywords')->whereIn('pool_id', $bindings->select('pool_id'))
            ->whereIn('pool_id', DB::table('keyword_pools')->where('status', 'active')->whereNull('deleted_at')->select('id'))
            ->where('status', 'active')->where('role', $data['role'])->whereNull('deleted_at');
        if (! empty($data['q'])) {
            $query->where('normalized_text', 'ilike', '%'.$data['q'].'%');
        }

        return response()->json(['data' => $query->orderBy('original_text')->limit(50)->get(['id', 'original_text', 'role', 'pool_id'])]);
    }

    public function bulkKeywords(Request $request)
    {
        $data = $request->validate(['keyword_ids' => 'required|array|min:1|max:1000', 'keyword_ids.*' => 'integer|distinct', 'keyword_versions_json' => 'required|string|max:100000', 'status' => 'nullable|in:active,paused,archived', 'weight' => 'nullable|integer|min:1', 'default_max_active_pages' => 'nullable|integer|min:0', 'change_cap' => 'nullable|boolean', 'group_id' => 'nullable|integer']);
        $versions = Json::decode($data['keyword_versions_json']);
        foreach ($data['keyword_ids'] as $keywordId) {
            abort_unless(isset($versions[$keywordId]) && filter_var($versions[$keywordId], FILTER_VALIDATE_INT) !== false && (int) $versions[$keywordId] > 0, 422, 'Phiên bản keyword không hợp lệ.');
        }
        $keywords = DB::table('keywords')->whereIn('id', $data['keyword_ids'])->get();
        abort_unless($keywords->count() === count($data['keyword_ids']), 404);
        foreach ($keywords->pluck('pool_id')->unique() as $poolId) {
            $this->pool($request, (int) $poolId, true);
        }
        $group = ! empty($data['group_id']) ? DB::table('keyword_groups')->find($data['group_id']) : null;
        if (! empty($data['group_id'])) {
            abort_unless($group && $keywords->every(fn ($k) => (int) $k->pool_id === (int) $group->pool_id), 422, 'Nhóm và keyword phải cùng pool.');
        }
        $changes = array_filter(array_intersect_key($data, array_flip(['status', 'weight'])), fn ($value) => $value !== null && $value !== '');
        if ($request->boolean('change_cap')) {
            $changes['default_max_active_pages'] = $data['default_max_active_pages'] ?? null;
        }
        abort_unless($changes || $group, 422, 'Chọn ít nhất một thay đổi.');
        DB::transaction(function () use ($keywords, $changes, $group, $request, $versions) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            foreach ($keywords->pluck('pool_id')->unique()->sort() as $poolId) {
                $pool = DB::table('keyword_pools')->where('id', $poolId)->lockForUpdate()->first();
                abort_if($pool->deleted_at || $pool->status === 'archived', 422, 'Khôi phục hoặc bật kho trước khi sửa keyword.');
                $this->pool($request, (int) $poolId, true);
            }
            if ($group) {
                $group = DB::table('keyword_groups')->where('id', $group->id)->lockForUpdate()->first();
                abort_if($group->deleted_at || $group->status === 'archived', 422, 'Khôi phục hoặc bật nhóm trước khi thêm thành viên.');
            }
            $groupChanged = false;
            foreach ($keywords as $keyword) {
                $keyword = DB::table('keywords')->where('id', $keyword->id)->lockForUpdate()->first();
                abort_if($keyword->deleted_at, 422, 'Khôi phục keyword trước khi chỉnh sửa.');
                if ((int) $versions[$keyword->id] !== (int) $keyword->row_version) {
                    throw new Conflict('KEYWORD_VERSION_CONFLICT');
                }
                if ($changes) {
                    DB::table('keywords')->where('id', $keyword->id)->update($changes + ['row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
                }
                if ($group) {
                    $groupChanged = (bool) DB::table('keyword_group_members')->insertOrIgnore(['pool_id' => $keyword->pool_id, 'group_id' => $group->id, 'keyword_id' => $keyword->id]) || $groupChanged;
                }
            }
            if ($groupChanged) {
                DB::table('keyword_groups')->where('id', $group->id)->increment('row_version');
            }
            DB::table('keyword_pools')->whereIn('id', $keywords->pluck('pool_id')->unique())->increment('row_version');
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            $this->audit($request, 'keyword.bulk.updated', null, ['keyword_ids' => $keywords->pluck('id')->all(), 'changes' => $changes]);
        });

        return back()->with('status', 'Đã cập nhật '.$keywords->count().' keyword đã chọn. Live không thay đổi.');
    }

    public function keywordUsage(Request $request, int $id)
    {
        $keyword = DB::table('keywords')->find($id);
        abort_unless($keyword, 404);
        $this->pool($request, (int) $keyword->pool_id);
        $pages = DB::table('page_keyword_assignments')->join('pages', 'pages.current_publication_id', '=', 'page_keyword_assignments.publication_id')->where('keyword_id', $id)->whereIn('pages.site_id', $this->sites($request)->pluck('id'))->where('pages.status', 'live')->select('pages.*', 'page_keyword_assignments.role')->get();
        $limit = null;
        $versions = DB::table('keyword_versions')->where('keyword_id', $id)->orderByDesc('version_no')->limit(20)->get();

        return $this->view($request, 'usage', compact('keyword', 'pages', 'limit', 'versions'));
    }

    public function exportKeywords(Request $request)
    {
        $ids = $this->pools($request)->pluck('id');
        $rows = DB::table('keywords')->whereIn('pool_id', $ids)->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['text', 'role', 'locale', 'weight', 'status', 'quota']);
            foreach ($rows as $row) {
                $text = $row->original_text;
                if (preg_match('/^[\s]*[=+\-@]/u', $text)) {
                    $text = "'".$text;
                } fputcsv($file, [$text, $row->role, $row->locale, $row->weight, $row->status, $row->default_max_active_pages]);
            } fclose($file);
        }, 'keywords-safe.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function poolList(Request $request)
    {
        $pools = $this->pools($request);
        $poolCanWrite = $this->poolWritePermissions($request, $pools);
        $writablePools = $pools->filter(fn ($pool) => $poolCanWrite[$pool->id]);
        $sites = $this->sites($request);
        $ownerSiteIds = DB::table('site_memberships')->where('user_id', $request->user()->id)->where('role', 'owner')->pluck('site_id')->all();
        $ownerSites = $request->user()->is_global_admin ? $sites : $sites->whereIn('id', $ownerSiteIds);
        $canCreatePool = $request->user()->is_global_admin || $ownerSites->isNotEmpty();
        $canBindPool = $ownerSites->isNotEmpty() && $pools->isNotEmpty();
        $bindings = DB::table('keyword_bindings')->whereIn('site_id', $this->sites($request)->pluck('id'))->get();
        $bindingCanWrite = $bindings->mapWithKeys(fn ($binding) => [$binding->id => $ownerSites->contains('id', $binding->site_id)])->all();
        $groups = DB::table('keyword_groups')->whereIn('pool_id', $pools->pluck('id'))->get();
        $categories = CatalogAvailability::filter(DB::table('categories'), 'categories')->whereIn('site_id', $this->sites($request)->pluck('id'))->get();
        $groupKeywords = DB::table('keyword_group_members')->whereIn('pool_id', $pools->pluck('id'))->get()->groupBy('group_id');
        $keywordChoices = DB::table('keywords')->whereIn('pool_id', $pools->pluck('id'))->orderBy('normalized_text')->get(['id', 'pool_id', 'original_text', 'role']);

        return $this->view($request, 'pools', compact('pools', 'bindings', 'groups', 'categories', 'groupKeywords', 'keywordChoices', 'sites', 'poolCanWrite', 'writablePools', 'ownerSites', 'canCreatePool', 'canBindPool', 'bindingCanWrite'));
    }

    public function savePool(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:200', 'locale' => 'required|string|max:16', 'scope' => 'required|in:shared,site', 'owner_site_id' => 'nullable|integer']);
        if ($data['scope'] === 'shared') {
            abort_unless($request->user()->is_global_admin, 403);
            $data['owner_site_id'] = null;
        } else {
            $this->requireSite($request, (int) ($data['owner_site_id'] ?? 0), 'owner');
        }
        DB::transaction(function () use ($data, $request) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $id = DB::table('keyword_pools')->insertGetId($data + ['status' => 'active', 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('system_settings')->increment('config_epoch');
            $this->audit($request, 'pool.created', $data['owner_site_id'], ['pool_id' => $id]);
        });

        return back()->with('status', 'Đã tạo kho keyword.');
    }

    public function updatePool(Request $request, int $id)
    {
        $pool = $this->pool($request, $id, true);
        $data = $request->validate(['name' => 'required|string|max:200', 'locale' => 'required|string|max:16', 'status' => 'required|in:active,paused,archived', 'row_version' => 'required|integer']);
        DB::transaction(function () use ($data, $id, $request) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $updated = DB::table('keyword_pools')->where('id', $id)->where('row_version', $data['row_version'])->update(['name' => $data['name'], 'locale' => $data['locale'], 'status' => $data['status'], 'row_version' => $data['row_version'] + 1, 'updated_at' => now()]);
            abort_unless($updated, 409, 'Kho đã thay đổi. Hãy tải lại.');
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            $this->audit($request, 'pool.updated', null, ['pool_id' => $id]);
        });

        return back()->with('status', 'Đã lưu metadata/status kho. Snapshot live giữ nguyên.');
    }

    public function updateBinding(Request $request, int $id, SitePoolBindingService $service)
    {
        $binding = DB::table('keyword_bindings')->find($id) ?? abort(404);
        $service->authorize($request->user()->id, $binding->site_id);
        $data = $request->validate(['row_version' => 'required|integer|min:1', 'priority' => 'required|integer', 'status' => 'required|in:active,paused,archived', 'group_id' => 'nullable|integer|min:1', 'page_type' => 'nullable|in:home,category,movie,novel,episode,chapter,editorial', 'category_id' => 'nullable|integer|min:1']);
        $data['filters'] = $request->has('filters_form')
            ? MetadataFilterForm::fromRequest($request, 'filters_form', Json::decode($binding->filters_json))
            : ($request->has('filters_json') ? $this->jsonInput($request, 'filters_json') : Json::decode($binding->filters_json));
        $service->save($binding->site_id, $id, $data, $request->user()->id);

        return redirect('/admin/sites/'.$binding->site_id.'/edit?tab=pools')->with('status', 'Đã lưu liên kết kho. Preview lại để áp dụng thay đổi.');
    }

    public function saveGroup(Request $request)
    {
        $data = $request->validate(['pool_id' => 'required|integer', 'name' => 'required|string|max:200', 'keyword_ids' => 'nullable|array', 'keyword_ids.*' => 'integer']);
        $this->pool($request, (int) $data['pool_id'], true);
        DB::transaction(function () use ($data) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $group = DB::table('keyword_groups')->insertGetId(['pool_id' => $data['pool_id'], 'name' => $data['name'], 'status' => 'active']);
            foreach ($data['keyword_ids'] ?? [] as $id) {
                abort_unless(DB::table('keywords')->where('pool_id', $data['pool_id'])->where('id', $id)->exists(), 422);
                DB::table('keyword_group_members')->insert(['pool_id' => $data['pool_id'], 'group_id' => $group, 'keyword_id' => $id]);
            } DB::table('system_settings')->increment('config_epoch');
        });

        return back()->with('status', 'Đã tạo nhóm.');
    }

    public function updateGroup(Request $request, int $id)
    {
        $group = DB::table('keyword_groups')->find($id);
        abort_unless($group, 404);
        $this->pool($request, (int) $group->pool_id, true);
        $data = $request->validate(['name' => 'required|string|max:200', 'status' => 'required|in:active,paused,archived', 'keyword_ids' => 'nullable|array|max:10000', 'keyword_ids.*' => 'integer|distinct']);
        foreach ($data['keyword_ids'] ?? [] as $keywordId) {
            abort_unless(DB::table('keywords')->where('pool_id', $group->pool_id)->where('id', $keywordId)->exists(), 422);
        }
        DB::transaction(function () use ($group, $data, $request) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            DB::table('keyword_pools')->where('id', $group->pool_id)->lockForUpdate()->first();
            DB::table('keyword_groups')->where('id', $group->id)->update(['name' => $data['name'], 'status' => $data['status']]);
            DB::table('keyword_group_members')->where('group_id', $group->id)->delete();
            foreach ($data['keyword_ids'] ?? [] as $keywordId) {
                DB::table('keyword_group_members')->insert(['pool_id' => $group->pool_id, 'group_id' => $group->id, 'keyword_id' => $keywordId]);
            } DB::table('keyword_pools')->where('id', $group->pool_id)->increment('row_version');
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            $this->audit($request, 'group.updated', null, ['group_id' => $group->id]);
        });

        return back()->with('status', 'Đã lưu nhóm/keyword trong nhóm. Snapshot live giữ nguyên.');
    }

    public function keywordLimit(Request $request, int $id, SitePoolBindingService $service)
    {
        $siteData = $request->validate(['site_id' => 'required|integer|min:1']);
        $siteId = (int) $siteData['site_id'];
        $service->authorize($request->user()->id, $siteId);
        $data = $request->validate(['site_config_epoch' => 'required|integer|min:1', 'max_active_pages' => 'nullable|integer|min:0', 'inherit' => 'nullable|boolean']);
        $data['inherit'] = $request->boolean('inherit');
        $service->limit($siteId, $id, $data, $request->user()->id);

        return redirect('/admin/sites/'.$siteId.'/edit?tab=pools')->with('status', 'Đã lưu giới hạn keyword của website. Trang live giữ nguyên.');
    }

    public function saveBinding(Request $request, SitePoolBindingService $service)
    {
        $siteData = $request->validate(['site_id' => 'required|integer|min:1']);
        $siteId = (int) $siteData['site_id'];
        $service->authorize($request->user()->id, $siteId);
        $data = $request->validate(['pool_id' => 'required|integer|min:1', 'group_id' => 'nullable|integer|min:1', 'category_id' => 'nullable|integer|min:1', 'page_type' => 'nullable|in:home,category,movie,novel,episode,chapter,editorial', 'priority' => 'required|integer', 'status' => 'required|in:active,paused']);
        $data['filters'] = $request->has('filters_form')
            ? MetadataFilterForm::fromRequest($request, 'filters_form')
            : ($request->has('filters_json') ? $this->jsonInput($request, 'filters_json') : []);
        $service->save($siteId, null, $data, $request->user()->id);

        return redirect('/admin/sites/'.$siteId.'/edit?tab=pools')->with('status', 'Đã gắn kho keyword. Trang live giữ nguyên.');
    }

    public function rules(Request $request)
    {
        $data = $request->validate(['status' => 'nullable|in:active,archived,all', 'kind' => 'nullable|in:all,allocation,links,seo', 'site_id' => 'nullable|integer|min:1', 'rule_id' => 'nullable|integer|min:1']);
        $ruleStatus = $data['status'] ?? 'active';
        $ruleKind = $data['kind'] ?? 'all';
        $ruleSites = app(AdminSiteLabels::class)->sortSites($this->siteQuery($request)->orderBy('id')->get(['id', 'name', 'status', 'base_url', 'content_type']));
        $site = $request->filled('site_id') ? $this->requireSite($request) : null;
        if ($request->filled('rule_id')) {
            $targetRule = CatalogAvailability::filter(DB::table('rules'), 'rules')->find((int) $data['rule_id']);
            abort_unless($targetRule, 404);
            if ($targetRule->site_id !== null) {
                $actualSite = $this->requireSite($request, (int) $targetRule->site_id);
                abort_if($site && $site->id !== $actualSite->id, 404);
                $site = $actualSite;
            }
        }
        $query = CatalogAvailability::filter(DB::table('rules'), 'rules')->leftJoin('rule_versions', 'rule_versions.id', '=', 'rules.current_version_id')
            ->where(fn ($q) => $q->whereNull('rules.site_id')->orWhereIn('rules.site_id', $site ? [$site->id] : $ruleSites->pluck('id')));
        if ($request->filled('rule_id')) {
            $query->where('rules.id', (int) $data['rule_id']);
        } else {
            $query->when($ruleStatus !== 'all', fn ($q) => $q->where('rules.status', $ruleStatus));
            match ($ruleKind) {
                'allocation' => $query->where('rules.slot', 'like', 'allocation.%'),
                'links' => $query->whereIn('rules.slot', ['managed_body', 'related_links']),
                'seo' => $query->where('rules.slot', 'not like', 'allocation.%')->whereNotIn('rules.slot', ['managed_body', 'related_links']),
                default => null,
            };
        }
        $rules = $query->select('rules.*', 'rule_versions.config_json', 'rule_versions.version_no')->orderBy('rules.id')->paginate(50)->withQueryString();
        $editingRule = $rules->firstWhere('id', (int) $request->input('rule_id', 0));
        abort_if($request->filled('rule_id') && ! $editingRule, 404);
        $isEditor = $editingRule || $request->boolean('create') || $request->session()->getOldInput('form_context') === 'rule:new';
        $selectedPageId = (int) $request->session()->getOldInput('page_id', $editingRule?->page_id ?? 0);
        $pages = $site && $isEditor && $selectedPageId > 0
            ? DB::table('pages')->where('site_id', $site->id)->where('id', $selectedPageId)->get(['id', 'canonical_path', 'page_type']) : collect();
        $categories = $site && $isEditor ? CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $site->id)->get(['id', 'name']) : collect();
        $pools = $site && $isEditor ? DB::table('keyword_pools')->whereNull('deleted_at')->whereIn('id', DB::table('keyword_bindings')->where('site_id', $site->id)->where('status', 'active')->select('pool_id'))->get() : collect();
        if ($isEditor && ($editingRule?->scope_type === 'global' || $site === null) && $request->user()->is_global_admin) {
            $pools = $this->pools($request)->whereIn('id', DB::table('keyword_bindings')->where('status', 'active')->pluck('pool_id'));
        }
        $editingPolicy = $editingRule && str_starts_with($editingRule->slot, 'allocation.') ? Json::decode($editingRule->config_json) : [];
        $selectedPoolIds = array_unique(array_merge($editingPolicy['pool_ids'] ?? [], $editingPolicy['fallback_pool_ids'] ?? []));
        if ($selectedPoolIds !== []) {
            $pools = $pools->concat(DB::table('keyword_pools')->whereNull('deleted_at')->whereIn('id', $selectedPoolIds)->whereNotIn('id', $pools->pluck('id'))->get());
        }
        $selectedIds = array_merge($editingPolicy['keyword_ids'] ?? [], $editingPolicy['manual_ids'] ?? []);
        $keywordChoices = $selectedIds === [] ? [] : DB::table('keywords')->whereNull('deleted_at')->whereIn('pool_id', $pools->pluck('id'))->whereIn('id', $selectedIds)
            ->get(['id', 'original_text', 'role', 'pool_id', 'status'])->map(fn ($keyword) => (array) $keyword)->all();
        $writeSiteIds = DB::table('site_memberships')->where('user_id', $request->user()->id)->whereIn('role', ['owner', 'operator'])->pluck('site_id');
        $ruleCanWrite = $rules->mapWithKeys(fn ($rule) => [$rule->id => $request->user()->is_global_admin || ($rule->site_id !== null && $writeSiteIds->contains($rule->site_id))])->all();
        $canWriteRule = $request->user()->is_global_admin || ($site && $writeSiteIds->contains($site->id));
        $ruleSummaries = app(RuleAdminSummary::class)->summarize($rules->getCollection());
        $pageIds = $rules->pluck('page_id')->filter()->unique();
        $categoryIds = $rules->pluck('category_id')->filter()->unique();
        $ruleTargets = ['pages' => $pageIds->isEmpty() ? collect() : DB::table('pages')->whereIn('id', $pageIds)->pluck('canonical_path', 'id'),
            'categories' => $categoryIds->isEmpty() ? collect() : DB::table('categories')->whereIn('id', $categoryIds)->pluck('name', 'id')];

        return $this->view($request, 'rules', compact('site', 'rules', 'pages', 'categories', 'pools', 'keywordChoices', 'ruleCanWrite', 'canWriteRule', 'ruleStatus', 'ruleKind', 'ruleSites', 'ruleSummaries', 'ruleTargets') + ['sites' => $ruleSites]);
    }

    public function rulePageOptions(Request $request)
    {
        $data = $request->validate(['site_id' => 'required|integer|min:1', 'q' => 'nullable|string|max:200']);
        $site = $this->requireSite($request, (int) $data['site_id']);
        $query = DB::table('pages')->where('site_id', $site->id);
        $term = trim($data['q'] ?? '');
        if ($term !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(function ($q) use ($term, $pattern): void {
                $q->where('canonical_path', 'ilike', $pattern);
                if (ctype_digit($term) && strlen($term) < 19) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }
        $rows = $query->orderByDesc('id')->limit(51)->get(['id', 'canonical_path', 'page_type']);

        return response()->json(['data' => $rows->take(50)->values(), 'has_more' => $rows->count() > 50])->header('Cache-Control', 'no-store');
    }

    public function saveRule(Request $request)
    {
        $data = $request->validate(['intent' => 'nullable|in:apply,draft', 'scope_type' => 'required|in:global,site,page_type,category,page', 'site_id' => 'nullable|integer', 'page_id' => 'nullable|integer', 'category_id' => 'nullable|integer', 'page_type' => 'nullable|string|max:40', 'slot' => 'required|string|max:80', 'mode' => 'required|in:inherit,off,on']);
        if ($data['scope_type'] === 'global') {
            abort_unless($request->user()->is_global_admin, 403);
            $data['site_id'] = null;
        } else {
            $site = $this->requireSite($request, (int) ($data['site_id'] ?? 0), 'write');
            if (! empty($data['page_id'])) {
                abort_unless(DB::table('pages')->where('site_id', $site->id)->where('id', $data['page_id'])->exists(), 422);
            } if (! empty($data['category_id'])) {
                abort_unless(CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $site->id)->where('id', $data['category_id'])->exists(), 422);
            }
        }
        if ($request->boolean('configuration_form_present')) {
            $edit = $request->validate(['rule_id' => 'nullable|integer|min:1', 'row_version' => 'nullable|integer|min:1']);
            $identity = ['scope_type' => $data['scope_type'], 'site_id' => $data['site_id'] ?? null, 'page_id' => $data['scope_type'] === 'page' ? ($data['page_id'] ?? null) : null, 'category_id' => $data['scope_type'] === 'category' ? ($data['category_id'] ?? null) : null, 'page_type' => $data['scope_type'] === 'page_type' ? ($data['page_type'] ?? null) : null, 'slot' => $data['slot']];
            $currentRule = ! empty($edit['rule_id']) ? DB::table('rules')->find($edit['rule_id']) : DB::table('rules')->where($identity)->first();
            if (! empty($edit['rule_id'])) {
                abort_unless($currentRule, 404);
                if ($currentRule->site_id === null) {
                    abort_unless($request->user()->is_global_admin, 403);
                } else {
                    $this->requireSite($request, (int) $currentRule->site_id, 'write');
                }
                foreach ($identity as $field => $value) {
                    if ((string) $currentRule->{$field} !== (string) $value) {
                        throw ValidationException::withMessages(['rule_id' => 'Phạm vi của quy tắc hiện có phải giữ nguyên. Tạo quy tắc riêng để dùng phạm vi khác.']);
                    }
                }
                $request->validate(['row_version' => 'required|integer|min:1']);
            }
            $current = $currentRule ? DB::table('rule_versions')->where('id', $currentRule->current_version_id)->value('config_json') : null;
            $current = $current === null ? null : json_decode($current, true, 32, JSON_THROW_ON_ERROR);
            $data['config_json'] = RuleConfigurationForm::fromRequest($request, $data['slot'], $current);
            if ($currentRule) {
                $data['row_version'] = (int) ($edit['row_version'] ?? $currentRule->row_version);
            } else {
                $data['expected_absent'] = true;
            }
        } else {
            $data['config_json'] = match ($data['mode']) {
                'inherit' => null, 'off' => false, default => $this->jsonInput($request, 'config_json')
            };
        }
        if (! $request->boolean('configuration_form_present') && $data['mode'] === 'on' && str_starts_with($data['slot'], 'allocation.') && $request->filled('allocation_mode')) {
            $policy = $request->validate(['allocation_mode' => 'required|in:manual,sequential,weighted,random-once', 'pool_ids' => 'nullable|array', 'pool_ids.*' => 'integer', 'keyword_ids' => 'nullable|array', 'keyword_ids.*' => 'integer', 'allocation_count' => 'required|integer|min:0|max:20', 'allocation_required' => 'nullable|boolean']);
            $poolIds = array_map('intval', $policy['pool_ids'] ?? []);
            foreach ($poolIds as $poolId) {
                $this->pool($request, $poolId);
                abort_unless(DB::table('keyword_bindings')->where('site_id', $data['site_id'])->where('pool_id', $poolId)->where('status', 'active')->exists(), 422, 'Pool chưa gắn vào website.');
            }
            $role = substr($data['slot'], strlen('allocation.'));
            $keywordIds = array_map('intval', $policy['keyword_ids'] ?? []);
            foreach ($keywordIds as $keywordId) {
                abort_unless(DB::table('keywords')->whereIn('pool_id', $poolIds)->where('id', $keywordId)->where('role', $role)->where('status', 'active')->exists(), 422, 'Keyword manual không khớp role/pool.');
            }
            $data['config_json'] = ['mode' => $policy['allocation_mode'], 'pool_ids' => $poolIds, 'count' => $role === 'primary' ? min(1, (int) $policy['allocation_count']) : (int) $policy['allocation_count'], 'required' => $request->boolean('allocation_required'), 'keyword_ids' => $keywordIds, 'filters' => $this->jsonInput($request, 'allocation_filters')];
        }
        if ($request->boolean('configuration_form_present') && str_starts_with($data['slot'], 'allocation.') && is_array($data['config_json'])) {
            $policy = $data['config_json'];
            $priorKeywordIds = is_array($current ?? null) ? array_merge($current['keyword_ids'] ?? [], $current['manual_ids'] ?? []) : [];
            $poolIds = array_unique(array_merge($policy['pool_ids'] ?? [], $policy['fallback_pool_ids'] ?? []));
            foreach ($poolIds as $poolId) {
                $this->pool($request, (int) $poolId);
                $bindings = DB::table('keyword_bindings')->where('pool_id', $poolId)->where('status', 'active');
                if ($data['site_id'] ?? null) {
                    $bindings->where('site_id', $data['site_id']);
                }
                abort_unless($bindings->exists(), 422, 'Kho chưa được gắn vào phạm vi website.');
            }
            foreach (array_unique(array_merge($policy['keyword_ids'] ?? [], $policy['manual_ids'] ?? [])) as $keywordId) {
                $keyword = DB::table('keywords')->whereIn('pool_id', $poolIds)->where('id', $keywordId)->where('role', substr($data['slot'], strlen('allocation.')))->first();
                abort_unless($keyword && ($keyword->status === 'active' || in_array((int) $keywordId, array_map('intval', $priorKeywordIds), true)), 422, 'Keyword được chọn không khớp vai trò hoặc kho.');
            }
        }
        unset($data['mode']);
        $rule = app(ConfigService::class)->saveRule($data, $request->user()->id);
        if (($data['intent'] ?? 'draft') === 'apply') {
            $result = app(SharedConfigApply::class)->rule($rule, $request->user()->id);

            return back()->with('status', 'Đã lưu quy tắc và yêu cầu cập nhật các trang đang dùng trong phạm vi này.')->with('shared_apply_result', $result);
        }

        return back()->with('status', 'Đã lưu quy tắc trong kho. Website giữ cấu hình đang phục vụ cho đến khi áp dụng.');
    }

    public function templates(Request $request)
    {
        $templates = CatalogAvailability::filter(DB::table('templates'), 'templates')->leftJoin('template_versions', 'template_versions.id', '=', 'templates.current_version_id')->select('templates.*', 'template_versions.definition_json', 'template_versions.version_no')->orderBy('templates.id')->get();

        return $this->view($request, 'templates', compact('templates'));
    }

    public function saveTemplate(Request $request)
    {
        abort_unless($request->user()->is_global_admin, 403);
        $data = $request->validate(['intent' => 'nullable|in:apply,draft', 'id' => 'nullable|integer|min:1', 'name' => 'required|string|max:200']);
        if ($request->boolean('configuration_form_present')) {
            $template = ! empty($data['id']) ? DB::table('templates')->find($data['id']) : DB::table('templates')->where('name', $data['name'])->first();
            if (empty($data['id']) && $template) {
                throw ValidationException::withMessages(['name' => 'Tên template đã có. Hãy mở template hiện có để sửa.']);
            }
            if (! empty($data['id'])) {
                abort_unless($template, 404);
                $edit = $request->validate(['row_version' => 'required|integer|min:1']);
                $data['row_version'] = (int) $edit['row_version'];
            } elseif ($template) {
                $data['row_version'] = (int) $template->row_version;
            } else {
                $data['expected_absent'] = true;
            }
            $current = $template ? Json::decode(DB::table('template_versions')->where('id', $template->current_version_id)->value('definition_json')) : [];
            abort_if(SeoTemplateBundle::isBundle($current), 422, 'Hãy dùng editor Bộ Template SEO để sửa cả bộ.');
            $data['definition_json'] = TemplateConfigurationForm::fromRequest($request, $current);
        } else {
            $request->validate(['definition_json' => 'required|string|max:100000']);
            if (! empty($data['id'])) {
                $template = DB::table('templates')->find($data['id']) ?? abort(404);
                $data['row_version'] = $request->validate(['row_version' => 'required|integer|min:1'])['row_version'];
                $current = Json::decode(DB::table('template_versions')->where('id', $template->current_version_id)->value('definition_json'));
                abort_if(SeoTemplateBundle::isBundle($current), 422, 'Hãy dùng editor Bộ Template SEO để sửa cả bộ.');
            } else {
                $data['expected_absent'] = true;
            }
            $data['definition_json'] = $this->jsonInput($request, 'definition_json');
        }
        $template = app(ConfigService::class)->saveTemplate($data, $request->user()->id);
        if (($data['intent'] ?? 'draft') === 'apply') {
            $result = app(SharedConfigApply::class)->template($template->id, $request->user()->id);

            return back()->with('status', 'Đã lưu template và yêu cầu cập nhật website đang dùng mẫu này.')->with('shared_apply_result', $result);
        }

        return back()->with('status', 'Đã lưu phiên bản template trong kho. Website giữ cấu hình đang phục vụ cho đến khi áp dụng.');
    }

    public function pages(Request $request)
    {
        $data = $request->validate(['site_id' => 'nullable|integer|min:1', 'kind' => 'nullable|in:all,regular,fanmulu',
            'q' => 'nullable|string|max:200', 'status' => 'nullable|in:draft,live,archived,gone']);
        $pageKind = $data['kind'] ?? 'all';
        $sites = $this->sites($request);
        $site = $request->filled('site_id') ? $this->requireSite($request) : null;
        $q = DB::table('pages')->whereIn('pages.site_id', $site ? [$site->id] : $sites->where('status', '!=', 'archived')->pluck('id'))->select('pages.*');
        if ($pageKind !== 'all') {
            $q->where('pages.page_kind', $pageKind);
        }
        if ($request->filled('status')) {
            $q->where('pages.status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $q->where('canonical_path', 'like', '%'.$request->input('q').'%');
        }
        $pages = app(CatalogPagination::class)->paginate($q, 25, 0, 'pages.id')->withQueryString();
        $indexed = app(CatalogMetadata::class)->available();
        $titles = DB::table($indexed ? 'catalog_revision_metadata' : 'content_revisions')
            ->whereIn('id', collect($pages->items())->pluck('working_content_revision_id')->filter()->all())
            ->select('id')->selectRaw(($indexed ? 'fields_json' : 'payload_json')."->>'title' AS title")->pluck('title', 'id');
        $pageRows = collect($pages->items());
        $fanmuluRows = $pageRows->where('page_kind', 'fanmulu');
        $publicationIds = $fanmuluRows->pluck('current_publication_id')->merge($fanmuluRows->pluck('source_publication_id'))->filter()->unique()->all();
        $publicationTitles = $publicationIds === [] ? collect() : DB::table('page_publications')->whereIn('id', $publicationIds)->whereIn('site_id', $fanmuluRows->pluck('site_id')->unique()->all())
            ->get(['id', 'page_id', 'site_id', DB::raw("snapshot_json #>> '{fanmulu,display_title}' AS published_display_title"),
                DB::raw("COALESCE(snapshot_json #>> '{_catalog_card,content,title}', snapshot_json #>> '{content,title}') AS original_title"),
                DB::raw("snapshot_json #>> '{fanmulu,original_title}' AS fanmulu_original_title")])->keyBy('id');
        foreach ($pages as $page) {
            $page->original_title = $titles->get($page->working_content_revision_id);
            $publication = $publicationTitles->get($page->current_publication_id);
            $sourcePublication = $publicationTitles->get($page->source_publication_id);
            $page->display_title = $page->page_kind === 'fanmulu' ? $publication?->published_display_title : $page->original_title;
            $page->source_original_title = $page->page_kind === 'fanmulu'
                ? ($sourcePublication?->original_title ?? $publication?->fanmulu_original_title ?? $page->original_title) : null;
        }
        $onDemandSiteIds = $this->onDemandSiteIds(collect($pages->items())->pluck('site_id')->unique()->all());
        $onDemandReadyPageIds = app(OnDemandEligibility::class)->pageIds($onDemandSiteIds, collect($pages->items())->pluck('id')->all());

        return $this->view($request, 'pages', compact('pages', 'sites', 'site', 'onDemandReadyPageIds', 'pageKind'));
    }

    /** @param array<int> $siteIds @return array<int> */
    private function onDemandSiteIds(array $siteIds): array
    {
        if (! config('public_on_demand.enabled', false) || $siteIds === [] || ! Schema::hasTable('site_delivery_settings')) {
            return [];
        }

        return DB::table('site_delivery_settings as delivery')->join('sites as site', 'site.id', '=', 'delivery.site_id')
            ->whereIn('delivery.site_id', $siteIds)->where(['delivery.mode' => 'on_demand', 'site.status' => 'active', 'site.content_type' => 'movie'])
            ->pluck('delivery.site_id')->all();
    }

    public function page(Request $request, int $id)
    {
        $page = DB::table('pages')->find($id);
        abort_unless($page, 404);
        $site = $this->requireSite($request, (int) $page->site_id);
        $revision = $page->working_content_revision_id ? DB::table('content_revisions')->find($page->working_content_revision_id) : null;
        $history = DB::table('page_publications')->where('page_id', $id)->orderByDesc('publication_no')->get();
        $pageReady = in_array($page->id, app(OnDemandEligibility::class)->pageIds($this->onDemandSiteIds([$site->id]), [$page->id]), true);
        $assignments = $page->current_publication_id ? DB::table('page_keyword_assignments')->where('publication_id', $page->current_publication_id)->get() : collect();
        $templates = CatalogAvailability::filter(DB::table('templates'), 'templates')->where('status', 'active')->get();
        $themes = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $page->theme_id))->orderBy('name')->get();
        $aggregateChoices = in_array($page->page_type, ['home', 'category']) ? DB::table('pages')->leftJoin('content_revisions', 'content_revisions.id', '=', 'pages.working_content_revision_id')->where('pages.site_id', $site->id)->whereNotNull('pages.working_content_revision_id')->select('pages.id', 'pages.canonical_path')->selectRaw("content_revisions.payload_json->>'title' as title")->get() : collect();

        return $this->view($request, 'page', compact('page', 'site', 'revision', 'history', 'assignments', 'templates', 'themes', 'aggregateChoices', 'pageReady'));
    }

    public function savePage(Request $request, int $id)
    {
        $page = DB::table('pages')->find($id);
        abort_unless($page, 404);
        $site = $this->requireSite($request, (int) $page->site_id, 'write');
        $data = $request->validate(['intent' => 'nullable|in:apply,draft', 'row_version' => 'required|integer', 'assignment_locked' => 'nullable|boolean', 'publish_locked' => 'nullable|boolean', 'template_id' => 'nullable|integer', 'theme_id' => 'nullable|integer', 'noindex' => 'nullable|boolean']);
        if (! empty($data['template_id'])) {
            abort_unless(CatalogAvailability::filter(DB::table('templates'), 'templates')->where('id', $data['template_id'])->where('status', 'active')->exists(), 422);
        }
        if (! empty($data['theme_id'])) {
            abort_unless($site->content_type === 'movie' && CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where('id', $data['theme_id'])->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $page->theme_id))->exists(), 422, 'Giao diện phim phải đang active hoặc là giao diện hiện tại của trang.');
        }
        $updated = DB::transaction(function () use ($id, $data, $request, $page) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $page->site_id)->sharedLock()->first();
            if (! empty($data['theme_id'])) {
                abort_unless(CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where('id', $data['theme_id'])->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $page->theme_id))->exists(), 422, 'Giao diện đã archive; hãy chọn lại.');
            }

            return DB::table('pages')->where('id', $id)->where('row_version', $data['row_version'])->update(['assignment_locked' => $request->boolean('assignment_locked'), 'publish_locked' => $request->boolean('publish_locked'), 'noindex' => $request->boolean('noindex'), 'template_id' => $data['template_id'] ?? null, 'theme_id' => array_key_exists('theme_id', $data) ? $data['theme_id'] : $page->theme_id, 'row_version' => $data['row_version'] + 1, 'updated_at' => now()]);
        });
        abort_unless($updated, 409, 'Nháp đã được sửa bởi thao tác khác. Hãy tải lại.');

        if (($data['intent'] ?? 'draft') === 'apply') {
            $batch = app(QuickApply::class)->apply((int) $site->id, [$id], $request->user()->id, 'page:'.$id.':v'.($data['row_version'] + 1));

            return redirect('/admin/sites/'.$site->id.'/cache')->with('apply_batch_id', $batch->id)->with('status', 'Đã lưu và xử lý áp dụng trang. Xem kết quả và cache bên dưới.');
        }

        return back()->with('status', 'Đã lưu nháp trang. Bản live chưa thay đổi.');
    }

    public function pageStatus(Request $request, int $id, PageLifecycleService $service)
    {
        $page = DB::table('pages')->find($id);
        abort_unless($page, 404);
        $this->requireSite($request, (int) $page->site_id, 'write');
        $data = $request->validate(['status' => 'required|in:draft,archived,gone', 'row_version' => 'required|integer']);
        $service->changeStatus($id, $data['status'], $request->user()->id, (int) $data['row_version']);

        return back()->with('status', 'Đã chuyển trạng thái trang. Lịch sử và keyword đã gán được giữ; chọn Lưu & áp dụng để phục vụ trang trở lại.');
    }

    public function pageMove(Request $request, int $id, PageLifecycleService $service)
    {
        $page = DB::table('pages')->find($id);
        abort_unless($page, 404);
        $this->requireSite($request, (int) $page->site_id, 'write');
        $data = $request->validate(['canonical_path' => 'required|string|max:2000', 'row_version' => 'required|integer']);
        $service->movePath($id, $data['canonical_path'], $request->user()->id, (int) $data['row_version']);

        return back()->with('status', 'Đã chuyển URL và tạo redirect 301 từ đường dẫn cũ. Chọn Lưu & áp dụng để phục vụ URL mới.');
    }

    public function pageAggregate(Request $request, int $id, Planner $planner)
    {
        $page = DB::table('pages')->find($id);
        abort_unless($page, 404);
        $this->requireSite($request, (int) $page->site_id, 'write');
        $data = $request->validate(['page_ids' => 'required|array|max:10000', 'page_ids.*' => 'integer|distinct']);
        abort_unless(DB::table('pages')->where('site_id', $page->site_id)->whereIn('id', $data['page_ids'])->count() === count($data['page_ids']), 403);
        $planner->refreshAggregate($id, $data['page_ids'], $request->user()->id);

        return back()->with('status', 'Đã lưu danh sách trang tổng hợp. Chọn Lưu & áp dụng để cập nhật HTML đang phục vụ.');
    }

    public function preview(Request $request, Planner $planner)
    {
        $data = $request->validate(['site_id' => 'required|integer', 'page_ids' => 'nullable|array', 'page_ids.*' => 'integer', 'action' => 'nullable|in:publish,reallocate']);
        $site = $this->requireSite($request, (int) $data['site_id'], 'write');
        $ids = $data['page_ids'] ?? DB::table('pages')->where('site_id', $site->id)->where('status', '!=', 'archived')->pluck('id')->all();
        abort_if(count($ids) > 10000, 422, 'Một preview tối đa 10.000 trang.');
        abort_unless(DB::table('pages')->where('site_id', $site->id)->whereIn('id', $ids)->count() === count(array_unique($ids)), 403);
        $batch = $planner->preview($site->id, $ids, $request->user()->id, $data['action'] ?? 'publish', ['idempotency_key' => $request->input('idempotency_key', (string) Str::uuid())]);

        return redirect('/admin/batches/'.$batch->id.'?site_id='.$site->id);
    }

    private function batch(Request $request, int $id, string $permission = 'read')
    {
        $batch = DB::table('publish_batches')->find($id);
        abort_unless($batch, 404);
        $this->requireSite($request, (int) $batch->site_id, $permission);
        $siteIds = DB::table('publish_batch_items')->where('batch_id', $id)->distinct()->pluck('site_id');
        foreach ($siteIds as $siteId) {
            $this->requireSite($request, (int) $siteId, $permission);
        }
        if ($permission !== 'read') {
            foreach ([$request->query('site_id'), $request->request->get('site_id')] as $selectedSite) {
                if ($selectedSite !== null && filter_var($selectedSite, FILTER_VALIDATE_INT) !== (int) $batch->site_id) {
                    throw new Conflict('BATCH_SITE_MISMATCH');
                }
            }
        }

        return $batch;
    }

    private function batchStatusLabels(): array
    {
        return ['pending' => 'Chờ xuất bản', 'claimed' => 'Đã giữ chỗ', 'rendering' => 'Đang tạo HTML', 'activating' => 'Đang kích hoạt', 'published' => 'Đã xuất bản', 'unchanged' => 'Không thay đổi', 'locked' => 'Đã khóa', 'error' => 'Lỗi xem trước', 'stale' => 'Cần xem trước lại', 'capacity_conflict' => 'Vượt giới hạn keyword', 'failed' => 'Thất bại', 'cancelled' => 'Đã hủy'];
    }

    public function batches(Request $request)
    {
        $request->validate(['site_id' => 'nullable|integer|min:1']);
        $sites = $this->sites($request);
        $site = $request->filled('site_id') ? $this->requireSite($request) : null;
        $batches = DB::table('publish_batches')->join('sites', 'sites.id', '=', 'publish_batches.site_id')
            ->whereIn('publish_batches.site_id', $sites->pluck('id'))
            ->when($site, fn ($query) => $query->where('publish_batches.site_id', $site->id))
            ->select('publish_batches.*')->orderByDesc('publish_batches.id')->paginate(25)->withQueryString();
        $statusLabels = $this->batchStatusLabels();

        return $this->view($request, 'batches', compact('batches', 'sites', 'site', 'statusLabels'));
    }

    public function showBatch(Request $request, int $id)
    {
        $batch = $this->batch($request, $id);
        $site = $this->requireSite($request, (int) $batch->site_id);
        if ($request->filled('site_id') && (string) $request->query('site_id') !== (string) $site->id) {
            return redirect('/admin/batches/'.$id.'?site_id='.$site->id)
                ->with('status', 'Batch #'.$id.' thuộc domain '.app(AdminSiteLabels::class)->label($site).' (#'.$site->id.'). Đã chuyển về đúng domain của batch.');
        }
        $items = DB::table('publish_batch_items')->join('pages', 'pages.id', '=', 'publish_batch_items.page_id')->leftJoin('page_publications as preview_heads', 'preview_heads.id', '=', 'publish_batch_items.expected_current_publication_id')->where('batch_id', $id)->select('publish_batch_items.*', 'pages.canonical_path', 'preview_heads.snapshot_json as expected_snapshot_json')->orderBy('page_id')->get();
        $statusLabels = $this->batchStatusLabels();
        $statusCounts = $items->countBy('status');
        $errorCounts = $items->filter(fn ($item) => $item->error_code !== null)->countBy('error_code');

        return $this->view($request, 'batch', compact('batch', 'items', 'site', 'statusLabels', 'statusCounts', 'errorCounts'));
    }

    public function acceptBatch(Request $request, int $id, Publisher $publisher)
    {
        $this->batch($request, $id, 'write');
        $batch = $publisher->accept($id, $request->user()->id);

        return back()->with('status', $batch->status === 'completed' ? 'Batch đã hoàn thành. Các mục không đổi giữ nguyên snapshot, không tạo publication thừa.' : 'Đã tiếp nhận batch vào queue; tải lại để xem trạng thái bền vững.');
    }

    public function cancelBatch(Request $request, int $id, Publisher $publisher)
    {
        $this->batch($request, $id, 'write');
        $publisher->cancel($id, $request->user()->id);

        return back()->with('status', 'Đã yêu cầu hủy. Các trang đã xuất bản được giữ lại.');
    }

    public function retryBatch(Request $request, int $id)
    {
        $this->batch($request, $id, 'write');
        app(Publisher::class)->retry($id, $request->user()->id);

        return back()->with('status', 'Đã yêu cầu retry các mục được phép.');
    }

    public function rollback(Request $request, int $id, Publisher $publisher)
    {
        $publication = DB::table('page_publications')->find($id);
        abort_unless($publication, 404);
        $this->requireSite($request, (int) $publication->site_id, 'write');
        $data = $request->validate(['expected_head_id' => 'required|integer']);
        $batch = $publisher->rollbackPreview($id, (int) $data['expected_head_id'], $request->user()->id);

        return redirect('/admin/batches/'.$batch->id.'?site_id='.$publication->site_id);
    }

    public function sources(Request $request)
    {
        $scope = $this->sites($request)->pluck('id');
        $sources = DB::table('source_connectors')->whereIn('id', DB::table('source_site_bindings')->whereIn('site_id', $scope)->select('connector_id'))->orderBy('id')->get();
        $readableSources = $sources;
        if (! $request->user()->is_global_admin) {
            $foreign = DB::table('source_site_bindings')->where('status', 'active')->whereNotIn('site_id', $scope)->pluck('connector_id');
            $sources = $sources->reject(fn ($source) => $foreign->contains($source->id))->values();
        }
        $typeOnlySources = $readableSources->whereNotIn('id', $sources->pluck('id'))->whereIn('kind', ['maccms', 'shandian'])->values();
        $typeSourceBindings = DB::table('source_site_bindings')->whereIn('connector_id', $typeOnlySources->pluck('id'))->whereIn('site_id', $scope)->whereNull('deleted_at')->whereIn('status', ['active', 'paused'])->get()->groupBy('connector_id');
        $runs = DB::table('ingest_runs')->whereIn('connector_id', $sources->pluck('id'))->orderByDesc('id')->limit(30)->get();
        $sourceBindings = DB::table('source_site_bindings')->whereIn('connector_id', $sources->pluck('id'))->whereIn('site_id', $scope)->whereNull('deleted_at')->get()->groupBy('connector_id');
        $quarantine = DB::table('ingestion_quarantine')->whereIn('run_id', $runs->pluck('id'))->orderByDesc('id')->limit(100)->get();
        $ownerSiteIds = DB::table('site_memberships')->where('user_id', $request->user()->id)->where('role', 'owner')->pluck('site_id');
        $operateSiteIds = DB::table('site_memberships')->where('user_id', $request->user()->id)->whereIn('role', ['owner', 'operator'])->pluck('site_id');
        $sourceCanWrite = $sources->mapWithKeys(function ($source) use ($request, $sourceBindings, $ownerSiteIds) {
            $activeBindings = ($sourceBindings[$source->id] ?? collect())->where('status', 'active');

            return [$source->id => $request->user()->is_global_admin || ($activeBindings->isNotEmpty() && $activeBindings->every(fn ($binding) => $ownerSiteIds->contains($binding->site_id)))];
        })->all();
        $sourceCanOperate = $sources->mapWithKeys(function ($source) use ($request, $sourceBindings, $operateSiteIds) {
            $activeBindings = ($sourceBindings[$source->id] ?? collect())->where('status', 'active');

            return [$source->id => $request->user()->is_global_admin || ($activeBindings->isNotEmpty() && $activeBindings->every(fn ($binding) => $operateSiteIds->contains($binding->site_id)))];
        })->all();
        $sourceWriteSites = $this->sites($request)->filter(fn ($site) => $request->user()->is_global_admin || $ownerSiteIds->contains($site->id))->values();

        return $this->view($request, 'sources', compact('sources', 'runs', 'sourceBindings', 'quarantine', 'typeOnlySources', 'typeSourceBindings', 'sourceCanWrite', 'sourceCanOperate', 'sourceWriteSites'));
    }

    private function source(Request $request, int $id)
    {
        $source = DB::table('source_connectors')->find($id);
        abort_unless($source, 404);
        $bindings = DB::table('source_site_bindings')->where('connector_id', $id)->where('status', 'active')->get();
        abort_unless($bindings->isNotEmpty() || $request->user()->is_global_admin, 403);
        foreach ($bindings as $binding) {
            $this->requireSite($request, (int) $binding->site_id, 'write');
        }

        return $source;
    }

    public function saveSource(Request $request)
    {
        $data = $request->validate(['site_id' => 'required|integer', 'name' => 'required|string|max:200', 'kind' => 'required|in:maccms,json,csv,html,fixture', 'endpoint' => 'nullable|url:https|max:2000']);
        $site = $this->requireSite($request, (int) $data['site_id'], 'owner');
        $config = $request->boolean('source_form_present')
            ? SourceConfigurationForm::fromRequest($request, $data['kind'])
            : $this->jsonInput($request, 'config_json');
        abort_unless(is_array($config), 422, 'Cấu hình nguồn phải là một nhóm trường hợp lệ.');
        if (! $request->boolean('source_form_present') && ! empty($data['endpoint'])) {
            $config['endpoint'] = $data['endpoint'];
            $config['allowed_hosts'] = [parse_url($data['endpoint'], PHP_URL_HOST)];
        }
        $id = DB::transaction(function () use ($data, $config, $site, $request) {
            $id = DB::table('source_connectors')->insertGetId(['kind' => $data['kind'], 'name' => $data['name'], 'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE), 'status' => 'active', 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('source_site_bindings')->insert(['connector_id' => $id, 'site_id' => $site->id, 'mapping_version' => 1, 'auto_publish_policy' => json_encode(['enabled' => false]), 'status' => 'active']);
            $this->bump($site->id);
            $this->audit($request, 'source.created', $site->id, ['connector_id' => $id]);

            return $id;
        });

        return back()->with('status', 'Đã tạo nguồn #'.$id.'. Nội dung nhận vào ở trạng thái nháp.');
    }

    public function crawl(Request $request, int $id, CrawlService $service)
    {
        $source = $this->source($request, $id);
        $data = $request->validate(['crawl_mode' => 'sometimes|required|in:initial,latest', 'idempotency_key' => 'nullable|string|max:255']);
        $key = $data['idempotency_key'] ?? (string) Str::uuid();
        if (! isset($data['crawl_mode'])) {
            $request->validate(['max_pages' => 'required|integer|min:1|max:3']);
            $run = $service->queue($id, $key, $request->integer('max_pages', 3));
        } elseif ($data['crawl_mode'] === 'initial') {
            $limit = $request->validate(['initial_limit_mode' => 'required|in:all,pages', 'initial_max_pages' => 'nullable|required_if:initial_limit_mode,pages|integer|min:1|max:1000000']);
            $run = $service->queue($id, $key, 3, 'initial', $limit['initial_limit_mode'] === 'pages' ? (int) $limit['initial_max_pages'] : null);
        } else {
            $request->validate(['latest_max_pages' => 'sometimes|required|integer|in:3,4,5']);
            $run = $service->queue($id, $key, 3, 'latest', $request->integer('latest_max_pages', (int) ($source->latest_max_pages ?? 3)));
        }

        return back()->with('status', 'Đã xếp hàng nhận dữ liệu. Mỗi job xử lý tối đa 3 trang rồi tự xếp phần tiếp theo trong phạm vi đã chọn; theo dõi tiến độ bên dưới.')->with('crawl_result', $run);
    }

    public function resumeCrawl(Request $request, int $id, CrawlService $service)
    {
        $run = DB::table('ingest_runs')->find($id);
        abort_unless($run, 404);
        $this->source($request, (int) $run->connector_id);
        $request->validate(['max_pages' => 'sometimes|required|integer|min:1|max:3']);
        $result = $service->queueResume($id, $run->crawl_mode ? 3 : $request->integer('max_pages', 3));

        return back()->with('crawl_result', $result)->with('status', 'Đã xếp tiếp tục từ cursor đã lưu, giữ chế độ và phạm vi của lượt gốc.');
    }

    public function pauseCrawl(Request $request, int $id, CrawlService $service)
    {
        $run = DB::table('ingest_runs')->find($id);
        abort_unless($run, 404);
        $this->source($request, (int) $run->connector_id);
        $result = $service->pause($id);

        return back()->with('crawl_result', $result)->with('status', 'Đã yêu cầu tạm dừng lượt nhận. Cursor và dữ liệu đã nhận được giữ để tiếp tục đúng lượt này.');
    }

    public function sourcePolicy(Request $request, int $id, SiteSourceBindingService $service)
    {
        $siteData = $request->validate(['site_id' => 'required|integer|min:1']);
        $site = $this->requireSite($request, (int) $siteData['site_id'], 'owner');
        $binding = DB::table('source_site_bindings')->where(['connector_id' => $id, 'site_id' => $site->id])->first() ?? abort(404);
        $data = $request->validate(['mapping_version' => 'required|integer|min:1', 'enabled' => 'nullable|boolean']);
        $data['enabled'] = $request->boolean('enabled');
        $data['status'] = $binding->status;
        $service->update($site->id, $id, $data, $request->user()->id);

        return redirect('/admin/sites/'.$site->id.'/edit?tab=sources')->with('status', 'Đã lưu cấu hình tự xuất bản. Mapping type và trang live giữ nguyên.');
    }

    public function updateSource(Request $request, int $id)
    {
        $source = $this->source($request, $id);
        foreach (DB::table('source_site_bindings')->where('connector_id', $id)->where('status', 'active')->pluck('site_id') as $siteId) {
            $this->requireSite($request, (int) $siteId, 'owner');
        }
        $data = $request->validate(['name' => 'required|string|max:200', 'status' => 'required|in:active,paused,archived', 'row_version' => 'required|integer', 'schedule_interval_minutes' => 'required|integer|min:0|max:10080']);
        if ($data['schedule_interval_minutes'] > 0 && $data['schedule_interval_minutes'] < 5) {
            throw ValidationException::withMessages(['schedule_interval_minutes' => 'Lịch crawl tối thiểu 5 phút; 0 để tắt.']);
        }
        $config = $request->boolean('source_form_present')
            ? SourceConfigurationForm::fromRequest($request, $source->kind, Json::decode($source->config_json))
            : $this->jsonInput($request, 'config_json');
        abort_unless(is_array($config), 422, 'Cấu hình nguồn phải là một nhóm trường hợp lệ.');
        $config['schedule_interval_minutes'] = (int) $data['schedule_interval_minutes'];
        $updated = DB::table('source_connectors')->where('id', $id)->where('row_version', $data['row_version'])->update(['name' => $data['name'], 'status' => $data['status'], 'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE), 'row_version' => $data['row_version'] + 1, 'updated_at' => now()]);
        abort_unless($updated, 409, 'Cấu hình nguồn đã thay đổi. Hãy tải lại.');
        $this->audit($request, 'source.updated', null, ['connector_id' => $id, 'status' => $data['status']]);

        return back()->with('status', 'Đã lưu cấu hình/lịch nguồn. Cấu hình mới sẽ được fetch guard kiểm tra trước khi kết nối.');
    }

    public function importContent(Request $request, int $id, ImportService $service, ContentFileParser $parser)
    {
        $source = $this->source($request, $id);
        $data = $request->validate(['file' => 'required|file|max:10240', 'format' => 'required|in:json,csv']);
        $mapping = Json::decode($source->config_json)['field_mapping'] ?? [];
        abort_unless(is_array($mapping) && count($mapping) <= 100, 422, 'Bảng ghép trường của nguồn không hợp lệ.');
        foreach ($mapping as $canonical => $sourceField) {
            abort_unless(is_string($canonical) && is_string($sourceField) && $sourceField !== '', 422, 'Bảng ghép trường của nguồn không hợp lệ.');
        }
        $records = $parser->parse(file_get_contents($request->file('file')->getRealPath()), $data['format'], $mapping);
        $result = $service->queueImportRecords($id, $records, $request->input('idempotency_key', (string) Str::uuid()));

        return back()->with('import_result', $result)->with('status', 'Đã xếp import vào queue. Worker xử lý từng chunk và ghi kết quả nhận/phân tích riêng.');
    }

    public function activity(Request $request, ActivityRecordService $records)
    {
        $filters = $records->filters($request->all());
        $request->validate(['audit_page' => 'nullable|integer|min:1', 'jobs_page' => 'nullable|integer|min:1']);
        $sites = $this->sites($request);
        $site = $request->filled('site_id') ? $this->requireSite($request) : null;
        $trash = $request->boolean('trash');
        $canManage = (bool) $request->user()->is_global_admin;
        $auditQuery = $records->query('audit', $request->user()->id, $filters, $trash);
        $failedQuery = $records->query('failed_job', $request->user()->id, $filters, $trash);
        $logCutoff = (int) (clone $auditQuery)->max('id');
        $jobCutoff = (int) (clone $failedQuery)->max('id');
        $logs = $auditQuery->orderByDesc('id')->paginate(30, ['*'], 'audit_page')->withQueryString();
        $failed = $failedQuery->orderByDesc('id')->paginate(15, ['id', 'queue', 'failed_at'], 'jobs_page')->withQueryString();

        return $this->view($request, 'activity', compact('logs', 'failed', 'sites', 'site', 'trash', 'canManage', 'logCutoff', 'jobCutoff'));
    }

    public function activityRecords(Request $request, ActivityRecordService $records)
    {
        $count = $records->mutate($request->all(), $request->user()->id);
        $message = $request->input('action') === 'restore'
            ? 'Đã khôi phục '.$count.' bản ghi khỏi thùng rác.'
            : 'Đã chuyển '.$count.' bản ghi vào thùng rác. Có thể khôi phục.';
        $filters = array_filter($request->only(['site_id', 'q', 'from', 'to', 'trash']), fn ($value): bool => $value !== null && $value !== '');

        return redirect('/admin/activity'.($filters !== [] ? '?'.http_build_query($filters) : ''))->with('status', $message);
    }

    public function baidu(Request $request)
    {
        $request->validate(['site_id' => 'nullable|integer|min:1']);
        $sites = $this->sites($request);
        $site = $request->filled('site_id') ? $this->requireSite($request) : null;
        $configuration = $site ? DB::table('baidu_configs')->where('site_id', $site->id)->first(['site_id', 'endpoint', 'status', 'schema_verified', 'updated_at']) : null;
        $submissions = DB::table('baidu_submissions')->whereIn('site_id', $site ? [$site->id] : $sites->pluck('id'))->orderByDesc('id')->paginate(25)->withQueryString();
        $observations = DB::table('search_observations')->whereIn('site_id', $site ? [$site->id] : $sites->pluck('id'))->orderByDesc('observed_at')->limit(10)->get();

        return $this->view($request, 'baidu', compact('configuration', 'submissions', 'observations', 'sites', 'site'));
    }

    public function configureBaidu(Request $request, BaiduService $service)
    {
        $data = $request->validate(['site_id' => 'required|integer', 'endpoint' => 'required|url:https|max:2000', 'token' => 'nullable|string|max:500', 'schema_verified' => 'nullable|boolean']);
        $site = $this->requireSite($request, (int) $data['site_id'], 'owner');
        $service->configure($site->id, $data['token'] ?? null, $data['endpoint'], $request->boolean('schema_verified') ? 'active' : 'not_configured', $request->boolean('schema_verified'));
        $this->audit($request, 'baidu.configured', $site->id, ['schema_verified' => $request->boolean('schema_verified')]);

        return back()->with('status', 'Đã lưu cấu hình Baidu. Token được mã hóa và không hiển thị lại.');
    }

    public function enqueueBaidu(Request $request, BaiduService $service)
    {
        $data = $request->validate(['site_id' => 'required|integer']);
        $site = $this->requireSite($request, (int) $data['site_id'], 'write');
        $ids = DB::table('pages')->where('site_id', $site->id)->where('status', 'live')->whereNotNull('current_publication_id')->pluck('current_publication_id')->all();
        $submissions = $service->enqueue($site->id, $ids);

        return back()->with('status', 'Đã ghi nhận '.count($submissions).' yêu cầu gửi URL. Trạng thái index chưa xác định.');
    }
}
