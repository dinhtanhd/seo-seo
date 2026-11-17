<?php

namespace App\Http\Controllers;

use App\Domain\Rules\RuleLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RuleLifecycleController extends Controller
{
    public function archive(Request $request, int $id, RuleLifecycleService $service): RedirectResponse
    {
        $service->authorize($id, $request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $rule = $service->archive($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/rules?'.http_build_query(array_filter(['site_id' => $rule->site_id, 'status' => 'archived'])))->with('status', 'Đã lưu trữ quy tắc #'.$id.'. Cấu hình, phiên bản và bản trang đang phục vụ được giữ nguyên. Có thể khôi phục từ mục Đã lưu trữ.');
    }

    public function restore(Request $request, int $id, RuleLifecycleService $service): RedirectResponse
    {
        $service->authorize($id, $request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $rule = $service->restore($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/rules'.($rule->site_id !== null ? '?site_id='.$rule->site_id : ''))->with('status', 'Đã khôi phục quy tắc #'.$id.'. Các trang đang phục vụ giữ nguyên; mở quy tắc rồi Lưu & áp dụng nếu muốn cập nhật các trang hiện có.');
    }
}
