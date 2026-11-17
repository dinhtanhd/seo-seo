<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Publishing\ProcessCampaignChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Persist reviewed plans across sites; publishing remains the existing fenced Publisher's responsibility. */
final class PublicationCampaignService
{
    public const CHUNK = 100;

    private const TERMINAL = ['completed', 'partial', 'failed', 'cancelled'];

    public function create(array $data, int $actor): object
    {
        $action = $data['action'] ?? 'publish';
        if (! in_array($action, ['publish', 'reallocate'], true)) {
            throw new \InvalidArgumentException('Chọn publish hoặc reallocate.');
        }
        $ids = $this->ids($data['page_ids'] ?? []);
        $key = $this->key($data['idempotency_key'] ?? (string) Str::uuid());
        $hash = Json::hash([$action, $ids, $data['operation_id'] ?? null, $data['fingerprint'] ?? null]);

        return DB::transaction(function () use ($data, $actor, $action, $ids, $key, $hash) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['publication-campaign:'.$actor.':'.$key]);
            $old = DB::table('publication_campaigns')->where(['requested_by' => $actor, 'idempotency_key' => $key])->first();
            if ($old) {
                $this->authorize($old, $actor, true);
                if ($old->request_hash !== $hash) {
                    throw new Conflict('IDEMPOTENCY_PAYLOAD_CONFLICT');
                }

                return $old;
            }
            $selection = isset($data['operation_id'])
                ? app(PageBulkService::class)->resolveOperation($data['operation_id'], $data['fingerprint'] ?? '', $actor)
                : app(PageBulkService::class)->resolve(['mode' => 'ids', 'page_ids' => $ids], $actor);
            if ($ids !== $selection['page_ids']) {
                throw new Conflict('BULK_SELECTION_STALE');
            }

            return $this->persist($selection, $actor, $action, $key, $hash);
        }, 3);
    }

    private function persist(array $selection, int $actor, string $action, string $key, string $hash, ?int $source = null, array $restore = []): object
    {
        $campaign = DB::table('publication_campaigns')->insertGetId([
            'public_id' => (string) Str::uuid(), 'requested_by' => $actor, 'action' => $action,
            'idempotency_key' => $key, 'request_hash' => $hash, 'selection_fingerprint' => $selection['fingerprint'],
            'source_campaign_id' => $source, 'options_json' => Json::encode(['seed' => bin2hex(random_bytes(16)),
                'global_epoch' => (int) DB::table('system_settings')->where('id', 1)->value('config_epoch'),
                'site_hashes' => DB::table('sites')->whereIn('id', $selection['site_ids'])->get()->mapWithKeys(fn ($site) => [$site->id => Json::hash((array) $site)])->all()]),
        ]);
        $rows = collect($selection['rows']);
        foreach ($rows->groupBy('site_id') as $site => $siteRows) {
            DB::table('publication_campaign_sites')->insert(['campaign_id' => $campaign, 'site_id' => $site, 'total' => $siteRows->count()]);
            $chunkNo = 0;
            // Aggregate pages are prepared last; their publication follows the content they link to.
            foreach ($siteRows->groupBy(fn ($row) => $row['page_type'] === 'home' ? 2 : ($row['page_type'] === 'category' ? 1 : 0))->sortKeys() as $group) {
                foreach ($group->chunk(self::CHUNK) as $chunkRows) {
                    $eligible = $chunkRows->filter(fn ($row) => $action !== 'rollback' || isset($restore[$row['id']]['restore_publication_id']));
                    $chunk = $eligible->isEmpty() ? null : DB::table('publication_campaign_chunks')->insertGetId([
                        'campaign_id' => $campaign, 'site_id' => $site, 'chunk_no' => ++$chunkNo,
                        'page_ids_json' => Json::encode($eligible->pluck('id')->values()->all()),
                        'restore_map_json' => Json::encode(array_intersect_key($restore, array_flip($eligible->pluck('id')->all()))),
                    ]);
                    $records = [];
                    foreach ($chunkRows as $row) {
                        $skip = $action === 'rollback' && ! isset($restore[$row['id']]['restore_publication_id']);
                        $records[] = ['campaign_id' => $campaign, 'site_id' => $site, 'page_id' => $row['id'],
                            'chunk_id' => $skip ? null : $chunk, 'expected_row_version' => $row['row_version'],
                            'expected_head_id' => $restore[$row['id']]['expected_head_id'] ?? $row['current_publication_id'],
                            'expected_row_hash' => $row['row_hash'], 'path' => $row['path'],
                            'status' => $skip ? 'skipped' : 'selected', 'error_code' => $skip ? 'NO_PREVIOUS_PUBLICATION' : null];
                    }
                    DB::table('publication_campaign_pages')->insert($records);
                    if ($chunk) {
                        $this->event($chunk, 'prepare');
                    }
                }
            }
        }
        Access::audit('campaign.created', $actor, null, ['campaign_id' => $campaign, 'action' => $action, 'total' => $rows->count()]);
        $this->reconcile($campaign);

        return DB::table('publication_campaigns')->find($campaign);
    }

    public function accept(int $id, int $actor): object
    {
        return DB::transaction(function () use ($id, $actor) {
            $campaign = $this->campaign($id, true);
            $this->authorize($campaign, $actor, true);
            if ($campaign->cancel_requested_at) {
                throw new Conflict('CAMPAIGN_CANCELLED');
            }
            if ($campaign->accepted_at) {
                return $campaign;
            }
            if ($campaign->status !== 'ready') {
                throw new Conflict('CAMPAIGN_NOT_READY');
            }
            $chunks = DB::table('publication_campaign_chunks')->where('campaign_id', $id)->where('status', 'ready')->get();
            if ($chunks->isEmpty()) {
                throw new Conflict('CAMPAIGN_NO_REVIEWABLE_BATCH');
            }
            DB::table('publication_campaigns')->where('id', $id)->update(['accepted_at' => now(), 'status' => 'applying', 'updated_at' => now()]);
            foreach ($chunks as $chunk) {
                DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['status' => 'apply_queued']);
                $this->event($chunk->id, 'apply');
            }
            Access::audit('campaign.accepted', $actor, null, ['campaign_id' => $id]);

            return $this->campaign($id);
        }, 3);
    }

    public function cancel(int $id, int $actor): object
    {
        return DB::transaction(function () use ($id, $actor) {
            $campaign = $this->campaign($id, true);
            $this->authorize($campaign, $actor, true);
            if ($campaign->cancel_requested_at) {
                return $campaign;
            }
            DB::table('publication_campaigns')->where('id', $id)->update(['cancel_requested_at' => now(), 'cancelled_by' => $actor, 'status' => 'cancelling', 'updated_at' => now()]);
            $chunks = DB::table('publication_campaign_chunks')->where('campaign_id', $id)->get();
            // Fence publication immediately, before any asynchronous claim cleanup.
            DB::table('publish_batches')->whereIn('id', $chunks->pluck('batch_id')->filter())->whereNull('cancel_requested_at')->update(['cancel_requested_at' => now()]);
            foreach ($chunks as $chunk) {
                if ($chunk->batch_id) {
                    DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['status' => 'cancel_queued']);
                    $this->event($chunk->id, 'cancel');
                } elseif ($chunk->status !== 'preparing') {
                    DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['status' => 'cancelled', 'finished_at' => now()]);
                    DB::table('publication_campaign_pages')->where('chunk_id', $chunk->id)->update(['status' => 'cancelled', 'error_code' => 'CAMPAIGN_CANCELLED']);
                }
            }
            Access::audit('campaign.cancelled', $actor, null, ['campaign_id' => $id]);
            $this->reconcile($id);

            return $this->campaign($id);
        }, 3);
    }

    public function retry(int $id, int $actor): object
    {
        return DB::transaction(function () use ($id, $actor) {
            $campaign = $this->campaign($id, true);
            $this->authorize($campaign, $actor, true);
            if ($campaign->cancel_requested_at) {
                throw new Conflict('CAMPAIGN_CANCELLED');
            }
            foreach (DB::table('publication_campaign_chunks')->where('campaign_id', $id)->get() as $chunk) {
                if (! $campaign->accepted_at && ! $chunk->batch_id && $chunk->status === 'failed') {
                    DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['status' => 'prepare_queued', 'attempts' => 0, 'error_code' => null, 'lease_token' => null, 'lease_expires_at' => null]);
                    $this->event($chunk->id, 'prepare');
                } elseif ($campaign->accepted_at && $chunk->batch_id && DB::table('publish_batch_items')->where('batch_id', $chunk->batch_id)->where('accepted_for_publish', true)->where('status', 'failed')->exists()) {
                    DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['status' => 'retry_queued', 'attempts' => 0, 'lease_token' => null, 'lease_expires_at' => null]);
                    $this->event($chunk->id, 'retry');
                }
            }
            $this->reconcile($id);

            return $this->campaign($id);
        }, 3);
    }

    public function rollback(int $id, int $actor, ?string $key = null): object
    {
        $key = $this->key($key ?? (string) Str::uuid());

        return DB::transaction(function () use ($id, $actor, $key) {
            $source = $this->campaign($id, true);
            $this->authorize($source, $actor, true);
            if (! in_array($source->status, self::TERMINAL, true)) {
                throw new Conflict('CAMPAIGN_STILL_RUNNING');
            }
            $hash = Json::hash(['rollback', $id]);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['publication-campaign:'.$actor.':'.$key]);
            $old = DB::table('publication_campaigns')->where(['requested_by' => $actor, 'idempotency_key' => $key])->first();
            if ($old) {
                if ($old->request_hash !== $hash) {
                    throw new Conflict('IDEMPOTENCY_PAYLOAD_CONFLICT');
                }

                return $old;
            }
            $published = DB::table('publication_campaign_pages as cp')->join('page_publications as p', 'p.batch_item_id', '=', 'cp.item_id')
                ->where('cp.campaign_id', $id)->get(['cp.page_id', 'p.id', 'p.previous_publication_id']);
            if ($published->isEmpty()) {
                throw new Conflict('CAMPAIGN_NOTHING_PUBLISHED');
            }
            $selection = app(PageBulkService::class)->resolve(['mode' => 'ids', 'page_ids' => $published->pluck('page_id')->all()], $actor);
            $restore = [];
            foreach ($published as $publication) {
                if ($publication->previous_publication_id) {
                    $restore[$publication->page_id] = ['restore_publication_id' => (int) $publication->previous_publication_id, 'expected_head_id' => (int) $publication->id];
                }
            }

            return $this->persist($selection, $actor, 'rollback', $key, $hash, $id, $restore);
        }, 3);
    }

    /** Queue entry point, also usable by isolated QA with Queue::fake(). */
    public function processChunk(int $chunkId, string $phase = 'prepare'): void
    {
        $lease = DB::transaction(function () use ($chunkId, $phase) {
            $initial = DB::table('publication_campaign_chunks')->find($chunkId);
            if (! $initial) {
                return null;
            }
            $campaign = $this->campaign($initial->campaign_id, true);
            $chunk = DB::table('publication_campaign_chunks')->where('id', $chunkId)->lockForUpdate()->first();
            if ($campaign->cancel_requested_at && $phase !== 'cancel') {
                return null;
            }
            $queued = $phase.'_queued';
            $working = ['prepare' => 'preparing', 'apply' => 'applying', 'retry' => 'retrying', 'cancel' => 'cancelling'][$phase] ?? null;
            if (! $working || ! in_array($chunk->status, [$queued, $working], true)) {
                return null;
            }
            if ($chunk->status === $working && $chunk->lease_expires_at && now()->lt($chunk->lease_expires_at)) {
                return null;
            }
            $prior = DB::table('publication_campaign_chunks')->where('campaign_id', $chunk->campaign_id)->where('site_id', $chunk->site_id)->where('chunk_no', '<', $chunk->chunk_no)->get();
            if ($phase === 'prepare' && $prior->contains(fn ($row) => in_array($row->status, ['prepare_queued', 'preparing'], true))) {
                return null;
            }
            if ($phase === 'apply') {
                if ($prior->contains(fn ($row) => $row->status === 'failed' && ! $row->batch_id || $row->error_code === 'APPLY_CONFLICT' || $row->error_code === 'PREVIOUS_CHUNK_FAILED')) {
                    $this->failChunk($chunk, 'PREVIOUS_CHUNK_FAILED');

                    return null;
                }
                if ($prior->contains(fn ($row) => ! in_array($row->status, self::TERMINAL, true))) {
                    return null;
                }
            }
            $token = (string) Str::uuid();
            DB::table('publication_campaign_chunks')->where('id', $chunkId)->update(['status' => $working, 'lease_token' => $token,
                'lease_expires_at' => now()->addMinutes(5), 'attempts' => $chunk->attempts + 1, 'started_at' => now(), 'updated_at' => now()]);

            return [$campaign, $chunk, $token];
        }, 3);
        if (! $lease) {
            return;
        }
        [$campaign, $chunk, $token] = $lease;
        try {
            if ($phase === 'prepare') {
                $batch = $this->prepare($campaign, $chunk);
                DB::transaction(function () use ($campaign, $chunk, $token, $batch) {
                    $current = $this->campaign($campaign->id, true);
                    $changes = ['batch_id' => $batch->id, 'status' => $current->cancel_requested_at ? 'cancel_queued' : 'ready', 'error_code' => null, 'lease_token' => null, 'lease_expires_at' => null];
                    $this->finish($chunk, $token, $changes);
                    foreach (DB::table('publish_batch_items')->where('batch_id', $batch->id)->get() as $item) {
                        DB::table('publication_campaign_pages')->where('chunk_id', $chunk->id)->where('page_id', $item->page_id)->update(['item_id' => $item->id, 'status' => $item->status, 'error_code' => $item->error_code]);
                    }
                    if ($current->cancel_requested_at) {
                        DB::table('publish_batches')->where('id', $batch->id)->update(['cancel_requested_at' => now()]);
                        $this->event($chunk->id, 'cancel');
                    }
                }, 3);
            } else {
                $publisher = app(Publisher::class);
                if ($phase === 'apply') {
                    $publisher->accept($chunk->batch_id, $campaign->requested_by);
                } elseif ($phase === 'retry') {
                    $publisher->retry($chunk->batch_id, $campaign->requested_by);
                } else {
                    $publisher->cancel($chunk->batch_id, $campaign->cancelled_by ?? $campaign->requested_by);
                }
                $this->finish($chunk, $token, ['status' => $phase === 'cancel' ? 'cancelled' : 'running', 'lease_token' => null, 'lease_expires_at' => null]);
                if ($phase === 'cancel') {
                    DB::table('publication_campaign_pages')->where('chunk_id', $chunk->id)->where('status', '!=', 'published')->update(['status' => 'cancelled', 'error_code' => 'CAMPAIGN_CANCELLED']);
                }
            }
        } catch (Conflict|\InvalidArgumentException|HttpExceptionInterface $e) {
            if ($e instanceof Conflict && $e->reason === 'CAMPAIGN_LEASE_LOST') {
                return;
            }
            $reason = $e instanceof Conflict ? $e->reason : ($e instanceof HttpExceptionInterface ? 'PERMISSION_REVOKED' : 'INVALID_PLAN');
            $this->finish($chunk, $token, ['status' => 'failed', 'error_code' => $phase === 'apply' ? 'APPLY_CONFLICT' : $reason, 'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => now()]);
            DB::table('publication_campaign_pages')->where('chunk_id', $chunk->id)->where('status', '!=', 'published')->update(['status' => 'error', 'error_code' => $reason]);
        } catch (\Throwable $e) {
            $attempt = DB::table('publication_campaign_chunks')->where('id', $chunk->id)->value('attempts');
            $this->finish($chunk, $token, ['status' => $attempt >= 3 ? 'failed' : $phase.'_queued', 'error_code' => 'JOB_FAILED', 'lease_token' => null, 'lease_expires_at' => null]);
            if ($attempt >= 3) {
                DB::table('publication_campaign_pages')->where('chunk_id', $chunk->id)->whereNotIn('status', ['published', 'unchanged'])->update(['status' => 'failed', 'error_code' => 'JOB_FAILED']);
            }
            throw $e;
        } finally {
            $this->reconcile($campaign->id);
            $this->wakeNext($campaign->id, $chunk->site_id);
        }
    }

    private function prepare(object $campaign, object $chunk): object
    {
        return DB::transaction(function () use ($campaign, $chunk) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $siteRow = DB::table('sites')->where('id', $chunk->site_id)->lockForUpdate()->first();
            $frozen = Json::decode($campaign->options_json);
            if ((int) DB::table('system_settings')->where('id', 1)->value('config_epoch') !== ($frozen['global_epoch'] ?? 0) || ! $siteRow || Json::hash((array) $siteRow) !== ($frozen['site_hashes'][$chunk->site_id] ?? null)) {
                throw new Conflict('CONFIGURATION_STALE');
            }
            Access::site($campaign->requested_by, $chunk->site_id, true);
            $saved = DB::table('publication_campaign_pages')->where('chunk_id', $chunk->id)->orderBy('page_id')->get();
            $pages = DB::table('pages')->whereIn('id', $saved->pluck('page_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($saved as $row) {
                $page = $pages[$row->page_id] ?? null;
                if (! $page || ($campaign->action !== 'rollback' && ((int) $page->row_version !== (int) $row->expected_row_version || Json::hash((array) $page) !== $row->expected_row_hash))) {
                    throw new Conflict('SELECTION_STALE');
                }
                if ($campaign->action !== 'rollback' && $page->current_publication_id !== $row->expected_head_id) {
                    throw new Conflict('SELECTION_STALE');
                }
            }
            $prior = DB::table('publication_campaign_chunks')->where('campaign_id', $campaign->id)->where('site_id', $chunk->site_id)->where('chunk_no', '<', $chunk->chunk_no)->whereNotNull('batch_id')->orderBy('chunk_no')->pluck('batch_id')->all();
            $options = ['seed' => $frozen['seed'], 'idempotency_key' => 'campaign:'.$campaign->id.':chunk:'.$chunk->id];
            if ($campaign->action === 'rollback') {
                $options['restore_map'] = Json::decode($chunk->restore_map_json);
            }

            return app(Planner::class)->preview($chunk->site_id, Json::decode($chunk->page_ids_json), $campaign->requested_by, $campaign->action, $options, $prior);
        }, 3);
    }

    private function finish(object $chunk, string $token, array $values): void
    {
        if (DB::table('publication_campaign_chunks')->where('id', $chunk->id)->where('lease_token', $token)->update($values + ['updated_at' => now()]) !== 1) {
            throw new Conflict('CAMPAIGN_LEASE_LOST');
        }
    }

    private function failChunk(object $chunk, string $reason): void
    {
        DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['status' => 'failed', 'error_code' => $reason, 'finished_at' => now()]);
        DB::table('publication_campaign_pages')->where('chunk_id', $chunk->id)->where('status', '!=', 'published')->update(['status' => 'error', 'error_code' => $reason]);
    }

    /** Rebuild counters from persisted item/publication records; lost queues do not lose work. */
    public function reconcile(int $id): void
    {
        DB::transaction(function () use ($id) {
            $campaign = $this->campaign($id, true);
            DB::update(<<<'SQL'
UPDATE publication_campaign_pages cp SET status=CASE WHEN c.status IN ('failed','cancelled') AND i.status<>'published' THEN cp.status ELSE i.status END,
 error_code=CASE WHEN c.status IN ('failed','cancelled') AND i.status<>'published' THEN cp.error_code ELSE i.error_code END,
 publication_id=p.id,previous_publication_id=p.previous_publication_id
FROM publication_campaign_chunks c, publish_batch_items i LEFT JOIN page_publications p ON p.batch_item_id=i.id
WHERE cp.campaign_id=? AND cp.item_id=i.id AND cp.chunk_id=c.id
SQL, [$id]);
            foreach (DB::table('publication_campaign_chunks')->where('campaign_id', $id)->whereNotNull('batch_id')->get() as $chunk) {
                $batch = DB::table('publish_batches')->find($chunk->batch_id);
                if (in_array($chunk->status, ['running', 'cancelled'], true) && in_array($batch->status, ['completed', 'partial', 'failed', 'stale', 'cancelled'], true)) {
                    $counts = $this->counts($id, null, $chunk->id);
                    $status = $campaign->cancel_requested_at ? 'cancelled' : ($counts['errors'] ? ($counts['published'] + $counts['unchanged'] ? 'partial' : 'failed') : 'completed');
                    DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['status' => $status, 'finished_at' => now()]);
                }
            }
            $chunks = DB::table('publication_campaign_chunks')->where('campaign_id', $id)->get();
            $counts = $this->counts($id);
            $preparing = $chunks->contains(fn ($c) => in_array($c->status, ['prepare_queued', 'preparing'], true));
            $active = $chunks->contains(fn ($c) => ! in_array($c->status, self::TERMINAL, true));
            $status = $campaign->cancel_requested_at ? ($active ? 'cancelling' : 'cancelled')
                : (! $campaign->accepted_at ? ($preparing ? 'preparing' : ($chunks->contains('status', 'ready') ? 'ready' : 'failed'))
                    : ($active ? 'running' : ($counts['errors'] ? ($counts['published'] + $counts['unchanged'] ? 'partial' : 'failed') : 'completed')));
            DB::table('publication_campaigns')->where('id', $id)->update(['status' => $status, 'counts' => Json::encode($counts), 'updated_at' => now()]);
            foreach (DB::table('publication_campaign_sites')->where('campaign_id', $id)->get() as $site) {
                $siteChunks = $chunks->where('site_id', $site->site_id);
                $siteCounts = $this->counts($id, $site->site_id);
                $siteStatus = $siteChunks->contains(fn ($c) => ! in_array($c->status, self::TERMINAL, true)) ? $status
                    : ($campaign->cancel_requested_at ? 'cancelled' : ($siteCounts['errors'] ? ($siteCounts['published'] + $siteCounts['unchanged'] ? 'partial' : 'failed') : 'completed'));
                DB::table('publication_campaign_sites')->where('id', $site->id)->update(['status' => $siteStatus, 'counts' => Json::encode($siteCounts)]);
            }
        }, 3);
    }

    public function afterItem(int $itemId): void
    {
        if (! Schema::hasTable('publication_campaign_pages')) {
            return;
        }
        $row = DB::table('publication_campaign_pages')->where('item_id', $itemId)->first();
        if ($row && DB::table('publish_batch_items as i')->join('publish_batches as b', 'b.id', '=', 'i.batch_id')->where('i.id', $itemId)->whereIn('b.status', ['completed', 'partial', 'failed', 'stale', 'cancelled'])->exists()) {
            $this->reconcile($row->campaign_id);
            $this->wakeNext($row->campaign_id, $row->site_id);
        }
    }

    private function wakeNext(int $campaignId, int $siteId): void
    {
        $chunk = DB::table('publication_campaign_chunks')->where('campaign_id', $campaignId)->where('site_id', $siteId)->whereIn('status', ['prepare_queued', 'apply_queued', 'retry_queued', 'cancel_queued'])->orderBy('chunk_no')->first();
        if (! $chunk) {
            return;
        }
        $prior = DB::table('publication_campaign_chunks')->where('campaign_id', $campaignId)->where('site_id', $siteId)->where('chunk_no', '<', $chunk->chunk_no)->get();
        if ($chunk->status === 'prepare_queued' && $prior->contains(fn ($c) => in_array($c->status, ['prepare_queued', 'preparing'], true))) {
            return;
        }
        if ($chunk->status === 'apply_queued' && $prior->contains(fn ($c) => ! in_array($c->status, self::TERMINAL, true))) {
            return;
        }
        $this->event($chunk->id, str_replace('_queued', '', $chunk->status));
    }

    public function dispatchPending(): int
    {
        if (! Schema::hasTable('publication_campaigns')) {
            return 0;
        }
        foreach (DB::table('publication_campaigns')->whereNotIn('status', self::TERMINAL)->pluck('id') as $id) {
            $this->reconcile($id);
        }
        $dispatched = 0;
        foreach (DB::table('outbox_events')->where('event_type', 'campaign.chunk.requested')->whereNull('delivered_at')->orderBy('created_at')->limit(500)->get() as $event) {
            $payload = Json::decode($event->payload_json);
            ProcessCampaignChunk::dispatch($payload['chunk_id'], $payload['phase'])->onQueue('campaigns');
            DB::table('outbox_events')->where('id', $event->id)->update(['delivered_at' => now(), 'attempts' => $event->attempts + 1]);
            DB::table('publication_campaign_chunks')->where('id', $payload['chunk_id'])->update(['last_dispatched_at' => now()]);
            $dispatched++;
        }
        // Recover acknowledged Redis jobs that disappeared after a restart, and expired worker leases.
        foreach (DB::table('publication_campaign_chunks')->whereIn('status', ['prepare_queued', 'preparing', 'apply_queued', 'applying', 'retry_queued', 'retrying', 'cancel_queued', 'cancelling'])
            ->where(fn ($q) => $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<', now()->subMinutes(6)))
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now()))->limit(500)->get() as $chunk) {
            $phase = ['preparing' => 'prepare', 'applying' => 'apply', 'retrying' => 'retry', 'cancelling' => 'cancel'][$chunk->status] ?? str_replace('_queued', '', $chunk->status);
            ProcessCampaignChunk::dispatch($chunk->id, $phase)->onQueue('campaigns');
            DB::table('publication_campaign_chunks')->where('id', $chunk->id)->update(['last_dispatched_at' => now()]);
            $dispatched++;
        }

        return $dispatched;
    }

    private function event(int $chunkId, string $phase): void
    {
        if (DB::table('outbox_events')->where('event_type', 'campaign.chunk.requested')->where('aggregate_id', $chunkId)->whereNull('delivered_at')->whereRaw("payload_json->>'phase' = ?", [$phase])->exists()) {
            return;
        }
        $event = (string) Str::uuid();
        DB::table('outbox_events')->insert(['id' => $event, 'event_type' => 'campaign.chunk.requested', 'aggregate_id' => $chunkId, 'payload_json' => Json::encode(['chunk_id' => $chunkId, 'phase' => $phase])]);
        DB::afterCommit(function () use ($chunkId, $phase, $event) {
            try {
                ProcessCampaignChunk::dispatch($chunkId, $phase)->onQueue('campaigns');
                DB::table('outbox_events')->where('id', $event)->update(['delivered_at' => now(), 'attempts' => 1]);
                DB::table('publication_campaign_chunks')->where('id', $chunkId)->update(['last_dispatched_at' => now()]);
            } catch (\Throwable $e) {
                report($e);
            } // The committed outbox remains replayable.
        });
    }

    public function inspect(int $id, int $actor): array
    {
        $campaign = $this->campaign($id);
        $this->authorize($campaign, $actor);
        $children = DB::table('publication_campaign_chunks as c')->join('sites as s', 's.id', '=', 'c.site_id')->where('c.campaign_id', $id)
            ->orderBy('c.site_id')->orderBy('c.chunk_no')->get(['c.*', 's.name as site_name']);
        foreach ($children as $child) {
            $child->counts = Json::encode($this->counts($id, null, $child->id));
        }
        $results = DB::table('publication_campaign_pages as c')->leftJoin('publish_batch_items as i', 'i.id', '=', 'c.item_id')
            ->leftJoin('publication_campaign_chunks as ch', 'ch.id', '=', 'c.chunk_id')->leftJoin('page_publications as p', 'p.batch_item_id', '=', 'c.item_id')->where('c.campaign_id', $id)->orderBy('c.site_id')->orderBy('c.page_id')
            ->selectRaw("c.page_id,c.site_id,c.path,c.expected_head_id,c.item_id,CASE WHEN ch.status IN ('failed','cancelled') AND i.status<>'published' THEN c.status ELSE COALESCE(i.status,c.status) END status,CASE WHEN ch.status IN ('failed','cancelled') AND i.status<>'published' THEN c.error_code ELSE COALESCE(i.error_code,c.error_code) END error_code,p.id publication_id,p.previous_publication_id")->get();
        $counts = $this->counts($id);
        $campaign->counts = Json::encode($counts);

        return ['campaign' => $campaign, 'counts' => $counts, 'children' => $children->all(),
            'sites' => DB::table('publication_campaign_sites')->where('campaign_id', $id)->orderBy('site_id')->get()->all(),
            'errors' => $results->filter(fn ($row) => $row->error_code !== null)->values()->all(), 'results' => $results->all()];
    }

    public function listFor(int $actor): Collection
    {
        $user = DB::table('users')->find($actor);
        abort_unless($user, 403);

        return DB::table('publication_campaigns')->when(! $user->is_global_admin, fn ($q) => $q->where('requested_by', $actor)->whereNotExists(function ($outside) use ($actor) {
            $outside->selectRaw('1')->from('publication_campaign_sites as scope')->whereColumn('scope.campaign_id', 'publication_campaigns.id')->whereNotIn('scope.site_id', DB::table('site_memberships')->where('user_id', $actor)->select('site_id'));
        }))
            ->orderByDesc('id')->limit(50)->get();
    }

    private function counts(int $id, ?int $site = null, ?int $chunk = null): array
    {
        $rows = DB::table('publication_campaign_pages as c')->leftJoin('publication_campaign_chunks as ch', 'ch.id', '=', 'c.chunk_id')->leftJoin('publish_batch_items as i', 'i.id', '=', 'c.item_id')->where('c.campaign_id', $id)
            ->when($site !== null, fn ($q) => $q->where('c.site_id', $site))->when($chunk !== null, fn ($q) => $q->where('c.chunk_id', $chunk))
            ->selectRaw("CASE WHEN ch.status IN ('failed','cancelled') AND i.status<>'published' THEN c.status ELSE COALESCE(i.status,c.status) END status,count(*) n")->groupByRaw("CASE WHEN ch.status IN ('failed','cancelled') AND i.status<>'published' THEN c.status ELSE COALESCE(i.status,c.status) END")->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return array_replace(['published' => 0, 'unchanged' => 0, 'skipped' => 0, 'cancelled' => 0], $rows, ['total' => array_sum($rows),
            'pending' => ($rows['selected'] ?? 0) + ($rows['pending'] ?? 0) + ($rows['claimed'] ?? 0) + ($rows['rendering'] ?? 0) + ($rows['activating'] ?? 0),
            'errors' => ($rows['error'] ?? 0) + ($rows['failed'] ?? 0) + ($rows['stale'] ?? 0) + ($rows['capacity_conflict'] ?? 0) + ($rows['locked'] ?? 0)]);
    }

    private function campaign(int $id, bool $lock = false): object
    {
        $row = DB::table('publication_campaigns')->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($row, 404);

        return $row;
    }

    private function authorize(object $campaign, int $actor, bool $write = false): void
    {
        $user = DB::table('users')->find($actor);
        abort_unless($user && ($user->is_global_admin || (int) $campaign->requested_by === $actor), 403);
        foreach (DB::table('publication_campaign_sites')->where('campaign_id', $campaign->id)->pluck('site_id') as $site) {
            Access::site($actor, $site, $write);
        }
    }

    private function ids(mixed $values): array
    {
        if (! is_array($values) || ! $values || count($values) > 10000) {
            throw new \InvalidArgumentException('Chọn 1–10.000 trang.');
        }
        foreach ($values as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value)) || (int) $value < 1) {
                throw new \InvalidArgumentException('Page ID phải là số nguyên dương.');
            }
        }
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values);

        return $values;
    }

    private function key(mixed $key): string
    {
        if (! is_string($key) || $key === '' || strlen($key) > 128) {
            throw new \InvalidArgumentException('Idempotency key dài tối đa 128 bytes.');
        }

        return $key;
    }
}
