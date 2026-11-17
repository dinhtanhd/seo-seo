<?php

namespace App\Domain\Keywords;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Keywords\ImportKeywords;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KeywordImportService
{
    public function __construct(private readonly KeywordService $keywords = new KeywordService) {}

    public function authorize(int $poolId, int $actorId, bool $write = true): object
    {
        $pool = DB::table('keyword_pools')->where('id', $poolId)->first();
        if (! $pool) {
            abort(404);
        }
        $actor = DB::table('users')->where('id', $actorId)->first();
        if (! $actor) {
            abort(403);
        }
        if ($actor->is_global_admin) {
            return $pool;
        }
        if ($pool->scope !== 'site' || ! $pool->owner_site_id) {
            abort(403);
        }
        Access::site($actorId, (int) $pool->owner_site_id, $write);

        return $pool;
    }

    public function queueImport(int $poolId, array $rows, array $defaults, int $actorId, string $idempotencyKey, bool $dryRun = false): object
    {
        $this->authorize($poolId, $actorId);
        if (count($rows) > 100000 || ! $idempotencyKey || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('Tối đa 100.000 dòng và cần idempotency key hợp lệ.');
        }
        $payload = ['rows' => $rows, 'defaults' => $defaults, 'dry_run' => $dryRun];
        $encoded = Json::encode($payload);
        if (strlen($encoded) > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('Payload keyword import vượt 20 MB.');
        }
        $hash = Json::hash($payload);

        $run = DB::transaction(function () use ($poolId, $rows, $defaults, $actorId, $idempotencyKey, $dryRun, $hash) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $this->authorize($poolId, $actorId);
            $existing = DB::table('keyword_import_runs')->where('pool_id', $poolId)->where('actor_id', $actorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new Conflict('IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            $id = DB::table('keyword_import_runs')->insertGetId(['pool_id' => $poolId, 'actor_id' => $actorId, 'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash, 'status' => 'queued', 'cursor' => 0, 'dry_run' => $dryRun,
                'counts' => Json::encode(['total' => count($rows), 'processed' => 0, 'new' => 0, 'duplicate' => 0, 'blank' => 0, 'restored' => 0, 'error' => 0]),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => 'keyword-import.requested', 'aggregate_id' => $id,
                'payload_json' => Json::encode(['run_id' => $id, 'rows' => $rows, 'defaults' => $defaults, 'dry_run' => $dryRun]), 'created_at' => now()]);

            return DB::table('keyword_import_runs')->where('id', $id)->first();
        }, 3);

        $this->dispatchAfterCommit((int) $run->id);

