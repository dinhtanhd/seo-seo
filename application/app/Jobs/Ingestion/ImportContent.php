<?php

namespace App\Jobs\Ingestion;

use App\Domain\Ingestion\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ImportContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 240;

    public function __construct(public int $runId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(ImportService $service): void
    {
        $service->resumeImport($this->runId);
    }
}
