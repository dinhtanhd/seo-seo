<?php

namespace App\Domain\Ingestion\Connectors;

interface Connector
{
    public function validateConfiguration(array $config): void;

    public function fetchBatch(array $config, ?string $cursor = null): FetchBatch;
}
