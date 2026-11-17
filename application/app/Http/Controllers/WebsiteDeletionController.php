<?php

namespace App\Http\Controllers;

use App\Domain\Sites\WebsiteDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WebsiteDeletionController extends Controller
{
    public function previewOne(Request $request, int $id, WebsiteDeletionService $service)
    {
        return $this->preview($request, $id, $service, 'delete');
    }

    public function restore(Request $request, int $id, WebsiteDeletionService $service)
    {
        return $this->preview($request, $id, $service, 'restore');
    }

    private function preview(Request $request, int $id, WebsiteDeletionService $service, string $action)
    {
        $service->authorize($request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1', 'request_key' => 'nullable|string|max:160']);
        $operation = $service->preview([$id], [$id => (int) $data['row_version']], $request->user()->id, $data['request_key'] ?? (string) Str::uuid(), $action);

        return redirect('/admin/sites/delete-operations/'.$operation->id);
    }

    public function show(Request $request, string $id, WebsiteDeletionService $service)
    {
        $data = $service->report($id, $request->user()->id);

        return view($data['operation']->status === 'preview' ? 'admin.website-delete-confirm' : 'admin.website-delete-report', $data + $this->context());
    }

    public function confirm(Request $request, string $id, WebsiteDeletionService $service)
    {
        $service->authorize($request->user()->id);
        $data = $request->validate(['row_version' => 'required|integer|min:1', 'preview_hash' => 'required|string|size:64', 'confirm_delete' => 'required|accepted']);
        $service->confirm($id, $request->user()->id, (int) $data['row_version'], $data['preview_hash']);

        return redirect('/admin/sites/delete-operations/'.$id)->with('status', 'Đã nhận xác nhận. Hệ thống xử lý đúng danh sách website đã lưu.');
    }

    public function deleted(Request $request, WebsiteDeletionService $service)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:160']);
        $query = $service->trash($request->user()->id);
        if (trim($filters['q'] ?? '') !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(fn ($search) => $search->where('sites.name', 'ilike', $pattern)->orWhere('sites.base_url', 'ilike', $pattern));
        }
        $websites = $query->paginate(50)->withQueryString();
        $websites->getCollection()->transform(fn ($site) => $service->decorateTrash($site));

        return view('admin.sites-deleted', compact('websites', 'filters') + $this->context());
    }

    public function retry(Request $request, string $id, WebsiteDeletionService $service)
    {
        $service->retry($id, $request->user()->id);

        return redirect('/admin/sites/delete-operations/'.$id)->with('status', 'Đã yêu cầu thử lại các dòng lỗi kỹ thuật.');
    }

    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Xóa & khôi phục website'];
    }
}
