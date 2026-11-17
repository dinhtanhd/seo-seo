<?php

namespace App\Http\Controllers;

use App\Domain\Sites\WebsiteBulkCreate;
use App\Domain\Sites\WebsiteBulkEdit;
use App\Domain\Sites\WebsiteDeletionService;
use App\Domain\Support\CatalogAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class WebsiteBulkEditController extends Controller
{
    public function create(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1|max:1000000']);
        $filter = trim($data['q'] ?? '');
        $primaryDomain = DB::table('site_domains')->whereColumn('site_domains.site_id', 'sites.id')->where('status', 'active')
            ->orderByDesc('is_primary')->orderBy('host_ascii')->limit(1)->select('host_ascii');
        $websites = $this->inventory($request, $filter)->select('sites.id', 'sites.base_url', 'sites.theme_id')
            ->selectSub($primaryDomain, 'admin_primary_domain')->orderBy('admin_primary_domain')->orderBy('sites.id')
            ->paginate(50)->appends(['q' => $filter]);
        $domains = DB::table('site_domains')->whereIn('site_id', $websites->pluck('id'))->where('status', 'active')
            ->orderByDesc('is_primary')->orderBy('host_ascii')->get(['site_id', 'host_ascii'])->groupBy('site_id');
        $themes = DB::table('movie_themes')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'current_version_id']);

        return view('admin.website-bulk-edit', compact('websites', 'domains', 'themes', 'filter')
            + ['requestKey' => (string) Str::uuid(), 'websiteDeletionAvailable' => Schema::hasTable('site_delete_operations')] + $this->context());
    }

    public function store(Request $request, WebsiteBulkEdit $service): RedirectResponse
    {
        $data = $request->validate(['selection_mode' => 'required|in:selected,domains,filter', 'q' => 'nullable|string|max:200',
            'site_ids' => 'nullable|array|max:10000', 'site_ids.*' => 'required|integer|min:1|distinct', 'domains' => 'nullable|string|max:2097152',
            'confirm_all' => 'nullable|boolean', 'idempotency_key' => 'required|uuid', 'theme_mode' => 'required|in:keep,set',
            'theme_id' => 'nullable|integer|min:1', 'advertising_regular' => 'required|in:keep,1,0',
            'advertising_fanmulu' => 'required|in:keep,1,0', 'clear_cache' => 'nullable|boolean']);
        $changes = [];
        if ($data['theme_mode'] === 'set') {
            $changes['theme_id'] = isset($data['theme_id']) ? (int) $data['theme_id'] : null;
        }
        foreach (['advertising_regular', 'advertising_fanmulu'] as $field) {
            if ($data[$field] !== 'keep') {
                $changes[$field] = (string) $data[$field] === '1';
            }
        }
        if ($request->boolean('clear_cache')) {
            $changes['clear_cache'] = true;
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'Chọn ít nhất một thay đổi hoặc xóa cache.']);
        }
        $ids = $this->selection($request, $data);
        if (array_key_exists('theme_id', $changes) && ! $request->user()->is_global_admin) {
            $owned = DB::table('site_memberships')->where('user_id', $request->user()->id)->where('role', 'owner')->whereIn('site_id', $ids)->count();
            abort_unless($owned === count($ids), 403, 'Chỉ owner của mọi website được chọn mới được đổi giao diện hàng loạt.');
        }
        $operation = $service->create($ids, $changes, $request->user()->id, $data['idempotency_key']);

        return redirect('/admin/sites/bulk-edit/'.$operation->id)
            ->with('status', 'Đã nhận yêu cầu sửa domain. Hệ thống xử lý từng nhóm website; theo dõi kết quả bên dưới, chưa xác nhận toàn bộ đã áp dụng.');
    }

    public function show(Request $request, string $id, WebsiteBulkEdit $service): View
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:1000000', 'status' => 'nullable|in:pending,running,completed,conflict,failed']);
        $report = $service->report($id, $request->user()->id);
        $itemFilter = $data['status'] ?? '';
        $primaryDomain = DB::table('site_domains')->whereColumn('site_domains.site_id', 'website_bulk_edit_items.site_id')
            ->orderByDesc('is_primary')->orderBy('host_ascii')->limit(1)->select('host_ascii');
        $report['items'] = DB::table('website_bulk_edit_items')->where('operation_id', $id)
            ->when($itemFilter !== '', fn (Builder $query) => $query->where('website_bulk_edit_items.status', $itemFilter))
            ->leftJoin('sites', 'sites.id', '=', 'website_bulk_edit_items.site_id')->select('website_bulk_edit_items.*', 'sites.base_url')
            ->selectSub($primaryDomain, 'domain')->orderBy('website_bulk_edit_items.id')->paginate(50)->appends(['status' => $itemFilter]);
        $themeLabel = array_key_exists('theme_id', $report['changes'])
            ? ($report['changes']['theme_id'] === null ? 'Mặc định hệ thống' : (DB::table('movie_themes')->where('id', $report['changes']['theme_id'])->value('name') ?? 'Giao diện #'.$report['changes']['theme_id']))
            : 'Giữ nguyên';

        return view('admin.website-bulk-edit-report', $report + compact('itemFilter', 'themeLabel') + $this->context());
    }

    public function previewDelete(Request $request, WebsiteDeletionService $service): RedirectResponse
    {
        abort_unless($request->user()->is_global_admin, 403, 'Chỉ quản trị toàn hệ thống được xóa website.');
        $data = $request->validate(['selection_mode' => 'required|in:selected,domains,filter', 'q' => 'nullable|string|max:200',
            'site_ids' => 'nullable|array|max:10000', 'site_ids.*' => 'required|integer|min:1|distinct',
            'domains' => 'nullable|string|max:2097152', 'confirm_all' => 'nullable|boolean', 'idempotency_key' => 'required|uuid']);
        $ids = $this->selection($request, $data);
        $versions = CatalogAvailability::filter(DB::table('sites'), 'sites')->whereIn('id', $ids)->pluck('row_version', 'id')->all();
        $operation = $service->preview($ids, $versions, $request->user()->id, $data['idempotency_key']);

        return redirect('/admin/sites/delete-operations/'.$operation->id);
    }

    public function retry(Request $request, string $id, WebsiteBulkEdit $service): RedirectResponse
    {
        $service->retry($id, $request->user()->id);

        return redirect('/admin/sites/bulk-edit/'.$id)->with('status', 'Đã yêu cầu thử lại các domain bị lỗi. Dòng đã áp dụng được giữ nguyên; dòng xung đột cần tạo yêu cầu mới.');
    }

    private function inventory(Request $request, string $filter = ''): Builder
    {
        $query = CatalogAvailability::filter(DB::table('sites'), 'sites')->where('sites.status', 'active')->where('sites.content_type', 'movie')
            ->whereExists(fn (Builder $domains) => $domains->selectRaw('1')->from('site_domains')->whereColumn('site_domains.site_id', 'sites.id')->where('site_domains.status', 'active'));
        if (! $request->user()->is_global_admin) {
            $query->whereIn('sites.id', DB::table('site_memberships')->where('user_id', $request->user()->id)->whereIn('role', ['owner', 'operator'])->select('site_id'));
        }
        if ($filter !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter).'%';
            $query->whereExists(fn (Builder $domains) => $domains->selectRaw('1')->from('site_domains')
                ->whereColumn('site_domains.site_id', 'sites.id')->where('site_domains.status', 'active')->where('site_domains.host_ascii', 'ilike', $pattern));
        }

        return $query;
    }

    private function selection(Request $request, array $data): array
    {
        $query = $this->inventory($request);
        if ($data['selection_mode'] === 'filter') {
            if (! $request->boolean('confirm_all')) {
                throw ValidationException::withMessages(['confirm_all' => 'Đánh dấu xác nhận để chọn tất cả domain khớp bộ lọc.']);
            }
            $query = $this->inventory($request, trim($data['q'] ?? ''));
        } elseif ($data['selection_mode'] === 'domains') {
            $hosts = array_column(app(WebsiteBulkCreate::class)->parseInput($data['domains'] ?? ''), 'domain');
            $matched = DB::table('site_domains')->whereIn('host_ascii', $hosts)->where('status', 'active')->get(['host_ascii', 'site_id']);
            $ids = array_values(array_unique(array_map('intval', $matched->pluck('site_id')->all())));
            if ($matched->count() !== count($hosts) || $this->inventory($request)->whereIn('sites.id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['domains' => 'Có domain chưa tồn tại, không hoạt động, không phải website phim hoặc bạn không có quyền sửa. Kiểm tra lại danh sách.']);
            }

            return $ids;
        } else {
            $ids = array_map('intval', $data['site_ids'] ?? []);
            if ($query->whereIn('sites.id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['site_ids' => 'Có website không hoạt động, không phải website phim hoặc bạn không có quyền sửa.']);
            }

            return $this->requireSelection($ids);
        }

        return $this->requireSelection($query->orderBy('sites.id')->limit(10001)->pluck('sites.id')->map(fn ($id) => (int) $id)->all());
    }

    private function requireSelection(array $ids): array
    {
        if ($ids === [] || count($ids) > 10000) {
            throw ValidationException::withMessages(['site_ids' => 'Chọn từ 1 đến 10.000 website cho một lượt. Có thể chia thành nhiều lượt.']);
        }

        return $ids;
    }

    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Sửa domain hàng loạt'];
    }
}