        return $run;
    }

    public function execute(int $runId, int $maxChunks = 4, ?string $outboxEventId = null): object
    {
        $run = DB::table('keyword_import_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new \InvalidArgumentException('Không tìm thấy keyword import.');
        }
        if (in_array($run->status, ['succeeded', 'partial'], true)) {
            return $run;
        }
        $event = DB::table('outbox_events')->where('event_type', 'keyword-import.requested')->where('aggregate_id', $runId)->whereNotNull('payload_json->rows')->first();
        if (! $event) {
            throw new \RuntimeException('Không tìm thấy payload keyword import bền vững.');
        }
        $payload = Json::decode($event->payload_json);
        if (Json::hash(['rows' => $payload['rows'], 'defaults' => $payload['defaults'], 'dry_run' => $payload['dry_run']]) !== $run->request_hash) {
            throw new \RuntimeException('Keyword import payload không khớp request hash.');
        }
        try {
            for ($chunk = 0; $chunk < min(4, max(1, $maxChunks)); $chunk++) {
                $done = DB::transaction(function () use ($runId, $payload) {
                    // Shared lock order with keyword writes; no pool lock is acquired before system/run.
                    DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
                    $run = DB::table('keyword_import_runs')->where('id', $runId)->lockForUpdate()->first();
                    if (in_array($run->status, ['succeeded', 'partial'], true)) {
                        return true;
                    }
                    $pool = $this->authorize((int) $run->pool_id, (int) $run->actor_id);
                    $offset = (int) $run->cursor;
                    $slice = array_slice($payload['rows'], $offset, 250);
                    $safeSlice = array_map(fn ($raw) => is_array($raw) || is_string($raw) ? $raw : ['_error' => 'Dòng JSON phải là object hoặc chuỗi keyword.'], $slice);
                    $identities = [];
                    foreach ($safeSlice as $raw) {
                        $row = array_merge($payload['defaults'], is_string($raw) ? ['text' => $raw] : $raw);
                        $text = $row['text'] ?? $row['original_text'] ?? '';
                        if (! is_scalar($text)) {
                            continue;
                        }
                        try {
                            $text = KeywordService::normalize((string) $text);
                            if ($text !== '') {
                                $identities[] = Json::hash([$row['role'] ?? 'primary', $row['locale'] ?? $pool->locale, $text]);
                            }
                        } catch (\InvalidArgumentException) { /* The importer records the row error below. */
                        }
                    }
                    $seen = DB::table('keyword_import_seen')->where('run_id', $runId)->whereIn('identity_key', $identities)->pluck('identity_key')->all();
                    DB::table('keyword_import_runs')->where('id', $runId)->update(['status' => 'running', 'updated_at' => now()]);
                    $report = $this->keywords->import((int) $run->pool_id, $safeSlice, $payload['defaults'], (bool) $run->dry_run, $seen);
                    $reported = [];
                    foreach ($report['rows'] as $entry) {
                        $localLine = (int) $entry['line'];
                        $reported[$localLine] = true;
                        $this->storeRow($runId, $offset + $localLine, $entry, $slice[$localLine - 1] ?? null);
                    }
                    // Keep one report row per input line, including blank lines on older parser versions.
                    foreach ($slice as $index => $raw) {
                        if (! isset($reported[$index + 1])) {
                            $this->storeRow($runId, $offset + $index + 1, ['status' => 'blank', 'text' => ''], $raw);
                        }
                    }
                    $counts = Json::decode($run->counts);
                    foreach (['new', 'duplicate', 'blank', 'restored', 'error'] as $status) {
                        $counts[$status] = ($counts[$status] ?? 0) + ($report[$status] ?? 0);
                    }
                    $cursor = $offset + count($slice);
                    $counts['processed'] = $cursor;
                    unset($counts['failure_error']);
                    $done = $cursor >= count($payload['rows']);
                    DB::table('keyword_import_runs')->where('id', $runId)->update(['cursor' => $cursor, 'counts' => Json::encode($counts),
                        'status' => $done ? ($counts['error'] > 0 ? 'partial' : 'succeeded') : 'queued', 'error' => null, 'updated_at' => now()]);

                    return $done;
                }, 3);
                if ($done) {
                    break;
                }
            }
            if (! $done) {
                $eventId = $this->enqueueResume($runId, $outboxEventId);
                if ($eventId !== null) {
                    $this->dispatchAfterCommit($runId, $eventId);
                }
            }
        } catch (\Throwable $e) {
            DB::transaction(function () use ($runId, $e) {
                DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
                $run = DB::table('keyword_import_runs')->where('id', $runId)->lockForUpdate()->first();
                if (! in_array($run->status, ['succeeded', 'partial'], true)) {
                    $counts = Json::decode($run->counts);
                    $counts['failure_error'] = mb_substr($e->getMessage(), 0, 1000);
                    DB::table('keyword_import_runs')->where('id', $runId)->update(['status' => 'failed', 'error' => $counts['failure_error'], 'counts' => Json::encode($counts), 'updated_at' => now()]);
                }
            }, 3);
            throw $e;
        }

        return DB::table('keyword_import_runs')->where('id', $runId)->first();
    }

    private function storeRow(int $runId, int $line, array $entry, mixed $raw): void
    {
        $identity = $entry['identity_key'] ?? null;
        DB::table('keyword_import_rows')->insertOrIgnore(['run_id' => $runId, 'row_no' => $line, 'status' => $entry['status'],
            'normalized_text' => $entry['text'] ?? null, 'identity_key' => $identity, 'error' => $entry['error'] ?? null,
            'data_json' => Json::encode(is_array($raw) ? $raw : ['text' => $raw])]);
        if ($identity && $entry['status'] !== 'error') {
            DB::table('keyword_import_seen')->insertOrIgnore(['run_id' => $runId, 'identity_key' => $identity]);
        }
    }

    private function enqueueResume(int $runId, ?string $executingEventId = null): ?string
    {
        return DB::transaction(function () use ($runId, $executingEventId) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $run = DB::table('keyword_import_runs')->where('id', $runId)->lockForUpdate()->first();
            if (in_array($run->status, ['succeeded', 'partial'], true)) {
                return null;
            }
            $eventId = DB::table('outbox_events')->where('event_type', 'keyword-import.requested')->where('aggregate_id', $runId)->whereNull('delivered_at')
                ->when($executingEventId !== null, fn ($query) => $query->where('id', '!=', $executingEventId))->value('id');
            if ($eventId === null) {
                $eventId = (string) Str::uuid();
                DB::table('outbox_events')->insert(['id' => $eventId, 'event_type' => 'keyword-import.requested', 'aggregate_id' => $runId,
                    'payload_json' => Json::encode(['run_id' => $runId, 'resume_only' => true]), 'created_at' => now()]);
            }
            DB::table('keyword_import_runs')->where('id', $runId)->update(['status' => 'queued', 'updated_at' => now()]);

            return $eventId;
        }, 3);
    }

    public function retry(int $runId, int $actorId): object
    {
        $run = DB::table('keyword_import_runs')->where('id', $runId)->first();
        if (! $run) {
            abort(404);
        }
        $this->authorize((int) $run->pool_id, $actorId);
        if (! in_array($run->status, ['succeeded', 'partial'], true)) {
            $eventId = $this->enqueueResume($runId);
            if ($eventId !== null) {
                $this->dispatchAfterCommit($runId, $eventId);
            }
        }

        return DB::table('keyword_import_runs')->where('id', $runId)->first();
    }

    public function report(int $runId, int $actorId, int $page = 1): array
    {
        $run = DB::table('keyword_import_runs')->where('id', $runId)->first();
        if (! $run) {
            abort(404);
        }
        $this->authorize((int) $run->pool_id, $actorId, false);

        return ['run' => $run, 'rows' => DB::table('keyword_import_rows')->where('run_id', $runId)->orderBy('row_no')->paginate(100, ['*'], 'page', max(1, $page))];
    }

    public function dispatchPending(?int $runId = null, ?string $eventId = null): int
    {
        $count = 0;
        while ($count < 100) {
            $event = DB::table('outbox_events')->where('event_type', 'keyword-import.requested')->whereNull('delivered_at')
                ->when($runId !== null, fn ($query) => $query->where('aggregate_id', $runId))
                ->when($eventId !== null, fn ($query) => $query->where('id', $eventId))
                ->orderBy('created_at')->orderBy('id')->first();
            if ($event === null) {
                break;
            }
            // The outbox is durable; acknowledgement must follow the actual queue push.
            Bus::dispatch((new ImportKeywords((int) $event->aggregate_id, $event->id))->onQueue('imports')->beforeCommit());
            DB::table('outbox_events')->where('id', $event->id)->whereNull('delivered_at')->update(['delivered_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
            $count++;
        }

        return $count;
    }

    private function dispatchAfterCommit(int $runId, ?string $eventId = null): void
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (in_array($driver, ['sync', 'null', 'deferred', 'background'], true)) {
            return;
        }
        DB::afterCommit(function () use ($runId, $eventId): void {
            try {
                $this->dispatchPending($runId, $eventId);
            } catch (\Throwable $e) {
                // A transport outage must not reject an import already accepted by the database.
                report($e);
            }
        });
    }

    public function recoverInterrupted(): int
    {
        $count = 0;
        foreach (DB::table('keyword_import_runs')->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(5))->limit(100)->pluck('id') as $id) {
            $this->enqueueResume((int) $id);
            $count++;
        }

        return $count;
    }
}
