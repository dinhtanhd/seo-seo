<?php

namespace App\Jobs\Publishing;

use App\Domain\Publishing\PublicationCampaignService;
use App\Domain\Publishing\Publisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $itemId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(Publisher $publisher): void
    {
        try {
            $publisher->publishItem($this->itemId);
        } finally {
            app(PublicationCampaignService::class)->afterItem($this->itemId);
        }
    }
}
