<?php

namespace App\Jobs\Keywords;

use App\Domain\Keywords\KeywordImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ImportKeywords implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 100;

    public function __construct(public int $runId, public ?string $outboxEventId = null) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(KeywordImportService $service): void
    {
        $service->execute($this->runId, outboxEventId: $this->outboxEventId ?? null);
    }
}
