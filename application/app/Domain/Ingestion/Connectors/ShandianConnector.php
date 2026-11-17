<?php

namespace App\Domain\Ingestion\Connectors;

final class ShandianConnector extends MacCmsConnector
{
    public static function defaultConfiguration(): array
    {
        return ['schema_version' => 1, 'endpoint' => 'https://xsd.sdzyapi.com/api.php/provide/vod/',
            'allowed_hosts' => ['xsd.sdzyapi.com'], 'allowed_ports' => [443], 'source_timezone' => 'Asia/Shanghai',
            'max_pages' => 3, 'max_bytes' => 5 * 1024 * 1024, 'total_timeout' => 25, 'max_redirects' => 2,
            'min_host_interval_ms' => 1000, 'help_url' => 'https://shandianzy.net/help/#More', 'metadata_only' => true,
            'json_mime_types' => ['application/json', 'text/json', 'text/plain', 'text/html']];
    }
}
