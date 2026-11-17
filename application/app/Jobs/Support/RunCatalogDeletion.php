<?php

namespace App\Jobs\Support;

use App\Domain\Support\CatalogDeletionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunCatalogDeletion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $operationId) {}

    public function handle(CatalogDeletionService $service): void
    {
        $service->process($this->operationId);
    }
}
