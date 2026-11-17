<?php

namespace App\Jobs\Ingestion;

use App\Domain\Delivery\BaiduService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SubmitBaidu implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 40;

    public function __construct(public int $submissionId) {}

    public function handle(BaiduService $service): void
    {
        $result = $service->submit($this->submissionId);
        if ($result['status'] === 'retry_wait' && $this->attempts() < $this->tries) {
            $this->release($result['retry_after'] ?? 60);
        }
    }
}
