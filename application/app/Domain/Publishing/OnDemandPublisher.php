<?php

namespace App\Domain\Publishing;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Fanmulu\FanmuluSourceCatalog;
use App\Domain\Ingestion\SourceTypeService;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

/** First publication of a known imported URL, explicitly enabled by its administrator. */
final class OnDemandPublisher
{
    public function resolve(int $siteId, int $pageId): object
    {
        if (! app(SiteDeliverySettings::class)->enabled($siteId)) {
            throw new Conflict('ON_DEMAND_DISABLED');
        }
        $result = DB::transaction(function () use ($siteId, $pageId): object|string|array {
            DB::statement("SET LOCAL lock_timeout = '10s'");
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $initialSite = DB::table('sites')->find($siteId);
            $fanmulu = FanmuluSettings::fromSite($initialSite);
            $changesSourceList = config('public_html_cache.enabled') && $fanmulu['enabled'] && $fanmulu['links_per_page'] > 0;
            $alreadyListed = $changesSourceList && in_array($pageId, app(FanmuluSourceCatalog::class)->candidateIds($siteId, 1, $pageId), true);
            $exclusive = $changesSourceList && ! $alreadyListed;
            $siteQuery = DB::table('sites')->where('id', $siteId);
            $site = ($exclusive ? $siteQuery->lockForUpdate() : $siteQuery->sharedLock())->first();
            if (! $site || ($site->deleted_at ?? null) !== null) {
                throw new Conflict('ON_DEMAND_PAGE_UNAVAILABLE');
            }
            $currentFanmulu = FanmuluSettings::fromSite($site);
            if (! $exclusive && config('public_html_cache.enabled') && $currentFanmulu['enabled'] && $currentFanmulu['links_per_page'] > 0
                && ! in_array($pageId, app(FanmuluSourceCatalog::class)->candidateIds($siteId, 1, $pageId), true)) {
                throw new Conflict('ON_DEMAND_CONFIGURATION_CHANGED');
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['on-demand-page:'.$siteId.':'.$pageId]);
            $page = DB::table('pages')->where('site_id', $siteId)->find($pageId);
            abort_unless($page, 404);
            abort_if(in_array($page->status, ['archived', 'gone'], true), 410);
            if ($page->status === 'live' && $page->current_publication_id) {
                return $page;
            }
            if (! $site || $site->status !== 'active' || $site->content_type !== 'movie'
                || $page->status !== 'draft' || $page->page_type !== 'movie' || $page->page_kind !== 'regular'
                || $page->current_publication_id || $page->publish_locked
                || DB::table('page_publications')->where('page_id', $pageId)->exists()) {
                throw new Conflict('ON_DEMAND_PAGE_UNAVAILABLE');
            }
            $settings = DB::table('site_delivery_settings')->where('site_id', $siteId)->first();
            if ($settings?->mode !== 'on_demand' || ! $settings->actor_id) {
                throw new Conflict('ON_DEMAND_DISABLED');
            }
            $actor = (int) $settings->actor_id;
            Access::site($actor, $siteId, true);
            $content = DB::table('content_items')->find($page->content_item_id);
            if ($content && $content->library_status !== 'active') {
                throw new Conflict('MOVIE_ARCHIVED');
            }
            $source = $content ? DB::table('source_connectors')->whereNull('deleted_at')->find($content->source_connector_id) : null;
            $binding = $source ? DB::table('source_site_bindings')->where(['site_id' => $siteId, 'connector_id' => $source->id, 'status' => 'active'])->whereNull('deleted_at')->first() : null;
            if (! $source || $source->status !== 'active' || ! $binding || ! (Json::decode($binding->auto_publish_policy)['enabled'] ?? false)
                || $page->working_content_revision_id !== $content->current_revision_id) {
                throw new Conflict('ON_DEMAND_SOURCE_UNAVAILABLE');
            }
            $policy = SourceTypeService::policy($binding);
            SourceTypeService::assertCurrentPolicy($policy, $source);
            $record = Json::decode(DB::table('content_revisions')->where('id', $page->working_content_revision_id)->value('payload_json'));
            if (! SourceTypeService::accepts($policy, SourceTypeService::recordType($record))) {
                throw new Conflict('ON_DEMAND_SOURCE_TYPE_DISABLED');
            }

            // Previously accepted pins have precedence over any newly planned proposal.
            $accepted = DB::table('publish_batch_items as i')->join('publish_batches as b', 'b.id', '=', 'i.batch_id')
                ->where('i.page_id', $pageId)->where('i.site_id', $siteId)->whereNotNull('b.accepted_at')
                ->whereNull('b.cancel_requested_at')->where('i.accepted_for_publish', true)
                ->whereNotIn('i.status', ['published', 'unchanged', 'cancelled'])->orderBy('i.id')
                ->first(['i.id', 'i.batch_id', 'i.status', 'i.error_code', 'b.options_json']);
            if ($accepted) {
                $options = Json::decode($accepted->options_json);
                if (($options['origin'] ?? null) === 'first_visit' && in_array($accepted->status, ['pending', 'claimed', 'rendering', 'activating'], true)) {
                    return ['first_visit_actor' => $actor];
                }
                $eventId = $options['workflow_event_id'] ?? null;
                if ($accepted->status !== 'pending') {
                    return $accepted->error_code ?? 'ON_DEMAND_ACCEPTED_ITEM_NOT_READY';
                }
                if ($eventId) {
                    app(QuickApplyWorkflow::class)->process($eventId, (int) $accepted->id, waitForParent: true);
                } else {
                    app(Publisher::class)->publishItem((int) $accepted->id);
                }
                $itemId = (int) $accepted->id;
            } else {
                return ['first_visit_actor' => $actor];
            }
            $fresh = DB::table('pages')->find($pageId);
            if ($fresh->status === 'live' && $fresh->current_publication_id) {
                return $fresh;
            }

            return DB::table('publish_batch_items')->where('id', $itemId)->value('error_code') ?? 'ON_DEMAND_BUSY';
        }, 3);
        if (is_array($result)) {
            return app(FirstVisitPublisher::class)->resolve($siteId, $pageId, $result['first_visit_actor']);
        }
        // Commit a failed proposal as evidence; a later request must not silently choose another keyword.
        if (is_string($result)) {
            throw new Conflict($result);
        }

        return $result;
    }
}
