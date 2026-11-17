<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\PublicationReadMemo;
use App\Jobs\Publishing\RunQuickApply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Fresh per-page publishing for an explicit one-step intent, separate from manual previews. */
final class QuickApplyWorkflow
{
    private const EVENT = 'quick-apply.requested';

    public function accept(object $initial, int $actorId): string
    {
        return DB::transaction(function () use ($initial, $actorId): string {
            return app(PublicationReadMemo::class)->within(function () use ($initial, $actorId): string {
                DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                $site = DB::table('sites')->where('id', $initial->site_id)->sharedLock()->first();
                Access::site($actorId, (int) $initial->site_id, true);
                if (! $site || $site->status !== 'active') {
                    throw new Conflict('SITE_NOT_ACTIVE');
                }
                $batch = DB::table('publish_batches')->where('id', $initial->id)->lockForUpdate()->first();
                $existing = DB::table('outbox_events')->where('event_type', self::EVENT)->where('aggregate_id', $batch->id)->first();
                if ($existing) {
                    return $existing->id;
                }
                if ($batch->accepted_at || $batch->status !== 'ready' || (int) $batch->requested_by !== $actorId) {
                    throw new Conflict('BATCH_NOT_READY');
                }
                $presentationOnly = (Json::decode($batch->options_json)['presentation_only'] ?? false) === true;
                $expected = [];
                foreach (DB::table('publish_batch_items')->where('batch_id', $batch->id)->where('status', 'pending')->lazyById(20) as $item) {
                    $page = DB::table('pages')->where('id', $item->page_id)->sharedLock()->first();
                    $proposal = Json::decode($item->preview_json);
                    if (! $page || $page->row_version !== $item->expected_row_version || $page->current_publication_id !== $item->expected_current_publication_id
                        || now()->greaterThan($item->expires_at)
                        || $item->fingerprint !== app(Planner::class)->fingerprint($page, null, $proposal['assignments'], app(SnapshotStorage::class)->references($proposal['snapshot']), $presentationOnly)) {
                        throw new Conflict('PREVIEW_STALE');
                    }
                    $expected[$item->id] = ['row_version' => $page->row_version, 'head' => $page->current_publication_id,
                        'revision' => $page->working_content_revision_id, 'input_hash' => $page->input_hash,
                        'proposal_hash' => Json::hash($proposal)];
                    DB::table('publish_batch_items')->where('id', $item->id)->update(['accepted_for_publish' => true]);
                }
                $id = (string) Str::uuid();
                $options = Json::decode($batch->options_json);
                $options['origin'] = 'quick_apply';
                $options['workflow_event_id'] = $id;
                DB::table('publish_batches')->where('id', $batch->id)->update(['status' => 'queued', 'accepted_at' => now(),
                    'accepted_plan_hash' => Json::hash($expected), 'options_json' => Json::encode($options)]);
                DB::table('outbox_events')->insert(['id' => $id, 'event_type' => self::EVENT, 'aggregate_id' => $batch->id,
                    'payload_json' => Json::encode(['site_id' => $site->id, 'batch_id' => $batch->id, 'actor_id' => $actorId,
                        'site_row_version' => $site->row_version, 'site_epoch' => $site->config_epoch,
                        'global_epoch' => DB::table('system_settings')->where('id', 1)->value('config_epoch'),
                        'expected' => $expected, 'status' => 'queued'])]);
                Access::audit('quick-apply.workflow.accepted', $actorId, (int) $site->id, ['event_id' => $id], null, (int) $batch->id);
                DB::afterCommit(function () use ($id): void {
                    try {
                        RunQuickApply::dispatch($id)->onQueue('publish');
                    } catch (\Throwable $error) {
                        report($error);
                    }
                });

                return $id;
            });
        }, 3);
    }

    public function dispatchPending(int $limit = 50): int
    {
        $pending = DB::table('outbox_events')->where('event_type', self::EVENT)->whereNull('delivered_at');
        $ids = app(SiteDeliverySettings::class)->excludeAutomated($pending, self::EVENT)
            ->orderBy('created_at')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            RunQuickApply::dispatch($id)->onQueue('publish');
        }

