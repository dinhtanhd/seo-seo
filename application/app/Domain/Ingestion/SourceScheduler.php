<?php

namespace App\Domain\Ingestion;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SourceScheduler
{
    public array $lastErrors = [];

    public function recoverWork(): int
    {
        $count = 0;
        $runs = DB::table('ingest_runs')->where(function ($q) {
            $q->where(function ($q) {
                $q->where('status', 'queued')->where('updated_at', '<', now()->subMinutes(5));
            })->orWhere(function ($q) {
                $q->where('status', 'running')->where('lease_until', '<', now());
            })->orWhere(function ($q) {
                $q->where('status', 'retry_wait')->where(function ($q) {
                    $q->where('next_attempt_at', '<=', now())->orWhere(function ($q) {
                        $q->whereNull('next_attempt_at')->where('updated_at', '<', now()->subMinutes(5));
                    });
                });
            });
        })->limit(100)->get();
        foreach ($runs as $initial) {
            DB::transaction(function () use ($initial, &$count) {
                $source = DB::table('source_connectors')->where('id', $initial->connector_id)->lockForUpdate()->first();
                $run = DB::table('ingest_runs')->where('id', $initial->id)->lockForUpdate()->first();
                if (! $source || $source->status !== 'active' || $source->deleted_at !== null || ! in_array($run->status, ['queued', 'running', 'retry_wait'], true)
                    || ($run->lease_until && now()->lt($run->lease_until)) || ($run->next_attempt_at && now()->lt($run->next_attempt_at))) {
                    return;
                }
                $pending = DB::table('outbox_events')->whereIn('event_type', ['crawl.requested', 'import.requested'])->where('aggregate_id', $run->id)->whereNull('delivered_at')->exists();
                if ($pending) {
                    return;
                }
                $isImport = DB::table('outbox_events')->where('event_type', 'import.requested')->where('aggregate_id', $run->id)->whereNotNull('payload_json->records')->exists();
                DB::table('ingest_runs')->where('id', $run->id)->update(['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'updated_at' => now()]);
                $payload = ['run_id' => $run->id, 'max_pages' => 3, 'resume_only' => true];
                if ($run->crawl_mode !== null) {
                    $payload['expected_pages'] = (int) (json_decode($run->counts ?? '{}', true)['fetched_pages'] ?? 0);
                }
                DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => $isImport ? 'import.requested' : 'crawl.requested',
                    'aggregate_id' => $run->id, 'payload_json' => json_encode($payload), 'created_at' => now()]);
                $count++;
            }, 3);
        }

        return $count;
    }

    public function dispatchDue(): int
    {
        $dispatched = 0;
        $this->lastErrors = [];
        $now = CarbonImmutable::now();
        foreach (DB::table('source_connectors')->where('status', 'active')->whereNull('deleted_at')->whereIn('kind', ['maccms', 'shandian', 'html'])->get() as $initial) {
            $attemptedSource = $initial;
            $key = null;
            try {
                DB::transaction(function () use ($initial, $now, &$dispatched, &$attemptedSource, &$key) {
                    $source = DB::table('source_connectors')->where('id', $initial->id)->lockForUpdate()->first();
                    if (! $source || $source->status !== 'active' || $source->deleted_at !== null) {
                        return;
                    }
                    $attemptedSource = $source;
                    $key = SourceSchedule::dueKey(SourceSchedule::forSource($source), $now);
                    if ($key === null) {
                        return;
                    }
                    $exists = DB::table('ingest_runs')->where('connector_id', $source->id)->where('idempotency_key', $key)->exists();
                    $busy = DB::table('ingest_runs')->where('connector_id', $source->id)->whereIn('status', ['queued', 'running', 'retry_wait'])->exists();
                    if ($exists || $busy) {
                        return;
                    }
                    // A daily update starts at page 1 without changing an initial
                    // backfill cursor. Busy sources remain due until they finish.
                    app(CrawlService::class)->queue($source->id, $key, 3, 'latest', (int) $source->latest_max_pages);
                    $dispatched++;
                }, 3);
            } catch (\Throwable $e) {
                $this->recordFailure($attemptedSource, $key, $e);
            }
        }

        return $dispatched;
    }

    private function recordFailure(object $attempted, ?string $key, \Throwable $exception): void
    {
        $error = 'SOURCE_CONFIGURATION_INVALID: '.mb_substr($exception->getMessage(), 0, 1800);
        $inserted = DB::transaction(function () use ($attempted, $key, $error): bool {
            $source = DB::table('source_connectors')->where('id', $attempted->id)->lockForUpdate()->first();
            if (! $source || $source->status !== 'active' || $source->deleted_at !== null
                || $source->config_json !== $attempted->config_json || $source->schedule_json !== $attempted->schedule_json) {
                return false;
            }
            $failureKey = $key ?? 'scheduled:invalid:'.hash('sha256', $source->config_json.'|'.($source->schedule_json ?? ''));

            return DB::table('ingest_runs')->insertOrIgnore(['public_id' => (string) Str::uuid(), 'connector_id' => $source->id,
                'idempotency_key' => $failureKey,
                'request_hash' => hash('sha256', $source->config_json), 'crawl_mode' => 'latest', 'page_limit' => (int) $source->latest_max_pages,
                'status' => 'failed', 'counts' => '{}', 'error' => $error, 'created_at' => now(), 'updated_at' => now()]) > 0;
        }, 3);
        if ($inserted) {
            $this->lastErrors[] = ['connector_id' => $attempted->id, 'error' => $error];
            report($exception);
        }
    }
}
