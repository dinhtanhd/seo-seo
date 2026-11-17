<?php

namespace App\Domain\Publishing;

use App\Domain\Advertising\AdvertisingScriptPaths;
use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Fanmulu\FanmuluUrlRegistry;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use Illuminate\Support\Facades\DB;

final class PageLifecycleService
{
    private function locks(int $siteId, array $keywordIds): void
    {
        $keywordIds = array_values(array_unique($keywordIds));
        sort($keywordIds);
        foreach ($keywordIds as $keywordId) {
            DB::table('keyword_usage_buckets')->insertOrIgnore(['site_id' => $siteId, 'keyword_id' => $keywordId]);
            DB::table('keyword_usage_buckets')->where(['site_id' => $siteId, 'keyword_id' => $keywordId])->lockForUpdate()->first();
        }
    }

    public function changeStatus(int $pageId, string $status, int $actorId, ?int $expectedVersion = null): object
    {
        if (! in_array($status, ['draft', 'archived', 'gone'])) {
            throw new \InvalidArgumentException('Khôi phục về nháp rồi preview/xuất bản để claim lại quota');
        }
        $initial = DB::table('pages')->find($pageId);
        if (! $initial) {
            abort(404);
        }Access::site($actorId, $initial->site_id, true);

        return DB::transaction(function () use ($initial, $pageId, $status, $actorId, $expectedVersion) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $initial->site_id)->lockForUpdate()->first();
            $lease = DB::table('page_publish_leases')->where('page_id', $pageId)->first();
            $item = $lease?->owner_item_id ? DB::table('publish_batch_items')->find($lease->owner_item_id) : null;
            if ($item) {
                DB::table('publish_batches')->where('id', $item->batch_id)->lockForUpdate()->first();
            }
            $page = DB::table('pages')->where('id', $pageId)->lockForUpdate()->first();
            if ($expectedVersion !== null && $page->row_version !== $expectedVersion) {
                throw new Conflict('PAGE_VERSION_CONFLICT');
            }
            $keys = DB::table('page_keyword_assignments')->where('publication_id', $page->current_publication_id)->pluck('keyword_id')->all();
            $keys = array_merge($keys, DB::table('keyword_capacity_claims')->where(['page_id' => $pageId, 'status' => 'held'])->pluck('keyword_id')->all());
            $this->locks($page->site_id, $keys);
            if ($lease) {
                DB::table('page_publish_leases')->where('page_id', $pageId)->update(['owner_item_id' => null, 'fencing_token' => DB::raw('fencing_token+1'), 'expires_at' => null]);
                DB::table('keyword_capacity_claims')->where(['page_id' => $pageId, 'status' => 'held'])->update(['status' => 'released']);
            }
            if ($item) {
                DB::table('publish_batch_items')->where('id', $item->id)->where('status', '<>', 'published')->update(['status' => 'stale', 'error_code' => 'PAGE_STATE_CHANGED']);
                app(Publisher::class)->counts($item->batch_id);
            }
            app(PublicHtmlCache::class)->invalidatePage($page);
            DB::table('pages')->where('id', $pageId)->update(['status' => $status, 'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
            if ($page->status === 'live' && ($page->page_kind ?? 'regular') === 'regular' && $page->page_type === 'movie') {
                $fanmulu = FanmuluSettings::fromSite($site);
                if ($fanmulu['enabled'] && $fanmulu['links_per_page'] > 0) {
                    app(PublicHtmlCache::class)->invalidateSite($page->site_id);
                    app(PublicHtmlCache::class)->scheduleRefresh($page->site_id);
                }
            }
            Access::audit('page.state.changed', $actorId, $page->site_id, ['status' => $status, 'previous_status' => $page->status], $pageId);

            return DB::table('pages')->find($pageId);
        }, 3);
    }

