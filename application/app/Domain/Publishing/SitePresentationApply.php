<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Publishing\RunSitePresentationApply;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Durable, bounded refresh of existing publications after a website presentation change. */
final class SitePresentationApply
{
    public const EVENT = 'site-presentation.requested';

    public const CHUNK = 10;

    private const RETRIES = 3;

    public function request(int $siteId, int $actorId, string $key, ?int $expectedRowVersion = null): object
    {
        Access::site($actorId, $siteId, true);
        if (trim($key) === '' || strlen($key) > 160) {
            throw new \InvalidArgumentException('Mã yêu cầu đổi giao diện không hợp lệ.');
        }

        return DB::transaction(function () use ($siteId, $actorId, $key, $expectedRowVersion): object {
            $global = DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            Access::site($actorId, $siteId, true);
            if ($site->status !== 'active') {
                throw new Conflict('SITE_NOT_ACTIVE');
            }
            if ($expectedRowVersion !== null && $site->row_version !== $expectedRowVersion) {
                throw new Conflict('SITE_PRESENTATION_VERSION_CONFLICT');
            }
            $hash = Json::hash([$siteId, $actorId, $site->row_version, $site->config_epoch, $global->config_epoch]);
            $existing = DB::table('outbox_events')->where('event_type', self::EVENT)->where('aggregate_id', $siteId)
                ->whereRaw("payload_json->>'request_key' = ?", [$key])->first();
            if ($existing) {
                if ((Json::decode($existing->payload_json)['request_hash'] ?? null) !== $hash) {
                    throw new Conflict('IDEMPOTENCY_PAYLOAD_CONFLICT');
                }

                return $existing;
            }
            $home = DB::table('pages')->where('site_id', $siteId)->where('page_type', 'home')
                ->whereNotIn('status', ['archived', 'gone'])->orderByRaw("CASE WHEN status='live' AND current_publication_id IS NOT NULL THEN 0 ELSE 1 END")
                ->orderBy('id')->first(['id']);
            $id = (string) Str::uuid();
            $payload = ['schema_version' => 1, 'status' => 'queued', 'site_id' => $siteId, 'actor_id' => $actorId,
                'site_row_version' => (int) $site->row_version, 'site_epoch' => (int) $site->config_epoch,
                'global_epoch' => (int) $global->config_epoch, 'request_key' => $key, 'request_hash' => $hash,
                'requested_at' => DB::selectOne('SELECT clock_timestamp() AS requested_at')->requested_at, 'updated_at' => now()->toISOString(),
                'max_live_id' => (int) DB::table('pages')->where('site_id', $siteId)->where('status', 'live')->whereNotNull('current_publication_id')->max('id'),
                'cursor' => 0, 'home' => ['page_id' => $home?->id, 'status' => $home ? 'pending' : 'none', 'error_code' => null, 'batch_id' => null],
                'stats' => ['processed' => 0, 'published' => 0, 'unchanged' => 0, 'errors' => 0, 'skipped' => 0], 'failures' => []];
            DB::table('outbox_events')->insert(['id' => $id, 'event_type' => self::EVENT, 'aggregate_id' => $siteId, 'payload_json' => Json::encode($payload)]);
            Access::audit('site.presentation.apply.requested', $actorId, $siteId, ['event_id' => $id, 'max_live_id' => $payload['max_live_id']]);
            DB::afterCommit(function () use ($id): void {
                try {
                    if ($this->process($id, homeOnly: true)) {
                        RunSitePresentationApply::dispatch($id)->onQueue('publish');
                    }
                } catch (\Throwable $error) {
                    report($error);
                }
            });

            return DB::table('outbox_events')->find($id);
        }, 3);
    }

