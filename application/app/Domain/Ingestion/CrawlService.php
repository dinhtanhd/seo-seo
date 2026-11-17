<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Connectors\Connector;
use App\Domain\Ingestion\Connectors\FetchBatch;
use App\Domain\Ingestion\Connectors\HtmlSelectorConnector;
use App\Domain\Ingestion\Connectors\MacCmsConnector;
use App\Domain\Ingestion\Connectors\RetryableFetchException;
use App\Domain\Ingestion\Connectors\ShandianConnector;
use App\Domain\Support\Conflict;
use App\Jobs\Ingestion\CrawlSource;
use App\Jobs\Ingestion\ImportContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class CrawlService
{
    public function __construct(private readonly ImportService $importer = new ImportService, private readonly ?Connector $connectorOverride = null) {}

    public function start(int $connectorId, string $idempotencyKey, int $maxPages = 3, ?string $mode = null, ?int $pageLimit = null): array
    {
        $run = $this->createRun($connectorId, $idempotencyKey, $mode, $pageLimit);
        if (in_array($run->status, ['succeeded', 'partial'], true)) {
            return $this->importer->report($run);
        }

        return $this->resume($run->id, $maxPages);
    }

    public function queue(int $connectorId, string $idempotencyKey, int $maxPages = 3, ?string $mode = null, ?int $pageLimit = null): array
    {
        return DB::transaction(function () use ($connectorId, $idempotencyKey, $maxPages, $mode, $pageLimit) {
            $run = $this->createRun($connectorId, $idempotencyKey, $mode, $pageLimit);
            DB::table('outbox_events')->insertOrIgnore(['id' => $run->public_id, 'event_type' => 'crawl.requested', 'aggregate_id' => $run->id,
                'payload_json' => json_encode($this->eventPayload($run, $maxPages)), 'created_at' => now()]);

            return $this->importer->report($run);
        }, 3);
    }

    public function dispatchQueued(?int $runId = null): int
    {
        $count = 0;
        foreach (DB::table('outbox_events')->whereIn('event_type', ['crawl.requested', 'import.requested'])->whereNull('delivered_at')->when($runId !== null, fn ($q) => $q->where('aggregate_id', $runId))
            ->where(function ($q) {
                $q->where('event_type', 'import.requested')->orWhereExists(function ($q) {
                    $q->selectRaw('1')->from('ingest_runs')->whereColumn('ingest_runs.id', 'outbox_events.aggregate_id')->where('ingest_runs.status', '<>', 'paused')
                        ->where(function ($q) {
                            $q->whereNull('ingest_runs.next_attempt_at')->orWhere('ingest_runs.next_attempt_at', '<=', now());
                        });
                });
            })->orderBy('created_at')->orderBy('id')->limit(100)->get() as $event) {
            $payload = json_decode($event->payload_json, true);
            $run = DB::table('ingest_runs')->find($event->aggregate_id);
            if ($event->event_type === 'crawl.requested' && $run && ($run->status === 'paused' || ($run->next_attempt_at && now()->lt($run->next_attempt_at)))) {
                continue;
            }
            if ($event->event_type === 'import.requested') {
                ImportContent::dispatch((int) $payload['run_id'])->onQueue('imports');
            } else {
                CrawlSource::dispatch((int) $payload['run_id'], (int) $payload['max_pages'], isset($payload['expected_pages']) ? (int) $payload['expected_pages'] : null)->onQueue('crawl');
            }
            DB::table('outbox_events')->where('id', $event->id)->update(['delivered_at' => now(), 'attempts' => $event->attempts + 1]);
            $count++;
        }

        return $count;
    }

    public function queueResume(int $runId, int $maxPages = 3): array
    {
        return DB::transaction(function () use ($runId, $maxPages) {
            $initial = DB::table('ingest_runs')->where('id', $runId)->first();
            if (! $initial) {
                throw new \InvalidArgumentException('Không tìm thấy đợt crawl.');
            }
            $source = DB::table('source_connectors')->where('id', $initial->connector_id)->lockForUpdate()->first();
            $run = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
            if ($source->status !== 'active') {
                throw new \RuntimeException('Nguồn đang tạm dừng.');
            }
            if (in_array($run->status, ['succeeded', 'partial', 'running'], true)) {
                return $this->importer->report($run);
            }
            if (DB::table('ingest_runs')->where('connector_id', $source->id)->where('id', '<>', $runId)->whereIn('status', ['queued', 'running', 'retry_wait'])->when($run->crawl_mode === null, fn ($q) => $q->whereNotNull('crawl_mode'))->exists()) {
                throw new Conflict('SOURCE_BUSY', 'Nguồn đang có lượt crawl khác; chưa thể tiếp tục lượt này.');
            }
            $pending = DB::table('outbox_events')->whereIn('event_type', ['crawl.requested', 'import.requested'])->where('aggregate_id', $runId)->whereNull('delivered_at')->exists();
            if (! $pending) {
                $isImport = DB::table('outbox_events')->where('event_type', 'import.requested')->where('aggregate_id', $runId)->whereNotNull('payload_json->records')->exists();
                DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => $isImport ? 'import.requested' : 'crawl.requested', 'aggregate_id' => $runId,
                    'payload_json' => json_encode($this->eventPayload($run, $maxPages)), 'created_at' => now()]);
            }
            DB::table('ingest_runs')->where('id', $runId)->update(['status' => 'queued', 'error' => null, 'next_attempt_at' => null, 'updated_at' => now()]);

            return $this->importer->report(DB::table('ingest_runs')->where('id', $runId)->first());
        }, 3);
    }

    private function createRun(int $connectorId, string $idempotencyKey, ?string $mode = null, ?int $pageLimit = null): object
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('Thiếu idempotency key crawl.');
        }

        if (($mode === null && $pageLimit !== null) || ($mode !== null && ! in_array($mode, ['initial', 'latest'], true))
            || ($mode === 'latest' && ($pageLimit === null || $pageLimit < 3 || $pageLimit > 5))
            || ($mode === 'initial' && $pageLimit !== null && ($pageLimit < 1 || $pageLimit > 1000000))) {
            throw new \InvalidArgumentException('Kế hoạch crawl không hợp lệ: cập nhật 3–5 trang; lượt đầu tối đa 1.000.000 trang hoặc toàn bộ.');
        }

        return DB::transaction(function () use ($connectorId, $idempotencyKey, $mode, $pageLimit) {
            $source = DB::table('source_connectors')->where('id', $connectorId)->lockForUpdate()->first();
            if (! $source || $source->status !== 'active') {
                throw new \RuntimeException('Nguồn không tồn tại hoặc đang tạm dừng.');
            }
            if ($mode === 'initial' && $pageLimit === null && ! in_array($source->kind, ['maccms', 'shandian'], true)) {
                throw new \InvalidArgumentException('Lấy toàn bộ tự động chỉ hỗ trợ API phim MacCMS/Shandian; nguồn HTML cần giới hạn số trang.');
            }
            $config = json_decode($source->config_json, true, 24, JSON_THROW_ON_ERROR);
            $this->connector($source->kind)->validateConfiguration($config);
            $hash = hash('sha256', CanonicalRecord::stableJson($config));
            $run = DB::table('ingest_runs')->where('connector_id', $connectorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($run) {
                if ($run->request_hash !== $hash || ($run->crawl_mode ?? null) !== $mode || (($run->page_limit ?? null) === null ? null : (int) $run->page_limit) !== $pageLimit) {
                    throw new Conflict('IDEMPOTENCY_CONFLICT', 'Cấu hình nguồn hoặc kế hoạch crawl đã thay đổi.');
                }

                return $run;
            }
            if (DB::table('ingest_runs')->where('connector_id', $connectorId)->whereIn('status', ['queued', 'running', 'retry_wait'])->when($mode === null, fn ($q) => $q->whereNotNull('crawl_mode'))->exists()) {
                throw new Conflict('SOURCE_BUSY', 'Nguồn đang có một lượt nhập/crawl; hãy đợi hoặc tạm dừng lượt đó.');
            }
            $id = DB::table('ingest_runs')->insertGetId(['public_id' => (string) Str::uuid(), 'connector_id' => $connectorId, 'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash, 'crawl_mode' => $mode, 'page_limit' => $pageLimit, 'binding_hash' => $mode === null ? null : $this->bindingHash($connectorId), 'cursor' => null,
                'counts' => $mode === 'initial' && $pageLimit === null && in_array($source->kind, ['maccms', 'shandian'], true) ? json_encode(['crawl_direction' => 'descending']) : '{}',
                'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('ingest_runs')->where('id', $id)->first();
        }, 3);
    }

    public function resume(int $runId, int $maxPages = 3, bool $allowPaused = true, ?int $expectedPages = null): array
    {
        $maxPages = min(100, max(1, $maxPages));
        $initial = DB::table('ingest_runs')->where('id', $runId)->first();
        if (! $initial) {
            throw new \RuntimeException('Không tìm thấy đợt crawl.');
        }
        $token = (string) Str::uuid();
        [$source, $run, $config] = DB::transaction(function () use ($initial, $runId, $token, $allowPaused, $expectedPages) {
            $source = DB::table('source_connectors')->where('id', $initial->connector_id)->lockForUpdate()->first();
            $run = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $source || $source->status !== 'active') {
                throw new \RuntimeException('Nguồn đang tạm dừng.');
            }
            if (in_array($run->status, ['succeeded', 'partial'], true) || (! $allowPaused && in_array($run->status, ['paused', 'failed'], true))
                || (! $allowPaused && $run->next_attempt_at && now()->lt($run->next_attempt_at))
                || (! $allowPaused && $run->lease_until && now()->lt($run->lease_until))
                || ($expectedPages !== null && $expectedPages !== (int) (json_decode($run->counts ?? '{}', true)['fetched_pages'] ?? 0))) {
                return [$source, $run, null];
            }
            if (DB::table('ingest_runs')->where('connector_id', $source->id)->where('id', '<>', $runId)->whereIn('status', ['queued', 'running', 'retry_wait'])->when($run->crawl_mode === null, fn ($q) => $q->whereNotNull('crawl_mode'))->exists()) {
                throw new Conflict('SOURCE_BUSY', 'Nguồn đang có lượt crawl khác.');
            }
            if ($run->lease_until && now()->lt($run->lease_until)) {
                throw new \RuntimeException('CRAWL_BUSY: worker khác đang xử lý đợt này.');
            }
            $config = json_decode($source->config_json, true, 24, JSON_THROW_ON_ERROR);
            if (hash('sha256', CanonicalRecord::stableJson($config)) !== $run->request_hash) {
                DB::table('ingest_runs')->where('id', $runId)->update(['status' => 'failed',
                    'error' => 'SOURCE_CONFIG_CHANGED: hãy tạo một đợt crawl mới.', 'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'updated_at' => now()]);

                return [$source, DB::table('ingest_runs')->find($runId), null];
            }
            DB::table('ingest_runs')->where('id', $runId)->update(['status' => 'running', 'lease_token' => $token,
                'lease_until' => now()->addMinutes(5), 'error' => null, 'next_attempt_at' => null, 'updated_at' => now()]);

            return [$source, $run, $config];
        }, 3);
        if ($config === null) {
            return $this->importer->report($run);
        }
        $managed = ($run->crawl_mode ?? null) !== null;
        $maxPages = $managed ? min(3, $maxPages) : min($maxPages, max(1, (int) ($config['max_pages'] ?? 3)));
        $cursor = $run->cursor;
        $fetchConfig = $run->crawl_mode === 'initial' ? $this->initialConfiguration($config) : $config;
        $descending = (json_decode($run->counts ?? '{}', true)['crawl_direction'] ?? null) === 'descending'
            && $run->crawl_mode === 'initial' && $run->page_limit === null && in_array($source->kind, ['maccms', 'shandian'], true);
        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $current = DB::table('ingest_runs')->find($runId);
                if ($managed && $current->status === 'paused') {
                    return $this->importer->report($current);
                }
                $fresh = DB::table('source_connectors')->where('id', $source->id)->first();
                if ($fresh->status !== 'active') {
                    throw new \RuntimeException('Nguồn đã bị tạm dừng trong khi crawl.');
                }
                $probe = null;
                if ($descending && $cursor === null) {
                    $probe = $this->fetchBatch($source->kind, $fetchConfig, null, $managed);
                    $startPage = max(1, $this->moviePageCount($probe, 1));
                    DB::transaction(function () use ($runId, $source, $token, $probe, $startPage) {
                        $freshSource = DB::table('source_connectors')->where('id', $source->id)->lockForUpdate()->first();
                        $checkpoint = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
                        $this->assertCheckpoint($freshSource, $checkpoint, $token, true);
                        $counts = json_decode($checkpoint->counts ?? '{}', true);
                        $counts['crawl_start_page'] = $startPage;
                        $counts['source_pagination'] = $probe->pagination;
                        DB::table('ingest_runs')->where('id', $runId)->update(['counts' => json_encode($counts), 'cursor' => (string) $startPage, 'updated_at' => now()]);
                    }, 3);
                    $cursor = (string) $startPage;
                }
                $batch = $probe !== null && $cursor === '1' ? $probe : $this->fetchBatch($source->kind, $fetchConfig, $cursor, $managed);
                if ($descending) {
                    $requestedPage = (int) $cursor;
                    $pageCount = $this->moviePageCount($batch, $requestedPage);
                    if ($requestedPage > max(1, $pageCount)) {
                        throw new \RuntimeException('CRAWL_PAGINATION_CHANGED: nguồn đã giảm số trang; hãy bắt đầu lượt lấy toàn bộ mới.');
                    }
                    $batch = new FetchBatch($this->descendingRecords($batch->records), $requestedPage > 1 ? (string) ($requestedPage - 1) : null,
                        $requestedPage > 1, $batch->sourceUrl, $batch->warnings, $batch->pagination);
                }
                $done = DB::transaction(function () use ($runId, $source, $token, $batch, $managed, $page, $maxPages) {
                    $freshSource = DB::table('source_connectors')->where('id', $source->id)->lockForUpdate()->first();
                    $run = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
                    $this->assertCheckpoint($freshSource, $run, $token, $managed);
                    $previous = json_decode($run->counts ?? '{}', true) ?? [];
                    $counts = $this->importer->commitRecords($source->id, $runId, $batch->records, $previous['received'] ?? 0);
                    foreach ($counts as $key => $value) {
                        $previous[$key] = ($previous[$key] ?? 0) + $value;
                    }
                    $previous['fetched_pages'] = ($previous['fetched_pages'] ?? 0) + 1;
                    $reachedLimit = $managed && $run->page_limit !== null && $previous['fetched_pages'] >= (int) $run->page_limit;
                    $done = ! $batch->hasMore || $reachedLimit;
                    if ($managed) {
                        $previous['retry_attempts'] = 0;
                        $previous['stop_reason'] = ! $batch->hasMore ? 'source_end' : ($reachedLimit ? 'page_limit' : null);
                        if ($batch->pagination !== []) {
                            $previous['source_pagination'] = $batch->pagination;
                        }
                        if (! $done && $previous['fetched_pages'] >= 1000000) {
                            throw new \RuntimeException('CRAWL_SAFETY_LIMIT: nguồn vượt 1.000.000 trang; cần kiểm tra API.');
                        }
                    }
                    $continue = $managed && ! $done && $page + 1 >= $maxPages;
                    DB::table('ingest_runs')->where('id', $runId)->update(['counts' => json_encode($previous), 'cursor' => $batch->nextCursor,
                        'status' => $done ? (($previous['errors'] ?? 0) > 0 ? 'partial' : 'succeeded') : ($continue ? 'queued' : 'running'),
                        'lease_until' => $done || $continue ? null : now()->addMinutes(5), 'lease_token' => $done || $continue ? null : $token, 'updated_at' => now()]);

                    if ($continue) {
                        $checkpoint = DB::table('ingest_runs')->where('id', $runId)->first();
                        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'tram-seo:crawl:'.$runId.':'.$previous['fetched_pages']);
                        DB::table('outbox_events')->insertOrIgnore(['id' => $id, 'event_type' => 'crawl.requested', 'aggregate_id' => $runId,
                            'payload_json' => json_encode($this->eventPayload($checkpoint, 3)), 'created_at' => now()]);
                    }

                    return $done;
                }, 3);
                $cursor = $batch->nextCursor;
                if ($done) {
                    break;
                }
            }
            if (! $managed) {
                DB::table('ingest_runs')->where('id', $runId)->where('lease_token', $token)->update(['status' => 'paused', 'lease_until' => null, 'lease_token' => null, 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            $current = DB::table('ingest_runs')->where('id', $runId)->first();
            if ($current->status === 'paused' && $current->lease_token !== $token) {
                return $this->importer->report($current);
            }
            $retry = $e instanceof RetryableFetchException;
            $counts = json_decode($current->counts ?? '{}', true) ?? [];
            if ($managed && $retry) {
                $counts['retry_attempts'] = ($counts['retry_attempts'] ?? 0) + 1;
            }
            $retry = $retry && (! $managed || ($counts['retry_attempts'] ?? 0) < 4);
            DB::table('ingest_runs')->where('id', $runId)->where('lease_token', $token)->update(['status' => $retry ? 'retry_wait' : 'failed',
                'counts' => json_encode($counts), 'error' => mb_substr($e->getMessage(), 0, 2000),
                'next_attempt_at' => $retry ? now()->addSeconds(min(3600, max(1, $e->retryAfter))) : null,
                'lease_until' => null, 'lease_token' => null, 'updated_at' => now()]);
            throw $e;
        }

        return $this->importer->report(DB::table('ingest_runs')->where('id', $runId)->first());
    }

    public function pause(int $runId): array
    {
        return DB::transaction(function () use ($runId) {
            $initial = DB::table('ingest_runs')->find($runId) ?? throw new \InvalidArgumentException('Không tìm thấy đợt crawl.');
            DB::table('source_connectors')->where('id', $initial->connector_id)->lockForUpdate()->first();
            $run = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
            if ($run->crawl_mode !== null && in_array($run->status, ['queued', 'running', 'retry_wait'], true)) {
                DB::table('ingest_runs')->where('id', $runId)->update(['status' => 'paused', 'lease_token' => null, 'lease_until' => null,
                    'next_attempt_at' => null, 'error' => null, 'updated_at' => now()]);
            }

            return $this->importer->report(DB::table('ingest_runs')->find($runId));
        }, 3);
    }

    private function eventPayload(object $run, int $maxPages): array
    {
        $payload = ['run_id' => $run->id, 'max_pages' => min(3, max(1, $maxPages))];
        if ($run->crawl_mode !== null) {
            $payload['expected_pages'] = (int) (json_decode($run->counts ?? '{}', true)['fetched_pages'] ?? 0);
        }

        return $payload;
    }

    private function fetchBatch(string $kind, array $config, ?string $cursor, bool $managed): FetchBatch
    {
        $host = parse_url($config['endpoint'], PHP_URL_HOST);
        $lock = Cache::lock('crawl-host:'.hash('sha256', $host), 70);
        $lock->block(10);
        try {
            $last = (float) Cache::get('crawl-last:'.$host, 0);
            $spacing = min(5000, max(250, (int) ($config['min_host_interval_ms'] ?? 1000)));
            $waitMs = max(0, (int) ($last * 1000 + $spacing - microtime(true) * 1000));
            if ($waitMs > 0) {
                usleep($waitMs * 1000);
            }
            $batch = $this->connector($kind)->fetchBatch($config, $cursor);
            if ($managed && $batch->hasMore && ($batch->nextCursor === null || $batch->nextCursor === $cursor)) {
                throw new \RuntimeException('CRAWL_PAGINATION_LOOP: API không tiến tới trang tiếp theo.');
            }
            if ($managed && in_array($kind, ['maccms', 'shandian'], true) && $batch->hasMore
                && (! ctype_digit((string) $batch->nextCursor) || (int) $batch->nextCursor !== ($cursor === null ? 1 : (int) $cursor) + 1)) {
                throw new \RuntimeException('CRAWL_PAGINATION_INVALID: cursor API phim không liên tiếp.');
            }
            Cache::put('crawl-last:'.$host, microtime(true), 3600);

            return $batch;
        } finally {
            $lock->release();
        }
    }

    private function moviePageCount(FetchBatch $batch, int $requestedPage): int
    {
        $pageCount = filter_var($batch->pagination['pagecount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if ($pageCount === false || ($batch->pagination['page'] ?? null) !== $requestedPage || ($pageCount === 0 && $batch->records !== [])) {
            throw new \RuntimeException('CRAWL_PAGINATION_INVALID: nguồn không trả tổng trang hợp lệ để lấy từ cuối về đầu.');
        }

        return $pageCount;
    }

    /** Reverse distinct films while preserving last-write order for duplicate IDs in one response. */
    private function descendingRecords(array $records): array
    {
        $groups = [];
        foreach ($records as $index => $record) {
            $key = is_array($record) && is_scalar($record['external_id'] ?? null) ? 'id:'.$record['external_id'] : 'row:'.$index;
            $groups[$key][] = $record;
        }

        return array_merge([], ...array_reverse(array_values($groups)));
    }

    private function assertCheckpoint(object $source, object $run, string $token, bool $managed): void
    {
        if ($source->status !== 'active' || $run->lease_token !== $token || ! $run->lease_until || now()->gte($run->lease_until)) {
            throw new \RuntimeException('CRAWL_LEASE_LOST: không commit checkpoint của worker cũ.');
        }
        if (hash('sha256', CanonicalRecord::stableJson(json_decode($source->config_json, true))) !== $run->request_hash) {
            throw new \RuntimeException('SOURCE_CONFIG_CHANGED: cấu hình thay đổi trong khi fetch.');
        }
        if ($managed && $run->binding_hash !== $this->bindingHash($source->id)) {
            throw new \RuntimeException('BINDINGS_CHANGED: website hoặc ghép danh mục đã thay đổi; hãy tạo lượt lấy toàn bộ mới để tránh chỉ nhận phần đuôi.');
        }
    }

    private function bindingHash(int $sourceId): string
    {
        $rows = DB::table('source_site_bindings')->where('connector_id', $sourceId)->orderBy('id')
            ->get(['id', 'site_id', 'status', 'mapping_version', 'mapping_json', 'auto_publish_policy'])->map(function ($row) {
                $row->mapping_json = json_decode($row->mapping_json ?? '{}', true);
                $row->auto_publish_policy = json_decode($row->auto_publish_policy ?? '{}', true);

                return (array) $row;
            })->all();

        return hash('sha256', CanonicalRecord::stableJson($rows));
    }

    private function initialConfiguration(array $config): array
    {
        unset($config['hours']);
        [$endpoint, $query] = array_pad(explode('?', $config['endpoint'], 2), 2, '');
        parse_str($query, $params);
        unset($params['h']);
        $config['endpoint'] = $endpoint.($params === [] ? '' : '?'.http_build_query($params));

        return $config;
    }

    private function connector(string $kind): Connector
    {
        return $this->connectorOverride ?? match ($kind) {
            'shandian' => app(ShandianConnector::class),
            'maccms' => app(MacCmsConnector::class),
            'html' => app(HtmlSelectorConnector::class),
            default => throw new \InvalidArgumentException('Loại nguồn này không hỗ trợ HTTP crawl.'),
        };
    }
}
