<?php

namespace App\Domain\Ingestion\Connectors;

final class RetryableFetchException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 30)
    {
        parent::__construct($message);
    }
}
