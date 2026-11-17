<?php

namespace App\Http\Controllers;

use App\Domain\Publishing\QuickApply;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Sites\SiteAppearance;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Sites\UrlSuffixCatalog;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SitePresentationController extends Controller
{
    public function show(Request $request, int $id, SitePresentationService $service)
    {
        $request->validate(['site_id' => 'nullable|integer|min:1']);
        $siteId = $request->integer('site_id') ?: $id;
        Access::site($request->user()->id, $siteId);
        if ($siteId !== $id) {
            return redirect('/admin/sites/'.$siteId.'/presentation?site_id='.$siteId);
        }
        $configuration = $service->configuration($id, $request->user()->id);
        $site = $configuration['site'];
        $sites = CatalogAvailability::filter(DB::table('sites'), 'sites')->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))->orderBy('name')->get();
        $themes = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->leftJoin('movie_theme_versions', 'movie_theme_versions.id', '=', 'movie_themes.current_version_id')->where(fn ($query) => $query->where('movie_themes.status', 'active')->orWhere('movie_themes.id', $site->theme_id))->select('movie_themes.*', 'movie_theme_versions.version_no', 'movie_theme_versions.views_json')->orderBy('movie_themes.name')->get();
        $themeChoices = $themes->map(fn ($theme) => ['id' => $theme->id, 'name' => $theme->name, 'slug' => $theme->slug, 'status' => $theme->status, 'version_no' => $theme->version_no, 'view_keys' => array_keys(Json::decode($theme->views_json))])->all();
        $previewPages = DB::table('pages')->where('site_id', $id)->whereNotIn('status', ['archived', 'gone']);
        if (app(SiteDeliverySettings::class)->enabled($id)) {
            $previewPages->where('status', 'live')->whereNotNull('current_publication_id');
        }
        $previewPageCount = (clone $previewPages)->count();
        $previewLimit = 100;
        $pageIds = $previewPages->orderBy('id')->limit($previewLimit)->pluck('id')->all();
        $draftHash = Json::hash($configuration['presentation']);
        $liveHash = $configuration['live'] ? Json::hash($configuration['live']['routing']) : null;
        $suffixChoices = app(UrlSuffixCatalog::class)->choices();

        return view('admin.site-presentation', compact('site', 'sites', 'configuration', 'themes', 'themeChoices', 'pageIds', 'previewPageCount', 'previewLimit', 'draftHash', 'liveHash', 'suffixChoices') + ['scope' => '?site_id='.$id]);
    }

    public function apply(Request $request, int $id)
    {
        Access::site($request->user()->id, $id, true);
        abort_unless(DB::table('sites')->where('id', $id)->exists(), 404);
        $request->validate(['site_id' => 'nullable|integer|min:1', 'page_ids' => 'nullable|array|max:10000', 'page_ids.*' => 'integer|min:1', 'idempotency_key' => 'required|string|max:160']);
        abort_if($request->filled('site_id') && $request->integer('site_id') !== $id, 409, 'Website gửi lên không khớp đường dẫn.');

        return $this->applySaved($request, $id, $request->string('idempotency_key')->toString());
    }

    private function applySaved(Request $request, int $id, string $key)
    {
        $request->validate(['page_ids' => 'nullable|array|max:10000', 'page_ids.*' => 'integer|min:1']);
        if (! $request->filled('page_ids')) {
            app(SiteAppearance::class)->activate($id, $request->user()->id);

            return $this->appearanceResponse($id);
        }
        $query = DB::table('pages')->where('site_id', $id)->whereNotIn('status', ['archived', 'gone'])->orderBy('id');
        $pageIds = $request->input('page_ids') ?: $query->pluck('id')->all();
        if ($pageIds === []) {
            return redirect('/admin/sites/'.$id.'/cache')->with('status', 'Đã lưu cấu hình. Website chưa có nội dung; hãy nhập phim hoặc gắn nguồn.');
        }
        $batch = app(QuickApply::class)->apply($id, $pageIds, $request->user()->id, $key);
        $counts = Json::decode($batch->counts);
        $errors = array_sum(array_intersect_key($counts, array_flip(['error', 'locked', 'stale', 'capacity_conflict', 'failed', 'cancelled'])));
        $message = ! $batch->accepted_at ? 'Đã lưu cấu hình nhưng chưa áp dụng được. Kiểm tra lỗi hoặc trạng thái cần xử lý bên dưới.' : ($errors ? 'Đã xử lý yêu cầu. Có '.$errors.' trang chưa áp dụng được; xem nguyên nhân bên dưới.' : 'Đã tiếp nhận áp dụng và tạo cache tự động. Trang chủ được xử lý trước; các trang còn lại cập nhật qua worker.');

        return redirect('/admin/sites/'.$id.'/cache')->with('status', $message)->with('apply_batch_id', $batch->id);
    }

    private function appearanceResponse(int $siteId): RedirectResponse
    {
        return redirect('/admin/sites/'.$siteId.'/presentation')
            ->with('status', 'Đã áp dụng giao diện cho toàn website và xóa cache. Phim chính và fanmulu dùng giao diện mới ngay khi mở link, rồi lưu cache; nội dung và keyword giữ nguyên.');
    }

    public function save(Request $request, int $id, SitePresentationService $service)
    {
        $configuration = $service->configuration($id, $request->user()->id);
        abort_unless($configuration['can_write'], 403);
        $rules = ['intent' => 'nullable|in:apply,draft', 'row_version' => 'required|integer|min:1', 'theme_id' => 'nullable|integer|min:1', 'movie_key' => 'nullable|in:source_id,pinyin_id,pinyin_compact_id', 'category_key' => 'nullable|in:id,pinyin_id,pinyin_compact_id', 'suffixes' => 'sometimes|array:home,list,detail,play,search', 'routes' => 'required|array:home,list,detail,play,search', 'views' => 'nullable|array:home,list,detail,play,search', 'player' => 'nullable|array:mode,allowed_hosts', 'player.mode' => 'nullable|in:external_link,native', 'player.allowed_hosts' => 'nullable|array', 'player.allowed_hosts.*' => 'string|max:253', 'page_ids' => 'nullable|array|max:10000', 'page_ids.*' => 'integer|min:1'];
        foreach (['home', 'list', 'detail', 'play', 'search'] as $role) {
            $rules['suffixes.'.$role] = 'sometimes|nullable|string|max:13';
            $rules['routes.'.$role] = 'required|string|max:120';
            $rules['views.'.$role] = 'nullable|string|in:home,list,category,detail,play,search';
        }
        $data = $request->validate($rules);
        $saved = DB::transaction(function () use ($service, $request, $id, $data): object {
            $saved = $service->save($id, $data, $request->user()->id);
            if (($data['intent'] ?? 'draft') === 'apply' && ! $request->filled('page_ids')) {
                return app(SiteAppearance::class)->activate($id, $request->user()->id, (int) $saved->row_version);
            }

            return $saved;
        });
        if (($data['intent'] ?? 'draft') === 'apply') {
            if (! $request->filled('page_ids')) {
                return $this->appearanceResponse($id);
            }

            return $this->applySaved($request, $id, 'presentation:'.$id.':v'.$saved->row_version);
        }

        return redirect('/admin/sites/'.$id.'/presentation?site_id='.$id)->with('status', 'Đã lưu nháp. Bấm Lưu & áp dụng khi muốn đổi giao diện toàn website và xóa cache.');
    }
}
