<?php

namespace App\Http\Controllers;

use App\Domain\Sites\UrlSuffixCatalog;
use App\Domain\Sites\WebsiteBulkCreate;
use App\Domain\Sites\WebsiteDisplayNames;
use App\Domain\Sites\WebsitePresetService;
use App\Domain\Sites\WebsiteRandomAttributes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class WebsiteBulkCreateController extends Controller
{
    public function create(Request $request, WebsitePresetService $presets, WebsiteBulkCreate $service): View
    {
        $this->authorizeManagement($request);

        return view('admin.website-bulk-create', ['presets' => $presets->listing($request->user()->id)->where('status', 'active'), 'requestKey' => (string) Str::uuid(),
            'eligibleSeoTemplates' => $service->eligibleSeoTemplates(),
            'randomAttributeDefaults' => WebsiteRandomAttributes::defaults(),
            'displayNames' => app(WebsiteDisplayNames::class)->get($request->user()->id),
            'randomSuffixChoices' => app(UrlSuffixCatalog::class)->choices()->pluck('suffix')->all(),
            'recentOperations' => DB::table('website_create_operations')->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get(['id', 'status', 'total', 'created_at'])] + $this->context());
    }

    public function store(Request $request, WebsiteBulkCreate $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['domains' => 'nullable|string|max:2097152', 'domain_file' => 'nullable|file|max:2048',
            'preset_ids' => 'required|array|min:1|max:100', 'preset_ids.*' => 'required|integer|min:1|distinct', 'request_key' => 'required|string|max:160',
            'default_scheme' => 'required|in:https,http', 'distribution' => 'required|in:random,round_robin', 'random_theme' => 'nullable|boolean', 'random_seo' => 'nullable|boolean',
            'fanmulu_mode' => 'nullable|in:preset,enabled,disabled', 'use_display_names' => 'nullable|boolean']);
        $input = $data['domains'] ?? '';
        if ($request->hasFile('domain_file')) {
            $file = $request->file('domain_file');
            if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
                throw ValidationException::withMessages(['domain_file' => 'Chọn file .csv hoặc .txt UTF-8, mỗi dòng một domain.']);
            }
            $input .= "\n".preg_replace('/^\xEF\xBB\xBF/', '', $file->get());
        }
        $lines = $service->parseInput($input, $data['default_scheme']);
        $randomAttributes = WebsiteRandomAttributes::fromForm($request->input('random_fields', []), $request->input('random_values', []));
        if ($request->boolean('use_display_names')) {
            $randomAttributes['name'] = WebsiteDisplayNames::normalize($request->input('display_names'));
        }
        $operation = $service->submit($lines, array_map('intval', $data['preset_ids']), $request->user()->id, $data['request_key'], ['distribution' => $data['distribution'],
            'random_theme' => $request->boolean('random_theme'), 'random_seo' => $request->boolean('random_seo'), 'fanmulu_mode' => $data['fanmulu_mode'] ?? 'preset', 'random_attributes' => $randomAttributes]);

        return redirect('/admin/sites/bulk-create/'.$operation->id)->with('status', 'Đã nhận danh sách domain. Hệ thống tự tạo website và áp dụng bộ cấu hình; mở website khi dòng báo Sẵn sàng.');
    }

    public function show(Request $request, string $id, WebsiteBulkCreate $service): View
    {
        $this->authorizeManagement($request);
        $request->validate(['page' => 'nullable|integer|min:1|max:10000']);
        $report = $service->report($id, $request->user()->id);
        $page = $request->integer('page') ?: 1;
        $report['items'] = new LengthAwarePaginator($report['items']->forPage($page, 100)->values(), $report['items']->count(), 100, $page, ['path' => $request->url()]);

        return view('admin.website-bulk-report', $report + $this->context());
    }

    public function retry(Request $request, string $id, WebsiteBulkCreate $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $service->retry($id, $request->user()->id);

        return redirect('/admin/sites/bulk-create/'.$id)->with('status', 'Đã yêu cầu thử lại những domain bị lỗi. Website đã sẵn sàng được giữ nguyên.');
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()->is_global_admin, 403, 'Chỉ quản trị toàn hệ thống được thêm website hàng loạt.');
    }

    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'globalManagement' => true, 'managementLabel' => 'Thêm website hàng loạt'];
    }
}
