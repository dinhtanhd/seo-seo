<?php

namespace App\Domain\Publishing;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Fanmulu\FanmuluSourceCatalog;
use App\Domain\Keywords\Allocator;
use App\Domain\Rules\RuleResolver;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Accepted first-visit assignments are durable before expensive artifact rendering. */
final class FirstVisitPublisher
{
    public static function lockKey(int $siteId, int $pageId): string
    {
        return 'first-visit:'.$siteId.':'.$pageId;
    }

    public static function deferred(array $proposal): bool
    {
        return ($proposal['first_visit_plan']['schema'] ?? null) === 1
            && ($proposal['first_visit_plan']['materialized'] ?? false) === false;
    }

    public function resolve(int $siteId, int $pageId, int $actorId, ?object &$committedPublication = null): object
    {
        $committedPublication = null;
        $lock = self::lockKey($siteId, $pageId);
        DB::select('SELECT pg_advisory_lock(hashtextextended(?,0))', [$lock]);
        try {
            $current = DB::table('pages')->where('site_id', $siteId)->find($pageId) ?? abort(404);
            if ($current->status === 'live' && $current->current_publication_id) {
                return $current;
            }
            $itemId = $this->prepare($siteId, $pageId, $actorId);
            if (is_string($itemId)) {
                throw new Conflict($itemId);
            }
            $publication = app(Publisher::class)->publishItem($itemId, heldFirstVisitLock: $lock);
            if (! $publication) {
                throw new Conflict('FIRST_VISIT_PUBLICATION_BLOCKED', (string) DB::table('publish_batch_items')->where('id', $itemId)->value('error_code'));
            }

            if (DB::transactionLevel() === 0) {
                $committedPublication = $publication;
            }

            return DB::table('pages')->find($pageId);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
        }
    }

