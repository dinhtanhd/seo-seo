<?php

namespace App\Domain\Publishing;

use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeCatalog;
use App\Domain\Themes\PublicationReadMemo;
use App\Domain\Themes\ReviewedThemeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Durable aggregate follow-up for explicitly requested one-step/ingestion batches. */
final class CatalogRefresh
{
    private const EVENT = 'catalog-refresh.requested';

    private const TERMINAL_ITEMS = ['published', 'unchanged', 'locked', 'error', 'stale', 'capacity_conflict', 'failed', 'cancelled'];

    private const MAX_ENTRIES = 10000;

    public function scheduleForBatch(int $siteId, int $batchId, int $actorId): string
    {
        Access::site($actorId, $siteId, true);

        return DB::transaction(function () use ($siteId, $batchId, $actorId): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['catalog-refresh:'.$batchId]);
            $batch = DB::table('publish_batches')->where('id', $batchId)->first();
            if (! $batch || (int) $batch->site_id !== $siteId) {
                throw new Conflict('CATALOG_BATCH_SITE_MISMATCH');
            }
            if ($batch->accepted_at === null) {
                throw new Conflict('CATALOG_BATCH_NOT_ACCEPTED');
            }
            $existing = DB::table('outbox_events')->where('event_type', self::EVENT)->where('aggregate_id', $batchId)->first();
            if ($existing) {
                return $existing->id;
            }
            $hasContent = DB::table('publish_batch_items as i')->join('pages as p', 'p.id', '=', 'i.page_id')
                ->where('i.batch_id', $batchId)->where('p.site_id', $siteId)->where('p.page_kind', 'regular')
                ->whereIn('p.page_type', ['movie', 'novel'])->exists();
            $id = (string) Str::uuid();
            DB::table('outbox_events')->insert(['id' => $id, 'event_type' => self::EVENT, 'aggregate_id' => $batchId,
                'payload_json' => Json::encode(['site_id' => $siteId, 'parent_batch_id' => $batchId, 'actor_id' => $actorId,
                    'status' => $hasContent ? 'waiting_parent' : 'skipped', 'reason' => $hasContent ? null : 'NO_REGULAR_CONTENT']),
                'delivered_at' => $hasContent ? null : now()]);

            return $id;
        }, 3);
    }

    public function dispatchPending(int $limit = 50): int
    {
        $delivered = 0;
        $delivery = app(SiteDeliverySettings::class);
        $pending = DB::table('outbox_events')->where('event_type', self::EVENT)->whereNull('delivered_at')->where('attempts', '<', 4);
        $ids = $delivery->excludeAutomated($pending, self::EVENT)
            ->orderBy('created_at')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $delivered += $this->process($id) ? 1 : 0;
            } catch (\Throwable $error) {
                $this->failure($id, $error);
                report($error);
            }
        }
        $exhaustedQuery = DB::table('outbox_events')->where('event_type', self::EVENT)->whereNull('delivered_at')->where('attempts', '>=', 4)
            ->whereRaw("payload_json->>'status' = ?", ['failed'])->whereRaw("payload_json->>'error' = ?", ['CATALOG_PUBLICATION_FAILED'])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('sites')->where('sites.status', 'active')->whereRaw("sites.id::text = outbox_events.payload_json->>'site_id'"))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('publish_batches')->whereNull('cancel_requested_at')->whereNotNull('accepted_at')->whereRaw("publish_batches.id::text = outbox_events.payload_json->>'parent_batch_id'"));
        $exhausted = $delivery->excludeAutomated($exhaustedQuery, self::EVENT)
            ->orderBy('created_at')->orderBy('id')->limit(min(10, max(1, $limit)))->pluck('id');
        foreach ($exhausted as $id) {
            try {
                $delivered += $this->recoverSuperseded($id) ? 1 : 0;
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return $delivered;
    }

    /** Reconcile an exhausted conflict only when another catalog publication already fulfilled it. */
    public function recoverSuperseded(string $id): bool
    {
        $event = DB::table('outbox_events')->where('id', $id)->where('event_type', self::EVENT)->whereNull('delivered_at')->first();
        $payload = Json::decode($event?->payload_json);
        if (($payload['status'] ?? null) !== 'failed' || ($payload['error'] ?? null) !== 'CATALOG_PUBLICATION_FAILED' || ! isset($payload['catalog_batch_id'])) {
            return false;
        }
        try {
            return $this->process($id);
        } catch (Conflict|HttpExceptionInterface) {
            return false;
        }
    }

    private function process(string $id): bool
    {
        $initial = DB::table('outbox_events')->where('id', $id)->first();
        $payload = Json::decode($initial?->payload_json);
        $siteId = (int) ($payload['site_id'] ?? 0);
        $delivery = app(SiteDeliverySettings::class);
        if (config('public_on_demand.enabled', false)
            && $delivery->pausesAutomatedBatch($initial ? DB::table('publish_batches')->where('id', $initial->aggregate_id)->first() : null)) {
            return false;
        }

        return DB::transaction(function () use ($id, $siteId, $delivery): bool {
            return app(PublicationReadMemo::class)->within(function () use ($id, $siteId, $delivery): bool {
                $lock = DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0)) acquired', ['catalog-event:'.$id]);
                if (! $lock->acquired) {
                    return false;
                }
                DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
                $event = DB::table('outbox_events')->where('id', $id)->lockForUpdate()->first();
                if (! $event || $event->delivered_at !== null) {
                    return false;
                }
                $payload = Json::decode($event->payload_json);
                $actor = (int) ($payload['actor_id'] ?? 0);
                Access::site($actor, $siteId, true);
                if (! $site || $site->status !== 'active') {
                    throw new Conflict('CATALOG_SITE_NOT_ACTIVE');
                }
                $parent = DB::table('publish_batches')->where('id', $payload['parent_batch_id'] ?? 0)->first();
                if (! $parent || (int) $parent->site_id !== $siteId || (int) $event->aggregate_id !== (int) $parent->id || $parent->accepted_at === null) {
                    throw new Conflict('CATALOG_PARENT_INVALID');
                }
                if ($delivery->pausesAutomatedBatch($parent)) {
                    return false;
                }
                if (! $this->parentFinished((int) $parent->id, $payload)) {
                    return false;
                }
                if (isset($payload['reference_batch_id'])) {
                    $child = DB::table('publish_batches')->where('id', $payload['reference_batch_id'])->where('site_id', $siteId)->first();
                    if (! $child || $child->accepted_at === null) {
                        throw new Conflict('REFERENCES_CHILD_INVALID');
                    }
                    if (! $this->finished((int) $child->id, 'REFERENCES_PUBLICATION_FAILED')) {
                        return false;
                    }
                    $this->complete($id, $payload);

                    return true;
                }
                if (isset($payload['catalog_batch_id'])) {
                    $child = DB::table('publish_batches')->where('id', $payload['catalog_batch_id'])->where('site_id', $siteId)->first();
                    if (! $child || $child->accepted_at === null) {
                        throw new Conflict('CATALOG_CHILD_INVALID');
                    }
                    if (! $this->catalogFinished((int) $child->id, $payload)) {
                        return false;
                    }
                    if (isset($payload['catalog_convergence'])) {
                        unset($payload['error']);
                        $payload['catalog_recovery_attempts'] = (int) $event->attempts;
                    }

                    $completed = $this->refreshReferences($id, $payload, $siteId, (int) $parent->id, $actor);
                    if (! $completed && isset($payload['catalog_convergence'])) {
                        DB::table('outbox_events')->where('id', $id)->update(['attempts' => 0]);
                    }

                    return $completed;
                }

                $pages = DB::table('pages')->where('site_id', $siteId)->whereIn('page_type', ['home', 'category'])
                    ->whereIn('status', ['draft', 'live'])->orderBy('id')->lockForUpdate()->get();
                $pageIds = [];
                $skipped = [];
                foreach ($pages as $page) {
                    if ($page->publish_locked) {
                        $skipped[] = (int) $page->id;

                        continue;
                    }
                    $input = Json::decode($page->input_snapshot_json);
                    // Preserve membership/order while refreshing only the source publications already selected.
                    $automatic = ! array_key_exists('selected_page_ids', $input)
                        && ! array_key_exists('catalog_selection', $input)
                        && (($input['catalog_auto'] ?? false) === true
                            || (! array_key_exists('catalog_auto', $input) && ($input['entries'] ?? []) === []));
                    if ($automatic) {
                        $next = array_replace($input, ['catalog_auto' => true, 'entries' => $this->entries($siteId, $page)]);
                    } else {
                        $references = array_key_exists('selected_page_ids', $input)
                            ? MovieThemeCatalog::references($input)
                            : ($input['catalog_selection'] ?? MovieThemeCatalog::references($input));
                        if (! is_array($references) || count($references) > self::MAX_ENTRIES) {
                            throw new Conflict('CATALOG_SELECTION_LIMIT');
                        }
                        $next = array_replace($input, ['entries' => app(MovieThemeCatalog::class)->publishedEntries($siteId, $references)]);
                        if (! array_key_exists('selected_page_ids', $input)) {
                            // Keep legacy membership even when every selected source is temporarily unpublished.
                            $next['catalog_auto'] = false;
                            $next['catalog_selection'] = array_map(fn ($entry) => array_intersect_key($entry, array_flip(['page_id', 'path'])), $references);
                        }
                    }
                    if (Json::hash($next) !== Json::hash($input)) {
                        DB::table('pages')->where('id', $page->id)->update(['input_snapshot_json' => Json::encode($next),
                            'input_hash' => Json::hash($next), 'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
                    }
                    $pageIds[] = (int) $page->id;
                }
                $payload['skipped_locked_page_ids'] = $skipped;
                if ($pageIds === []) {
                    return $this->refreshReferences($id, $payload + ['reason' => 'NO_ELIGIBLE_AGGREGATES'], $siteId, (int) $parent->id, $actor);
                }
                if (count($pageIds) > 10000) {
                    throw new Conflict('CATALOG_AGGREGATE_LIMIT');
                }
                $batch = app(Planner::class)->preview($siteId, $pageIds, $actor, 'publish', ['idempotency_key' => 'catalog-refresh:'.$id]);
                $invalid = DB::table('publish_batch_items')->where('batch_id', $batch->id)->whereNotIn('status', ['pending', 'unchanged'])
                    ->first(['page_id', 'status', 'error_code']);
                if ($invalid) {
                    throw new Conflict('CATALOG_PREVIEW_FAILED', 'page '.$invalid->page_id.' '.($invalid->error_code ?: $invalid->status));
                }
                app(Publisher::class)->accept($batch->id, $actor);
                $payload['catalog_batch_id'] = (int) $batch->id;
                $payload['page_ids'] = $pageIds;
                $payload['status'] = 'waiting_catalog';
                unset($payload['error']);
                DB::table('outbox_events')->where('id', $id)->update(['payload_json' => Json::encode($payload)]);
                if ($this->catalogFinished((int) $batch->id, $payload)) {
                    return $this->refreshReferences($id, $payload, $siteId, (int) $parent->id, $actor);
                }

                return false;
            });
        }, 3);
    }

    /** Only refresh reachable routes after aggregate activation; never publish a newer working draft. */
    private function refreshReferences(string $eventId, array $payload, int $siteId, int $parentId, int $actorId): bool
    {
        $pages = DB::table('pages as p')->join('publish_batch_items as i', 'i.page_id', '=', 'p.id')
            ->join('page_publications as pp', function ($join): void {
                $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.site_id', '=', 'p.site_id')->on('pp.page_id', '=', 'p.id');
            })->where('i.batch_id', $parentId)->whereIn('i.status', ['published', 'unchanged'])
            ->where('p.site_id', $siteId)->where('p.status', 'live')->where('p.page_kind', 'regular')->where('p.page_type', 'movie')
            ->select(['p.*', 'pp.snapshot_json', 'pp.batch_item_id', 'pp.content_revision_id',
                'i.id as parent_item_id', 'i.status as parent_item_status', 'i.expected_current_publication_id as parent_expected_head', 'i.expected_row_version as parent_expected_version'])
            ->lockForUpdate()->lazyById(10, 'p.id', 'id');
        $children = Json::decode(DB::table('publish_batches')->where('id', $parentId)->value('options_json'))['workflow_children'] ?? [];
        $parentHeads = DB::table('page_publications')->where('site_id', $siteId)
            ->whereIn('batch_item_id', DB::table('publish_batch_items')->where('batch_id', $parentId)->select('id'))->pluck('id', 'batch_item_id');
        $heads = [];
        $skipped = [];
        foreach ($pages as $page) {
            $snapshot = app(SnapshotStorage::class)->decode($page->snapshot_json);
            if (! ReviewedThemeRuntime::enabled($snapshot['theme'] ?? []) || ! MovieThemeCatalog::supports($snapshot['theme'] ?? null)) {
                continue;
            }
            $expectedHead = $page->parent_item_status === 'unchanged' ? $page->parent_expected_head
                : ($children[$page->parent_item_id]['publication_id'] ?? $parentHeads[$page->parent_item_id] ?? null);
            if ($expectedHead === null || (int) $page->current_publication_id !== (int) $expectedHead) {
                $skipped[$page->id] = 'PAGE_CHANGED';

                continue;
            }
            $item = DB::table('publish_batch_items')->find($page->batch_item_id);
            $expectedVersion = $page->parent_item_status === 'unchanged' ? (int) $page->parent_expected_version : (int) ($item?->expected_row_version ?? 0) + 1;
            if ($page->publish_locked || ! $item || (int) $page->row_version !== $expectedVersion
                || $page->working_content_revision_id !== $page->content_revision_id) {
                $skipped[$page->id] = $page->publish_locked ? 'PAGE_LOCKED' : 'OWN_DRAFT_CHANGED';

                continue;
            }
            $entries = $snapshot['aggregate']['entries'] ?? [];
            $current = app(MovieThemeCatalog::class)->publishedEntries($siteId, $entries);
            $routing = SitePresentationService::profile($snapshot['routing'] ?? null);
            if ($this->referencePaths($entries, $routing) !== $this->referencePaths($current, $routing)) {
                $heads[$page->id] = $this->referenceInvariant($snapshot);
            }
        }
        $payload['skipped_reference_page_ids'] = $skipped;
        $payload['reference_page_ids'] = array_keys($heads);
        if ($heads === []) {
            $this->complete($eventId, $payload);

            return true;
        }
        $batch = app(Planner::class)->preview($siteId, array_keys($heads), $actorId, 'publish', ['idempotency_key' => 'catalog-refresh:'.$eventId.':references']);
        foreach (DB::table('publish_batch_items')->where('batch_id', $batch->id)->lazyById(10) as $item) {
            $proposal = Json::decode($item->preview_json);
            if (! in_array($item->status, ['pending', 'unchanged'], true)
                || $heads[$item->page_id] !== $this->referenceInvariant($proposal['snapshot'] ?? [])) {
                throw new Conflict('REFERENCES_STATE_CHANGED', 'page '.$item->page_id.' '.($item->error_code ?: $item->status));
            }
        }
        app(Publisher::class)->accept($batch->id, $actorId);
        $payload['reference_batch_id'] = (int) $batch->id;
        $payload['status'] = 'waiting_references';
        DB::table('outbox_events')->where('id', $eventId)->update(['payload_json' => Json::encode($payload)]);
        if ($this->finished((int) $batch->id, 'REFERENCES_PUBLICATION_FAILED')) {
            $this->complete($eventId, $payload);

            return true;
        }

        return false;
    }

    private function referencePaths(array $entries, array $routing): string
    {
        return Json::hash(array_map(function (array $entry) use ($routing): array {
            $path = $entry['path'] ?? '/';
            $play = $entry['play_path'] ?? (! empty($entry['content']['episodes']) && $path !== '/'
                ? SitePresentationService::playPath($routing, $entry['route_key'] ?? SitePresentationService::keyFromPath($path, $routing, 'detail'), 1) : '');

            return ['page_id' => $entry['page_id'] ?? null, 'path' => $path, 'play_path' => $play, 'category_path' => $entry['category']['path'] ?? null];
        }, $entries));
    }

    private function referenceInvariant(array $snapshot): string
    {
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        $snapshot['assignments'] = array_map(fn ($entry) => array_intersect_key($entry, array_flip(['keyword_id', 'role', 'ordinal', 'text', 'target_url'])), $snapshot['assignments'] ?? []);
        unset($snapshot['aggregate']['entries'], $snapshot['theme_catalog']['entry_categories'], $snapshot['_snapshot_cards'], $snapshot['_snapshot_aggregate_hash'], $snapshot['_catalog_card']);

        return Json::hash($snapshot);
    }

    /** A competing catalog child may fulfill the exact same accepted proposal without a new publication. */
    private function catalogFinished(int $batchId, array &$payload): bool
    {
        $items = DB::table('publish_batch_items')->where('batch_id', $batchId);
        if ((clone $items)->whereNotIn('status', self::TERMINAL_ITEMS)->exists()) {
            return false;
        }
        $proofs = [];
        foreach ((clone $items)->whereNotIn('status', ['published', 'unchanged'])->lazyById(10) as $item) {
            $proof = $this->supersededProof($item, $payload);
            if ($proof === null) {
                throw new Conflict('CATALOG_PUBLICATION_FAILED');
            }
            $proofs[] = $proof;
        }
        if ($proofs !== []) {
            $payload['catalog_convergence'] = $proofs;
        }

        return true;
    }

    private function supersededProof(object $item, array $payload): ?array
    {
        if ($item->status !== 'stale' || $item->error_code !== 'PREVIEW_STALE') {
            return null;
        }
        $parent = DB::table('publish_batches')->find($payload['parent_batch_id'] ?? 0);
        $child = DB::table('publish_batches')->find($item->batch_id);
        $page = DB::table('pages')->where('site_id', $item->site_id)->lockForUpdate()->find($item->page_id);
        if (! $parent || $parent->cancel_requested_at !== null || ! $child || $child->cancel_requested_at !== null
            || ! $page || $page->status !== 'live' || $page->publish_locked || ($page->page_kind ?? 'regular') !== 'regular'
            || ! in_array($page->page_type, ['home', 'category'], true) || $page->row_version !== $item->expected_row_version + 1) {
            return null;
        }
        $live = DB::table('page_publications')->where('site_id', $item->site_id)->where('page_id', $item->page_id)->find($page->current_publication_id);
        $winner = $live ? DB::table('publish_batch_items')->where('site_id', $item->site_id)->where('page_id', $item->page_id)->find($live->batch_item_id) : null;
        $winnerBatch = $winner ? DB::table('publish_batches')->where('site_id', $item->site_id)->find($winner->batch_id) : null;
        if (! $live || ! $winner || ! $winnerBatch || $winner->id === $item->id || $winner->batch_id === $item->batch_id
            || $winner->status !== 'published' || $winnerBatch->action !== 'publish' || $winnerBatch->accepted_at === null
            || $winnerBatch->cancel_requested_at !== null || ! str_starts_with($winnerBatch->request_idempotency_key, 'catalog-refresh:')
            || $winner->fingerprint !== $item->fingerprint || $winner->expected_row_version !== $item->expected_row_version
            || $winner->expected_current_publication_id !== $item->expected_current_publication_id
            || $live->previous_publication_id !== $item->expected_current_publication_id) {
            return null;
        }
        $proposal = Json::decode($item->preview_json);
        $snapshot = app(SnapshotStorage::class)->hydrate($proposal['snapshot'] ?? []);
        $html = $proposal['html'] ?? null;
        if (! is_string($html) || ($proposal['html_hash'] ?? null) !== hash('sha256', $html)
            || $live->html_hash !== $proposal['html_hash'] || $live->html_body !== $html || hash('sha256', $live->html_body) !== $live->html_hash
            || ! app(SnapshotStorage::class)->equivalent(Json::decode($live->snapshot_json), $snapshot)
            || $page->working_content_revision_id !== ($snapshot['content_revision_id'] ?? null)
            || $live->content_revision_id !== $page->working_content_revision_id
            || $page->canonical_path !== ($snapshot['page']['path'] ?? null)
            || $page->input_hash !== Json::hash(Json::decode($page->input_snapshot_json))
            || $page->input_hash !== ($snapshot['_snapshot_aggregate_hash'] ?? Json::hash($snapshot['aggregate'] ?? []))) {
            return null;
        }
        $pins = DB::table('page_keyword_assignments')->where('publication_id', $live->id)->get(['keyword_id', 'role', 'ordinal', 'text_snapshot as text', 'target_url_snapshot as target_url'])->map(fn ($pin) => (array) $pin)->all();
        if ($this->pinHash($pins) !== $this->pinHash($proposal['assignments'] ?? []) || $this->pinHash($pins) !== $this->pinHash($snapshot['assignments'] ?? [])) {
            return null;
        }

        return ['page_id' => (int) $page->id, 'stale_item_id' => (int) $item->id, 'publication_id' => (int) $live->id,
            'published_item_id' => (int) $winner->id, 'html_hash' => $live->html_hash];
    }

    private function pinHash(array $assignments): string
    {
        $pins = [];
        foreach ($assignments as $entry) {
            $pin = array_intersect_key($entry, array_flip(['keyword_id', 'role', 'ordinal', 'text', 'target_url']));
            $pins[$entry['role'].':'.$entry['ordinal'].':'.$entry['keyword_id']] = $pin;
        }
        ksort($pins);

        return Json::hash($pins);
    }

    /** Waiting does not consume retries; terminal errors are visible instead of a false completed state. */
    private function finished(int $batchId, string $error): bool
    {
        $items = DB::table('publish_batch_items')->where('batch_id', $batchId);
        if ((clone $items)->whereNotIn('status', self::TERMINAL_ITEMS)->exists()) {
            return false;
        }
        if ((clone $items)->whereNotIn('status', ['published', 'unchanged'])->exists()) {
            throw new Conflict($error);
        }

        return true;
    }

    private function parentFinished(int $batchId, array &$payload): bool
    {
        $counts = DB::table('publish_batch_items')->where('batch_id', $batchId)->select('status')->selectRaw('count(*) count')
            ->groupBy('status')->pluck('count', 'status')->map(fn ($count) => (int) $count)->all();
        if (array_diff(array_keys($counts), self::TERMINAL_ITEMS)) {
            return false;
        }
        $payload['parent_counts'] = $counts;
        $success = ($counts['published'] ?? 0) + ($counts['unchanged'] ?? 0);
        $payload['parent_error_count'] = array_sum($counts) - $success;
        if ($payload['parent_error_count'] > 0) {
            if ($success === 0) {
                throw new Conflict('CATALOG_PARENT_INCOMPLETE');
            }
            $payload['warning'] = 'CATALOG_PARENT_PARTIAL';
        } else {
            unset($payload['warning']);
        }

        return true;
    }

    private function entries(int $siteId, object $page): array
    {
        $query = DB::table('pages as p')->join('page_publications as pp', function ($join): void {
            $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
        })->where('p.site_id', $siteId)->where('p.page_kind', 'regular')->where('p.status', 'live')->whereIn('p.page_type', ['movie', 'novel']);
        if ($page->page_type === 'category') {
            if ($page->category_id === null) {
                return [];
            }
            $query->where(function ($category) use ($page): void {
                $category->whereRaw("pp.snapshot_json->'page'->>'category_id' = ?", [(string) $page->category_id])
                    ->orWhereRaw("pp.snapshot_json->'category'->'ancestor_ids' @> ?::jsonb", [Json::encode([(int) $page->category_id])]);
            });
        }
        $ids = $query->orderByDesc('pp.activated_at')->orderByDesc('p.id')->limit(self::MAX_ENTRIES)->pluck('p.id')->all();

        return app(MovieThemeCatalog::class)->publishedEntries($siteId, array_map(fn ($id) => ['page_id' => (int) $id], $ids));
    }

    private function complete(string $id, array $payload): void
    {
        $payload['status'] = 'completed';
        unset($payload['error']);
        DB::table('outbox_events')->where('id', $id)->update(['payload_json' => Json::encode($payload), 'delivered_at' => now()]);
    }

    private function failure(string $id, \Throwable $error): void
    {
        DB::transaction(function () use ($id, $error): void {
            $event = DB::table('outbox_events')->where('id', $id)->whereNull('delivered_at')->lockForUpdate()->first();
            if (! $event) {
                return;
            }
            $payload = Json::decode($event->payload_json);
            if (isset($payload['parent_batch_id'])) {
                $counts = DB::table('publish_batch_items')->where('batch_id', $payload['parent_batch_id'])->select('status')
                    ->selectRaw('count(*) count')->groupBy('status')->pluck('count', 'status')->map(fn ($count) => (int) $count)->all();
                $payload['parent_counts'] = $counts;
                $payload['parent_error_count'] = array_sum($counts) - ($counts['published'] ?? 0) - ($counts['unchanged'] ?? 0);
            }
            $payload['status'] = 'failed';
            $payload['error'] = $error instanceof HttpExceptionInterface ? 'CATALOG_ACCESS_'.$error->getStatusCode() : mb_substr($error->getMessage(), 0, 1000);
            DB::table('outbox_events')->where('id', $id)->update(['payload_json' => Json::encode($payload), 'attempts' => DB::raw('attempts+1')]);
        }, 3);
    }
}
