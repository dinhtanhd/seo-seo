<?php

namespace App\Http\Controllers;

use App\Domain\Publishing\SharedConfigApply;
use App\Domain\Rules\ConfigService;
use App\Domain\Rules\MovieSeoTemplateLibrary;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use App\Domain\Support\SeoTemplateBundleForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SeoTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $templates = CatalogAvailability::filter(DB::table('templates'), 'templates')->leftJoin('template_versions', 'template_versions.id', '=', 'templates.current_version_id')
            ->select('templates.*', 'template_versions.definition_json', 'template_versions.version_no')
            ->orderBy('templates.id')->get();
        $websiteCounts = DB::table('sites')->whereNotNull('template_id')
            ->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))
            ->selectRaw('template_id, count(*) as total')->groupBy('template_id')->pluck('total', 'template_id');
        $movieStarterTemplates = collect();
        $movieStarterLibraryReady = Schema::hasTable('movie_seo_template_catalog');
        if ($movieStarterLibraryReady) {
            $movieStarterTemplates = DB::table('movie_seo_template_catalog')->get(['template_id', 'stable_key'])
                ->mapWithKeys(fn (object $entry): array => [$entry->template_id => $this->starter($entry->stable_key)])
                ->filter();
        }

        return view('admin.templates', compact('templates', 'websiteCounts', 'movieStarterTemplates', 'movieStarterLibraryReady') + $this->context());
    }

    public function installMovieStarters(Request $request, MovieSeoTemplateLibrary $library): RedirectResponse
    {
        $this->authorizeManagement($request);
        abort_unless(Schema::hasTable('movie_seo_template_catalog'), 503, 'Thư viện mẫu đang được cập nhật. Hãy tải lại sau.');
        $result = $library->install($request->user()->id);
        $message = 'Đã thêm '.$result['created'].' bộ mẫu SEO phim; '.$result['existing'].' bộ đã có được giữ nguyên. Chọn bộ trong cấu hình website hoặc bộ cấu hình để sử dụng.';
        if ($result['skipped_deleted'] > 0) {
            $message .= ' Bỏ qua '.$result['skipped_deleted'].' bộ đang trong thùng rác. Mở Xóa & thùng rác, chọn Template SEO và Thùng rác để khôi phục.';
        }

        return redirect('/admin/templates')->with('status', $message);
    }

    public function create(Request $request): View
    {
        $this->authorizeManagement($request);

        return view('admin.templates.editor', ['template' => null, 'definition' => SeoTemplateBundle::defaults(), 'affectedSiteCount' => 0, 'affectedPageCount' => 0] + $this->context());
    }

    public function edit(Request $request, int $id): View
    {
        $template = CatalogAvailability::filter(DB::table('templates'), 'templates')->find($id);
        abort_unless($template, 404);
        $version = DB::table('template_versions')->where('id', $template->current_version_id)->first();
        $template->version_no = $version?->version_no;
        $definition = Json::decode($version?->definition_json);
        abort_unless(SeoTemplateBundle::isBundle($definition), 422, 'Mẫu cũ được sửa tại danh sách Template SEO. Tạo bộ mới để dùng cho năm kiểu trang.');
        $stableKey = Schema::hasTable('movie_seo_template_catalog')
            ? DB::table('movie_seo_template_catalog')->where('template_id', $id)->value('stable_key') : null;
        $starter = is_string($stableKey) ? $this->starter($stableKey) : null;

        return view('admin.templates.editor', compact('template', 'definition', 'starter') + $this->impact($request, $id) + $this->context());
    }

    public function store(Request $request, ConfigService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['name' => 'required|string|max:200', 'intent' => 'nullable|in:apply,draft']);
        if (DB::table('templates')->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'Tên bộ template đã có. Hãy mở bộ hiện có để sửa.']);
        }
        $data['definition_json'] = SeoTemplateBundleForm::fromRequest($request, SeoTemplateBundle::defaults());
        $data['expected_absent'] = true;
        $template = $service->saveTemplate($data, $request->user()->id);

        $response = redirect('/admin/templates/'.$template->id.'/edit')->with('status', 'Đã tạo bộ Template SEO gồm năm kiểu trang. Chọn bộ này khi tạo hoặc sửa website.');
        if (($data['intent'] ?? 'draft') === 'apply') {
            $response->with('shared_apply_result', app(SharedConfigApply::class)->template($template->id, $request->user()->id));
        }

        return $response;
    }

    public function update(Request $request, int $id, ConfigService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $template = CatalogAvailability::filter(DB::table('templates'), 'templates')->find($id);
        abort_unless($template, 404);
        $definition = Json::decode(DB::table('template_versions')->where('id', $template->current_version_id)->value('definition_json'));
        abort_unless(SeoTemplateBundle::isBundle($definition), 422, 'Không sửa mẫu cũ thành bộ bằng thao tác này.');
        $data = $request->validate(['name' => 'required|string|max:200', 'row_version' => 'required|integer|min:1', 'intent' => 'nullable|in:apply,draft']);
        abort_unless((int) $template->row_version === (int) $data['row_version'], 409, 'Bộ Template SEO đã thay đổi. Hãy tải lại trước khi lưu.');
        $data['id'] = $id;
        $data['definition_json'] = SeoTemplateBundleForm::fromRequest($request, $definition);
        $service->saveTemplate($data, $request->user()->id);

        $response = redirect('/admin/templates/'.$id.'/edit')->with('status', ($data['intent'] ?? 'draft') === 'apply'
            ? 'Đã lưu bộ Template SEO và yêu cầu áp dụng cho các trang đang phục vụ.'
            : 'Đã lưu phiên bản trong kho. Chưa yêu cầu áp dụng cho website.');
        if (($data['intent'] ?? 'draft') === 'apply') {
            $response->with('shared_apply_result', app(SharedConfigApply::class)->template($id, $request->user()->id));
        }

        return $response;
    }

    private function impact(Request $request, int $id): array
    {
        $counts = DB::table('pages as p')->join('sites as s', 's.id', '=', 'p.site_id')->where('p.status', 'live')->whereNotNull('p.current_publication_id')->where('s.status', 'active')
            ->whereRaw('COALESCE(p.template_id, s.template_id) = ?', [$id])
            ->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('s.id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))
            ->selectRaw('count(*) as pages, count(distinct s.id) as sites')->first();

        return ['affectedSiteCount' => (int) $counts->sites, 'affectedPageCount' => (int) $counts->pages];
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()->is_global_admin, 403);
    }

    private function starter(string $key): ?array
    {
        $starter = app(MovieSeoTemplateLibrary::class)->catalog()[$key] ?? null;
        if ($starter === null) {
            return null;
        }
        $path = app('path.resources').'/seo/movie-reference-tkd.json';
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $reference = collect(is_array($data) ? ($data['sources'] ?? $data) : [])
            ->first(fn (mixed $reference): bool => is_array($reference) && ($reference['brand'] ?? null) === $starter['reference_brand']);

        return $starter + ['reference' => $reference];
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Bộ Template SEO dùng chung'];
    }
}
