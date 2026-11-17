<?php

namespace App\Jobs\Ingestion;

use App\Domain\Ingestion\Connectors\RetryableFetchException;
use App\Domain\Ingestion\CrawlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class CrawlSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 240;

    public ?int $expectedPages = null;

    public function __construct(public int $runId, public int $maxPages = 3, ?int $expectedPages = null)
    {
        $this->expectedPages = $expectedPages;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(CrawlService $service): void
    {
        try {
            $service->resume($this->runId, $this->maxPages, false, $this->expectedPages);
            // Redis dispatch is an optimization: PostgreSQL keeps the next chunk
            // available for the scheduler if dispatch fails or the worker exits.
            $service->dispatchQueued($this->runId);
        } catch (RetryableFetchException $e) {
            if ($this->attempts() >= $this->tries || DB::table('ingest_runs')->where('id', $this->runId)->value('status') === 'failed') {
                DB::table('ingest_runs')->where('id', $this->runId)->whereNull('lease_token')->whereIn('status', ['retry_wait', 'failed'])->update(['status' => 'failed', 'next_attempt_at' => null, 'error' => 'Hết số lần retry nguồn: '.$e->getMessage()]);
                throw $e;
            }
            $run = DB::table('ingest_runs')->find($this->runId);
            if ($run && $run->status === 'retry_wait') {
                // release() reuses the old serialized payload. A new delayed job
                // must carry the checkpoint committed before the failed fetch.
                $expectedPages = $run->crawl_mode === null ? null : (int) (json_decode($run->counts ?? '{}', true)['fetched_pages'] ?? 0);
                self::dispatch($this->runId, $this->maxPages, $expectedPages)->onQueue('crawl')->delay(min(3600, max(1, $e->retryAfter)));
            }
        }
    }
}
