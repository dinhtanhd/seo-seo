<?php

namespace App\Domain\Ingestion\Connectors;

final readonly class FetchBatch
{
    public function __construct(public array $records, public ?string $nextCursor, public bool $hasMore, public string $sourceUrl, public array $warnings = [], public array $pagination = []) {}
}
