<?php

namespace App\Http\Controllers;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Publishing\SitePresentationApply;
use App\Domain\Sites\SiteAppearance;
use App\Domain\Support\Access;
use App\Domain\Support\Json;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SiteCacheController extends Controller
{
    public function index(Request $request, int $id, PublicHtmlCache $cache): View
    {
        $site = $this->site($request, $id);
        $actor = $request->user();
        $canWrite = $actor->is_global_admin || DB::table('site_memberships')->where(['site_id' => $id, 'user_id' => $actor->id])->whereIn('role', ['owner', 'operator'])->exists();
        $stats = $cache->stats($id);
        $pageCounts = DB::table('pages')->where('site_id', $id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $publicationPending = DB::table('publish_batch_items as item')->join('publish_batches as batch', 'batch.id', '=', 'item.batch_id')
            ->where('item.site_id', $id)->where('batch.site_id', $id)->whereNotNull('batch.accepted_at')
            ->where('item.accepted_for_publish', true)->whereIn('item.status', ['pending', 'claimed', 'rendering', 'activating'])->count();
        $cacheEvents = DB::table('outbox_events')->where('event_type', 'public-cache.refresh')->whereRaw("payload_json->>'site_id' = ?", [(string) $id]);
        $cachePending = (clone $cacheEvents)->whereNull('delivered_at')->where('attempts', 0)->count();
        $cacheRetry = (clone $cacheEvents)->whereNull('delivered_at')->where('attempts', '>', 0)->count();
        $latestCacheEvent = (clone $cacheEvents)->orderByDesc('created_at')->first(['created_at', 'delivered_at', 'attempts']);
        $batches = DB::table('publish_batches')->where('site_id', $id);
        $latestBatch = null;
        if ($request->session()->has('apply_batch_id')) {
            $latestBatch = (clone $batches)->where('id', (int) $request->session()->get('apply_batch_id'))->first();
        }
        $latestBatch ??= (clone $batches)->whereRaw("COALESCE(options_json->>'origin', '') <> ?", ['quick_apply_child'])->where('request_idempotency_key', 'not like', 'quick-page:%')->where('request_idempotency_key', 'not like', 'catalog-refresh:%')->orderByDesc('id')->first();
        $latestBatch ??= $batches->orderByDesc('id')->first();
        $batchCounts = collect();
        $batchErrors = collect();
        if ($latestBatch) {
            $batchCounts = DB::table('publish_batch_items')->where(['batch_id' => $latestBatch->id, 'site_id' => $id])
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $batchErrors = DB::table('publish_batch_items as item')->leftJoin('pages as page', function ($join) {
                $join->on('page.id', '=', 'item.page_id')->on('page.site_id', '=', 'item.site_id');
            })->where('item.batch_id', $latestBatch->id)->where('item.site_id', $id)->whereNotNull('item.error_code')->where('item.error_code', '<>', '')
                ->orderBy('item.id')->limit(10)->get(['item.page_id', 'item.status', 'item.error_code', 'page.canonical_path']);
        }
        $catalogEvents = DB::table('outbox_events')->where('event_type', 'catalog-refresh.requested')->whereRaw("payload_json->>'site_id' = ?", [(string) $id]);
        $catalogPending = (clone $catalogEvents)->whereNull('delivered_at')->whereRaw("COALESCE(payload_json->>'status', '') <> 'failed'")->count();
        $catalogFailed = (clone $catalogEvents)->whereNull('delivered_at')->whereRaw("payload_json->>'status' = 'failed'")->count();
        $catalogEvent = (clone $catalogEvents)->orderByRaw('delivered_at IS NULL DESC')->orderByDesc('created_at')->first();
        $catalogPayload = $catalogEvent ? Json::decode($catalogEvent->payload_json) : [];
        $catalogParent = isset($catalogPayload['parent_batch_id']) ? DB::table('publish_batches')->where('site_id', $id)->find((int) $catalogPayload['parent_batch_id']) : null;
        $catalogChild = isset($catalogPayload['catalog_batch_id']) ? DB::table('publish_batches')->where('site_id', $id)->find((int) $catalogPayload['catalog_batch_id']) : null;
        $referenceBatch = isset($catalogPayload['reference_batch_id']) ? DB::table('publish_batches')->where('site_id', $id)->find((int) $catalogPayload['reference_batch_id']) : null;
        $referenceCounts = $referenceBatch ? DB::table('publish_batch_items')->where(['batch_id' => $referenceBatch->id, 'site_id' => $id])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') : collect();
        $openUrl = $this->publicUrl($site);
        $applyKey = (string) Str::uuid();
        $delivery = app(SiteDeliverySettings::class)->configuration($id, $actor->id);
        $presentationEvent = app(SitePresentationApply::class)->latest($id, $actor->id);
        $presentationPayload = $presentationEvent ? Json::decode($presentationEvent->payload_json) : [];
        $appearance = app(SiteAppearance::class)->active($id);

        return view('admin.site-cache', compact('site', 'canWrite', 'stats', 'pageCounts', 'publicationPending', 'cachePending', 'cacheRetry', 'latestCacheEvent', 'latestBatch', 'batchCounts', 'batchErrors', 'catalogEvent', 'catalogPending', 'catalogFailed', 'catalogPayload', 'catalogParent', 'catalogChild', 'referenceBatch', 'referenceCounts', 'openUrl', 'applyKey', 'delivery', 'presentationEvent', 'presentationPayload', 'appearance') + [
            'sites' => collect(), 'scope' => '?site_id='.$id, 'globalManagement' => true, 'managementLabel' => 'Cache website',
        ]);
    }

    public function update(Request $request, int $id, PublicHtmlCache $cache): RedirectResponse
    {
        $this->site($request, $id, true);
        $data = $request->validate(['action' => 'required|in:clear,rebuild,delivery_mode']);
        if ($data['action'] === 'delivery_mode') {
            $delivery = $request->validate(['delivery_mode' => 'required|in:prepublish,on_demand', 'delivery_row_version' => 'required|integer|min:0']);
            app(SiteDeliverySettings::class)->save($id, $delivery['delivery_mode'], (int) $delivery['delivery_row_version'], $request->user()->id);

            return redirect('/admin/sites/'.$id.'/cache')->with('status', 'Đã lưu cách phục vụ website. Nội dung đã xuất bản và keyword giữ nguyên.');
        }
        $scheduled = DB::transaction(function () use ($request, $id, $cache, $data): bool {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $id)->lockForUpdate()->first();
            abort_unless($site, 404);
            Access::site($request->user()->id, $id, true);
            if (! $cache->stats($id)['available']) {
                throw ValidationException::withMessages(['action' => 'Chưa đọc được thư mục cache an toàn. Kiểm tra quyền và đường dẫn trước khi thao tác.']);
            }
            if ($data['action'] === 'clear') {
                $cache->invalidateSite($id);
                Access::audit('site.cache.cleared', $request->user()->id, $id, ['action' => 'clear']);

                return false;
            }
            if (! config('public_html_cache.enabled') || $site->content_type !== 'movie' || $site->status !== 'active') {
                throw ValidationException::withMessages(['action' => 'Chỉ dựng lại khi cache HTML đã bật và website phim đang hoạt động.']);
            }
            $alreadyPending = DB::table('outbox_events')->where('event_type', 'public-cache.refresh')->whereNull('delivered_at')
                ->whereRaw("payload_json->>'site_id' = ?", [(string) $id])->whereRaw("payload_json->>'page_id' IS NULL")->exists();
            if ($alreadyPending) {
                return false;
            }
            $cache->scheduleRefresh($id);
            Access::audit('site.cache.rebuild.requested', $request->user()->id, $id, ['action' => 'rebuild']);

            return true;
        }, 3);
        $message = $data['action'] === 'clear'
            ? 'Đã xóa file cache của website này. Nội dung đã xuất bản và keyword giữ nguyên; cache có thể được tạo lại khi truy cập hoặc khi xử lý yêu cầu đang chờ.'
            : ($scheduled ? 'Đã yêu cầu dựng lại cache từ nội dung đang phục vụ. Yêu cầu đang chờ xử lý; chưa xác nhận đã dựng xong.' : 'Website đã có yêu cầu dựng lại cache đang chờ xử lý. Không tạo thêm yêu cầu trùng.');

        return redirect('/admin/sites/'.$id.'/cache')->with('status', $message);
    }

    private function site(Request $request, int $id, bool $write = false): object
    {
        Access::site($request->user()->id, $id, $write);
        $request->validate(['site_id' => 'nullable|integer|min:1']);
        abort_if($request->filled('site_id') && $request->integer('site_id') !== $id, 403);

        return DB::table('sites')->find($id) ?? abort(404);
    }

    private function publicUrl(object $site): ?string
    {
        $base = $site->base_url;
        if (! $base) {
            $host = DB::table('site_domains')->where(['site_id' => $site->id, 'is_primary' => true, 'status' => 'active'])->value('host_ascii');
            $base = $host ? 'https://'.$host : null;
        }
        $parts = $base ? parse_url($base) : false;
        if ($parts === false || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return rtrim($base, '/').'/';
    }
}
