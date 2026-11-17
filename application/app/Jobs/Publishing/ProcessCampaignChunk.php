<?php

namespace App\Jobs\Publishing;

use App\Domain\Publishing\PublicationCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCampaignChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $chunkId, public string $phase = 'prepare') {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(PublicationCampaignService $campaigns): void
    {
        $campaigns->processChunk($this->chunkId, $this->phase);
    }
}