    public function latest(int $siteId, int $actorId): ?object
    {
        Access::site($actorId, $siteId);

        return DB::table('outbox_events')->where('event_type', self::EVENT)->where('aggregate_id', $siteId)
            ->orderByRaw("(payload_json->>'requested_at')::timestamptz DESC")->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function dispatchPending(int $limit = 50): int
    {
        $events = DB::table('outbox_events')->where('event_type', self::EVENT)->whereNull('delivered_at')
            ->orderBy('created_at')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($events as $id) {
            RunSitePresentationApply::dispatch($id)->onQueue('publish');
        }

        return $events->count();
    }

    /** Return whether work remains; competing duplicate jobs need no further rescheduling. */
    public function process(string $eventId, bool $homeOnly = false): bool
    {
        $lock = 'site-presentation-apply:'.$eventId;
        if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) acquired', [$lock])->acquired) {
            return false;
        }
        try {
            $event = $this->event($eventId);
            if (! $event || $event->delivered_at !== null) {
                return false;
            }
            $payload = Json::decode($event->payload_json);
            $this->context($payload);
            if ($payload['home']['status'] === 'pending') {
                $this->page($eventId, (int) $payload['home']['page_id'], true);
            }
            if (! $homeOnly) {
                $payload = Json::decode($this->event($eventId)->payload_json);
                foreach ($this->remaining($payload)->limit(self::CHUNK)->pluck('id') as $id) {
                    $this->page($eventId, (int) $id, false);
                }
            }
            $payload = Json::decode($this->event($eventId)->payload_json);
            if ($this->remaining($payload)->exists()) {
                return true;
            }
            $payload['status'] = $payload['stats']['errors'] ? 'partial' : 'completed';
            unset($payload['error_code']);
            $payload['updated_at'] = now()->toISOString();
            DB::table('outbox_events')->where('id', $eventId)->whereNull('delivered_at')->update(['payload_json' => Json::encode($payload), 'delivered_at' => now()]);

            return false;
        } catch (Conflict $error) {
            if ($error->reason === 'LEASE_BUSY') {
                return true;
            }
            $this->stop($eventId, 'stale', $error->reason);

            return false;
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 403) {
                report($error);

                return $this->retry($eventId);
            }
            $this->stop($eventId, 'failed', 'SITE_PRESENTATION_PERMISSION_CHANGED');

