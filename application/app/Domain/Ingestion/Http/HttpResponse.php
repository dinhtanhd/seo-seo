<?php

namespace App\Domain\Ingestion\Http;

final readonly class HttpResponse
{
    public function __construct(public int $status, public array $headers, public string $body, public string $url) {}

    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Nguồn không trả JSON hợp lệ.', 0, $e);
        }
        if (! is_array($data)) {
            throw new \RuntimeException('JSON nguồn phải là object hoặc array.');
        }

        return $data;
    }
}
