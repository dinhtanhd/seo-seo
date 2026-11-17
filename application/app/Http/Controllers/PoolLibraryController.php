<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\PoolCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PoolLibraryController extends Controller
{
    public function index(Request $request, PoolCatalogService $catalog): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:200', 'status' => 'nullable|in:active,paused,archived', 'deleted' => 'nullable|in:active,trash,all']);
        $allPools = $catalog->visiblePools($request->user()->id);
        $writeSites = DB::table('site_memberships')->where('user_id', $request->user()->id)->whereIn('role', ['owner', 'operator'])->pluck('site_id')->all();
        $poolCanWrite = $allPools->mapWithKeys(fn ($pool) => [$pool->id => $request->user()->is_global_admin || ($pool->scope === 'site' && in_array($pool->owner_site_id, $writeSites, true))])->all();
        $writablePools = $allPools->filter(fn ($pool) => $poolCanWrite[$pool->id] && $pool->deleted_at === null && $pool->status !== 'archived');
        $allGroups = DB::table('keyword_groups')->whereIn('pool_id', $allPools->pluck('id'))->orderBy('name')->orderBy('id')->get();
        $query = $filters['q'] ?? '';
        $deleted = $filters['deleted'] ?? 'active';
        $matches = fn ($name) => $query === '' || mb_stripos($name, $query) !== false;
        $pools = $allPools->filter(function ($pool) use ($filters, $deleted, $matches, $allGroups) {
            $visible = $deleted === 'all' || ($deleted === 'trash' ? $pool->deleted_at !== null : $pool->deleted_at === null);

            return $visible && (empty($filters['status']) || $pool->status === $filters['status'])
                && ($matches($pool->name) || $allGroups->where('pool_id', $pool->id)->contains(fn ($group) => $matches($group->name)));
        });
        $groups = $allGroups->filter(function ($group) use ($allPools, $filters, $deleted, $matches) {
            $pool = $allPools->firstWhere('id', $group->pool_id);
            $trashed = $group->deleted_at !== null || $pool->deleted_at !== null;

            return ($deleted === 'all' || ($deleted === 'trash' ? $trashed : ! $trashed))
                && (empty($filters['status']) || $group->status === $filters['status']) && ($matches($group->name) || $matches($pool->name));
        });
        $groupKeywords = DB::table('keyword_group_members')->join('keywords', 'keywords.id', '=', 'keyword_group_members.keyword_id')
            ->whereIn('keyword_group_members.group_id', $groups->pluck('id'))->orderBy('keywords.normalized_text')
            ->get(['keyword_group_members.group_id', 'keywords.id', 'keywords.original_text', 'keywords.role', 'keywords.status', 'keywords.deleted_at'])->groupBy('group_id');
        $choices = DB::table('keywords')->whereIn('pool_id', $writablePools->pluck('id'))->whereNull('deleted_at')
            ->select('id', 'pool_id', 'original_text', 'role', 'status', 'deleted_at')
            ->selectRaw('row_number() over (partition by pool_id order by normalized_text, id) as choice_no');
        $initialKeywordOptions = DB::query()->fromSub($choices, 'choices')->where('choice_no', '<=', 50)->get()->groupBy('pool_id');
        $keywordCounts = DB::table('keywords')->whereIn('pool_id', $allPools->pluck('id'))->whereNull('deleted_at')->selectRaw('pool_id, count(*) as total')->groupBy('pool_id')->pluck('total', 'pool_id');

        return view('admin.pools', compact('filters', 'pools', 'allPools', 'poolCanWrite', 'writablePools', 'groups', 'groupKeywords', 'initialKeywordOptions', 'keywordCounts') + [
            'site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Pool & nhóm dùng chung',
            'canCreatePool' => (bool) $request->user()->is_global_admin,
        ]);
    }

    public function store(Request $request, PoolCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate(array_replace($this->poolRules(), ['status' => 'nullable|in:active,paused,archived']));
        $data['status'] ??= 'active';
        $pool = $catalog->create($data, $request->user()->id);

        return redirect('/admin/pools#pool-'.$pool->id)->with('status', 'Đã tạo kho dùng chung. Gắn kho vào từng website tại Website & domain.');
    }

    public function update(Request $request, int $id, PoolCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate($this->poolRules() + ['row_version' => 'required|integer|min:1']);
        $catalog->update($id, $data, $request->user()->id);

        return back()->with('status', 'Đã lưu kho. Binding và các bản live/lịch sử giữ nguyên.');
    }

    public function delete(Request $request, int $id, PoolCatalogService $catalog): RedirectResponse
    {
        $catalog->delete($id, $this->rowVersion($request), $request->user()->id);

        return back()->with('status', 'Đã chuyển kho vào thùng rác; keyword, nhóm, binding và lịch sử vẫn được giữ.');
    }

    public function restore(Request $request, int $id, PoolCatalogService $catalog): RedirectResponse
    {
        $catalog->restore($id, $this->rowVersion($request), $request->user()->id);

        return back()->with('status', 'Đã khôi phục kho ở trạng thái tạm dừng. Chọn hoạt động khi muốn cấp keyword mới.');
    }

    public function storeGroup(Request $request, PoolCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate(['pool_id' => 'required|integer', 'pool_row_version' => 'required|integer|min:1', 'name' => 'required|string|max:200', 'status' => 'nullable|in:active,paused,archived']);
        $data += $this->members($request);
        if (($data['status'] ?? null) === null) {
            unset($data['status']);
        }
        $group = $catalog->createGroup($data, $request->user()->id);

        return redirect('/admin/pools#group-'.$group->id)->with('status', 'Đã tạo nhóm keyword trong kho.');
    }

    public function updateGroup(Request $request, int $id, PoolCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:200', 'status' => 'required|in:active,paused,archived', 'row_version' => 'required|integer|min:1']);
        $data += $this->members($request);
        $catalog->updateGroup($id, $data, $request->user()->id);

        return back()->with('status', 'Đã lưu nhóm và danh sách thành viên. Các bản live/lịch sử giữ nguyên.');
    }

    public function deleteGroup(Request $request, int $id, PoolCatalogService $catalog): RedirectResponse
    {
        $catalog->deleteGroup($id, $this->rowVersion($request), $request->user()->id);

        return back()->with('status', 'Đã chuyển nhóm vào thùng rác; thành viên, binding và lịch sử vẫn được giữ.');
    }

    public function restoreGroup(Request $request, int $id, PoolCatalogService $catalog): RedirectResponse
    {
        $catalog->restoreGroup($id, $this->rowVersion($request), $request->user()->id);

        return back()->with('status', 'Đã khôi phục nhóm ở trạng thái tạm dừng và giữ nguyên thành viên.');
    }

    private function poolRules(): array
    {
        return ['name' => 'required|string|max:200', 'locale' => 'required|string|max:16|regex:/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', 'status' => 'required|in:active,paused,archived'];
    }

    private function rowVersion(Request $request): int
    {
        return (int) $request->validate(['row_version' => 'required|integer|min:1'])['row_version'];
    }

    private function members(Request $request): array
    {
        if ($request->has('keyword_ids_json')) {
            $data = $request->validate(['keyword_ids_json' => 'required|string|max:250000']);
            try {
                $ids = json_decode($data['keyword_ids_json'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['keyword_ids_json' => 'Danh sách keyword chưa hợp lệ. Hãy tải lại form.']);
            }
            if (! str_starts_with(ltrim($data['keyword_ids_json']), '[') || ! is_array($ids) || ! array_is_list($ids)) {
                throw ValidationException::withMessages(['keyword_ids_json' => 'Danh sách keyword phải là danh sách ID hợp lệ.']);
            }
            $request->merge(['keyword_ids' => $ids]);
        }

        return $request->validate(['keyword_ids' => 'sometimes|array|max:10000', 'keyword_ids.*' => 'required|integer|min:1|distinct']);
    }
}
