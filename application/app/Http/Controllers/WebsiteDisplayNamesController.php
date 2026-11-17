<?php

namespace App\Http\Controllers;

use App\Domain\Sites\WebsiteDisplayNames;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WebsiteDisplayNamesController extends Controller
{
    public function store(Request $request, WebsiteDisplayNames $service): RedirectResponse
    {
        abort_unless($request->user()->is_global_admin, 403, 'Chỉ quản trị toàn hệ thống được lưu danh sách tên website.');
        $data = $request->validate(['display_names' => 'required|string|max:1048576', 'row_version' => 'required|integer|min:1']);
        $service->save($data['display_names'], (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/sites/bulk-create#create-display-names')->with('status', 'Đã lưu danh sách tên cho các lượt tạo website tiếp theo.');
    }
}
