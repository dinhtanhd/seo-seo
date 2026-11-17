<?php

namespace App\Http\Controllers;

use App\Domain\Ingestion\SourceCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SourceLifecycleController extends Controller
{
    public function destroy(Request $request, int $id, SourceCatalogService $service): RedirectResponse
    {
        $service->authorizeManagement($request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $service->delete($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/sources')->with('status', 'Đã chuyển nguồn #'.$id.' vào thùng rác và dừng lấy dữ liệu. Dữ liệu phim, liên kết website và lịch sử được giữ nguyên.');
    }

    public function restore(Request $request, int $id, SourceCatalogService $service): RedirectResponse
    {
        $service->authorizeManagement($request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $service->restore($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/sources')->with('status', 'Đã khôi phục nguồn #'.$id.' ở trạng thái tạm dừng. Kiểm tra cấu hình rồi bật hoạt động khi muốn lấy dữ liệu tiếp.');
    }
}
