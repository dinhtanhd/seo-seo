<?php

namespace App\Domain\Ingestion\Http;

class DnsResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw new \RuntimeException('Không phân giải được DNS nguồn.');
        }

        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records))));
    }
}
