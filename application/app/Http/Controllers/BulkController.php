<?php

namespace App\Http\Controllers;

use App\Domain\Publishing\PageBulkService;
use App\Domain\Publishing\PublicationCampaignService;
use App\Domain\Publishing\QuickApply;
use App\Domain\Sites\SiteBulkService;
use App\Domain\Sites\UrlSuffixCatalog;
use App\Domain\Support\Access;
use App\Domain\Support\AdminSiteLabels;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class BulkController extends Controller
{
    private const PAGE_TYPES = ['home', 'category', 'movie', 'novel', 'episode', 'chapter', 'editorial'];

    private function context(Request $request, bool $write = false): array
    {
        if ($request->has('site_ids_json')) {
            $request->validate(['site_ids_json' => 'required|string|max:20000']);
            $ids = json_decode($request->input('site_ids_json'), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($ids) || ! array_is_list($ids)) {
                throw new \InvalidArgumentException('Phạm vi website phải là danh sách ID.');
            }
            $request->merge(['site_ids' => $ids]);
        }
        $data = $request->validate(['scope_selected' => 'nullable|boolean', 'site_id' => 'nullable|integer|min:1', 'site_ids' => 'nullable|array|max:1000', 'site_ids.*' => 'required|integer|min:1|distinct',
            'q' => 'nullable|string|max:200', 'page_type' => 'nullable|in:'.implode(',', self::PAGE_TYPES), 'status' => 'nullable|in:draft,live,archived,gone', 'category_id' => 'nullable|integer|min:1']);
        $sites = CatalogAvailability::filter(DB::table('sites'), 'sites')->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))->orderBy('id')->get();
        $sites = app(AdminSiteLabels::class)->sortSites($sites);
        $siteIds = array_values(array_unique(array_map('intval', $data['site_ids'] ?? [])));
        if (! $siteIds && ! $request->has('scope_selected') && ! $request->has('site_ids')) {
            if ($request->filled('site_id')) {
                $siteIds = [(int) $data['site_id']];
            } elseif ($write) {
                throw new \InvalidArgumentException('Chọn rõ các tên miền cần thao tác trước khi lưu.');
            } else {
                $siteIds = $sites->where('status', '!=', 'archived')->pluck('id')->all();
            }
        }
        sort($siteIds);
        foreach ($siteIds as $siteId) {
            abort_unless($sites->contains('id', $siteId), 403);
            Access::site($request->user()->id, $siteId, $write);
        }
        $site = count($siteIds) === 1 ? $sites->firstWhere('id', $siteIds[0]) : null;
        $categories = $site ? CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $site->id)->orderBy('name')->get() : collect();
        if (! empty($data['category_id'])) {
            abort_unless($site && $categories->contains('id', (int) $data['category_id']), 422, 'Lọc category chỉ áp dụng trong một website.');
        }
        $filters = ['site_ids' => $siteIds, 'q' => trim($data['q'] ?? ''), 'page_type' => $data['page_type'] ?? '', 'status' => $data['status'] ?? '', 'category_id' => (int) ($data['category_id'] ?? 0) ?: null];
        $query = DB::table('pages')->leftJoin('content_revisions', 'content_revisions.id', '=', 'pages.working_content_revision_id')->whereIn('pages.site_id', $siteIds);
        if ($filters['q'] !== '') {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($where) => $where->where('pages.canonical_path', 'ilike', $search)->orWhereRaw("content_revisions.payload_json->>'title' ILIKE ?", [$search]));
        }
        if ($filters['page_type']) {
            $query->where('pages.page_type', $filters['page_type']);
        }
        if ($filters['status']) {
            $query->where('pages.status', $filters['status']);
        }
        if ($filters['category_id']) {
            $query->where('pages.category_id', $filters['category_id']);
        }
        $writeSiteIds = $request->user()->is_global_admin ? $sites->pluck('id')->all() : DB::table('site_memberships')->where('user_id', $request->user()->id)->whereIn('role', ['owner', 'operator'])->pluck('site_id')->all();
        $canWrite = count($siteIds) > 0 && ! array_diff($siteIds, $writeSiteIds);
        $ownerSiteIds = $request->user()->is_global_admin ? $sites->pluck('id')->all() : DB::table('site_memberships')->where('user_id', $request->user()->id)->where('role', 'owner')->pluck('site_id')->all();
        $canWriteSites = count($siteIds) > 0 && ! array_diff($siteIds, $ownerSiteIds);
        $movieSitesOnly = count($siteIds) > 0 && ! $sites->whereIn('id', $siteIds)->contains(fn ($entry) => $entry->content_type !== 'movie');
        $canWriteSites = $canWriteSites && $movieSitesOnly;

        return compact('sites', 'site', 'siteIds', 'categories', 'filters', 'query', 'writeSiteIds', 'canWrite', 'canWriteSites', 'movieSitesOnly') + ['scope' => $site ? '?site_id='.$site->id : '', 'globalManagement' => true, 'managementLabel' => 'Thao tác nhiều tên miền'];
    }

    public function index(Request $request)
    {
        $context = $this->context($request);
        $matchingCount = (clone $context['query'])->count();
        $pages = (clone $context['query'])->select('pages.*')->selectRaw("content_revisions.payload_json->>'title' as original_title")->orderBy('pages.site_id')->orderBy('pages.id')->paginate(100)->appends($request->except('site_ids'));
        $pageTypes = self::PAGE_TYPES;
        $themes = DB::table('movie_themes')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']);
        $templates = DB::table('templates')->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $selectionKey = 'tram-bulk-'.$request->user()->id.'-'.Json::hash($context['filters']);
        $campaigns = app(PublicationCampaignService::class)->listFor($request->user()->id)->take(10);
        $suffixChoices = app(UrlSuffixCatalog::class)->choices();

        return view('admin.bulk', $context + compact('pages', 'matchingCount', 'pageTypes', 'themes', 'templates', 'selectionKey', 'campaigns', 'suffixChoices'));
    }

    private function selection(Request $request): array
    {
        $context = $this->context($request, true);
        if ($request->has('page_ids_json')) {
            $request->validate(['page_ids_json' => 'required|string|max:350000']);
            $ids = json_decode($request->input('page_ids_json'), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($ids) || ! array_is_list($ids)) {
                throw new \InvalidArgumentException('Lựa chọn trang phải là danh sách ID.');
            }
            $request->merge(['page_ids' => $ids]);
        }
        $data = $request->validate(['selection_mode' => 'required|in:ids,matching', 'page_ids' => 'nullable|array|max:10000', 'page_ids.*' => 'required|integer|min:1|distinct']);
        if ($data['selection_mode'] === 'ids') {
            if (empty($data['page_ids'])) {
                throw new \InvalidArgumentException('Chọn ít nhất một trang hoặc chọn rõ toàn bộ kết quả bộ lọc.');
            }
            $ids = array_map('intval', $data['page_ids']);
            abort_unless(DB::table('pages')->whereIn('id', $ids)->whereIn('site_id', $context['siteIds'])->count() === count($ids), 403, 'Trang đã chọn nằm ngoài phạm vi website.');

            return ['mode' => 'ids', 'page_ids' => $ids];
        }
        if (! $context['siteIds']) {
            throw new \InvalidArgumentException('Chọn ít nhất một website.');
        }

        return ['mode' => 'matching', 'site_ids' => $context['siteIds'], 'filters' => array_diff_key($context['filters'], ['site_ids' => true])];
    }

    private function changes(Request $request): array
    {
        $fields = ['theme_id', 'template_id', 'category_id', 'assignment_locked', 'publish_locked', 'noindex'];
        $rules = ['changes' => 'required|array:'.implode(',', $fields)];
        foreach ($fields as $field) {
            $rules['changes.'.$field] = 'nullable|array:mode,value';
            $rules['changes.'.$field.'.mode'] = 'required_with:changes.'.$field.'|in:keep,set';
            $rules['changes.'.$field.'.value'] = in_array($field, ['theme_id', 'template_id', 'category_id']) ? 'nullable|integer|min:1' : 'nullable|boolean';
        }
        $data = $request->validate($rules)['changes'];
        $changes = [];
        foreach ($fields as $field) {
            $entry = $data[$field] ?? ['mode' => 'keep'];
            if ($entry['mode'] === 'set') {
                if (in_array($field, ['assignment_locked', 'publish_locked', 'noindex'])) {
                    if (! array_key_exists('value', $entry) || $entry['value'] === null) {
                        throw new \InvalidArgumentException('Chọn bật hoặc tắt cho '.$field.'.');
                    }
                    $entry['value'] = filter_var($entry['value'], FILTER_VALIDATE_BOOLEAN);
                } else {
                    $entry['value'] = isset($entry['value']) ? (int) $entry['value'] : null;
                }
            } else {
                $entry = ['mode' => 'keep'];
            }
            $changes[$field] = $entry;
        }

        return $changes;
    }

    private function operationContext(Request $request, array $ids): array
    {
        $sites = CatalogAvailability::filter(DB::table('sites'), 'sites')->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))->orderBy('id')->get();
        $sites = app(AdminSiteLabels::class)->sortSites($sites);
        foreach ($ids as $id) {
            abort_unless($sites->contains('id', (int) $id), 403);
            Access::site($request->user()->id, (int) $id);
        }
        $site = count($ids) === 1 ? $sites->firstWhere('id', (int) $ids[0]) : null;

        return compact('sites', 'site') + ['scope' => $site ? '?site_id='.$site->id : '', 'globalManagement' => true, 'managementLabel' => 'Kết quả thao tác nhiều tên miền', 'returnUrl' => '/admin/bulk?'.http_build_query(['scope_selected' => 1, 'site_ids_json' => json_encode($ids)])];
    }

    public function draftPreview(Request $request, PageBulkService $service)
    {
        $selection = $this->selection($request);
        $changes = $this->changes($request);
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'intent' => 'nullable|in:apply,preview']);
        [$report, $result] = DB::transaction(function () use ($request, $service, $selection, $changes, $data) {
            $report = $service->inspect($selection, $request->user()->id, 'edit', $data['idempotency_key'], $changes);
            if (($data['intent'] ?? null) !== 'apply') {
                return [$report, null];
            }
            $service->apply($report['id'], $report['fingerprint'], $changes, $request->user()->id);

            // Keep metadata and durable publishing requests in the same commit.
            return [$report, $this->applySelection($report['page_ids'], $report['site_ids'], $request->user()->id, 'bulk-pages:'.$report['id'])];
        }, 3);
        if ($result !== null) {
            return redirect($this->bulkUrl($report['site_ids']))->with('status', 'Đã lưu các trang đã chọn và yêu cầu tạo cache tự động. Kết quả hoặc lỗi được ghi riêng cho từng website.')->with('shared_apply_result', $result);
        }

        return redirect('/admin/bulk/drafts/'.$report['id']);
    }

    public function draft(Request $request, string $id, PageBulkService $service)
    {
        $report = $service->report($id, $request->user()->id);

        return view('admin.bulk-draft', $this->operationContext($request, $report['site_ids']) + compact('report'));
    }

    public function applyDraft(Request $request, string $id, PageBulkService $service)
    {
        $data = $request->validate(['fingerprint' => 'required|string|size:64']);
        $report = $service->report($id, $request->user()->id);
        $storedChanges = array_map(fn ($value) => ['mode' => 'set', 'value' => $value], $report['changes']);
        $service->apply($id, $data['fingerprint'], $storedChanges, $request->user()->id);

        return redirect('/admin/bulk/drafts/'.$id)->with('status', 'Đã xử lý sửa nháp hàng loạt. Kết quả từng trang được lưu; bản live giữ nguyên đến khi xuất bản.');
    }

    public function createCampaign(Request $request, PageBulkService $selectionService, PublicationCampaignService $service)
    {
        $selection = $this->selection($request);
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'action' => 'required|in:publish,reallocate']);
        $campaign = DB::transaction(function () use ($request, $selection, $data, $selectionService, $service) {
            $report = $selectionService->inspect($selection, $request->user()->id, 'publish', $data['idempotency_key'], []);

            // The service returns an existing campaign before checking a stale/expired preview.
            return $service->create(['page_ids' => $report['page_ids'], 'action' => $data['action'], 'idempotency_key' => $data['idempotency_key'], 'operation_id' => $report['id'], 'fingerprint' => $report['fingerprint']], $request->user()->id);
        });

        return redirect('/admin/bulk/campaigns/'.$campaign->id)->with('status', 'Đã xếp chuẩn bị preview nhiều trang. Chờ proposal và kiểm lỗi trước khi Apply.');
    }

    public function campaign(Request $request, int $id, PublicationCampaignService $service)
    {
        $report = $service->inspect($id, $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['status' => $report['campaign']->status, 'counts' => $report['counts']]);
        }
        $ids = collect($report['sites'])->pluck('site_id')->unique()->values()->all();
        $resultRows = collect($report['results']);
        $page = max(1, $request->integer('results_page', 1));
        $results = new LengthAwarePaginator($resultRows->forPage($page, 100)->values(), $resultRows->count(), 100, $page, ['path' => $request->url(), 'pageName' => 'results_page', 'query' => $request->query()]);

        return view('admin.bulk-campaign', $this->operationContext($request, $ids) + compact('report', 'results'));
    }

    public function campaignAction(Request $request, int $id, string $action, PublicationCampaignService $service)
    {
        abort_unless(in_array($action, ['accept', 'cancel', 'retry', 'rollback'], true), 404);
        $result = $service->{$action}($id, $request->user()->id);

        return redirect('/admin/bulk/campaigns/'.$result->id)->with('status', match ($action) {
            'accept' => 'Đã tiếp nhận phát hành. Theo dõi kết quả từng trang ở bên dưới.','cancel' => 'Đã yêu cầu hủy các mục chưa xuất bản. Các trang đã live được giữ.','retry' => 'Đã yêu cầu thử lại các mục được phép. Proposal stale cần preview lại.','rollback' => 'Đã tạo chiến dịch preview rollback từ các publication đã ghi nhận.'
        });
    }

    public function sitePreview(Request $request, SiteBulkService $service)
    {
        $context = $this->context($request, true);
        abort_unless($context['canWriteSites'], 403, 'Chỉ owner của tất cả website đã chọn hoặc quản trị toàn hệ thống được sửa cấu hình website.');
        $rules = ['idempotency_key' => 'required|uuid', 'intent' => 'nullable|in:apply,preview', 'site_changes' => 'required|array:theme_id,movie_key,category_key,routes,suffixes', 'site_changes.theme_id' => 'nullable|array:mode,value', 'site_changes.theme_id.mode' => 'required_with:site_changes.theme_id|in:keep,set', 'site_changes.theme_id.value' => 'nullable|integer|min:1', 'site_changes.movie_key' => 'nullable|array:mode,value', 'site_changes.movie_key.mode' => 'required_with:site_changes.movie_key|in:keep,set', 'site_changes.movie_key.value' => 'nullable|in:source_id,pinyin_id,pinyin_compact_id', 'site_changes.category_key' => 'nullable|array:mode,value', 'site_changes.category_key.mode' => 'required_with:site_changes.category_key|in:keep,set', 'site_changes.category_key.value' => 'nullable|in:id,pinyin_id,pinyin_compact_id', 'site_changes.routes' => 'nullable|array:home,list,detail,play,search', 'site_changes.suffixes' => 'nullable|array:home,list,detail,play,search'];
        foreach (['home', 'list', 'detail', 'play', 'search'] as $role) {
            $rules['site_changes.suffixes.'.$role] = 'nullable|array:mode,value';
            $rules['site_changes.suffixes.'.$role.'.mode'] = 'required_with:site_changes.suffixes.'.$role.'|in:keep,set';
            $rules['site_changes.suffixes.'.$role.'.value'] = 'nullable|string|max:13';
            $rules['site_changes.routes.'.$role] = 'nullable|array:mode,value';
            $rules['site_changes.routes.'.$role.'.mode'] = 'required_with:site_changes.routes.'.$role.'|in:keep,set';
            $rules['site_changes.routes.'.$role.'.value'] = 'nullable|string|max:120';
        }
        $data = $request->validate($rules);
        [$report, $result] = DB::transaction(function () use ($request, $service, $context, $data) {
            $report = $service->inspect($context['siteIds'], $data['site_changes'], $request->user()->id, $data['idempotency_key']);
            if (($data['intent'] ?? null) !== 'apply') {
                return [$report, null];
            }
            $pageIds = $report['result']['apply_page_ids'] ?? null;
            $newSelection = $pageIds === null;
            if ($pageIds === null) {
                $pageIds = DB::table('pages')->whereIn('site_id', $context['siteIds'])->whereNotIn('status', ['archived', 'gone'])
                    ->orderBy('site_id')->orderBy('id')->limit(PageBulkService::LIMIT + 1)->pluck('id')->all();
                if (count($pageIds) > PageBulkService::LIMIT) {
                    throw new \InvalidArgumentException('Phạm vi vượt 10.000 trang. Thu hẹp số website để áp dụng và tạo cache.');
                }
            }
            $report = $service->apply($report['id'], $report['fingerprint'], $request->user()->id);
            if ($newSelection) {
                // Replaying the operation must never pick up newly created drafts.
                $report['result']['apply_page_ids'] = $pageIds;
                DB::table('site_bulk_operations')->where('id', $report['id'])->update(['result_json' => Json::encode($report['result'])]);
            }

            return [$report, $this->applySelection($pageIds, $context['siteIds'], $request->user()->id, 'bulk-sites:'.$report['id'])];
        }, 3);
        if ($result !== null) {
            return redirect($this->bulkUrl($context['siteIds']))->with('status', 'Đã lưu cấu hình website và yêu cầu cập nhật các trang, tạo cache tự động. Theo dõi kết quả tại từng website.')->with('shared_apply_result', $result);
        }

        return redirect('/admin/bulk/sites/'.$report['id']);
    }

    public function siteOperation(Request $request, string $id, SiteBulkService $service)
    {
        $report = $service->report($id, $request->user()->id);

        return view('admin.bulk-sites', $this->operationContext($request, array_column($report['sites'], 'id')) + compact('report'));
    }

    public function applySites(Request $request, string $id, SiteBulkService $service)
    {
        $data = $request->validate(['fingerprint' => 'required|string|size:64']);
        $service->apply($id, $data['fingerprint'], $request->user()->id);

        return redirect('/admin/bulk/sites/'.$id)->with('status', 'Đã xử lý cấu hình nháp nhiều website. Tạo preview trang và Apply chiến dịch để phát hành.');
    }

    private function bulkUrl(array $siteIds): string
    {
        return '/admin/bulk?'.http_build_query(['scope_selected' => 1, 'site_ids_json' => json_encode($siteIds)]);
    }

    private function applySelection(array $pageIds, array $siteIds, int $actorId, string $key): array
    {
        $groups = DB::table('pages')->whereIn('site_id', $siteIds)->whereIn('id', $pageIds)->orderBy('site_id')->orderBy('id')
            ->get(['id', 'site_id', 'status'])->groupBy('site_id');
        $result = ['site_count' => count($siteIds), 'page_count' => 0, 'batch_ids' => [], 'errors' => []];
        foreach ($siteIds as $siteId) {
            Access::site($actorId, (int) $siteId, true);
        }
        foreach ($siteIds as $siteId) {
            $pages = $groups->get($siteId, collect());
            $eligible = $pages->whereNotIn('status', ['archived', 'gone']);
            $result['page_count'] += $eligible->count();
            if ($eligible->isEmpty()) {
                $result['errors'][(int) $siteId] = 'Không có trang hợp lệ để tạo cache. Trang lưu trữ hoặc đã gỡ không được tự khôi phục.';

                continue;
            }
            $messages = [];
            if ($eligible->count() !== $pages->count()) {
                $messages[] = ($pages->count() - $eligible->count()).' trang lưu trữ/đã gỡ được bỏ qua.';
            }
            try {
                $batch = app(QuickApply::class)->apply((int) $siteId, $eligible->pluck('id')->all(), $actorId, $key.':site:'.$siteId);
                $result['batch_ids'][(int) $siteId] = (int) $batch->id;
                if (in_array($batch->status, ['stale', 'failed', 'cancelled'], true)) {
                    $messages[] = 'Yêu cầu tạo cache có trạng thái '.$batch->status.'. Kiểm tra kết quả trước khi tạo thao tác mới.';
                }
                $errors = DB::table('publish_batch_items')->where(['site_id' => $siteId, 'batch_id' => $batch->id])
                    ->whereIn('status', ['error', 'failed', 'stale', 'locked', 'capacity_conflict']);
                $count = (clone $errors)->count();
                if ($count) {
                    $codes = $errors->whereNotNull('error_code')->distinct()->limit(5)->pluck('error_code')->all();
                    $messages[] = $count.' trang cần kiểm tra'.($codes ? ': '.implode(', ', $codes) : '.');
                }
            } catch (Conflict|\InvalidArgumentException $error) {
                $messages[] = $error instanceof Conflict ? $error->reason : $error->getMessage();
            }
            if ($messages) {
                $result['errors'][(int) $siteId] = implode(' ', $messages);
            }
        }

        return $result;
    }
}
