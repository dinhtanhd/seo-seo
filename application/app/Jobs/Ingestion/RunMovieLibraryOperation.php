<?php

namespace App\Jobs\Ingestion;

use App\Domain\Ingestion\MovieLibraryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunMovieLibraryOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public string $operationId)
    {
        $this->onQueue('imports');
    }

    public function handle(MovieLibraryService $library): void
    {
        if ($library->process($this->operationId)) {
            self::dispatch($this->operationId)->delay(1);
        }
    }
}
