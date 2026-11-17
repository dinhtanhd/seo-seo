<?php

namespace App\Http\Controllers;

use App\Domain\Ingestion\SiteSourceBindingService;
use App\Domain\Keywords\SitePoolBindingService;
use App\Domain\Support\Access;
use App\Domain\Support\Json;
use App\Domain\Support\MetadataFilterForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SiteLibraryBindingController extends Controller
{
    private function poolData(Request $request, ?object $binding = null): array
    {
        $data = $request->validate([
            'pool_id' => $binding ? 'sometimes|integer|min:1' : 'required|integer|min:1',
            'row_version' => $binding ? 'required|integer|min:1' : 'nullable|integer|min:1',
            'group_id' => 'nullable|integer|min:1', 'category_id' => 'nullable|integer|min:1',
            'page_type' => 'nullable|in:home,category,movie,novel,episode,chapter,editorial',
            'priority' => 'required|integer', 'status' => 'required|in:active,paused,archived',
        ]);
        $data['filters'] = $request->has('filters_form')
            ? MetadataFilterForm::fromRequest($request, 'filters_form', Json::decode($binding?->filters_json ?? '{}'))
            : Json::decode($binding?->filters_json ?? '{}');

        return $data;
    }

    private function done(int $siteId, string $tab, string $message): RedirectResponse
    {
        return redirect('/admin/sites/'.$siteId.'/edit?tab='.$tab)->with('status', $message);
    }

    public function addPool(Request $request, int $siteId, SitePoolBindingService $service): RedirectResponse
    {
        $service->save($siteId, null, $this->poolData($request), $request->user()->id);

        return $this->done($siteId, 'pools', 'Đã gắn kho keyword. Trang đang phục vụ giữ keyword; nội dung mới tự dùng kho khi áp dụng.');
    }

    public function updatePool(Request $request, int $siteId, int $id, SitePoolBindingService $service): RedirectResponse
    {
        $service->authorize($request->user()->id, $siteId);
        $binding = DB::table('keyword_bindings')->where(['id' => $id, 'site_id' => $siteId])->first() ?? abort(404);
        $service->save($siteId, $id, $this->poolData($request, $binding), $request->user()->id);

        return $this->done($siteId, 'pools', 'Đã sửa liên kết kho. Keyword trên trang đang phục vụ được giữ nguyên.');
    }

    public function removePool(Request $request, int $siteId, int $id, SitePoolBindingService $service): RedirectResponse
    {
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $service->archive($siteId, $id, (int) $data['row_version'], $request->user()->id);

        return $this->done($siteId, 'pools', 'Đã gỡ kho khỏi việc cấp mới. Lịch sử và assignment live được giữ.');
    }

    public function keywordOptions(Request $request, int $siteId): JsonResponse
    {
        abort_unless(DB::table('sites')->where('id', $siteId)->exists(), 404);
        Access::site($request->user()->id, $siteId);
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $query = DB::table('keywords')->whereIn('pool_id', DB::table('keyword_bindings')->where('site_id', $siteId)->select('pool_id'))->whereNull('deleted_at');
        if (! empty($data['q'])) {
            $query->where('normalized_text', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%');
        }

        return response()->json(['data' => $query->orderBy('normalized_text')->orderBy('id')->limit(50)->get(['id', 'original_text', 'role', 'default_max_active_pages'])]);
    }

    public function keywordLimit(Request $request, int $siteId, SitePoolBindingService $service): RedirectResponse
    {
        $data = $request->validate(['keyword_id' => 'required|integer|min:1', 'site_config_epoch' => 'required|integer|min:1', 'max_active_pages' => 'nullable|integer|min:0', 'inherit' => 'nullable|boolean']);
        $data['inherit'] = $request->boolean('inherit');
        $service->limit($siteId, (int) $data['keyword_id'], $data, $request->user()->id);

        return $this->done($siteId, 'pools', 'Đã lưu giới hạn keyword của website. Trang live giữ nguyên.');
    }

    public function addSource(Request $request, int $siteId, SiteSourceBindingService $service): RedirectResponse
    {
        $data = $request->validate(['connector_id' => 'required|integer|min:1', 'enabled' => 'nullable|boolean']);
        $service->create($siteId, (int) $data['connector_id'], $request->user()->id, $request->boolean('enabled'));

        return $this->done($siteId, 'sources', $request->boolean('enabled') ? 'Đã gắn nguồn và bật tự áp dụng nội dung mới sau khi crawl. Trang và cache cập nhật tự động.' : 'Đã gắn nguồn. Nội dung mới được giữ nháp; chưa bật xuất bản tự động.');
    }

    public function updateSource(Request $request, int $siteId, int $connectorId, SiteSourceBindingService $service): RedirectResponse
    {
        $data = $request->validate(['mapping_version' => 'required|integer|min:1', 'status' => 'required|in:active,paused,archived', 'enabled' => 'nullable|boolean']);
        $data['enabled'] = $request->boolean('enabled');
        $service->update($siteId, $connectorId, $data, $request->user()->id);

        return $this->done($siteId, 'sources', 'Đã lưu cách nhận nội dung cho website này. Trang đã xuất bản giữ nguyên.');
    }

    public function removeSource(Request $request, int $siteId, int $connectorId, SiteSourceBindingService $service): RedirectResponse
    {
        $data = $request->validate(['mapping_version' => 'required|integer|min:1']);
        $service->delete($siteId, $connectorId, (int) $data['mapping_version'], $request->user()->id);

        return $this->done($siteId, 'sources', 'Đã xóa liên kết nguồn khỏi website. Website ngừng nhận phim và cập nhật tự động từ nguồn này; kho phim chung, trang đã xuất bản và lịch sử được giữ.');
    }
}
