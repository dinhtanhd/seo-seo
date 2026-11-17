<?php

namespace App\Domain\Ingestion\Connectors;

use App\Domain\Ingestion\Http\HttpTransport;
use App\Domain\Ingestion\Http\SafeHttpClient;
use Symfony\Component\CssSelector\CssSelectorConverter;

final class HtmlSelectorConnector implements Connector
{
    public function __construct(private readonly HttpTransport $http = new SafeHttpClient) {}

    public function validateConfiguration(array $config): void
    {
        if (empty($config['endpoint']) || empty($config['allowed_hosts']) || empty($config['record_selector'])
            || empty($config['fields']['external_id']) || empty($config['fields']['title'])) {
            throw new \InvalidArgumentException('HTML connector cần endpoint, allowlist, selector và mapping external_id/title.');
        }
        $this->xpath($config['record_selector'], $config['selector_type'] ?? 'css');
        foreach ($config['fields'] as $field) {
            $definition = is_string($field) ? ['selector' => $field] : $field;
            $this->xpath($definition['selector'] ?? '', $config['selector_type'] ?? 'css');
            if (isset($definition['attribute']) && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $definition['attribute'])) {
                throw new \InvalidArgumentException('Tên attribute selector không hợp lệ.');
            }
        }
    }

    public function fetchBatch(array $config, ?string $cursor = null): FetchBatch
    {
        $this->validateConfiguration($config);
        $url = $cursor ?? $config['endpoint'];
        $response = $this->http->request('GET', $url, $config);
        if ($response->status === 429 || $response->status >= 500) {
            throw new RetryableFetchException('Nguồn HTML tạm thời lỗi.', (int) ($response->headers['retry-after'] ?? 30));
        }
        if ($response->status !== 200 || ! str_contains(strtolower($response->headers['content-type'] ?? ''), 'text/html')) {
            throw new \RuntimeException('HTTP/MIME nguồn HTML không hợp lệ.');
        }
        if (! mb_check_encoding($response->body, 'UTF-8')) {
            throw new \RuntimeException('Nguồn HTML không phải UTF-8; cần cấu hình chuyển mã tường minh.');
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$response->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query($this->xpath($config['record_selector'], $config['selector_type'] ?? 'css'));
            if ($nodes === false || $nodes->length === 0) {
                throw new \RuntimeException('Selector không khớp bản ghi; cần kiểm tra mẫu nguồn.');
            }
            if ($nodes->length > 1000) {
                throw new \RuntimeException('Nguồn HTML vượt 1.000 records mỗi trang.');
            }
            $records = [];
            foreach ($nodes as $node) {
                $record = ['kind' => $config['content_kind'] ?? 'movie', 'source_url' => $url, 'parser_version' => 'html-selector-v1'];
                foreach ($config['fields'] as $field => $definition) {
                    $definition = is_string($definition) ? ['selector' => $definition] : $definition;
                    $query = $this->xpath($definition['selector'], $config['selector_type'] ?? 'css');
                    $matched = $xpath->query($query, $node)?->item(0);
                    if (! $matched) {
                        $record[$field] = null;
                    } elseif (isset($definition['attribute']) && $matched instanceof \DOMElement) {
                        $record[$field] = $matched->getAttribute($definition['attribute']);
                        if (in_array($field, ['source_url', 'poster'], true) && $record[$field] !== '') {
                            $record[$field] = SafeHttpClient::resolveUrl($url, $record[$field]);
                        }
                    } elseif (($definition['mode'] ?? 'text') === 'html') {
                        $record[$field] = implode('', array_map(fn ($child) => $dom->saveHTML($child), iterator_to_array($matched->childNodes)));
                    } else {
                        $record[$field] = trim($matched->textContent);
                    }
                }
                $records[] = $record;
            }
            $next = null;
            if (! empty($config['next_selector'])) {
                $node = $xpath->query($this->xpath($config['next_selector'], $config['selector_type'] ?? 'css'))?->item(0);
                if ($node instanceof \DOMElement && $node->getAttribute('href') !== '') {
                    $next = SafeHttpClient::resolveUrl($url, $node->getAttribute('href'));
                    if ($next === $url) {
                        throw new \RuntimeException('Nguồn lặp URL pagination.');
                    }
                }
            }

            return new FetchBatch($records, $next, $next !== null, $url);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function xpath(string $selector, string $type): string
    {
        if ($selector === '' || strlen($selector) > 1000) {
            throw new \InvalidArgumentException('Selector không hợp lệ.');
        }
        if ($type === 'xpath') {
            if (preg_match('/document\s*\(|php:|\b(system|exec)\s*\(/i', $selector)) {
                throw new \InvalidArgumentException('XPath không được phép.');
            }
            $dom = new \DOMDocument;
            $dom->loadXML('<root/>', LIBXML_NONET);
            $xpath = new \DOMXPath($dom);
            if (@$xpath->query($selector) === false) {
                throw new \InvalidArgumentException('XPath sai cú pháp.');
            }

            return $selector;
        }
        if ($type !== 'css') {
            throw new \InvalidArgumentException('Selector type phải là css hoặc xpath.');
        }

        return (new CssSelectorConverter)->toXPath($selector);
    }
}
