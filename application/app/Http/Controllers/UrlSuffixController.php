<?php

namespace App\Http\Controllers;

use App\Domain\Sites\UrlSuffixCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UrlSuffixController extends Controller
{
    public function index(Request $request, UrlSuffixCatalog $catalog): View
    {
        $catalog->authorizeManagement($request->user()->id);
        $filters = $request->validate(['deleted' => 'nullable|in:active,trash,all']);
        $deleted = $filters['deleted'] ?? 'active';
        $suffixes = $catalog->catalog($request->user()->id, $deleted);

        return view('admin.url-suffixes', compact('suffixes', 'deleted') + ['site' => null, 'sites' => collect(), 'globalManagement' => true, 'managementLabel' => 'Danh mục đuôi URL']);
    }

    public function store(Request $request, UrlSuffixCatalog $catalog): RedirectResponse
    {
        $catalog->create($request->only(['suffix', 'label']), $request->user()->id);

        return redirect('/admin/url-suffixes')->with('status', 'Đã thêm đuôi URL vào danh mục. Chọn đuôi trong Giao diện & đường dẫn của website để áp dụng.');
    }

    public function update(Request $request, int $id, UrlSuffixCatalog $catalog): RedirectResponse
    {
        $catalog->update($id, $request->only(['suffix', 'label', 'row_version']), $request->user()->id);

        return redirect('/admin/url-suffixes')->with('status', 'Đã sửa danh mục. URL đã chọn của website và lịch sử đang phục vụ được giữ nguyên.');
    }

    public function delete(Request $request, int $id, UrlSuffixCatalog $catalog): RedirectResponse
    {
        $catalog->authorizeManagement($request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $catalog->delete($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/url-suffixes')->with('status', 'Đã chuyển đuôi URL vào thùng rác. Website đang dùng vẫn giữ đuôi đã chọn; các lựa chọn mới không dùng đuôi này.');
    }

    public function restore(Request $request, int $id, UrlSuffixCatalog $catalog): RedirectResponse
    {
        $catalog->authorizeManagement($request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $catalog->restore($id, (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/url-suffixes')->with('status', 'Đã khôi phục đuôi URL trong danh mục. Chưa thay cấu hình website.');
    }
}