            return false;
        } catch (\Throwable $error) {
            report($error);

            return $this->retry($eventId);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
        }
    }

    private function event(string $id): ?object
    {
        return DB::table('outbox_events')->where('id', $id)->where('event_type', self::EVENT)->first();
    }

    private function context(array $payload): void
    {
        Access::site((int) $payload['actor_id'], (int) $payload['site_id'], true);
        $site = DB::table('sites')->find($payload['site_id']);
        if (! $site || $site->status !== 'active' || (int) $site->row_version !== $payload['site_row_version']
            || (int) $site->config_epoch !== $payload['site_epoch']
            || (int) DB::table('system_settings')->where('id', 1)->value('config_epoch') !== $payload['global_epoch']) {
            throw new Conflict('SITE_PRESENTATION_CONFIGURATION_CHANGED');
        }
    }

    private function remaining(array $payload): Builder
    {
        return DB::table('pages')->where('site_id', $payload['site_id'])->where('status', 'live')->whereNotNull('current_publication_id')
            ->where('id', '>', $payload['cursor'])->where('id', '<=', $payload['max_live_id'])
            ->when($payload['home']['page_id'], fn ($query) => $query->where('id', '<>', $payload['home']['page_id']))->orderBy('id');
    }

    private function page(string $eventId, int $pageId, bool $home): void
    {
        try {
            DB::transaction(function () use ($eventId, $pageId, $home): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                $payload = Json::decode($this->event($eventId)->payload_json);
                DB::table('sites')->where('id', $payload['site_id'])->sharedLock()->first();
                if ($this->event($eventId)->delivered_at !== null) {
                    return;
                }
                $this->context($payload);
                $page = DB::table('pages')->where('site_id', $payload['site_id'])->where('id', $pageId)->lockForUpdate()->first();
                $draftHome = $home && $page && $page->page_type === 'home' && $page->status === 'draft' && $page->current_publication_id === null;
                if (! $page || (! $draftHome && ($page->status !== 'live' || $page->current_publication_id === null))) {
                    $this->record($eventId, $pageId, $home, 'skipped', 'SITE_PRESENTATION_PAGE_NO_LONGER_LIVE');

                    return;
                }
                if (! $draftHome) {
                    $publication = DB::table('page_publications')->where(['id' => $page->current_publication_id, 'page_id' => $pageId, 'site_id' => $payload['site_id']])->first(['activated_at']);
                    if (! $publication || CarbonImmutable::parse($publication->activated_at)->greaterThan(CarbonImmutable::parse($payload['requested_at']))) {
                        $this->record($eventId, $pageId, $home, 'skipped', 'SITE_PRESENTATION_NEWER_PUBLICATION');

                        return;
                    }
                }
                $batch = app(QuickApply::class)->apply((int) $payload['site_id'], [$pageId], (int) $payload['actor_id'],
                    'site-presentation:'.$eventId.':page:'.$pageId, $draftHome ? [] : ['presentation_only' => true]);
                $item = DB::table('publish_batch_items')->where('batch_id', $batch->id)->where('page_id', $pageId)->first();
                if ($item?->status === 'pending' && $item->accepted_for_publish) {
                    app(Publisher::class)->publishItem((int) $item->id);
                    $item = DB::table('publish_batch_items')->find($item->id);
                }
                $status = in_array($item?->status, ['published', 'unchanged'], true) ? $item->status : 'error';
                $this->record($eventId, $pageId, $home, $status, $status === 'error' ? ($item?->error_code ?? 'SITE_PRESENTATION_PAGE_NOT_APPLIED') : null, (int) $batch->id);
            }, 3);
        } catch (Conflict $error) {
            if (in_array($error->reason, ['LEASE_BUSY', 'SITE_PRESENTATION_CONFIGURATION_CHANGED'], true)) {
                throw $error;
            }
            $this->record($eventId, $pageId, $home, 'error', $error->reason);
        } catch (\InvalidArgumentException $error) {
            $this->record($eventId, $pageId, $home, 'error', $error->getMessage());
        }
    }

    private function record(string $eventId, int $pageId, bool $home, string $status, ?string $reason = null, ?int $batchId = null): void
    {
        DB::transaction(function () use ($eventId, $pageId, $home, $status, $reason, $batchId): void {
            $event = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->delivered_at !== null) {
                return;
            }
            $payload = Json::decode($event->payload_json);
            if (($home && $payload['home']['status'] !== 'pending') || (! $home && $payload['cursor'] >= $pageId)) {
                return;
            }
            $payload['stats']['processed']++;
            $payload['stats'][['published' => 'published', 'unchanged' => 'unchanged', 'error' => 'errors', 'skipped' => 'skipped'][$status]]++;
            $payload['status'] = 'processing';
            $payload['updated_at'] = now()->toISOString();
            if ($home) {
                $payload['home'] = ['page_id' => $pageId, 'status' => $status, 'error_code' => $reason, 'batch_id' => $batchId];
            } else {
                $payload['cursor'] = max($payload['cursor'], $pageId);
            }
            if ($status === 'error' && count($payload['failures']) < 50) {
                $payload['failures'][] = ['page_id' => $pageId, 'error_code' => $reason, 'batch_id' => $batchId];
            }
            DB::table('outbox_events')->where('id', $eventId)->update(['payload_json' => Json::encode($payload)]);
        }, 3);
    }

    private function stop(string $eventId, string $status, string $reason): void
    {
        DB::transaction(function () use ($eventId, $status, $reason): void {
            $event = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->delivered_at !== null) {
                return;
            }
            $payload = Json::decode($event->payload_json);
            $payload['status'] = $status;
            $payload['error_code'] = $reason;
            $payload['updated_at'] = now()->toISOString();
            DB::table('outbox_events')->where('id', $eventId)->update(['payload_json' => Json::encode($payload), 'delivered_at' => now()]);
        }, 3);
    }

    /** Retry from the durable cursor without exposing exception text or unbounded job recovery loops. */
    private function retry(string $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->delivered_at !== null) {
                return false;
            }
            $attempts = (int) $event->attempts + 1;
            $retry = $attempts < self::RETRIES;
            $payload = Json::decode($event->payload_json);
            $payload['status'] = $retry ? 'retrying' : 'failed';
            $payload['error_code'] = $retry ? 'SITE_PRESENTATION_RETRY_PENDING' : 'SITE_PRESENTATION_PROCESSING_FAILED';
            $payload['updated_at'] = now()->toISOString();
            DB::table('outbox_events')->where('id', $eventId)->update(['payload_json' => Json::encode($payload),
                'attempts' => $attempts, 'delivered_at' => $retry ? null : now()]);

            return $retry;
        }, 3);
    }
}
