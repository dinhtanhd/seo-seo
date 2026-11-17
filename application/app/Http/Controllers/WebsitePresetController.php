<?php

namespace App\Http\Controllers;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Sites\FanmuluPresetSettings;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Sites\UrlSuffixCatalog;
use App\Domain\Sites\WebsitePresetService;
use App\Domain\Support\AdminSiteLabels;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class WebsitePresetController extends Controller
{
    public function index(Request $request, WebsitePresetService $service): View
    {
        $this->authorizeManagement($request);

        return view('admin.website-presets', ['presets' => $service->listing($request->user()->id)] + $this->context());
    }

    public function create(Request $request): View
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['template_id' => 'nullable|integer|min:1']);
        $definition = [];
        if (! empty($data['template_id'])) {
            $template = CatalogAvailability::filter(DB::table('templates'), 'templates')->join('template_versions', 'template_versions.id', '=', 'templates.current_version_id')
                ->where('templates.id', $data['template_id'])->where('templates.status', 'active')
                ->first(['templates.id', 'template_versions.definition_json']);
            abort_unless($template && SeoTemplateBundle::isBundle(Json::decode($template->definition_json)), 404, 'Bộ Template SEO không còn hoạt động hoặc không đủ năm kiểu trang.');
            $definition['template_id'] = (int) $template->id;
        }

        return view('admin.website-presets-editor', $this->editorContext(null, null, $definition) + $this->context());
    }

    public function edit(Request $request, int $id, WebsitePresetService $service): View
    {
        $this->authorizeManagement($request);
        $configuration = $service->get($id, $request->user()->id);

        return view('admin.website-presets-editor', $this->editorContext($configuration['preset'], $configuration['version'], $configuration['definition']) + $this->context());
    }

    public function store(Request $request, WebsitePresetService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $preset = $service->save(null, $this->formData($request), $request->user()->id);

        return redirect('/admin/site-presets/'.$preset->id.'/edit')->with('status', 'Đã tạo bộ cấu hình. Chọn bộ này khi thêm website hàng loạt.');
    }

    public function update(Request $request, int $id, WebsitePresetService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $this->formData($request, true);
        $configuration = $service->get($id, $request->user()->id);
        if (isset($configuration['definition']['presentation']['fanmulu'])) {
            $data['definition']['presentation']['fanmulu'] = $configuration['definition']['presentation']['fanmulu'];
        }
        $service->save($id, $data, $request->user()->id);

        return redirect('/admin/site-presets/'.$id.'/edit')->with('status', 'Đã lưu phiên bản bộ cấu hình. Các website đã tạo giữ nguyên cấu hình đang dùng.');
    }

    public function archive(Request $request, int $id, WebsitePresetService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $service->archive($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/site-presets')->with('status', 'Đã lưu trữ bộ cấu hình. Website đã tạo vẫn hoạt động.');
    }

    public function seedDefaults(Request $request, WebsitePresetService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['base_site_id' => 'nullable|integer|min:1']);
        $service->seedDefaults($request->user()->id, isset($data['base_site_id']) ? (int) $data['base_site_id'] : null);

        return redirect('/admin/site-presets')->with('status', 'Đã chuẩn bị 10 bộ cấu hình mẫu. Các bộ đã có và dữ liệu website hiện hữu được giữ nguyên.');
    }

    public function restore(Request $request, int $id, WebsitePresetService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $service->restore($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/site-presets/'.$id.'/edit')->with('status', 'Đã khôi phục bộ cấu hình để dùng cho domain mới.');
    }

    private function formData(Request $request, bool $updating = false): array
    {
        $rules = ['name' => 'required|string|max:160', 'description' => 'nullable|string|max:1000', 'locale' => 'required|string|max:20',
            'theme_id' => 'required|integer|min:1', 'template_id' => 'required|integer|min:1', 'delivery_mode' => 'required|in:on_demand,prepublish',
            'pool_ids' => 'required|array|min:1|max:100', 'pool_ids.*' => 'integer|min:1|distinct', 'source_ids' => 'required|array|min:1|max:100', 'source_ids.*' => 'integer|min:1|distinct',
            'source_reference_site_id' => 'nullable|integer|min:1', 'primary_mode' => 'required|in:weighted,random-once,sequential', 'secondary_count' => 'required|integer|min:0|max:5',
            'anchor_count' => 'required|integer|min:0|max:5', 'keyword_cap' => 'nullable|integer|min:1|max:100000000', 'initial_movie_count' => 'required|integer|min:0|max:100',
            'movie_key' => 'required|in:source_id,pinyin_id,pinyin_compact_id', 'category_key' => 'required|in:id,pinyin_id,pinyin_compact_id',
            'routes' => 'required|array:home,list,detail,play,search', 'views' => 'required|array:home,list,detail,play,search', 'suffixes' => 'required|array:home,list,detail,play,search',
            'player_mode' => 'required|in:external_link,native', 'player_allowed_hosts' => 'nullable|string|max:10000',
            'fanmulu' => 'sometimes|required|array:enabled,prefix,prefixes,prefixes_text,suffix,links_per_page,max_pages,url_rule,display_name_pool_ids,display_name_pool_ids_present',
            'fanmulu.display_name_pool_ids_present' => 'sometimes|boolean',
            'fanmulu.display_name_pool_ids' => 'sometimes|array|max:1000',
            'fanmulu.display_name_pool_ids.*' => 'required|integer|min:1|distinct',
            'fanmulu.enabled' => 'required_with:fanmulu|boolean', 'fanmulu.prefix' => 'required_with:fanmulu|string|max:120',
            'fanmulu.prefixes' => 'sometimes|array|max:20', 'fanmulu.prefixes.*' => 'string|max:120',
            'fanmulu.prefixes_text' => 'nullable|string|max:2500',
            'fanmulu.suffix' => 'nullable|string|max:13', 'fanmulu.links_per_page' => 'required_with:fanmulu|integer|min:0|max:20',
            'fanmulu.max_pages' => 'required_with:fanmulu|integer|min:1|max:1000000',
            'fanmulu.url_rule' => 'sometimes|required|array:mode,alphabet,length,group_length,separator,preset,date_format,template',
            'fanmulu.url_rule.mode' => 'required_with:fanmulu.url_rule|in:legacy,random,pattern,tokens',
            'fanmulu.url_rule.preset' => 'sometimes|required|in:legacy,letters,digits,alnum,date_letters,date_digits,date_alnum,custom',
            'fanmulu.url_rule.template' => 'sometimes|required|string|max:180',
            'fanmulu.url_rule.alphabet' => 'sometimes|required|in:alnum,letters,digits',
            'fanmulu.url_rule.length' => 'sometimes|required|integer|min:8|max:64',
            'fanmulu.url_rule.group_length' => 'sometimes|required|integer|min:0|max:16',
            'fanmulu.url_rule.separator' => 'sometimes|nullable|in:-,_,/',
            'fanmulu.url_rule.date_format' => 'sometimes|required|in:Ymd,Y-m-d,Y/m/d,dmY,d-m-Y'];
        if ($updating) {
            $rules['row_version'] = 'required|integer|min:1';
        }
        foreach (SitePresentationService::TYPES as $role) {
            $rules['routes.'.$role] = 'required|string|max:120';
            $rules['views.'.$role] = 'required|in:home,list,detail,play,search';
            $rules['suffixes.'.$role] = 'nullable|string|max:13';
        }
        $data = $request->validate($rules);
        $presentation = ['configured' => true, 'movie_key' => $data['movie_key'], 'category_key' => $data['category_key'], 'routes' => $data['routes'], 'views' => $data['views'],
            'suffixes' => array_map(fn ($suffix) => $suffix ?? '', $data['suffixes']),
            'player' => ['mode' => $data['player_mode'], 'allowed_hosts' => preg_split('/[\s,;]+/u', trim($data['player_allowed_hosts'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)]];
        $definition = array_intersect_key($data, array_flip(['locale', 'theme_id', 'template_id', 'delivery_mode', 'source_reference_site_id', 'primary_mode', 'secondary_count', 'anchor_count', 'keyword_cap', 'initial_movie_count']));
        $definition['pool_ids'] = array_map('intval', $data['pool_ids'] ?? []);
        $definition['source_ids'] = array_map('intval', $data['source_ids'] ?? []);
        $definition['presentation'] = $presentation;
        if (array_key_exists('fanmulu', $data)) {
            $definition['fanmulu'] = FanmuluSettings::fromForm($data['fanmulu']);
        }

        return array_intersect_key($data, array_flip(['name', 'description', 'row_version'])) + ['definition' => $definition];
    }

    private function editorContext(?object $preset, ?object $version, array $definition): array
    {
        $definition = array_replace(['locale' => 'zh-CN', 'theme_id' => null, 'template_id' => null, 'delivery_mode' => 'on_demand', 'pool_ids' => [], 'source_ids' => [], 'source_reference_site_id' => null,
            'primary_mode' => 'weighted', 'secondary_count' => 2, 'anchor_count' => 0, 'keyword_cap' => 500000, 'initial_movie_count' => 24], $definition);
        $presentation = array_replace_recursive(SitePresentationService::defaults(), ['category_key' => 'pinyin_compact_id', 'movie_key' => 'pinyin_compact_id', 'suffixes' => array_fill_keys(SitePresentationService::TYPES, '')], $definition['presentation'] ?? []);
        $fanmulu = array_replace(FanmuluPresetSettings::defaults($preset === null), $definition['fanmulu'] ?? []);
        $themes = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where('status', 'active')->whereNotNull('current_version_id')->orderBy('name')->get(['id', 'name']);
        $templates = CatalogAvailability::filter(DB::table('templates'), 'templates')->join('template_versions', 'template_versions.id', '=', 'templates.current_version_id')->where('templates.status', 'active')->orderBy('templates.name')->get(['templates.id', 'templates.name', 'template_versions.definition_json'])->filter(fn ($template) => SeoTemplateBundle::isBundle(Json::decode($template->definition_json)));
        $pools = DB::table('keyword_pools')->where('scope', 'shared')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'locale']);
        $selectedNamePools = $fanmulu['display_name_pool_ids'] ?? [];
        $availableNamePools = DB::table('keywords')->where(['status' => 'active', 'role' => 'primary', 'locale' => $definition['locale']])
            ->whereNull('deleted_at')->distinct()->pluck('pool_id')->map(fn ($poolId) => (int) $poolId)->all();
        $displayNamePools = DB::table('keyword_pools')->where('scope', 'shared')
            ->where(fn ($query) => $query->whereIn('id', $selectedNamePools)->orWhere(fn ($active) => $active->where('status', 'active')->whereNull('deleted_at')->whereIn('id', $availableNamePools)))
            ->orderBy('name')->get(['id', 'name', 'locale', 'status', 'deleted_at'])
            ->map(function ($pool) use ($availableNamePools) {
                $pool->name_pool_available = $pool->status === 'active' && $pool->deleted_at === null && in_array((int) $pool->id, $availableNamePools, true);

                return $pool;
            });
        $sources = DB::table('source_connectors')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'kind']);
        $referenceSites = app(AdminSiteLabels::class)->sortSites(CatalogAvailability::filter(DB::table('sites'), 'sites')->where('content_type', 'movie')->where('status', 'active')->get(['id', 'name', 'base_url']));
        $suffixChoices = app(UrlSuffixCatalog::class)->choices();

        return compact('preset', 'version', 'definition', 'presentation', 'fanmulu', 'themes', 'templates', 'pools', 'sources', 'referenceSites', 'suffixChoices', 'displayNamePools');
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()->is_global_admin, 403, 'Chỉ quản trị toàn hệ thống được quản lý bộ cấu hình và thêm website.');
    }

    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'globalManagement' => true, 'managementLabel' => 'Bộ cấu hình website dùng chung'];
    }
}
