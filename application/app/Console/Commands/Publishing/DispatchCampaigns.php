<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Publishing\PublicationCampaignService;
use Illuminate\Console\Command;

class DispatchCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch';

    protected $description = 'Reconcile campaign progress and replay durable work after queue/worker interruption.';

    public function handle(PublicationCampaignService $campaigns): int
    {
        $this->line('Dispatched '.$campaigns->dispatchPending().' campaign jobs.');

        return self::SUCCESS;
    }
}
