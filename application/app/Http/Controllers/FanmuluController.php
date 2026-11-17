<?php

namespace App\Http\Controllers;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Fanmulu\FanmuluSourceCatalog;
use App\Domain\Fanmulu\FanmuluUrlRegistry;
use App\Domain\Keywords\PoolCatalogService;
use App\Domain\Rules\Html;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Sites\UrlSuffixCatalog;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class FanmuluController extends Controller
{
    public function overview(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:253', 'status' => 'nullable|in:active,paused,archived,all',
            'fanmulu' => 'nullable|in:on,off,all', 'per_page' => 'nullable|integer|in:25,50,100']);
        $filters = ['q' => trim($data['q'] ?? ''), 'status' => $data['status'] ?? 'active',
            'fanmulu' => $data['fanmulu'] ?? 'all', 'per_page' => (int) ($data['per_page'] ?? 25)];
        $query = CatalogAvailability::filter(DB::table('sites'), 'sites')->where('content_type', 'movie')
            ->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('id',
                DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['fanmulu'] !== 'all', fn ($query) => $query->whereRaw("COALESCE(presentation_json #>> '{fanmulu,enabled}', 'false') = ?", [$filters['fanmulu'] === 'on' ? 'true' : 'false']));
        if ($filters['q'] !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($where) => $where->where('base_url', 'ilike', $pattern)
                ->orWhereExists(fn ($domains) => $domains->selectRaw('1')->from('site_domains')
                    ->whereColumn('site_domains.site_id', 'sites.id')->where('host_ascii', 'ilike', $pattern)));
        }
        $websites = $query->orderBy('base_url')->orderBy('id')->paginate($filters['per_page'], ['id', 'name', 'base_url', 'locale', 'status', 'presentation_json'])->withQueryString();
        $readiness = $this->readiness(collect($websites->items()));

        return view('admin.fanmulu-overview', compact('websites', 'filters', 'readiness') + [
            'site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Phim chính & fanmulu theo domain',
        ]);
    }

    public function index(Request $request, int $id, SitePresentationService $presentation): View
    {
        $configuration = $presentation->configuration($id, $request->user()->id);
        $site = $configuration['site'];
        abort_unless($site->content_type === 'movie', 404);
        $filters = $request->validate([
            'kind' => 'nullable|in:all,regular,fanmulu',
            'status' => 'nullable|in:draft,live,archived,gone',
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $filters = ['kind' => $filters['kind'] ?? 'fanmulu', 'status' => $filters['status'] ?? '',
            'q' => $filters['q'] ?? '', 'per_page' => (int) ($filters['per_page'] ?? 25)];
        $settings = FanmuluSettings::fromSite($site);
        $selectedNamePools = $settings['display_name_pool_ids'] ?? [];
        $availableNamePools = DB::table('keywords')->where(['status' => 'active', 'role' => 'primary', 'locale' => $site->locale])
            ->whereNull('deleted_at')->distinct()->pluck('pool_id')->map(fn ($poolId) => (int) $poolId)->all();
        $visibleNamePoolIds = app(PoolCatalogService::class)->visiblePools($request->user()->id)->pluck('id')->all();
        $displayNamePools = DB::table('keyword_pools')->whereIn('id', array_unique([...$visibleNamePoolIds, ...$selectedNamePools]))->orderBy('name')->get()
            ->filter(fn ($pool) => ($pool->scope === 'shared' || (int) $pool->owner_site_id === (int) $site->id)
                && (in_array((int) $pool->id, $selectedNamePools, true)
                    || ($pool->status === 'active' && $pool->deleted_at === null && in_array((int) $pool->id, $availableNamePools, true))))
            ->map(function ($pool) use ($availableNamePools) {
                $pool->name_pool_available = $pool->status === 'active' && $pool->deleted_at === null && in_array((int) $pool->id, $availableNamePools, true);

                return $pool;
            });
        $readiness = $this->readiness(collect([$site]))->get($id);
        $suffixChoices = app(UrlSuffixCatalog::class)->choices();
        $currentSuffix = $settings['suffix'] ?? '';
        if (! $suffixChoices->contains('suffix', $currentSuffix)) {
            $suffixChoices->push((object) ['suffix' => $currentSuffix, 'label' => 'Giá trị đang dùng · đã bỏ khỏi danh sách']);
        }
        $pages = DB::table('pages')
            ->leftJoin('page_publications as current_publication', function ($join) {
                $join->on('current_publication.id', '=', 'pages.current_publication_id')
                    ->on('current_publication.site_id', '=', 'pages.site_id');
            })
            ->leftJoin('page_keyword_assignments as primary_assignment', function ($join) {
                $join->on('primary_assignment.publication_id', '=', 'pages.current_publication_id')
                    ->on('primary_assignment.page_id', '=', 'pages.id')->where('primary_assignment.role', 'primary')
                    ->whereRaw("primary_assignment.purpose = CASE WHEN EXISTS (SELECT 1 FROM page_keyword_assignments display_pin
                        WHERE display_pin.publication_id = primary_assignment.publication_id AND display_pin.page_id = pages.id
                            AND display_pin.role = 'primary' AND display_pin.purpose = 'fanmulu_title') THEN 'fanmulu_title' ELSE 'seo' END");
            })
            ->leftJoin('pages as source_page', function ($join) {
                $join->on('source_page.id', '=', 'pages.source_page_id')
                    ->on('source_page.site_id', '=', 'pages.site_id');
            })
            ->leftJoin('page_publications as source_publication', function ($join) {
                $join->on('source_publication.id', '=', 'pages.source_publication_id')
                    ->on('source_publication.site_id', '=', 'pages.site_id');
            })
            ->where('pages.site_id', $id)->where('pages.page_type', 'movie')
            ->when($filters['kind'] !== 'all', fn ($query) => $query->where('pages.page_kind', $filters['kind']))
            ->when($filters['status'] !== '', fn ($query) => $query->where('pages.status', $filters['status']))
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $search = '%'.$filters['q'].'%';
                $query->where(function ($where) use ($search) {
                    $where->where('pages.canonical_path', 'ilike', $search)
                        ->orWhereRaw("current_publication.snapshot_json #>> '{fanmulu,display_title}' ilike ?", [$search])
                        ->orWhereRaw("source_publication.snapshot_json #>> '{content,title}' ilike ?", [$search])
                        ->orWhereRaw("current_publication.snapshot_json #>> '{content,title}' ilike ?", [$search])
                        ->orWhere('primary_assignment.text_snapshot', 'ilike', $search);
                });
            })
            ->select('pages.*', 'source_page.canonical_path as source_path', 'source_page.status as source_status', 'primary_assignment.text_snapshot as assigned_keyword')
            ->selectRaw("current_publication.snapshot_json #>> '{fanmulu,display_title}' as published_display_title,
                source_publication.snapshot_json #>> '{content,title}' as pinned_source_title,
                current_publication.snapshot_json #>> '{content,title}' as published_original_title")
            ->orderByDesc('pages.id')->paginate($filters['per_page'])->withQueryString();
        $pages->getCollection()->transform(function ($page) {
            $page->display_title = $page->published_display_title ?? $page->assigned_keyword;
            $page->source_title = $page->pinned_source_title ?? $page->published_original_title;

            return $page;
        });
        $counts = DB::table('pages')->where('site_id', $id)->where('page_type', 'movie')
            ->selectRaw('page_kind, count(*) as total')->groupBy('page_kind')->pluck('total', 'page_kind');

        return view('admin.fanmulu', compact('site', 'configuration', 'settings', 'filters', 'pages', 'counts', 'suffixChoices', 'readiness', 'displayNamePools') + [
            'sites' => collect(), 'scope' => '?site_id='.$id, 'globalManagement' => true, 'managementLabel' => 'Trang chính quy & fanmulu',
        ]);
    }

    public function save(Request $request, int $id, SitePresentationService $presentation): RedirectResponse
    {
        $configuration = $presentation->configuration($id, $request->user()->id);
        abort_unless($configuration['can_write'], 403);
        abort_unless($configuration['site']->content_type === 'movie', 404);
        $data = $request->validate([
            'row_version' => 'required|integer|min:1',
            'fanmulu' => 'required|array:enabled,prefix,prefixes,prefixes_text,suffix,links_per_page,max_pages,url_rule,display_name_pool_ids,display_name_pool_ids_present',
            'fanmulu.display_name_pool_ids_present' => 'sometimes|boolean',
            'fanmulu.display_name_pool_ids' => 'sometimes|array|max:1000',
            'fanmulu.display_name_pool_ids.*' => 'required|integer|min:1|distinct',
            'fanmulu.enabled' => 'required|boolean',
            'fanmulu.prefix' => 'required|string|max:120',
            'fanmulu.prefixes' => 'sometimes|array|max:20',
            'fanmulu.prefixes.*' => 'string|max:120',
            'fanmulu.prefixes_text' => 'nullable|string|max:2500',
            'fanmulu.suffix' => 'nullable|string|max:13',
            'fanmulu.links_per_page' => 'required|integer|min:0|max:20',
            'fanmulu.max_pages' => 'required|integer|min:1|max:1000000',
            'fanmulu.url_rule' => 'sometimes|required|array:mode,alphabet,length,group_length,separator,preset,date_format,template',
            'fanmulu.url_rule.mode' => 'required_with:fanmulu.url_rule|in:legacy,random,pattern,tokens',
            'fanmulu.url_rule.preset' => 'sometimes|required|in:legacy,letters,digits,alnum,date_letters,date_digits,date_alnum,custom',
            'fanmulu.url_rule.template' => 'sometimes|required|string|max:180',
            'fanmulu.url_rule.alphabet' => 'sometimes|required|in:alnum,letters,digits',
            'fanmulu.url_rule.length' => 'sometimes|required|integer|min:8|max:64',
            'fanmulu.url_rule.group_length' => 'sometimes|required|integer|min:0|max:16',
            'fanmulu.url_rule.separator' => 'sometimes|nullable|in:-,_,/',
            'fanmulu.url_rule.date_format' => 'sometimes|required|in:Ymd,Y-m-d,Y/m/d,dmY,d-m-Y',
        ], [
            'row_version.required' => 'Phiên bản cấu hình bị thiếu. Tải lại trang trước khi lưu.',
            'fanmulu.enabled.required' => 'Chọn trạng thái tạo trang fanmulu.',
            'fanmulu.prefix.required' => 'Nhập đường dẫn gốc cho trang fanmulu.',
            'fanmulu.links_per_page.max' => 'Mỗi trang chính quy được chèn tối đa 20 liên kết fanmulu.',
            'fanmulu.max_pages.min' => 'Giới hạn trang fanmulu phải từ 1 trở lên.',
        ]);
        try {
            $settings = FanmuluSettings::fromForm($data['fanmulu']);
            FanmuluSettings::assertDisplayNamePools($settings['display_name_pool_ids'] ?? [], $id, $configuration['site']->locale);
            $presentation->saveFanmulu($id, $settings, (int) $data['row_version'], $request->user()->id);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['fanmulu' => $error->getMessage()]);
        }

        return redirect('/admin/sites/'.$id.'/fanmulu')->with('status', 'Đã lưu cấu hình fanmulu. Keyword và nội dung của các URL đã xuất bản vẫn giữ nguyên.');
    }

    public function sample(Request $request, int $id, SitePresentationService $presentation, FanmuluUrlRegistry $registry): RedirectResponse
    {
        $configuration = $presentation->configuration($id, $request->user()->id);
        abort_unless($configuration['can_write'], 403);
        $site = $configuration['site'];
        abort_unless($site->content_type === 'movie', 404);
        $data = $request->validate(['request_id' => 'required|uuid']);
        $state = $this->readiness(collect([$site]))->get($id);
        abort_unless($state['sample_action'] !== null && $state['source_page_id'] !== null, 409, $state['message']);
        $settings = $state['settings'];
        $path = $registry->issue($id, Json::canonicalEncode(['admin', $id, $state['source_page_id'], $data['request_id'],
            $settings['url_rule'], $settings['prefix'], $settings['suffix']]), $settings['url_rule'], $settings['prefix'], $settings['suffix'], [
                'origin' => 'admin', 'source_page_id' => $state['source_page_id'],
            ]);

        return redirect()->away(rtrim((string) $site->base_url, '/').$path);
    }

    /** Admin GET reads only metadata; POST issues a URL mapping, and public GET creates the page. */
    private function readiness(Collection $sites): Collection
    {
        if ($sites->isEmpty()) {
            return collect();
        }
        $siteIds = $sites->pluck('id')->all();
        $pageCounts = DB::table('pages')->whereIn('site_id', $siteIds)->where('page_type', 'movie')->groupBy('site_id')
            ->select('site_id')->selectRaw("COUNT(*) FILTER (WHERE page_kind = 'regular' AND status = 'live' AND current_publication_id IS NOT NULL) AS regular_live,
                COUNT(*) FILTER (WHERE page_kind = 'regular' AND status = 'draft') AS regular_draft,
                COUNT(*) FILTER (WHERE page_kind = 'fanmulu') AS fanmulu_total,
                COUNT(*) FILTER (WHERE page_kind = 'fanmulu' AND status = 'live') AS fanmulu_live")
            ->get()->keyBy('site_id');
        $keywordCounts = DB::table('keyword_bindings as binding')->join('keyword_pools as pool', 'pool.id', '=', 'binding.pool_id')
            ->join('keywords as keyword', 'keyword.pool_id', '=', 'pool.id')->join('sites as site', 'site.id', '=', 'binding.site_id')
            ->whereIn('binding.site_id', $siteIds)->where(['binding.status' => 'active', 'pool.status' => 'active', 'keyword.status' => 'active', 'keyword.role' => 'primary'])
            ->whereNull('pool.deleted_at')->whereNull('keyword.deleted_at')->whereColumn('keyword.locale', 'site.locale')
            ->groupBy('binding.site_id')->select('binding.site_id')->selectRaw('COUNT(DISTINCT keyword.id) AS total')->get()->keyBy('site_id');

        return $sites->mapWithKeys(function (object $site) use ($pageCounts, $keywordCounts): array {
            $settings = FanmuluSettings::fromSite($site);
            $counts = $pageCounts->get($site->id);
            $regularLive = (int) ($counts->regular_live ?? 0);
            $regularDraft = (int) ($counts->regular_draft ?? 0);
            $keywordCount = (int) ($keywordCounts->get($site->id)->total ?? 0);
            if (! empty($settings['display_name_pool_ids'])) {
                $keywordCount = DB::table('keywords as keyword')->join('keyword_pools as pool', 'pool.id', '=', 'keyword.pool_id')
                    ->whereIn('pool.id', $settings['display_name_pool_ids'])
                    ->where(['pool.status' => 'active', 'keyword.status' => 'active', 'keyword.role' => 'primary', 'keyword.locale' => $site->locale])
                    ->whereNull('pool.deleted_at')->whereNull('keyword.deleted_at')
                    ->where(fn ($scope) => $scope->where('pool.scope', 'shared')->orWhere(fn ($owned) => $owned->where('pool.scope', 'site')->where('pool.owner_site_id', $site->id)))
                    ->count('keyword.id');
            }
            $sourceIds = $site->status === 'active' && $settings['enabled'] && ($regularLive > 0 || $regularDraft > 0)
                ? app(FanmuluSourceCatalog::class)->candidateIds((int) $site->id, 1) : [];
            $sourceId = $sourceIds[0] ?? null;
            $sampleUrl = null;
            $samplePath = null;
            $sampleAction = null;
            $sampleRequestId = null;
            if ($site->status !== 'active') {
                $message = 'Website đang tạm dừng hoặc lưu trữ.';
            } elseif (! $settings['enabled']) {
                $message = 'Fanmulu đang tắt. Bật tại cấu hình domain để tạo URL mới.';
            } elseif ($sourceId === null) {
                $message = 'Chưa có phim chính đang phục vụ hoặc đủ điều kiện tạo khi mở link. Kiểm tra nguồn phim và chế độ tạo trang.';
            } elseif ($keywordCount === 0) {
                $message = empty($settings['display_name_pool_ids'])
                    ? 'Chưa có keyword chính đang hoạt động trong các kho đã gắn.'
                    : 'Chưa có tên thay thế đang hoạt động trong các kho fanmulu đã chọn.';
            } else {
                $message = empty($settings['display_name_pool_ids'])
                    ? 'Có phim nguồn và keyword chính; dung lượng còn lại được kiểm tra khi mở URL.'
                    : 'Có phim nguồn và kho tên thay thế riêng; keyword SEO dùng cấu hình chung của website. Dung lượng còn lại được kiểm tra khi mở URL.';
                $baseUrl = rtrim((string) $site->base_url, '/');
                if (! Html::safeUrl($baseUrl.'/')) {
                    $message = 'Cần cấu hình URL chuẩn hợp lệ để mở trang thử.';
                } elseif (in_array($settings['url_rule']['mode'] ?? 'legacy', ['random', 'pattern', 'tokens'], true)) {
                    $sampleAction = '/admin/sites/'.$site->id.'/fanmulu/sample';
                    $sampleRequestId = (string) Str::uuid();
                } else {
                    $samplePath = rtrim($settings['prefix'], '/').'/'.$sourceId.'-admin-'.Str::lower(Str::random(12)).($settings['suffix'] ?? '');
                    $sampleUrl = $baseUrl.$samplePath;
                }
            }

            return [$site->id => ['settings' => $settings, 'regular_live' => $regularLive, 'regular_draft' => $regularDraft,
                'fanmulu_total' => (int) ($counts->fanmulu_total ?? 0), 'fanmulu_live' => (int) ($counts->fanmulu_live ?? 0),
                'active_primary_keywords' => $keywordCount, 'source_page_id' => $sourceId, 'sample_url' => $sampleUrl,
                'sample_path' => $samplePath, 'sample_action' => $sampleAction, 'sample_request_id' => $sampleRequestId, 'message' => $message]];
        });
    }
}
