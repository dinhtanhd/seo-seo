<?php

namespace App\Http\Controllers;

use App\Domain\Support\CatalogDeletionService;
use App\Domain\Support\CatalogUsageGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogDeletionController extends Controller
{
    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Xóa & thùng rác'];
    }

    public function index(Request $request, CatalogDeletionService $service)
    {
        $filters = $request->validate(['kind' => 'nullable|in:'.implode(',', array_keys(CatalogDeletionService::KINDS)), 'status' => 'nullable|in:active,trash', 'q' => 'nullable|string|max:100']);
        $kind = $filters['kind'] ?? 'movie';
        $filters += ['status' => 'active'];
        $items = $service->listing($kind, $filters, $request->user()->id)->paginate(50)->withQueryString();
        $recentOperations = DB::table('catalog_delete_operations')->where('kind', $kind)->orderByDesc('created_at')->limit(5)->get();

        return view('admin.deletions.index', ['kind' => $kind, 'kinds' => CatalogDeletionService::KINDS, 'filters' => $filters, 'items' => $items, 'recentOperations' => $recentOperations] + $this->context());
    }

    public function show(Request $request, string $kind, int $id, CatalogDeletionService $service, CatalogUsageGuard $guard)
    {
        $item = $service->item($kind, $id, $request->user()->id);
        try {
            $usage = $item->deleted_at === null ? DB::transaction(function () use ($guard, $kind, $id): array {
                DB::statement("SET LOCAL statement_timeout='5s'");

                return $guard->report($kind, $id);
            }) : ['can_delete' => false, 'reasons' => []];
        } catch (QueryException $error) {
            report($error);
            $usage = ['can_delete' => null, 'reasons' => [['label' => 'Chưa kiểm tra được đầy đủ nơi sử dụng. Tải lại hoặc kiểm tra dữ liệu liên quan.', 'count' => null, 'has_more' => false, 'samples' => []]]];
        }

        return view('admin.deletions.show', compact('kind', 'item', 'usage') + ['kinds' => CatalogDeletionService::KINDS] + $this->context());
    }

    public function delete(Request $request, string $kind, int $id, CatalogDeletionService $service)
    {
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $service->mutate($kind, $id, (int) $data['row_version'], 'delete', $request->user()->id);

        return redirect('/admin/deletions?'.http_build_query(['kind' => $kind, 'status' => 'trash']))->with('status', 'Đã xóa mục chưa được sử dụng vào thùng rác. Lịch sử được giữ để khôi phục.');
    }

    public function restore(Request $request, string $kind, int $id, CatalogDeletionService $service)
    {
        $data = $request->validate(['row_version' => 'required|integer|min:1']);
        $service->mutate($kind, $id, (int) $data['row_version'], 'restore', $request->user()->id);

        return redirect('/admin/deletions?'.http_build_query(['kind' => $kind]))->with('status', 'Đã khôi phục mục. Các liên kết website không được tự gắn lại.');
    }

    public function bulk(Request $request, CatalogDeletionService $service)
    {
        $service->authorize($request->user()->id);
        $data = $request->validate(['kind' => 'required|in:'.implode(',', array_keys(CatalogDeletionService::KINDS)), 'action' => 'required|in:delete,restore',
            'ids' => 'nullable|array|max:10000', 'ids.*' => 'integer|min:1|distinct', 'ids_text' => 'nullable|string|max:220000',
            'versions_json' => 'nullable|string|max:220000', 'idempotency_key' => 'required|uuid']);
        if (! empty($data['ids']) && trim($data['ids_text'] ?? '') !== '') {
            throw ValidationException::withMessages(['selection' => 'Dùng danh sách đã chọn hoặc dán ID, không dùng cả hai cùng lúc.']);
        }
        $ids = $data['ids'] ?? [];
        if (trim($data['ids_text'] ?? '') !== '') {
            $ids = preg_split('/[\s,;]+/u', trim($data['ids_text']), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($ids as $id) {
                if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw ValidationException::withMessages(['ids_text' => 'Mỗi ID phải là số nguyên dương.']);
                }
            }
        }
        try {
            $versions = isset($data['versions_json']) ? json_decode($data['versions_json'], true, 64, JSON_THROW_ON_ERROR) : [];
        } catch (\JsonException) {
            throw ValidationException::withMessages(['selection' => 'Phiên bản mục không hợp lệ. Tải lại danh sách.']);
        }
        if (! is_array($versions)) {
            throw ValidationException::withMessages(['selection' => 'Phiên bản mục không hợp lệ. Tải lại danh sách.']);
        }
        foreach ($versions as $id => $version) {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages(['selection' => 'Phiên bản mục không hợp lệ. Tải lại danh sách.']);
            }
        }
        $operation = $service->submit($data['kind'], $data['action'], $ids, $versions, $request->user()->id, $data['idempotency_key']);

        return redirect('/admin/deletions/operations/'.$operation->id);
    }

    public function operation(Request $request, string $id, CatalogDeletionService $service)
    {
        $service->authorize($request->user()->id);
        $operation = DB::table('catalog_delete_operations')->find($id) ?? abort(404);
        $results = DB::table('catalog_delete_items')->where('operation_id', $id)->orderBy('id')->paginate(50);
        $canRetry = DB::table('catalog_delete_items')->where('operation_id', $id)->where('status', 'failed')->exists();

        return view('admin.deletions.operation', compact('operation', 'results', 'canRetry') + ['kinds' => CatalogDeletionService::KINDS] + $this->context());
    }

    public function retry(Request $request, string $id, CatalogDeletionService $service)
    {
        $service->retry($id, $request->user()->id);

        return back()->with('status', 'Đã tiếp nhận thử lại các dòng lỗi kỹ thuật. Dòng đang dùng hoặc xung đột không bị xóa lại.');
    }
}
