<?php

namespace App\Jobs\Publishing;

use App\Domain\Keywords\FirstVisitKeywordCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class WarmFirstVisitKeywordCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public int $uniqueFor = 180;

    public function __construct(public int $siteId) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(FirstVisitKeywordCache $cache): void
    {
        $cache->warmSite($this->siteId);
    }
}