    public function movePath(int $pageId, string $path, int $actorId, int $expectedVersion): object
    {
        if (! preg_match('~^/(?!/)[^?#\\\\\x00-\x20]*$~u', $path) || strlen($path) > 2000) {
            throw new \InvalidArgumentException('Đường dẫn tuyệt đối nội bộ không hợp lệ');
        }
        $initial = DB::table('pages')->find($pageId);
        if (! $initial) {
            abort(404);
        }Access::site($actorId, $initial->site_id, true);

        return DB::transaction(function () use ($initial, $pageId, $path, $actorId, $expectedVersion) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $initial->site_id)->lockForUpdate()->first();
            $page = DB::table('pages')->where('id', $pageId)->lockForUpdate()->first();
            if ($page->row_version !== $expectedVersion) {
                throw new Conflict('PAGE_VERSION_CONFLICT');
            }if ($page->canonical_path === $path) {
                return $page;
            }
            app(AdvertisingScriptPaths::class)->assertPagePathAvailable($path);
            app(FanmuluUrlRegistry::class)->assertPathWriteAvailable((int) $page->site_id, $path, $page->canonical_path);
            if (DB::table('pages')->where(['site_id' => $page->site_id, 'canonical_path' => $path])->exists() || DB::table('page_redirects')->where(['site_id' => $page->site_id, 'from_path' => $path])->exists()) {
                throw new Conflict('PATH_ALREADY_USED');
            }
            // A live snapshot retains its canonical URL. Move requires explicit unpublish first.
            if ($page->status === 'live') {
                throw new Conflict('UNPUBLISH_BEFORE_PATH_MOVE');
            }
            if (DB::table('page_publish_leases')->where('page_id', $pageId)->whereNotNull('owner_item_id')->exists()) {
                throw new Conflict('LEASE_BUSY');
            }
            app(PublicHtmlCache::class)->invalidatePage($page, [$path]);
            DB::table('page_redirects')->insert(['site_id' => $page->site_id, 'from_path' => $page->canonical_path, 'to_path' => $path, 'status_code' => 301]);
            DB::table('page_redirects')->where('site_id', $page->site_id)->where('to_path', $page->canonical_path)->update(['to_path' => $path]);
            DB::table('pages')->where('id', $pageId)->update(['canonical_path' => $path, 'row_version' => DB::raw('row_version+1')]);
            Access::audit('page.path.moved', $actorId, $page->site_id, ['from' => $page->canonical_path, 'to' => $path], $pageId);

            return DB::table('pages')->find($pageId);
        }, 3);
    }

    public function setSiteStatus(int $siteId, string $status, int $actorId): void
    {
        Access::site($actorId, $siteId, true);
        if (! in_array($status, ['active', 'paused', 'archived'])) {
            throw new \InvalidArgumentException('Site status không hợp lệ');
        }
        DB::transaction(function () use ($siteId, $status, $actorId) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            $assignments = DB::table('pages as p')->join('page_keyword_assignments as a', 'a.publication_id', '=', 'p.current_publication_id')->where(['p.site_id' => $siteId, 'p.status' => 'live'])->select('a.keyword_id', 'p.id as page_id')->get();
            $this->locks($siteId, $assignments->pluck('keyword_id')->all());
            if ($status === 'active' && $site->status !== 'active') {
                foreach ($assignments->groupBy('keyword_id') as $key => $pairs) {
                    $keyword = DB::table('keywords')->find($key);
                    $override = DB::table('site_keyword_limits')->where(['site_id' => $siteId, 'keyword_id' => $key])->first();
                    $cap = $override ? $override->max_active_pages : $keyword->default_max_active_pages;
                    $held = DB::table('keyword_capacity_claims')->where(['site_id' => $siteId, 'keyword_id' => $key, 'status' => 'held'])->pluck('page_id')->all();
                    if ($cap !== null && count(array_unique(array_merge($pairs->pluck('page_id')->all(), $held))) > $cap) {
                        throw new Conflict('SITE_RESUME_CAPACITY_CONFLICT', (string) $key);
                    }
                }
            }
            if ($site->status !== $status) {
                app(PublicHtmlCache::class)->invalidateAll();
            }
            app(PublicHtmlCache::class)->invalidateSite($siteId);
            DB::table('sites')->where('id', $siteId)->update(['status' => $status, 'config_epoch' => DB::raw('config_epoch+1'), 'row_version' => DB::raw('row_version+1')]);
            app(PublicHtmlCache::class)->scheduleRefresh($siteId);
            Access::audit('site.state.changed', $actorId, $siteId, ['from' => $site->status, 'to' => $status]);
        }, 3);
    }
}