        return $ids->count();
    }

    /** Process at most one page; return whether durable work remains. */
    public function process(string $eventId, ?int $preferredItem = null, bool $waitForParent = false): bool
    {
        $event = DB::table('outbox_events')->where(['id' => $eventId, 'event_type' => self::EVENT])->first();
        if (! $event || $event->delivered_at !== null) {
            return false;
        }
        $delivery = app(SiteDeliverySettings::class);
        if ($preferredItem === null && config('public_on_demand.enabled', false)
            && $delivery->pausesAutomatedBatch(DB::table('publish_batches')->where('id', $event->aggregate_id)->first())) {
            return false;
        }
        $lock = 'quick-apply-workflow:'.$event->aggregate_id;
        if ($waitForParent && $preferredItem !== null && DB::transactionLevel() > 0) {
            // The public first-visit transaction supplies a bounded lock_timeout.
            // Wait for the accepted parent instead of returning a spurious busy page.
            DB::select('SELECT pg_advisory_lock(hashtextextended(?,0))', [$lock]);
            $acquired = true;
        } else {
            $acquired = DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) acquired', [$lock])->acquired;
        }
        if (! $acquired) {
            return true;
        }
        try {
            $event = DB::table('outbox_events')->where('id', $eventId)->first();
            if ($event->delivered_at !== null) {
                return false;
            }
            $payload = Json::decode($event->payload_json);
            $parent = DB::table('publish_batches')->where('id', $event->aggregate_id)->first();
            if ($preferredItem === null && $delivery->pausesAutomatedBatch($parent)) {
                return false;
            }
            if (! $parent || $parent->cancel_requested_at || $parent->status === 'cancelled') {
                $this->stop($eventId, $payload, 'BATCH_CANCELLED');

                return false;
            }
            try {
                $this->context($payload);
            } catch (Conflict $error) {
                $this->stop($eventId, $payload, $error->reason);

                return false;
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 403) {
                    throw $error;
                }
                $this->stop($eventId, $payload, 'QUICK_APPLY_PERMISSION_CHANGED');

                return false;
            }
            $query = DB::table('publish_batch_items as i')->join('pages as p', 'p.id', '=', 'i.page_id')
                ->where('i.batch_id', $parent->id)->where('i.accepted_for_publish', true)->where('i.status', 'pending');
            if ($preferredItem !== null) {
                $query->where('i.id', $preferredItem);
            }
            $item = $query->orderByRaw("CASE WHEN p.page_type='home' THEN 0 ELSE 1 END")->orderBy('i.page_id')->first(['i.*']);
            if ($item) {
                try {
                    $this->page($payload, $parent, $item);
                } catch (Conflict $error) {
                    if ($error->reason === 'LEASE_BUSY') {
                        return true;
                    }
                    $this->failure((int) $item->id, $error->reason);
                } catch (\Throwable $error) {
                    if (! in_array(DB::table('publish_batch_items')->where('id', $item->id)->value('status'), ['published', 'unchanged'], true)) {
                        throw $error;
                    }
                    report($error);
                }
            }
            $remaining = DB::table('publish_batch_items')->where('batch_id', $parent->id)->where('accepted_for_publish', true)->where('status', 'pending')->exists();
            $this->progress((int) $parent->id);
            if (! $remaining) {
                $payload['status'] = DB::table('publish_batches')->where('id', $parent->id)->value('status');
                DB::table('outbox_events')->where('id', $eventId)->update(['payload_json' => Json::encode($payload), 'delivered_at' => now()]);
            }

            return $remaining;
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
        }
    }

    private function context(array $payload): void
    {
        Access::site((int) $payload['actor_id'], (int) $payload['site_id'], true);
        $site = DB::table('sites')->where('id', $payload['site_id'])->first();
        if (! $site || $site->status !== 'active' || $site->row_version !== $payload['site_row_version']
            || $site->config_epoch !== $payload['site_epoch']
            || DB::table('system_settings')->where('id', 1)->value('config_epoch') !== $payload['global_epoch']) {
            throw new Conflict('QUICK_APPLY_CONFIGURATION_CHANGED');
        }
    }

    private function page(array $payload, object $parent, object $item): void
    {
        DB::transaction(function () use ($payload, $parent, $item): void {
            app(PublicationReadMemo::class)->within(function () use ($payload, $parent, $item): void {
                DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                DB::table('sites')->where('id', $payload['site_id'])->sharedLock()->first();
                $currentParent = DB::table('publish_batches')->where('id', $parent->id)->lockForUpdate()->first();
                if ($currentParent->cancel_requested_at) {
                    throw new Conflict('BATCH_CANCELLED');
                }
                $this->context($payload);
                $key = 'quick-page:'.$parent->public_id.':'.$item->page_id;
                $child = DB::table('publish_batches')->where(['requested_by' => $payload['actor_id'], 'request_idempotency_key' => $key])->first();
                if (! $child) {
                    $child = DB::transaction(function () use ($payload, $parent, $item, $key): object {
                        DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                        DB::table('sites')->where('id', $payload['site_id'])->sharedLock()->first();
                        $this->context($payload);
                        $page = DB::table('pages')->where('id', $item->page_id)->lockForUpdate()->first();
                        $expected = $payload['expected'][$item->id] ?? null;
                        if (! $page || ! $expected || $page->row_version !== $expected['row_version'] || $page->current_publication_id !== $expected['head']
                            || $page->working_content_revision_id !== $expected['revision'] || $page->input_hash !== $expected['input_hash']
                            || in_array($page->status, ['archived', 'gone'])) {
                            throw new Conflict('QUICK_APPLY_PAGE_CHANGED');
                        }
                        $parentOptions = Json::decode($parent->options_json);
                        $seed = $parentOptions['seed'];
                        $options = ['idempotency_key' => $key, 'seed' => $seed, 'origin' => 'quick_apply_child', 'parent_batch_id' => $parent->id];
                        if (($parentOptions['presentation_only'] ?? false) === true) {
                            $options['presentation_only'] = true;
                            if (($parentOptions['refresh_seo'] ?? false) === true) {
                                $options['refresh_seo'] = true;
                            }
                        }
                        $child = $this->frozenChild($payload, $parent, $item, $page, $expected, $options)
                            ?? app(Planner::class)->preview((int) $payload['site_id'], [(int) $item->page_id], (int) $payload['actor_id'], 'publish', $options);
                        $childItem = DB::table('publish_batch_items')->where('batch_id', $child->id)->first();
                        if (! in_array($childItem->status, ['pending', 'unchanged'])) {
                            throw new Conflict($childItem->error_code ?? 'QUICK_APPLY_PREVIEW_FAILED');
                        }
                        $pins = Json::decode($item->preview_json)['assignments'] ?? [];
                        $fresh = Json::decode($childItem->preview_json)['assignments'] ?? [];
                        if ($this->pins($pins) !== $this->pins($fresh)) {
                            throw new Conflict('QUICK_APPLY_ASSIGNMENT_CHANGED');
                        }
                        app(Publisher::class)->accept($child->id, (int) $payload['actor_id']);

                        return $child;
                    }, 3);
                }
                $childItem = DB::table('publish_batch_items')->where('batch_id', $child->id)->first();
                if ($childItem->status === 'pending') {
                    app(Publisher::class)->publishItem((int) $childItem->id);
                    $childItem = DB::table('publish_batch_items')->where('id', $childItem->id)->first();
                }
                if (in_array($childItem->status, ['rendering', 'claimed', 'activating'])) {
                    throw new Conflict('LEASE_BUSY');
                }
                if ($childItem->status === 'published') {
                    $publication = DB::table('page_publications')->where('batch_item_id', $childItem->id)->first();
                    if (! $publication || DB::table('pages')->where('id', $item->page_id)->value('current_publication_id') !== $publication->id) {
                        throw new Conflict('QUICK_APPLY_PAGE_CHANGED');
                    }
                }
                DB::table('publish_batch_items')->where('id', $item->id)->where('status', 'pending')->update(['status' => $childItem->status, 'error_code' => $childItem->error_code]);
                $options = Json::decode(DB::table('publish_batches')->where('id', $parent->id)->value('options_json'));
                $options['workflow_children'][$item->id] = ['batch_id' => $child->id, 'item_id' => $childItem->id,
                    'publication_id' => DB::table('page_publications')->where('batch_item_id', $childItem->id)->value('id')];
                DB::table('publish_batches')->where('id', $parent->id)->update(['options_json' => Json::encode($options)]);
                Access::audit('quick-apply.page.completed', (int) $payload['actor_id'], (int) $payload['site_id'], ['child_batch_id' => $child->id, 'status' => $childItem->status], (int) $item->page_id, (int) $parent->id);
            });
        }, 3);
    }

    /** Reuse a verified accepted first-publication artifact; existing live pages still refresh their references. */
    private function frozenChild(array $payload, object $parent, object $item, object $page, array $expected, array $options): ?object
    {
        if ($parent->action !== 'publish' || $page->page_type !== 'movie'
            || $page->page_kind !== 'regular' || $page->current_publication_id !== null || $item->expected_current_publication_id !== null
            || Json::decode($parent->sequential_json) !== []
            || DB::table('page_publications')->where('page_id', $page->id)->exists()) {
            return null;
        }
        $proposal = Json::decode($item->preview_json);
        $snapshot = $proposal['snapshot'] ?? [];
        $assignments = $proposal['assignments'] ?? null;
        $parentOptions = Json::decode($parent->options_json);
        if ($parent->accepted_at === null || ($parentOptions['origin'] ?? null) !== 'quick_apply'
            || ! is_string($parentOptions['workflow_event_id'] ?? null) || (int) ($payload['batch_id'] ?? 0) !== (int) $parent->id
            || (int) $parent->requested_by !== (int) $payload['actor_id'] || (int) $item->site_id !== (int) $page->site_id
            || ! $item->accepted_for_publish || $item->status !== 'pending'
            || ! is_string($parent->accepted_plan_hash) || ! hash_equals($parent->accepted_plan_hash, Json::hash($payload['expected']))
            || (array_key_exists('proposal_hash', $expected) && (! is_string($expected['proposal_hash'])
                || ! hash_equals($expected['proposal_hash'], Json::hash($proposal))))
            || ! is_string($proposal['html'] ?? null) || ! is_string($proposal['html_hash'] ?? null)
            || ! hash_equals($proposal['html_hash'], hash('sha256', $proposal['html']))
            || ($snapshot['site']['id'] ?? null) !== (int) $payload['site_id'] || ($snapshot['page']['id'] ?? null) !== (int) $page->id
            || ($snapshot['page']['type'] ?? null) !== 'movie' || isset($snapshot['fanmulu'])
            || ($snapshot['content_revision_id'] ?? null) !== $page->working_content_revision_id
            || ! is_array($assignments) || Json::hash($assignments) !== Json::hash($snapshot['assignments'] ?? null)) {
            throw new Conflict('QUICK_APPLY_PROPOSAL_CHANGED');
        }
        if ($page->publish_locked || $item->fingerprint !== app(Planner::class)->fingerprint($page, null, $assignments, app(SnapshotStorage::class)->references($snapshot), ($parentOptions['presentation_only'] ?? false) === true)) {
            throw new Conflict('PREVIEW_STALE');
        }
        $batchId = DB::table('publish_batches')->insertGetId(['public_id' => (string) Str::uuid(), 'site_id' => $payload['site_id'],
            'action' => 'publish', 'status' => 'ready', 'requested_by' => $payload['actor_id'],
            'request_idempotency_key' => $options['idempotency_key'],
            'request_hash' => Json::hash([(int) $payload['site_id'], [(int) $page->id], 'publish', $options]),
            'options_json' => Json::encode($options + ['frozen_parent_item_id' => (int) $item->id]),
            'counts' => Json::encode(['pending' => 1])]);
        DB::table('publish_batch_items')->insert(['batch_id' => $batchId, 'site_id' => $payload['site_id'], 'page_id' => $page->id,
            'status' => 'pending', 'preview_json' => $item->preview_json, 'fingerprint' => $item->fingerprint,
            'expires_at' => now()->addMinutes(30), 'expected_row_version' => $page->row_version,
            'expected_current_publication_id' => null]);

        return DB::table('publish_batches')->find($batchId);
    }

    private function pins(array $assignments): string
    {
        $pins = array_map(fn ($entry) => ['keyword_id' => (int) $entry['keyword_id'], 'role' => $entry['role'], 'ordinal' => (int) $entry['ordinal'], 'text' => $entry['text'], 'target_url' => $entry['target_url'] ?? null], $assignments);
        usort($pins, fn ($a, $b) => [$a['role'], $a['ordinal']] <=> [$b['role'], $b['ordinal']]);

        return Json::hash($pins);
    }

    private function failure(int $itemId, string $reason): void
    {
        DB::table('publish_batch_items')->where('id', $itemId)->where('status', 'pending')->update(['status' => 'stale', 'error_code' => $reason]);
    }

    private function stop(string $eventId, array $payload, string $reason): void
    {
        $cancelled = $reason === 'BATCH_CANCELLED';
        DB::table('publish_batch_items')->where('batch_id', $payload['batch_id'])->where('status', 'pending')->update(['status' => $cancelled ? 'cancelled' : 'stale', 'error_code' => $reason]);
        $this->progress((int) $payload['batch_id']);
        $payload['status'] = $cancelled ? 'cancelled' : 'failed';
        $payload['error'] = $reason;
        DB::table('outbox_events')->where('id', $eventId)->update(['payload_json' => Json::encode($payload), 'delivered_at' => now()]);
    }

    private function progress(int $batchId): void
    {
        $counts = DB::table('publish_batch_items')->where('batch_id', $batchId)->selectRaw('status,count(*) total')->groupBy('status')->pluck('total', 'status')->all();
        $pending = $counts['pending'] ?? 0;
        $success = ($counts['published'] ?? 0) + ($counts['unchanged'] ?? 0);
        $errors = array_sum($counts) - $pending - $success;
        $batch = DB::table('publish_batches')->find($batchId);
        $status = $batch->cancel_requested_at ? 'cancelled' : ($pending ? 'running' : ($errors ? ($success ? 'partial' : 'failed') : 'completed'));
        DB::table('publish_batches')->where('id', $batchId)->update(['counts' => Json::encode($counts), 'status' => $status, 'updated_at' => now()]);
    }
}
