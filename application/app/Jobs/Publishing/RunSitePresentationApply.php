<?php

namespace App\Jobs\Publishing;

use App\Domain\Publishing\SitePresentationApply;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunSitePresentationApply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public string $eventId) {}

    public function handle(SitePresentationApply $apply): void
    {
        if ($apply->process($this->eventId)) {
            self::dispatch($this->eventId)->onQueue('publish')->delay(1);
        }
    }
}
