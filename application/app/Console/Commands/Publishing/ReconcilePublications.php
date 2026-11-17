<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Publishing\Publisher;
use Illuminate\Console\Command;

class ReconcilePublications extends Command
{
    protected $signature = 'tram:reconcile';

    protected $description = 'Fence lost workers before releasing expired capacity claims';

    public function handle(Publisher $publisher): int
    {
        $this->info('Reconciled: '.$publisher->reconcile());

        return self::SUCCESS;
    }
}
