<?php

namespace App\Domain\Ingestion\Http;

interface HttpTransport
{
    public function request(string $method, string $url, array $policy, ?string $body = null, array $headers = []): HttpResponse;
}
