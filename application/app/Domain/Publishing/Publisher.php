<?php

namespace App\Domain\Publishing;

use App\Domain\Fanmulu\FanmuluNavigation;
use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Fanmulu\FanmuluSourceCatalog;
use App\Domain\Keywords\KeywordService;
use App\Domain\Rules\TemplateVariableService;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\PublicationReadMemo;
use App\Jobs\Publishing\PublishItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Publisher
{
    private function configLocks(int $siteId, bool $exclusiveSite = false): array
    {
        $global = DB::table('system_settings')->where('id', 1)->sharedLock()->first();
        $query = DB::table('sites')->where('id', $siteId);
        $site = ($exclusiveSite ? $query->lockForUpdate() : $query->sharedLock())->first();
        if (! $site || $site->status !== 'active') {
            throw new Conflict('SITE_NOT_ACTIVE');
        }

        return [$site, (int) $global->config_epoch];
    }

    public function accept(int $batchId, int $actorId): object
    {
        $initial = DB::table('publish_batches')->find($batchId);
        if (! $initial) {
            abort(404);
        }Access::site($actorId, $initial->site_id, true);
        try {
            return DB::transaction(function () use ($batchId, $actorId, $initial) {
                return app(PublicationReadMemo::class)->within(function () use ($batchId, $actorId, $initial) {
                    $this->configLocks($initial->site_id);
                    $batch = DB::table('publish_batches')->where('id', $batchId)->lockForUpdate()->first();
                    if ((Json::decode($batch->options_json)['origin'] ?? null) === 'quick_apply') {
                        throw new Conflict('QUICK_APPLY_MANAGED_BATCH');
                    }
                    if ($batch->accepted_at) {
                        return $batch;
                    }
                    if ($batch->status !== 'ready') {
                        throw new Conflict('BATCH_NOT_READY');
                    }
                    $previewItems = DB::table('publish_batch_items')->where('batch_id', $batchId)->whereIn('status', ['pending', 'unchanged']);
                    ['hash' => $plan, 'pending_count' => $pendingCount] = $this->acceptedPlan((clone $previewItems)->lazyById(10, 'page_id'));
                    $sequential = Json::decode($batch->sequential_json);
                    uasort($sequential, fn ($a, $b) => $a['state_id'] <=> $b['state_id']);
                    foreach ($sequential as $range) {
                        $state = DB::table('allocation_state')->where('id', $range['state_id'])->lockForUpdate()->first();
                        if ($state->cursor_revision !== $range['cursor_revision'] || $state->cursor_position !== $range['start']) {
                            throw new Conflict('SEQUENTIAL_CURSOR_STALE');
                        }
                        DB::table('allocation_batch_reservations')->insert(['allocation_state_id' => $state->id, 'batch_id' => $batchId, 'start_position' => $range['start'], 'end_position' => $range['end'], 'cursor_revision_before' => $range['cursor_revision'], 'plan_hash' => $plan]);
                        DB::table('allocation_state')->where('id', $state->id)->update(['cursor_position' => $range['end'], 'cursor_revision' => DB::raw('cursor_revision+1')]);
                    }
                    foreach ((clone $previewItems)->lazyById(10, 'page_id') as $item) {
                        $page = DB::table('pages')->where('id', $item->page_id)->lockForUpdate()->first();
                        $proposal = Json::decode($item->preview_json);
                        $options = Json::decode($batch->options_json);
                        $fingerprint = $this->proposalFingerprint($page, $proposal, $options);
                        if (now()->greaterThan($item->expires_at) || $item->fingerprint !== $fingerprint) {
                            throw new Conflict('PREVIEW_STALE');
                        }
                        if ($item->status === 'pending') {
                            DB::table('publish_batch_items')->where('id', $item->id)->update(['accepted_for_publish' => true]);
                            $this->enqueue($item->id);
                        }
                    }
                    DB::table('publish_batches')->where('id', $batchId)->update(['status' => $pendingCount === 0 ? 'completed' : 'queued', 'accepted_at' => now(), 'accepted_plan_hash' => $plan]);
                    Access::audit('batch.accept', $actorId, $initial->site_id, ['accepted_items' => $pendingCount, 'plan_hash' => $plan], null, $batchId);

                    return DB::table('publish_batches')->find($batchId);
                });
            }, 3);
        } catch (Conflict $e) {
            if (in_array($e->reason, ['PREVIEW_STALE', 'SEQUENTIAL_CURSOR_STALE'])) {
                DB::table('publish_batches')->where('id', $batchId)->whereNull('accepted_at')->update(['status' => 'stale']);
            }throw $e;
        }
    }

    /** Store a new one-item plan while its page, configuration and cursors are fenced. */
    public function persistFirstVisitPlan(object $page, int $actorId, string $seed, array $proposal, array $sequential): int
    {
        if (DB::transactionLevel() === 0 || ! FirstVisitPublisher::deferred($proposal) || $page->publish_locked || $page->current_publication_id !== null) {
            throw new Conflict('FIRST_VISIT_PLAN_INVALID');
        }

        $itemId = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('publish_batch_items', 'id')) AS id")->id;
        $context = $proposal['first_visit_plan']['context_hash'];
        $plan = Json::hash([[$itemId, $context, $proposal]]);
        $written = DB::selectOne(<<<'SQL'
WITH batch AS (
    INSERT INTO publish_batches (public_id, site_id, action, status, requested_by, request_idempotency_key,
        request_hash, options_json, sequential_json, counts, accepted_at, accepted_plan_hash)
    VALUES (?, ?, 'publish', 'queued', ?, ?, ?, ?::jsonb, ?::jsonb, '{"pending":1}'::jsonb, now(), ?)
    RETURNING id, site_id, requested_by
), item AS (
    INSERT INTO publish_batch_items (id, batch_id, site_id, page_id, status, accepted_for_publish, preview_json,
        fingerprint, expires_at, expected_row_version)
    SELECT ?, id, site_id, ?, 'pending', true, ?::jsonb, ?, now() + interval '30 minutes', ? FROM batch
    RETURNING id
), advanced AS (
    UPDATE allocation_state AS s SET cursor_position = r."end", cursor_revision = s.cursor_revision + 1
    FROM jsonb_to_recordset(?::jsonb) AS r(state_id bigint, "start" bigint, "end" bigint, cursor_revision bigint)
    WHERE s.id = r.state_id AND s.cursor_revision = r.cursor_revision AND s.cursor_position = r."start"
    RETURNING s.id, r."start", r."end", r.cursor_revision
), reservations AS (
    INSERT INTO allocation_batch_reservations (allocation_state_id, batch_id, start_position, end_position, cursor_revision_before, plan_hash)
    SELECT advanced.id, batch.id, advanced."start", advanced."end", advanced.cursor_revision, ? FROM advanced CROSS JOIN batch
), event AS (
    INSERT INTO outbox_events (id, event_type, aggregate_id, payload_json)
    SELECT ?, 'publish.requested', id, jsonb_build_object('item_id', id) FROM item
), audit AS (
    INSERT INTO audit_events (actor_id, action, site_id, batch_id, after_json, correlation_id)
    SELECT requested_by, 'batch.accept', site_id, id, ?::jsonb, ? FROM batch
)
SELECT count(*) AS total FROM advanced
SQL, [(string) Str::uuid(), $page->site_id, $actorId, FirstVisitPublisher::lockKey($page->site_id, $page->id),
            Json::hash([$page->site_id, $page->id, $context]), Json::encode(['origin' => 'first_visit', 'seed' => $seed]),
            Json::encode($sequential), $plan, $itemId, $page->id, Json::encode($proposal), $context, $page->row_version,
            Json::encode(array_values($sequential)), $plan, (string) Str::uuid(),
            Json::encode(['accepted_items' => 1, 'plan_hash' => $plan]), (string) Str::uuid()], false);
        if ((int) $written->total !== count($sequential)) {
            throw new Conflict('SEQUENTIAL_CURSOR_STALE');
        }
        DB::connection()->recordsHaveBeenModified();
        $this->dispatchAfterCommit($itemId);

        return $itemId;
    }

    /**
     * The former filtered Collection retained its original numeric keys: a gap
     * before a pending item makes the canonical outer JSON an object, while
     * trailing unchanged items do not. Keep both encodings until this is known.
     *
     * @param  iterable<object>  $previewItems
     * @return array{hash: string, pending_count: int}
     */
    private function acceptedPlan(iterable $previewItems): array
    {
        $list = hash_init('sha256');
        $object = hash_init('sha256');
        hash_update($list, '[');
        hash_update($object, '{');
        $isList = true;
        $index = 0;
        $pendingCount = 0;
        foreach ($previewItems as $item) {
            if ($item->status === 'pending') {
                $encoded = Json::canonicalEncode([$item->id, $item->fingerprint, Json::decode($item->preview_json)]);
                $separator = $pendingCount === 0 ? '' : ',';
                hash_update($list, $separator.$encoded);
                hash_update($object, $separator.Json::encode((string) $index).':'.$encoded);
                $isList = $isList && $index === $pendingCount;
                $pendingCount++;
            }
            $index++;
        }
        hash_update($list, ']');
        hash_update($object, '}');

        return ['hash' => hash_final($isList ? $list : $object), 'pending_count' => $pendingCount];
    }

    private function enqueue(int $itemId): void
    {
        DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => 'publish.requested', 'aggregate_id' => $itemId, 'payload_json' => Json::encode(['item_id' => $itemId])]);
        $this->dispatchAfterCommit($itemId);
    }

    private function dispatchAfterCommit(int $itemId): void
    {
        DB::afterCommit(function () use ($itemId): void {
            try {
                PublishItem::dispatch($itemId)->onQueue('publish');
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }

    private function lockBuckets(int $siteId, array $keywordIds): void
    {
        $keywordIds = array_values(array_unique(array_map('intval', $keywordIds)));
        sort($keywordIds);
        if ($keywordIds !== []) {
            DB::table('keyword_usage_buckets')->insertOrIgnore(array_map(fn (int $id): array => ['site_id' => $siteId, 'keyword_id' => $id], $keywordIds));
            DB::table('keyword_usage_buckets')->where('site_id', $siteId)->whereIn('keyword_id', $keywordIds)
                ->orderBy('keyword_id')->lockForUpdate()->get(['keyword_id']);
        }
    }

    private function checkCapacity(object $page, array $assignments): void
    {
        $keywordIds = array_column($assignments, 'keyword_id');
        $keywords = DB::table('keywords as k')->leftJoin('site_keyword_limits as l', function ($join) use ($page): void {
            $join->on('l.keyword_id', '=', 'k.id')->where('l.site_id', $page->site_id);
        })->whereIn('k.id', $keywordIds)->select('k.id')
            ->selectRaw('CASE WHEN l.keyword_id IS NOT NULL THEN l.max_active_pages ELSE k.default_max_active_pages END AS capacity')
            ->get()->keyBy('id');
        $caps = [];
        foreach ($assignments as $a) {
            $keyword = $keywords->get($a['keyword_id']);
            if (! $keyword) {
                throw new Conflict('KEYWORD_MISSING');
            }
            $cap = $keyword->capacity;
            if ($cap !== null) {
                $caps[$keyword->id] = $cap;
            }
        }
        $usage = app(KeywordService::class)->usageSummaries($page->site_id, array_keys($caps), [$page->id]);
        foreach ($caps as $keywordId => $cap) {
            if (! in_array($page->id, $usage[$keywordId]['page_ids'], true) && $usage[$keywordId]['total'] >= $cap) {
                throw new Conflict('CAPACITY_CONFLICT', (string) $keywordId);
            }
        }
    }

    /** The locked page and lease prevent reconciliation from releasing these already counted claims. */
    private function hasFirstPublicationCapacity(object $page, object $item, int $fence, array $assignments): bool
    {
        if ($page->current_publication_id !== null) {
            return false;
        }
        $keywordIds = array_values(array_unique(array_map('intval', array_column($assignments, 'keyword_id'))));
        sort($keywordIds);
        $held = DB::table('keyword_capacity_claims')->where(['site_id' => $page->site_id, 'page_id' => $page->id,
            'item_id' => $item->id, 'fencing_token' => $fence, 'status' => 'held'])
            ->orderBy('keyword_id')->pluck('keyword_id')->all();

        return $keywordIds === $held;
    }

    public function claim(int $itemId): ?array
    {
        $initial = DB::table('publish_batch_items')->find($itemId, ['id', 'site_id', 'batch_id']) ?? abort(404);

        return $this->claimItem($initial);
    }

    private function claimItem(object $initial): ?array
    {
        $itemId = (int) $initial->id;

        return DB::transaction(function () use ($initial, $itemId) {
            [$site] = $this->configLocks($initial->site_id);
            $batch = DB::table('publish_batches')->where('id', $initial->batch_id)->lockForUpdate()->first();
            if ((Json::decode($batch->options_json)['origin'] ?? null) === 'quick_apply') {
                throw new Conflict('QUICK_APPLY_MANAGED_BATCH');
            }
            Access::site($batch->requested_by, $initial->site_id, true, $site);
            $item = DB::table('publish_batch_items')->where('id', $itemId)->lockForUpdate()->first();
            if ($item->status === 'published') {
                return null;
            }
            if (! $batch->accepted_at || ! $item->accepted_for_publish) {
                throw new Conflict('BATCH_NOT_ACCEPTED');
            }
            if ($batch->cancel_requested_at || $item->status === 'cancelled') {
                throw new Conflict('BATCH_CANCELLED');
            }
            if (in_array($item->status, ['stale', 'capacity_conflict', 'error', 'locked', 'unchanged'])) {
                throw new Conflict('ITEM_NEEDS_PREVIEW');
            }
            foreach (DB::table('allocation_batch_reservations')->where('batch_id', $batch->id)->get() as $r) {
                if ($r->plan_hash !== $batch->accepted_plan_hash) {
                    throw new Conflict('RESERVATION_PLAN_CONFLICT');
                }
            }
            $page = DB::table('pages')->where('id', $item->page_id)->lockForUpdate()->first();
            $proposal = Json::decode($item->preview_json);
            $assignmentKeywordIds = array_map('intval', array_column($proposal['assignments'], 'keyword_id'));
            $keywordIds = array_values(array_unique($assignmentKeywordIds));
            if (count($keywordIds) !== count($assignmentKeywordIds)) {
                throw new Conflict('DUPLICATE_KEYWORD_ASSIGNMENT');
            }
            $options = Json::decode($batch->options_json);
            $deferred = ($options['origin'] ?? null) === 'first_visit' && FirstVisitPublisher::deferred($proposal);
            $deferredFanmulu = $page->page_kind === 'fanmulu' && $deferred;
            $fingerprint = $deferredFanmulu ? $proposal['first_visit_plan']['context_hash'] : $this->proposalFingerprint($page, $proposal, $options);
            if ($page->publish_locked || ($deferredFanmulu && $page->row_version !== $item->expected_row_version) || $item->fingerprint !== $fingerprint) {
                throw new Conflict('PREVIEW_STALE');
            }
            if ($deferred) {
                $fence = app(FirstVisitPublicationWrites::class)->claim($page, $item);
                if ($page->page_kind !== 'fanmulu') {
                    $this->lockBuckets($page->site_id, $keywordIds);
                    $this->checkCapacity($page, $proposal['assignments']);
                    $expires = now()->addMinutes(3);
                    DB::table('keyword_capacity_claims')->insert(array_map(fn (int $keywordId): array => ['site_id' => $page->site_id,
                        'keyword_id' => $keywordId, 'page_id' => $page->id, 'item_id' => $itemId, 'fencing_token' => $fence,
                        'status' => 'held', 'expires_at' => $expires], $keywordIds));
                }

                return ['item_id' => $itemId, 'fence' => $fence, 'proposal' => $proposal, 'site_id' => $page->site_id,
                    'batch_id' => $batch->id, 'first_visit_page' => $page];
            }
            DB::table('page_publish_leases')->insertOrIgnore(['page_id' => $page->id]);
            $lease = DB::table('page_publish_leases')->where('page_id', $page->id)->lockForUpdate()->first();
            if ($lease->owner_item_id !== null) {
                throw new Conflict('LEASE_BUSY', 'Chạy reconcile nếu worker đã mất');
            }
            if ($page->page_kind !== 'fanmulu') {
                $old = $page->current_publication_id === null ? [] : DB::table('page_keyword_assignments')->where('publication_id', $page->current_publication_id)->pluck('keyword_id')->all();
                $this->lockBuckets($page->site_id, array_merge($old, $keywordIds));
                $this->checkCapacity($page, $proposal['assignments']);
            }
            $fence = $lease->fencing_token + 1;
            $expires = now()->addMinutes(3);
            DB::table('page_publish_leases')->where('page_id', $page->id)->update(['owner_item_id' => $itemId, 'fencing_token' => $fence, 'expires_at' => $expires, 'heartbeat_at' => now()]);
            if ($page->page_kind !== 'fanmulu') {
                DB::table('keyword_capacity_claims')->insert(array_map(fn (int $keywordId): array => ['site_id' => $page->site_id,
                    'keyword_id' => $keywordId, 'page_id' => $page->id, 'item_id' => $itemId, 'fencing_token' => $fence, 'status' => 'held',
                    'expires_at' => $expires], $keywordIds));
            }
            DB::table('publish_batch_items')->where('id', $itemId)->update(['status' => 'rendering', 'attempts' => DB::raw('attempts+1')]);
            DB::table('publish_batches')->where('id', $batch->id)->update(['status' => 'running']);

            return ['item_id' => $itemId, 'fence' => $fence, 'proposal' => $proposal, 'site_id' => $page->site_id, 'batch_id' => $batch->id]
                + ($deferred ? ['first_visit_page' => $page] : []);
        }, 3);
    }

    public function heartbeat(int $itemId, int $fence): bool
    {
        return DB::transaction(function () use ($itemId, $fence) {
            $item = DB::table('publish_batch_items')->find($itemId);
            if (! $item) {
                return false;
            }$lease = DB::table('page_publish_leases')->where(['page_id' => $item->page_id, 'owner_item_id' => $itemId, 'fencing_token' => $fence])->lockForUpdate()->first();
            if (! $lease || now()->greaterThan($lease->expires_at)) {
                return false;
            }DB::table('page_publish_leases')->where('page_id', $item->page_id)->update(['expires_at' => now()->addMinutes(3), 'heartbeat_at' => now()]);
            DB::table('keyword_capacity_claims')->where(['item_id' => $itemId, 'fencing_token' => $fence, 'status' => 'held'])->update(['expires_at' => now()->addMinutes(3)]);

            return true;
        });
    }

    public function activate(array $claim, ?array $renderedFirstVisit = null): object
    {
        $fanmulu = $renderedFirstVisit !== null && ($claim['first_visit_page']->page_kind ?? null) === 'fanmulu';
        $refreshSite = ! $fanmulu && $this->addsFanmuluSource($claim);

        return DB::transaction(function () use ($claim, $refreshSite, $fanmulu, $renderedFirstVisit) {
            [$site, $globalEpoch] = $this->configLocks($claim['site_id'], $refreshSite);
            if (! $fanmulu && ! $refreshSite && $this->addsFanmuluSource($claim)) {
                throw new Conflict('PREVIEW_STALE');
            }
            $batch = DB::table('publish_batches')->where('id', $claim['batch_id'])->lockForUpdate()->first();
            Access::site($batch->requested_by, $claim['site_id'], true, $site);
            $item = DB::table('publish_batch_items')->where('id', $claim['item_id'])->lockForUpdate()->first();
            $existing = DB::table('page_publications')->where('batch_item_id', $item->id)->first();
            if ($existing) {
                return $existing;
            }
            if ($batch->cancel_requested_at || $item->status === 'cancelled') {
                throw new Conflict('BATCH_CANCELLED');
            }
            $page = DB::table('pages')->where('id', $item->page_id)->lockForUpdate()->first();
            $lease = DB::table('page_publish_leases')->where('page_id', $page->id)->lockForUpdate()->first();
            if (! $lease || $lease->owner_item_id !== $item->id || $lease->fencing_token !== $claim['fence'] || now()->greaterThan($lease->expires_at)) {
                throw new Conflict('FENCE_LOST');
            }
            $options = Json::decode($batch->options_json);
            $proposal = $renderedFirstVisit['proposal'] ?? $claim['proposal'];
            $itemChanges = ['status' => 'published', 'error_code' => null];
            if (($proposal['first_visit_plan']['schema'] ?? null) === 1) {
                app(FirstVisitPublisher::class)->assertEligibility($page, (int) $batch->requested_by, $site);
            }
            if ($page->content_item_id && ! $page->current_publication_id && $batch->action !== 'rollback'
                && DB::table('content_items')->where('id', $page->content_item_id)->value('library_status') === 'archived') {
                throw new Conflict('MOVIE_ARCHIVED');
            }
            if ($page->publish_locked || $page->current_publication_id !== $item->expected_current_publication_id || $page->row_version !== $item->expected_row_version) {
                throw new Conflict('PREVIEW_STALE');
            }
            $references = app(SnapshotStorage::class)->references($proposal['snapshot']);
            if ($renderedFirstVisit !== null) {
                $context = $claim['proposal']['first_visit_plan']['context_hash'];
                if ($page->page_kind !== ($fanmulu ? 'fanmulu' : 'regular') || $page->current_publication_id !== null || $item->status !== 'rendering'
                    || ($options['origin'] ?? null) !== 'first_visit' || $item->fingerprint !== $context) {
                    throw new Conflict('PREVIEW_STALE');
                }
                $itemChanges['fingerprint'] = app(Planner::class)->materializedFirstVisitFingerprint($page, $proposal['assignments'], $context, $references, $site, $globalEpoch);
                app(FirstVisitPublisher::class)->assertRecommendationHeads((int) $page->site_id, $renderedFirstVisit['entries']);
            } elseif ($item->fingerprint !== app(Planner::class)->fingerprint($page, null, $proposal['assignments'], $references, ($options['presentation_only'] ?? false) === true)) {
                throw new Conflict('PREVIEW_STALE');
            }
            if ($page->page_kind !== 'fanmulu' && ! $this->hasFirstPublicationCapacity($page, $item, $claim['fence'], $proposal['assignments'])) {
                $old = $page->current_publication_id === null ? [] : DB::table('page_keyword_assignments')->where('publication_id', $page->current_publication_id)->pluck('keyword_id')->all();
                $this->lockBuckets($page->site_id, array_merge($old, array_column($proposal['assignments'], 'keyword_id')));
                $this->checkCapacity($page, $proposal['assignments']);
            }
            if (hash('sha256', $proposal['html']) !== $proposal['html_hash']) {
                throw new Conflict('ARTIFACT_HASH_MISMATCH');
            }
            $path = $proposal['snapshot']['page']['path'] ?? $page->canonical_path;
            if ($refreshSite) {
                app(PublicHtmlCache::class)->invalidateSite($page->site_id);
            } else {
                // An eligible first-visit film was already a catalog card before
                // gaining HTML. Moving draft -> live does not change its count.
                $alreadyListed = $page->page_kind !== 'fanmulu' && $page->current_publication_id === null
                    && app(SiteDeliverySettings::class)->enabled((int) $page->site_id)
                    && in_array((int) $page->id, app(OnDemandEligibility::class)->pageIds([(int) $page->site_id], [(int) $page->id]), true);
                app(PublicHtmlCache::class)->invalidatePage($page, [$path], catalog: $page->page_kind !== 'fanmulu' && ! $alreadyListed);
            }
            SitePresentationService::activateVirtualRoutes($page, $proposal['snapshot']);
            SitePresentationService::activatePath($page, $path);
            $usesFrozenContent = $batch->action === 'rollback' || ($options['presentation_only'] ?? false) === true;
            $revisionId = $usesFrozenContent ? ($proposal['snapshot']['content_revision_id'] ?? null) : $page->working_content_revision_id;
            $preserveRecommendations = $renderedFirstVisit !== null && ! $fanmulu && $path === $page->canonical_path
                && property_exists($page, 'serving_content_revision_id')
                && app(FanmuluRecommendationIndex::class)->preservesMembership($page, $proposal['snapshot']);
            if ($renderedFirstVisit !== null) {
                $publication = app(FirstVisitPublicationWrites::class)->insertPublication($page, $item, $revisionId, $proposal);
                $pid = $publication->id;
            } else {
                $pid = DB::table('page_publications')->insertGetId(['page_id' => $page->id, 'site_id' => $page->site_id, 'batch_item_id' => $item->id, 'publication_no' => (DB::table('page_publications')->where('page_id', $page->id)->max('publication_no') ?? 0) + 1, 'content_item_id' => $page->content_item_id, 'content_revision_id' => $revisionId, 'snapshot_json' => Json::encode($proposal['snapshot']), 'html_body' => $proposal['html'], 'html_hash' => $proposal['html_hash'], 'previous_publication_id' => $page->current_publication_id, 'restored_from_publication_id' => $proposal['restored_from_publication_id'] ?? null]);
            }
            DB::table('page_keyword_assignments')->insert(array_map(fn (array $a): array => ['publication_id' => $pid, 'page_id' => $page->id,
                'keyword_id' => $a['keyword_id'], 'role' => $a['role'], 'purpose' => $a['purpose'] ?? 'seo', 'ordinal' => $a['ordinal'],
                'text_snapshot' => $a['text'], 'target_url_snapshot' => $a['target_url'] ?? null, 'selection_json' => Json::encode($a['selection'] ?? [])], $proposal['assignments']));
            $pageChanges = ['canonical_path' => $path, 'current_publication_id' => $pid, 'status' => 'live', 'row_version' => DB::raw('row_version+1'), 'updated_at' => now()];
            if (property_exists($page, 'serving_content_revision_id')) {
                $firstFanmulu = $page->page_kind === 'fanmulu' && $page->current_publication_id === null && $batch->action !== 'rollback';
                $pageChanges['serving_content_revision_id'] = ($options['refresh_seo'] ?? false) === true
                    ? $page->serving_content_revision_id
                    : ($firstFanmulu && $page->serving_content_revision_id !== null ? $page->serving_content_revision_id : $revisionId);
            }
            if ($preserveRecommendations) {
                DB::selectOne("SELECT set_config('tram.recommendation_equivalent_head', ?, true)", [$page->id.':'.$pid]);
            }
            $changed = DB::table('pages')->where(['id' => $page->id, 'row_version' => $item->expected_row_version])->where('current_publication_id', $item->expected_current_publication_id)->update($pageChanges);
            if ($preserveRecommendations) {
                DB::selectOne("SELECT set_config('tram.recommendation_equivalent_head', '', true)");
            }
            if ($changed !== 1) {
                throw new Conflict('PUBLICATION_CAS_CONFLICT');
            }
            if (array_key_exists('custom_variables', $proposal['snapshot']) || $batch->action === 'rollback') {
                $variables = app(TemplateVariableService::class);
                if ($variables->ready()) {
                    $variables->activateSnapshot($page, $proposal['snapshot']['custom_variables'] ?? []);
                }
            }
            if (FanmuluNavigation::opaque($proposal['snapshot'])) {
                $snapshot = app(SnapshotStorage::class)->hydrate($proposal['snapshot']);
                if (! empty($snapshot['_live_recommendations_changed'])) {
                    app(LiveMovieRecommendations::class)->remember($snapshot, $pid);
                } else {
                    app(FanmuluNavigation::class)->remember($snapshot, $pid);
                }
            }
            if ($renderedFirstVisit !== null) {
                app(FirstVisitPublicationWrites::class)->finish($page, $item, $batch, $claim['fence'], $publication, $proposal, $itemChanges['fingerprint']);
                app(PublicHtmlCache::class)->scheduleRefresh($page->site_id, $page->id, immediate: ! FanmuluDetailArtifact::exists($proposal['snapshot']));

                return $publication;
            }
            DB::table('keyword_capacity_claims')->where(['item_id' => $item->id, 'fencing_token' => $claim['fence'], 'status' => 'held'])->update(['status' => 'consumed']);
            DB::table('page_publish_leases')->where('page_id', $page->id)->update(['owner_item_id' => null, 'expires_at' => null]);
            DB::table('publish_batch_items')->where('id', $item->id)->update($itemChanges);
            DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => 'publication.activated', 'aggregate_id' => $pid, 'payload_json' => Json::encode(['publication_id' => $pid, 'site_id' => $page->site_id])]);
            app(PublicHtmlCache::class)->scheduleRefresh($page->site_id, $page->id, immediate: ! FanmuluDetailArtifact::exists($proposal['snapshot']));
            if ($refreshSite) {
                app(PublicHtmlCache::class)->scheduleRefresh($page->site_id);
            }
            Access::audit('page.published', $batch->requested_by, $page->site_id, ['publication_id' => $pid], $page->id, $batch->id);
            $this->counts($batch->id);

            return DB::table('page_publications')->find($pid);
        }, 3);
    }

    /** Source additions need the site lock before batch/page locks; activation still validates the preview fingerprint. */
    private function addsFanmuluSource(array $claim): bool
    {
        if (! config('public_html_cache.enabled')) {
            return false;
        }
        $source = DB::table('publish_batch_items as i')->join('pages as p', 'p.id', '=', 'i.page_id')
            ->join('sites as s', 's.id', '=', 'p.site_id')->where('i.id', $claim['item_id'])
            ->where('i.site_id', $claim['site_id'])->where('i.batch_id', $claim['batch_id'])
            ->first(['p.id', 'p.status', 'p.page_kind', 'p.page_type', 's.presentation_json']);
        if (! $source || $source->status === 'live' || $source->page_kind !== 'regular' || $source->page_type !== 'movie') {
            return false;
        }
        $settings = FanmuluSettings::fromSite($source);

        if (! $settings['enabled'] || $settings['links_per_page'] === 0) {
            return false;
        }

        // Eligible drafts already participate in the same stable source-ID order.
        return ! (app(SiteDeliverySettings::class)->enabled((int) $claim['site_id'])
            && in_array((int) $source->id, app(FanmuluSourceCatalog::class)->candidateIds((int) $claim['site_id'], 1, (int) $source->id), true));
    }

    public function publishItem(int $itemId, ?string $heldFirstVisitLock = null): ?object
    {
        $item = DB::table('publish_batch_items as i')->join('publish_batches as b', 'b.id', '=', 'i.batch_id')->where('i.id', $itemId)
            ->first(['i.id', 'i.site_id', 'i.batch_id', 'i.page_id', 'i.status', 'b.options_json']) ?? abort(404);
        $origin = Json::decode($item->options_json)['origin'] ?? null;
        if ($origin === 'quick_apply') {
            return null;
        }
        if ($origin === 'first_visit') {
            $key = FirstVisitPublisher::lockKey((int) $item->site_id, (int) $item->page_id);
            if ($heldFirstVisitLock === $key) {
                return app(PublicationReadMemo::class)->within(fn () => $this->publishPreparedItem($item));
            }
            $locked = DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS locked', [$key]);
            if (! $locked->locked) {
                throw new Conflict('LEASE_BUSY');
            }
            try {
                return app(PublicationReadMemo::class)->within(fn () => $this->publishPreparedItem($item));
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
            }
        }

        return $this->publishPreparedItem($item);
    }

    private function proposalFingerprint(object $page, array $proposal, array $options): string
    {
        if (FirstVisitPublisher::deferred($proposal)) {
            if (($options['origin'] ?? null) !== 'first_visit') {
                throw new Conflict('FIRST_VISIT_PLAN_INVALID');
            }

            return app(FirstVisitPublisher::class)->fingerprint($page, $proposal['assignments']);
        }

        return app(Planner::class)->fingerprint($page, null, $proposal['assignments'], app(SnapshotStorage::class)->references($proposal['snapshot']), ($options['presentation_only'] ?? false) === true);
    }

    private function publishPreparedItem(object $item): ?object
    {
        $itemId = (int) $item->id;
        if (in_array($item->status, ['stale', 'capacity_conflict', 'error', 'locked', 'failed', 'cancelled', 'unchanged'], true)) {
            return null;
        }
        try {
            $claim = $this->claimItem($item);
            $firstVisitPage = $claim['first_visit_page'] ?? null;
            if ($firstVisitPage !== null) {
                // Claim checked this context. Activation rechecks it under configuration locks after rendering.
                $rendered = app(FirstVisitPublisher::class)->renderProposal($firstVisitPage, $claim['proposal'], contextChecked: true);

                return $this->activate($claim, $rendered);
            }

            return $claim ? $this->activate($claim) : DB::table('page_publications')->where('batch_item_id', $itemId)->first();
        } catch (Conflict $e) {
            if ($e->reason === 'LEASE_BUSY') {
                throw $e;
            }$this->fail($itemId, $e->reason);

            return null;
        } catch (\Throwable $e) {
            $this->fail($itemId, 'RENDER_FAILED');
            report($e);
            throw $e;
        }
    }

    private function fail(int $itemId, string $reason): void
    {
        $initial = DB::table('publish_batch_items')->find($itemId);
        if (! $initial) {
            return;
        }
        DB::transaction(function () use ($initial, $itemId, $reason) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $initial->site_id)->sharedLock()->first();
            DB::table('publish_batches')->where('id', $initial->batch_id)->lockForUpdate()->first();
            $item = DB::table('publish_batch_items')->where('id', $itemId)->lockForUpdate()->first();
            if ($item->status === 'published') {
                return;
            }
            DB::table('pages')->where('id', $item->page_id)->lockForUpdate()->first();
            $lease = DB::table('page_publish_leases')->where('page_id', $item->page_id)->lockForUpdate()->first();
            $this->lockBuckets($item->site_id, DB::table('keyword_capacity_claims')->where('item_id', $itemId)->pluck('keyword_id')->all());
            DB::table('keyword_capacity_claims')->where(['item_id' => $itemId, 'status' => 'held'])->update(['status' => 'released']);
            if ($lease && $lease->owner_item_id === $itemId) {
                DB::table('page_publish_leases')->where('page_id', $item->page_id)->update(['owner_item_id' => null, 'fencing_token' => DB::raw('fencing_token+1'), 'expires_at' => null]);
            }
            $status = match ($reason) {
                'CAPACITY_CONFLICT' => 'capacity_conflict','PREVIEW_STALE','FENCE_LOST' => 'stale','BATCH_CANCELLED' => 'cancelled',default => 'failed'
            };
            DB::table('publish_batch_items')->where('id', $itemId)->update(['status' => $status, 'error_code' => $reason]);
            $this->counts($item->batch_id);
        }, 3);
    }

    public function counts(int $batchId): array
    {
        $counts = DB::table('publish_batch_items')->where('batch_id', $batchId)->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status')->all();
        $batch = DB::table('publish_batches')->find($batchId);
        $accepted = DB::table('publish_batch_items')->where(['batch_id' => $batchId, 'accepted_for_publish' => true])->get(['status']);
        $pending = $accepted->whereIn('status', ['pending', 'claimed', 'rendering', 'activating'])->count();
        $success = $accepted->where('status', 'published')->count();
        $status = $batch->status;
        if ($batch->accepted_at && ! $pending) {
            $status = $batch->cancel_requested_at ? 'cancelled' : ($success === $accepted->count() ? 'completed' : ($success ? 'partial' : 'failed'));
        }
        DB::table('publish_batches')->where('id', $batchId)->update(['counts' => Json::encode($counts), 'status' => $status, 'updated_at' => now()]);

        return $counts;
    }

    public function cancel(int $batchId, ?int $actorId = null): object
    {
        $batch = DB::table('publish_batches')->find($batchId);
        if (! $batch) {
            abort(404);
        }Access::site($actorId ?? $batch->requested_by, $batch->site_id, true);
        DB::transaction(function () use ($batch) {
            $this->configLocks($batch->site_id);
            DB::table('publish_batches')->where('id', $batch->id)->lockForUpdate()->first();
            DB::table('publish_batches')->where('id', $batch->id)->update(['cancel_requested_at' => now(), 'status' => 'cancelling']);
        });
        foreach (DB::table('publish_batch_items')->where('batch_id', $batchId)->where('accepted_for_publish', true)->whereNotIn('status', ['published', 'cancelled'])->orderBy('page_id')->get(['id']) as $item) {
            $this->fail($item->id, 'BATCH_CANCELLED');
        }
        DB::table('publish_batches')->where('id', $batchId)->update(['status' => 'cancelled']);
        $this->counts($batchId);

        return DB::table('publish_batches')->find($batchId);
    }

    public function retry(int $batchId, ?int $actorId = null): object
    {
        $batch = DB::table('publish_batches')->find($batchId);
        if (! $batch) {
            abort(404);
        }Access::site($actorId ?? $batch->requested_by, $batch->site_id, true);
        if ($batch->cancel_requested_at) {
            throw new Conflict('BATCH_CANCELLED');
        }
        DB::transaction(function () use ($batchId, $batch) {
            $this->configLocks($batch->site_id);
            $current = DB::table('publish_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ((Json::decode($current->options_json)['origin'] ?? null) === 'quick_apply') {
                throw new Conflict('QUICK_APPLY_MANAGED_BATCH');
            }
            if ($current->cancel_requested_at) {
                throw new Conflict('BATCH_CANCELLED');
            }
            if (! $current->accepted_at) {
                throw new Conflict('BATCH_NOT_ACCEPTED');
            }
            $retryable = DB::table('publish_batch_items')->where(['batch_id' => $batchId, 'status' => 'failed', 'accepted_for_publish' => true])->get();
            foreach ($retryable as $item) {
                DB::table('publish_batch_items')->where('id', $item->id)->update(['status' => 'pending', 'error_code' => null]);
                $this->enqueue($item->id);
            }
            if ($retryable->isNotEmpty()) {
                DB::table('publish_batches')->where('id', $batchId)->update(['status' => 'queued']);
            }
            $this->counts($batchId);
        });

        return DB::table('publish_batches')->find($batchId);
    }

    public function reconcile(): int
    {
        $count = 0;
        foreach (DB::table('page_publish_leases')->whereNotNull('owner_item_id')->where('expires_at', '<', now())->orderBy('page_id')->get() as $lease) {
            // Re-read under the same lock order as activation; heartbeat cannot race release.
            $item = DB::table('publish_batch_items')->find($lease->owner_item_id);
            if (! $item) {
                continue;
            }
            DB::transaction(function () use ($item, $lease, &$count) {
                DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                DB::table('sites')->where('id', $item->site_id)->sharedLock()->first();
                DB::table('publish_batches')->where('id', $item->batch_id)->lockForUpdate()->first();
                DB::table('publish_batch_items')->where('id', $item->id)->lockForUpdate()->first();
                DB::table('pages')->where('id', $item->page_id)->lockForUpdate()->first();
                $current = DB::table('page_publish_leases')->where('page_id', $item->page_id)->lockForUpdate()->first();
                if ($current->owner_item_id !== $item->id || $current->fencing_token !== $lease->fencing_token || now()->lessThanOrEqualTo($current->expires_at)) {
                    return;
                }
                $this->lockBuckets($item->site_id, DB::table('keyword_capacity_claims')->where('item_id', $item->id)->pluck('keyword_id')->all());
                $published = DB::table('page_publications')->where('batch_item_id', $item->id)->exists();
                DB::table('page_publish_leases')->where('page_id', $item->page_id)->update(['owner_item_id' => null, 'fencing_token' => DB::raw('fencing_token+1'), 'expires_at' => null]);
                DB::table('keyword_capacity_claims')->where(['item_id' => $item->id, 'status' => 'held'])->update(['status' => $published ? 'consumed' : 'released']);
                DB::table('publish_batch_items')->where('id', $item->id)->update(['status' => $published ? 'published' : 'failed', 'error_code' => $published ? null : 'WORKER_EXPIRED']);
                $this->counts($item->batch_id);
                $count++;
            }, 3);
        }

        return $count;
    }

    public function rollbackPreview(int $publicationId, int $expectedHeadId, int $actorId): object
    {
        $publication = DB::table('page_publications')->find($publicationId);
        if (! $publication) {
            abort(404);
        }Access::site($actorId, $publication->site_id, true);

        return app(Planner::class)->preview($publication->site_id, [$publication->page_id], $actorId, 'rollback', ['restore_publication_id' => $publicationId, 'expected_head_id' => $expectedHeadId]);
    }
}
