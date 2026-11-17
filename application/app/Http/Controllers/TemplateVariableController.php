<?php

namespace App\Http\Controllers;

use App\Domain\Publishing\QuickApply;
use App\Domain\Rules\TemplateVariableService;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class TemplateVariableController extends Controller
{
    public function index(Request $request, TemplateVariableService $service): View
    {
        $this->authorizeManagement($request, $service);

        return view('admin.template-variables', ['variables' => $service->variables(), 'sets' => $service->sets()] + $this->context());
    }

    public function create(Request $request, TemplateVariableService $service): View
    {
        $this->authorizeManagement($request, $service);

        return view('admin.template-variables-editor', ['variable' => null, 'sets' => $service->sets()] + $this->context());
    }

    public function edit(Request $request, int $id, TemplateVariableService $service): View
    {
        $this->authorizeManagement($request, $service);
        $variable = $service->variables()->firstWhere('id', $id);
        abort_unless($variable, 404);

        return view('admin.template-variables-editor', ['variable' => $variable, 'sets' => $service->sets()] + $this->context());
    }

    public function save(Request $request, TemplateVariableService $service, ?int $id = null): RedirectResponse
    {
        $this->authorizeManagement($request, $service);
        $data = $request->validate([
            'slug' => 'required|string|max:60|regex:/^[a-z][a-z0-9_]*$/',
            'name' => 'required|string|max:200', 'source_kind' => 'required|in:file,database',
            'set_id' => 'nullable|integer|min:1', 'values' => 'nullable|string|max:10000000',
            'values_file' => 'nullable|file|max:10240|mimes:txt,csv',
            'fallback' => 'nullable|string|max:1000', 'status' => 'required|in:active,paused',
            'row_version' => $id === null ? 'nullable|integer|min:1' : 'required|integer|min:1',
        ]);
        if ($id !== null) {
            $data['id'] = $id;
        }
        if ($data['source_kind'] === 'file') {
            $data['values'] = $request->hasFile('values_file') ? $service->importFile($request->file('values_file')) : ($data['values'] ?? '');
        }
        unset($data['values_file']);
        $variable = $service->saveVariable($data, (int) $request->user()->id);

        return redirect()->route('admin.template-variables.edit', ['id' => $variable->id])
            ->with('status', 'Đã lưu biến. Các trang đã có giá trị và bản đang phục vụ giữ nguyên; trang mới dùng nguồn hiện tại.');
    }

    public function createSet(Request $request, TemplateVariableService $service): View
    {
        $this->authorizeManagement($request, $service);

        return view('admin.template-variables-set-editor', ['valueSet' => null] + $this->context());
    }

    public function editSet(Request $request, int $id, TemplateVariableService $service): View
    {
        $this->authorizeManagement($request, $service);
        $valueSet = $service->sets()->firstWhere('id', $id);
        abort_unless($valueSet, 404);

        return view('admin.template-variables-set-editor', compact('valueSet') + $this->context());
    }

    public function saveSet(Request $request, TemplateVariableService $service, ?int $id = null): RedirectResponse
    {
        $this->authorizeManagement($request, $service);
        $data = $request->validate(['name' => 'required|string|max:200', 'values' => 'nullable|string|max:10000000',
            'values_file' => 'nullable|file|max:10240|mimes:txt,csv',
            'row_version' => $id === null ? 'nullable|integer|min:1' : 'required|integer|min:1']);
        if ($id !== null) {
            $data['id'] = $id;
        }
        $data['values'] = $request->hasFile('values_file') ? $service->importFile($request->file('values_file')) : ($data['values'] ?? '');
        unset($data['values_file']);
        $valueSet = $service->saveSet($data, (int) $request->user()->id);

        return redirect()->route('admin.template-variable-sets.edit', ['id' => $valueSet->id])
            ->with('status', 'Đã lưu phiên bản bộ giá trị trong DB. Các giá trị đã chốt theo trang giữ nguyên.');
    }

    public function reroll(Request $request, int $id, TemplateVariableService $service): RedirectResponse
    {
        abort_unless(method_exists($service, 'ready') && $service->ready(), 503, 'Biến template đang được cập nhật. Hãy tải lại sau.');
        $page = DB::table('pages')->find($id);
        abort_unless($page, 404);
        Access::site((int) $request->user()->id, (int) $page->site_id, true);
        $data = $request->validate(['row_version' => 'required|integer|min:1',
            'variable_ids' => 'nullable|array|min:1|max:100', 'variable_ids.*' => 'required|integer|min:1|distinct']);
        $actor = (int) $request->user()->id;
        $batch = DB::transaction(function () use ($page, $actor, $data, $service): ?object {
            $service->reroll((int) $page->id, $actor, (int) $data['row_version'], $data['variable_ids'] ?? null);
            if ($page->status !== 'live' || $page->current_publication_id === null) {
                return null;
            }
            $batch = app(QuickApply::class)->apply((int) $page->site_id, [(int) $page->id], $actor, 'variable-reroll:'.Str::uuid(),
                ['presentation_only' => true, 'refresh_seo' => true]);
            $failed = DB::table('publish_batch_items')->where('batch_id', $batch->id)->whereNotIn('status', ['pending', 'published', 'unchanged'])->exists();
            if ($failed) {
                throw new Conflict('VARIABLE_APPLY_FAILED', 'Chưa áp dụng được giá trị mới. Trang vẫn giữ giá trị cũ; hãy kiểm tra cấu hình rồi thử lại.');
            }

            return $batch;
        });
        $response = redirect('/admin/pages/'.$id.'?site_id='.$page->site_id)->with('status', $batch
            ? 'Đã chọn lại giá trị và gửi yêu cầu cập nhật SEO cho trang này. Theo dõi kết quả trong Lịch sử áp dụng.'
            : 'Đã chọn lại giá trị cho trang nháp. Giá trị được dùng khi áp dụng trang.');
        if ($batch !== null) {
            $response->with('template_variable_batch', $batch->id);
        }

        return $response;
    }

    private function authorizeManagement(Request $request, TemplateVariableService $service): void
    {
        abort_unless($request->user()->is_global_admin, 403);
        abort_unless(method_exists($service, 'ready') && $service->ready(), 503, 'Biến template đang được cập nhật. Hãy tải lại sau.');
    }

    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Biến template dùng chung'];
    }
}
