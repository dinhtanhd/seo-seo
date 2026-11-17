<?php

namespace App\Jobs\Sites;

use App\Domain\Sites\WebsiteBulkCreate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunWebsiteCreation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public readonly string $operationId)
    {
        $this->onQueue('websites');
    }

    public function handle(WebsiteBulkCreate $service): void
    {
        if ($service->process($this->operationId)) {
            self::dispatch($this->operationId);
        }
    }
}
