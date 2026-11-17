<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\KeywordCatalogService;
use App\Domain\Keywords\PoolCatalogService;
use App\Domain\Rules\Html;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use App\Domain\Support\MetadataFilterForm;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class KeywordLibraryController extends Controller
{
    public function index(Request $request, PoolCatalogService $catalog): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:200', 'pool_id' => 'nullable|integer', 'group_id' => 'nullable|integer',
            'role' => 'nullable|in:primary,secondary,anchor', 'status' => 'nullable|in:active,paused,archived',
            'deleted' => 'nullable|in:active,trash,all', 'per_page' => 'nullable|in:25,50,100']);
        $pools = $catalog->visiblePools($request->user()->id);
        $writeSites = DB::table('site_memberships')->where('user_id', $request->user()->id)->whereIn('role', ['owner', 'operator'])->pluck('site_id')->all();
        $poolCanWrite = $pools->mapWithKeys(fn ($pool) => [$pool->id => $request->user()->is_global_admin || ($pool->scope === 'site' && in_array($pool->owner_site_id, $writeSites, true))])->all();
        $writablePools = $pools->filter(fn ($pool) => $poolCanWrite[$pool->id] && $pool->deleted_at === null && $pool->status !== 'archived');
        $query = DB::table('keywords')->join('keyword_pools', 'keyword_pools.id', '=', 'keywords.pool_id')->whereIn('keywords.pool_id', $pools->pluck('id'))
            ->select('keywords.*', 'keyword_pools.name as pool_name', 'keyword_pools.deleted_at as pool_deleted_at', 'keyword_pools.status as pool_status');
        $deleted = $filters['deleted'] ?? 'active';
        if ($deleted === 'active') {
            $query->whereNull('keywords.deleted_at')->whereNull('keyword_pools.deleted_at');
        } elseif ($deleted === 'trash') {
            $query->where(fn ($q) => $q->whereNotNull('keywords.deleted_at')->orWhereNotNull('keyword_pools.deleted_at'));
        }
        if (! empty($filters['q'])) {
            $query->where('keywords.normalized_text', 'ilike', $this->pattern($filters['q']));
        }
        foreach (['pool_id', 'role', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('keywords.'.$field, $filters[$field]);
            }
        }
        if (! empty($filters['group_id'])) {
            $query->whereIn('keywords.id', DB::table('keyword_group_members')->where('group_id', $filters['group_id'])->select('keyword_id'));
        }
        $keywords = $query->orderBy('keywords.id')->paginate((int) ($filters['per_page'] ?? 25))->appends($filters);
        $usage = DB::table('page_keyword_assignments')->join('pages', 'pages.current_publication_id', '=', 'page_keyword_assignments.publication_id')
            ->where('pages.status', 'live')->whereIn('pages.site_id', $this->allowedSites($request)->where('status', 'active')->select('id'))
            ->whereIn('keyword_id', $keywords->pluck('id'))->selectRaw('keyword_id, count(distinct pages.id) as total')->groupBy('keyword_id')->pluck('total', 'keyword_id');
        $held = DB::table('keyword_capacity_claims')->where('status', 'held')->whereIn('site_id', $this->allowedSites($request)->where('status', 'active')->select('id'))
            ->whereIn('keyword_id', $keywords->pluck('id'))->selectRaw('keyword_id, count(distinct page_id) as total')->groupBy('keyword_id')->pluck('total', 'keyword_id');
        $groups = DB::table('keyword_groups')->whereIn('pool_id', $pools->pluck('id'))->whereNull('deleted_at')->orderBy('name')->get();
        $imports = DB::table('keyword_import_runs')->whereIn('pool_id', $pools->pluck('id'))
            ->when(! $request->user()->is_global_admin, fn ($q) => $q->where('actor_id', $request->user()->id))->orderByDesc('id')->limit(10)->get();

        return view('admin.keywords', compact('filters', 'pools', 'poolCanWrite', 'writablePools', 'keywords', 'usage', 'held', 'groups', 'imports') + [
            'site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Kho keyword dùng chung',
        ]);
    }

    public function store(Request $request, KeywordCatalogService $catalog): RedirectResponse
    {
        $data = $this->fields($request, true);
        $data['eligibility_json'] = $request->boolean('eligibility_form_present')
            ? MetadataFilterForm::fromRequest($request, 'eligibility_form')
            : ($request->filled('eligibility_json') ? Json::decode($request->input('eligibility_json')) : []);
        $result = $catalog->create((int) $data['pool_id'], $data, $request->user()->id);
        $message = match ($result['outcome']) {
            'new' => 'Đã tạo keyword #'.$result['keyword']->id.'.',
            'restored' => 'Đã khôi phục keyword trùng định danh #'.$result['keyword']->id.'; metadata cũ được giữ.',
            default => 'Keyword đã có trong kho (#'.$result['keyword']->id.'); không tạo trùng hoặc thay metadata cũ.',
        };

        return redirect('/admin/keywords?pool_id='.$data['pool_id'].'#keyword-'.$result['keyword']->id)->with('status', $message.' Các bản live giữ nguyên.');
    }

    public function update(Request $request, int $id, KeywordCatalogService $catalog, PoolCatalogService $pools): RedirectResponse
    {
        $keyword = DB::table('keywords')->find($id);
        abort_unless($keyword, 404);
        $pools->accessiblePool($keyword->pool_id, $request->user()->id, true, false);
        $data = $this->fields($request);
        if ($request->boolean('eligibility_form_present')) {
            $data['eligibility_json'] = MetadataFilterForm::fromRequest($request, 'eligibility_form', Json::decode($keyword->eligibility_json));
        } elseif ($request->has('eligibility_json')) {
            $data['eligibility_json'] = Json::decode($request->input('eligibility_json'));
        }
        $catalog->update($id, $data, $request->user()->id);

        return back()->with('status', 'Đã lưu phiên bản keyword. Các trang live và assignment lịch sử giữ nguyên.');
    }

    public function changeRole(Request $request, int $id, KeywordCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate(['role' => 'required|in:primary,secondary,anchor', 'target_url' => $this->targetRules(), 'row_version' => 'required|integer|min:1']);
        $keyword = DB::table('keywords')->find($id);
        abort_unless($keyword, 404);
        abort_if($keyword->role === $data['role'], 422, 'Hãy chọn vai trò khác.');
        $target = $catalog->update($id, $data, $request->user()->id);

        return back()->with('status', 'Đã tạo/khôi phục keyword vai trò mới #'.$target->id.' và lưu trữ keyword cũ; lịch sử vẫn dùng ID và vai trò cũ.');
    }

    public function delete(Request $request, int $id, KeywordCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $catalog->delete($id, (int) $data['row_version'], $request->user()->id);

        return back()->with('status', 'Đã chuyển keyword vào thùng rác. ID, nhóm và các bản live/lịch sử được giữ nguyên.');
    }

    public function restore(Request $request, int $id, KeywordCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $catalog->restore($id, (int) $data['row_version'], $request->user()->id);

        return back()->with('status', 'Đã khôi phục keyword ở trạng thái tạm dừng. Chuyển sang hoạt động khi muốn cấp mới.');
    }

    public function options(Request $request, PoolCatalogService $catalog): JsonResponse
    {
        $data = $request->validate(['pool_id' => 'required|integer', 'q' => 'nullable|string|max:200', 'role' => 'nullable|in:primary,secondary,anchor']);
        $catalog->accessiblePool((int) $data['pool_id'], $request->user()->id, false, false);
        $query = DB::table('keywords')->where('pool_id', $data['pool_id'])->whereNull('deleted_at');
        if (! empty($data['q'])) {
            $query->where('normalized_text', 'ilike', $this->pattern($data['q']));
        }
        if (! empty($data['role'])) {
            $query->where('role', $data['role']);
        }

        return response()->json(['data' => $query->orderBy('normalized_text')->orderBy('id')->limit(50)->get(['id', 'pool_id', 'original_text', 'role', 'status', 'deleted_at'])]);
    }

    private function fields(Request $request, bool $create = false): array
    {
        $rules = ['original_text' => ($create ? 'required' : 'sometimes|required').'|string|max:255',
            'locale' => ($create ? 'required' : 'sometimes|required').'|string|max:16|regex:/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/',
            'weight' => 'required|integer|min:1', 'priority' => 'nullable|integer', 'status' => 'required|in:active,paused,archived',
            'default_max_active_pages' => 'nullable|integer|min:0', 'target_url' => $this->targetRules(), 'eligibility_json' => 'nullable|string|max:20000'];
        if ($create) {
            $rules += ['pool_id' => 'required|integer', 'pool_row_version' => 'required|integer|min:1', 'role' => 'required|in:primary,secondary,anchor', 'group_id' => 'nullable|integer|min:1'];
        } else {
            $rules['row_version'] = 'required|integer|min:1';
        }
        $data = $request->validate($rules);
        if (($data['priority'] ?? null) === null) {
            unset($data['priority']);
        }
        unset($data['eligibility_json']);

        return $data;
    }

    private function targetRules(): array
    {
        return ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
            if (! Html::safeUrl((string) $value, true)) {
                $fail('URL đích phải là HTTP(S) hoặc đường dẫn nội bộ hợp lệ.');
            }
        }];
    }

    private function allowedSites(Request $request): Builder
    {
        return CatalogAvailability::filter(DB::table('sites'), 'sites')->when(! $request->user()->is_global_admin,
            fn ($q) => $q->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')));
    }

    private function pattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
