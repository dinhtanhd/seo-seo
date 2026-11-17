<?php

namespace App\Jobs\Sites;

use App\Domain\Sites\WebsiteBulkEdit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunWebsiteBulkEdit implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public string $operationId)
    {
        $this->onQueue('websites');
    }

    public function handle(WebsiteBulkEdit $service): void
    {
        if ($service->process($this->operationId)) {
            self::dispatch($this->operationId)->delay(1);
        }
    }
}
