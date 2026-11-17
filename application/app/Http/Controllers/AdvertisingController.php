<?php

namespace App\Http\Controllers;

use App\Domain\Advertising\AdvertisingScriptPaths;
use App\Domain\Advertising\AdvertisingService;
use App\Domain\Advertising\TongjiService;
use App\Domain\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class AdvertisingController extends Controller
{
    public function index(Request $request, AdvertisingService $service, TongjiService $tongji): View
    {
        abort_unless($request->user()->is_global_admin, 403);

        return view('admin.advertising', ['settings' => $service->globalSettings(), 'tongji' => $tongji->settings(), 'canManage' => true] + $this->context());
    }

    public function save(Request $request, AdvertisingService $service): RedirectResponse
    {
        abort_unless($request->user()->is_global_admin, 403);
        $request->validate(['extra_origins' => 'nullable|string|max:10000']);
        $service->saveGlobal($request->only(['row_version', 'enabled', 'code', 'extra_origins', 'regular_js_path', 'fanmulu_js_path']), $request->user()->id);

        return redirect('/admin/advertising')->with('status', 'Đã lưu mã JS quảng cáo dùng chung. Các website đang bật dùng mã mới khi tải lại trang.');
    }

    public function saveTongji(Request $request, TongjiService $service): RedirectResponse
    {
        abort_unless($request->user()->is_global_admin, 403);
        $service->save($request->only(['tongji_row_version', 'tongji_enabled', 'tongji_code', 'tongji_placement']), $request->user()->id);

        return redirect('/admin/advertising#tongji-config')->with('status', 'Đã lưu mã thống kê HTML dùng chung. Cache HTML được làm mới; nội dung và keyword giữ nguyên.');
    }

    public function dispatchJavascript(Request $request, string $path, AdvertisingService $service, AdvertisingScriptPaths $paths): Response
    {
        abort_if($request->attributes->get('admin_host'), 404);
        $area = $paths->area('/'.ltrim($path, '/'));
        if ($area === null) {
            return app(TramPublicController::class)->show($request, $path);
        }

        return $this->javascript($request, $area, $service);
    }

    public function site(Request $request, int $id, AdvertisingService $service): View
    {
        Access::site($request->user()->id, $id);
        $site = DB::table('sites')->find($id) ?? abort(404);
        abort_unless($site->content_type === 'movie', 404);
        $canManage = $request->user()->is_global_admin || DB::table('site_memberships')->where(['site_id' => $id, 'user_id' => $request->user()->id])
            ->whereIn('role', ['owner', 'operator'])->exists();
        $global = DB::table('advertising_settings')->where('id', 1)->first(['enabled', 'has_code']);

        return view('admin.site-advertising', ['site' => $site, 'settings' => $service->preferences($id), 'canManage' => $canManage,
            'globalEnabled' => (bool) ($global->enabled ?? false), 'globalHasCode' => (bool) ($global->has_code ?? false)] + $this->context());
    }

    public function saveSite(Request $request, int $id, AdvertisingService $service): RedirectResponse
    {
        $service->saveSite($id, $request->only(['row_version', 'regular_enabled', 'fanmulu_enabled']), $request->user()->id);

        return redirect('/admin/sites/'.$id.'/advertising')->with('status', 'Đã lưu lựa chọn quảng cáo cho phim chính và fanmulu. Cache website sẽ được dựng lại từ dữ liệu đã lưu.');
    }

    public function javascript(Request $request, string $area, AdvertisingService $service): Response
    {
        abort_if($request->attributes->get('admin_host'), 404);
        $site = $request->attributes->get('public_site');
        abort_unless($site && $site->content_type === 'movie', 404);
        $code = $service->javascript($site, $area);

        return response($code)->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Cache-Control', 'no-store, max-age=0')->header('X-Content-Type-Options', 'nosniff');
    }

    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'globalManagement' => true, 'managementLabel' => 'JS quảng cáo dùng chung'];
    }
}
