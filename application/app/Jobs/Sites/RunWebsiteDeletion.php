<?php

namespace App\Jobs\Sites;

use App\Domain\Sites\WebsiteDeletionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunWebsiteDeletion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $operationId) {}

    public function handle(WebsiteDeletionService $service): void
    {
        $service->process($this->operationId);
    }
}