    public function prepare(int $siteId, int $pageId, int $actorId): int|string
    {
        $committedConfiguration = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($siteId, $pageId, $actorId, $committedConfiguration): int|string {
            $global = DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->sharedLock()->first();
            Access::site($actorId, $siteId, true, $site);
            $page = DB::table('pages')->where('site_id', $siteId)->find($pageId) ?? abort(404);
            if (! $site || $site->status !== 'active' || $site->content_type !== 'movie' || $page->status !== 'draft'
                || $page->current_publication_id || $page->publish_locked || $page->page_type !== 'movie') {
                throw new Conflict('FIRST_VISIT_PAGE_UNAVAILABLE');
            }
            $existing = DB::table('publish_batch_items as i')->join('publish_batches as b', 'b.id', '=', 'i.batch_id')
                ->where('i.page_id', $pageId)->where('i.site_id', $siteId)->whereNotNull('b.accepted_at')
                ->whereNull('b.cancel_requested_at')->where('i.accepted_for_publish', true)->whereNotIn('i.status', ['published', 'unchanged', 'cancelled'])
                ->orderBy('i.id')->first(['i.id', 'i.batch_id', 'i.status', 'i.error_code', 'b.options_json']);
            if ($existing) {
                if ($page->page_kind === 'fanmulu' && $existing->status === 'capacity_conflict' && $existing->error_code === 'CAPACITY_CONFLICT'
                    && (Json::decode($existing->options_json)['origin'] ?? null) === 'first_visit') {
                    $batch = DB::table('publish_batches')->where('id', $existing->batch_id)->lockForUpdate()->first();
                    if ($batch->cancel_requested_at === null && $batch->status !== 'cancelled') {
                        DB::table('publish_batch_items')->where(['id' => $existing->id, 'status' => 'capacity_conflict'])
                            ->update(['status' => 'pending', 'error_code' => null]);
                        DB::table('publish_batches')->where('id', $batch->id)->update(['status' => 'queued']);
                    }
                }

                return (int) $existing->id;
            }
            $prior = DB::table('publish_batches')->where(['requested_by' => $actorId, 'request_idempotency_key' => self::lockKey($siteId, $pageId)])->first();
            if ($prior && ! $prior->accepted_at) {
                return DB::table('publish_batch_items')->where('batch_id', $prior->id)->value('error_code') ?? 'ON_DEMAND_PREPARATION_FAILED';
            }
            if (DB::table('page_publications')->where('page_id', $pageId)->exists()) {
                throw new Conflict('FIRST_VISIT_REQUIRES_NEW_PAGE');
            }
            $this->assertEligibility($page, $actorId, $site);
            $resolved = app(RuleResolver::class)->resolve($page, $site);
            $content = Json::decode(DB::table('content_revisions')->where('id', $page->working_content_revision_id)->value('payload_json'));
            $allocator = app(Allocator::class);
            $allocator->prepare($siteId, $committedConfiguration ? [(int) $global->config_epoch, (int) $site->config_epoch, (int) $site->row_version] : null, $resolved,
                checksCapacity: $page->page_kind !== 'fanmulu');
            $allocator->prime($page, $content, $resolved);
            $fingerprintContext = app(Planner::class)->firstVisitContext($page, $resolved, $site, (int) $global->config_epoch);
            $policyKeys = [];
            foreach (['primary', 'secondary', 'anchor'] as $role) {
                $policy = $resolved['slots']['allocation.'.$role] ?? false;
                if (is_array($policy) && ($policy['enabled'] ?? true) && (int) ($policy['count'] ?? 1) > 0
                    && ! $page->assignment_locked && ($policy['mode'] ?? null) === 'sequential') {
                    $policyKeys[] = Json::hash([$siteId, $role, $resolved['sources']['allocation.'.$role], $policy]);
                }
            }
            $starts = [];
            if ($policyKeys !== []) {
                $states = DB::table('allocation_state')->whereIn('policy_key', $policyKeys);
                $missing = array_diff($policyKeys, (clone $states)->pluck('policy_key')->all());
                if ($missing !== []) {
                    DB::table('allocation_state')->insertOrIgnore(array_values(array_map(fn ($key) => ['policy_key' => $key], $missing)));
                }
                foreach ($states->orderBy('id')->lockForUpdate()->get() as $state) {
                    $starts[$state->policy_key] = ['state_id' => $state->id, 'start' => $state->cursor_position,
                        'end' => $state->cursor_position, 'cursor_revision' => $state->cursor_revision];
                }
            }
            $fresh = DB::table('pages')->where('id', $pageId)->lockForUpdate()->first();
            if ($fresh->row_version !== $page->row_version || $fresh->current_publication_id !== null) {
                throw new Conflict('FIRST_VISIT_PAGE_CHANGED');
            }
            $simulation = $sequential = [];
            $seed = bin2hex(random_bytes(16));
            try {
                $assignments = $allocator->allocate($page, $content, $resolved, $seed, 'publish', ['_sequential_start' => $starts], $simulation, $sequential);
            } catch (Conflict $error) {
                if ($page->page_kind === 'fanmulu') {
                    $selected = Json::decode($page->input_snapshot_json)['fanmulu']['selected_primary'] ?? null;
                    throw new Conflict($selected ? 'FANMULU_ASSIGNMENT_UNAVAILABLE' : 'FANMULU_NO_KEYWORD', $error->reason);
                }
                $batchId = DB::table('publish_batches')->insertGetId(['public_id' => (string) Str::uuid(), 'site_id' => $siteId,
                    'action' => 'publish', 'status' => 'ready', 'requested_by' => $actorId, 'request_idempotency_key' => self::lockKey($siteId, $pageId),
                    'request_hash' => Json::hash([$siteId, $pageId, $error->reason]), 'options_json' => Json::encode(['origin' => 'first_visit']),
                    'counts' => Json::encode(['error' => 1])]);
                DB::table('publish_batch_items')->insert(['batch_id' => $batchId, 'site_id' => $siteId, 'page_id' => $pageId,
                    'status' => 'error', 'preview_json' => '{}', 'expires_at' => now()->addMinutes(30), 'expected_row_version' => $page->row_version,
                    'expected_current_publication_id' => null, 'error_code' => $error->reason]);

                return $error->reason;
            }
            if ($page->page_kind === 'fanmulu') {
                $input = Json::decode($page->input_snapshot_json);
                $name = collect($assignments)->firstWhere('purpose', 'fanmulu_title') ?? collect($assignments)->firstWhere('role', 'primary');
                if (! $name) {
                    throw new Conflict('FANMULU_NO_KEYWORD');
                }
                $selected = $input['fanmulu']['selected_primary'] ?? null;
                if ($selected && ((int) $selected['keyword_id'] !== (int) $name['keyword_id'] || $selected['text'] !== $name['text'])) {
                    throw new Conflict('FANMULU_ASSIGNMENT_UNAVAILABLE');
                }
                if (! $selected) {
                    $input['fanmulu']['selected_primary'] = ['keyword_id' => (int) $name['keyword_id'], 'text' => $name['text']];
                    DB::table('pages')->where('id', $pageId)->update(['input_snapshot_json' => Json::encode($input), 'input_hash' => Json::hash($input), 'row_version' => DB::raw('row_version+1')]);
                    $page = DB::table('pages')->find($pageId);
                    $resolved = app(RuleResolver::class)->resolve($page, $site);
                }
            }
            $context = app(Planner::class)->firstVisitFingerprint($fingerprintContext, $page, $resolved, $allocator->selectedRows(array_column($assignments, 'keyword_id')));
            $proposal = ['assignments' => $assignments, 'snapshot' => [], 'html' => '', 'html_hash' => hash('sha256', ''), 'before' => null, 'after' => [],
                'first_visit_plan' => ['schema' => 1, 'materialized' => false, 'context_hash' => $context, 'resolved' => $resolved]];

            return app(Publisher::class)->persistFirstVisitPlan($page, $actorId, $seed, $proposal, $sequential);
        }, 3);
    }

    public function fingerprint(object $page, array $assignments, ?array $resolved = null): string
    {
        return app(Planner::class)->fingerprint($page, $resolved, $assignments, []);
    }

    public function assertEligibility(object $page, int $actorId, ?object $site = null): void
    {
        if ($page->page_kind === 'regular') {
            $delivery = DB::table('site_delivery_settings')->where('site_id', $page->site_id)->first();
            if (! app(SiteDeliverySettings::class)->enabled((int) $page->site_id) || (int) ($delivery?->actor_id ?? 0) !== $actorId
                || ! in_array((int) $page->id, app(OnDemandEligibility::class)->pageIds([(int) $page->site_id], [(int) $page->id]), true)) {
                throw new Conflict('ON_DEMAND_SOURCE_UNAVAILABLE');
            }
        } elseif ($page->page_kind === 'fanmulu') {
            $settings = FanmuluSettings::fromSite($site ?? DB::table('sites')->find($page->site_id));
            if (! $settings['enabled'] || (int) $settings['actor_id'] !== $actorId
                || ! app(FanmuluSourceCatalog::class)->canServe($page)) {
                throw new Conflict('FANMULU_SOURCE_UNAVAILABLE');
            }
        } else {
            throw new Conflict('FIRST_VISIT_PAGE_UNAVAILABLE');
        }
    }

    public function renderProposal(object $page, array $minimal, bool $contextChecked = false): array
    {
        $plan = $minimal['first_visit_plan'];
        if (! $contextChecked && $page->page_kind !== 'fanmulu' && $this->fingerprint($page, $minimal['assignments']) !== $plan['context_hash']) {
            throw new Conflict('PREVIEW_STALE');
        }
        $content = Json::decode(DB::table('content_revisions')->where('id', $page->working_content_revision_id)->value('payload_json'));
        $artifact = (Json::decode($page->input_snapshot_json)['fanmulu']['render_detail_once'] ?? false) === true ? app(FanmuluDetailArtifact::class) : null;
        $snapshot = app(Renderer::class)->snapshot($page, $content, $minimal['assignments'], $plan['resolved'], deferFanmuluRecommendations: $artifact !== null);
        $projection = $artifact?->prepare($snapshot, $page) ?? $snapshot;
        $html = app(Renderer::class)->html($projection);
        $snapshot = $artifact?->frozenSnapshot($projection, $snapshot) ?? $snapshot;
        $proposal = ['snapshot' => app(SnapshotStorage::class)->compact($snapshot, true), 'html' => $html, 'html_hash' => hash('sha256', $html),
            'assignments' => $minimal['assignments'], 'before' => null, 'after' => $snapshot['slots'],
            'first_visit_plan' => array_replace($plan, ['materialized' => true])];

        return ['proposal' => $proposal, 'entries' => $snapshot['aggregate']['entries'] ?? []];
    }

    public function materialize(array $claim): array
    {
        if (! self::deferred($claim['proposal'])) {
            return $claim;
        }
        $item = DB::table('publish_batch_items')->find($claim['item_id']);
        $page = DB::table('pages')->find($item->page_id);
        ['proposal' => $proposal, 'entries' => $entries] = $this->renderProposal($page, $claim['proposal']);
        $plan = $claim['proposal']['first_visit_plan'];

        return DB::transaction(function () use ($claim, $page, $plan, $proposal, $entries): array {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $page->site_id)->sharedLock()->first();
            $batch = DB::table('publish_batches')->where('id', $claim['batch_id'])->lockForUpdate()->first();
            $item = DB::table('publish_batch_items')->where('id', $claim['item_id'])->lockForUpdate()->first();
            $current = DB::table('pages')->where('id', $page->id)->lockForUpdate()->first();
            $lease = DB::table('page_publish_leases')->where('page_id', $page->id)->lockForUpdate()->first();
            if (! $lease || $lease->owner_item_id !== $item->id || $lease->fencing_token !== $claim['fence'] || now()->greaterThan($lease->expires_at)) {
                throw new Conflict('FENCE_LOST');
            }
            if ($item->status !== 'rendering') {
                throw new Conflict('PREVIEW_STALE');
            }
            $fingerprint = app(Planner::class)->materializedFirstVisitFingerprint($current, $proposal['assignments'],
                $plan['context_hash'], app(SnapshotStorage::class)->references($proposal['snapshot']));
            $this->assertEligibility($current, (int) $batch->requested_by);
            $this->assertRecommendationHeads((int) $page->site_id, $entries);
            DB::table('publish_batch_items')->where('id', $item->id)->update(['preview_json' => Json::encode($proposal), 'fingerprint' => $fingerprint]);
            $claim['proposal'] = $proposal;

            return $claim;
        }, 3);
    }

    public function assertRecommendationHeads(int $siteId, array $entries): void
    {
        $references = array_filter($entries, fn ($entry) => isset($entry['page_id'], $entry['publication_id']));
        if ($references === []) {
            return;
        }
        $heads = DB::table('pages')->where('site_id', $siteId)->whereIn('id', array_column($references, 'page_id'))
            ->orderBy('id')->sharedLock()->get(['id', 'status', 'current_publication_id'])->keyBy('id');
        foreach ($references as $reference) {
            $head = $heads->get($reference['page_id']);
            if (! $head || $head->status !== 'live' || (int) $head->current_publication_id !== (int) $reference['publication_id']) {
                throw new Conflict('PREVIEW_STALE');
            }
        }
    }
}
