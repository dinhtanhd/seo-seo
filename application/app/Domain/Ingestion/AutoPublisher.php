<?php

namespace App\Domain\Ingestion;

use App\Domain\Publishing\CatalogRefresh;
use App\Domain\Publishing\QuickApply;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

final class AutoPublisher
{
    public function dispatchPending(): int
    {
        $count = 0;
        $delivery = app(SiteDeliverySettings::class);
        $pending = DB::table('outbox_events')->where('event_type', 'ingestion.committed')->whereNull('delivered_at')->where('attempts', '<', 4);
        foreach ($delivery->excludeAutomated($pending, 'ingestion.committed')->limit(50)->get() as $event) {
            try {
                $payload = json_decode($event->payload_json, true, 24, JSON_THROW_ON_ERROR);
                if (isset($payload['site_id']) && $delivery->enabled((int) $payload['site_id'])) {
                    continue;
                }
                $pageIds = $this->eligiblePages($event, $payload);
                if ($pageIds === []) {
                    // Selection/auto-publish was withdrawn after ingestion; retain existing publications.
                    DB::table('outbox_events')->where('id', $event->id)->update(['delivered_at' => now(), 'attempts' => $event->attempts + 1]);

                    continue;
                }
                if ($pageIds !== $this->eligiblePages($event, $payload)) {
                    throw new Conflict('SOURCE_SELECTION_CHANGED');
                }
                $batch = app(QuickApply::class)->apply((int) $payload['site_id'], $pageIds, (int) $payload['actor_id'], 'auto-ingest:'.$event->id);
                $invalid = DB::table('publish_batch_items')->where('batch_id', $batch->id)->whereIn('status', ['error', 'stale', 'locked', 'failed', 'capacity_conflict'])->first();
                if ($invalid && ! $batch->accepted_at) {
                    throw new \RuntimeException('AUTO_PUBLISH_VALIDATION_FAILED: '.$invalid->error_code);
                }
                if (! $batch->accepted_at) {
                    throw new \RuntimeException('AUTO_APPLY_NOT_ACCEPTED: '.$batch->status);
                }
                if ($invalid) {
                    DB::table('ingest_runs')->where('id', $event->aggregate_id)->update(['error' => 'AUTO_APPLY_PARTIAL: '.mb_substr((string) $invalid->error_code, 0, 1900)]);
                }
                DB::table('outbox_events')->where('id', $event->id)->update(['delivered_at' => now(), 'attempts' => $event->attempts + 1]);
                $count++;
            } catch (\Throwable $e) {
                DB::table('outbox_events')->where('id', $event->id)->increment('attempts');
                DB::table('ingest_runs')->where('id', $event->aggregate_id)->update(['error' => 'AUTO_PUBLISH_FAILED: '.mb_substr($e->getMessage(), 0, 1900)]);
                report($e);
            }
        }
        app(CatalogRefresh::class)->dispatchPending();

        return $count;
    }

    private function eligiblePages(object $event, array $payload): array
    {
        $run = DB::table('ingest_runs')->where('id', $event->aggregate_id)->first();
        $source = $run ? DB::table('source_connectors')->where('id', $run->connector_id)->first() : null;
        if (! $source) {
            return [];
        }
        if ($source->status !== 'active') {
            throw new \RuntimeException('SOURCE_INACTIVE: nguồn đã tạm dừng.');
        }
        $binding = DB::table('source_site_bindings')->where(['connector_id' => $source->id, 'site_id' => (int) $payload['site_id'], 'status' => 'active'])->whereNull('deleted_at')->first();
        $auto = $binding ? Json::decode($binding->auto_publish_policy) : [];
        if (! $binding || ! ($auto['enabled'] ?? false) || (int) ($auto['actor_id'] ?? 0) !== (int) $payload['actor_id']) {
            return [];
        }
        if (isset($payload['binding_id']) || isset($payload['binding_generation'])) {
            if ((int) ($payload['binding_id'] ?? 0) !== (int) $binding->id
                || (int) ($payload['binding_generation'] ?? 0) !== (int) $binding->link_generation) {
                return [];
            }
        } elseif ((int) $binding->link_generation !== 1) {
            // Legacy events cannot become valid again after an explicit unlink/relink.
            return [];
        }
        $policy = SourceTypeService::policy($binding);
        SourceTypeService::assertCurrentPolicy($policy, $source);
        $ids = [];
        foreach (DB::table('pages as p')->join('content_items as c', 'c.id', '=', 'p.content_item_id')
            ->join('content_revisions as r', 'r.id', '=', 'p.working_content_revision_id')
            ->where('p.site_id', (int) $payload['site_id'])->where('c.source_connector_id', $source->id)
            ->whereIn('p.id', $payload['page_ids'])->orderBy('p.id')->get(['p.id', 'r.payload_json']) as $page) {
            if (SourceTypeService::accepts($policy, SourceTypeService::recordType(Json::decode($page->payload_json)))) {
                $ids[] = (int) $page->id;
            }
        }

        return $ids;
    }
}
