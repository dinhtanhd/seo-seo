<?php

namespace App\Domain\Delivery;

use App\Domain\Ingestion\Http\HttpTransport;
use App\Domain\Ingestion\Http\SafeHttpClient;

final class BaiduAdapter
{
    public const ENDPOINT = 'https://data.zz.baidu.com/urls';

    public function __construct(private readonly HttpTransport $http = new SafeHttpClient) {}

    public function submit(string $endpoint, string $siteHost, string $token, array $urls): array
    {
        if ($endpoint !== self::ENDPOINT) {
            throw new \InvalidArgumentException('Endpoint Baidu không thuộc allowlist cố định.');
        }
        if ($token === '' || ! preg_match('/^[a-zA-Z0-9_-]{8,256}$/', $token)) {
            throw new \InvalidArgumentException('Token Baidu không hợp lệ.');
        }
        $urls = array_values(array_unique($urls));
        if (! $urls || count($urls) > 2000 || ! preg_match('/^[a-zA-Z0-9.-]+$/', $siteHost)) {
            throw new \InvalidArgumentException('URL batch hoặc site Baidu không hợp lệ.');
        }
        foreach ($urls as $url) {
            $parts = parse_url($url);
            if (! $parts || strtolower($parts['host'] ?? '') !== strtolower($siteHost) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || preg_match('/[\r\n\x00]/', $url) || isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('Chỉ gửi URL canonical cùng site.');
            }
        }
        $requestUrl = $endpoint.'?'.http_build_query(['site' => $siteHost, 'token' => $token]);
        try {
            $response = $this->http->request('POST', $requestUrl,
                ['allowed_hosts' => ['data.zz.baidu.com'], 'allowed_ports' => [443], 'max_redirects' => 0, 'total_timeout' => 20, 'max_bytes' => 512000],
                implode("\n", $urls), ['Content-Type: text/plain; charset=UTF-8', 'Accept: application/json']);
        } catch (\Throwable) {
            // A timeout may occur after provider accepts the request; no automatic blind retry.
            return ['status' => 'unknown', 'error' => 'Kết quả truyền không xác định; cần kiểm tra trước khi gửi lại.', 'receipt' => null, 'remaining' => null, 'indexed' => 'unknown'];
        }
        if ($response->status === 429 || $response->status >= 500) {
            return ['status' => 'retry_wait', 'error' => 'Baidu HTTP '.$response->status, 'receipt' => null,
                'retry_after' => min(3600, max(30, (int) ($response->headers['retry-after'] ?? 60))), 'remaining' => null, 'indexed' => 'unknown'];
        }
        try {
            $data = $response->json();
        } catch (\Throwable) {
            return ['status' => 'unknown', 'error' => 'Baidu trả schema không hợp lệ.', 'receipt' => null, 'remaining' => null, 'indexed' => 'unknown'];
        }
        if (isset($data['error']) || $response->status < 200 || $response->status >= 300) {
            return ['status' => 'rejected', 'error' => 'Baidu từ chối: '.mb_substr((string) ($data['message'] ?? $data['error'] ?? $response->status), 0, 500),
                'receipt' => $data, 'remaining' => null, 'indexed' => 'unknown'];
        }
        if (! isset($data['success']) || ! is_int($data['success']) || $data['success'] < 0 || $data['success'] > count($urls)
            || (isset($data['remain']) && (! is_int($data['remain']) || $data['remain'] < 0))
            || (isset($data['not_same_site']) && ! is_array($data['not_same_site'])) || (isset($data['not_valid']) && ! is_array($data['not_valid']))) {
            return ['status' => 'unknown', 'error' => 'Baidu receipt không khớp schema đã kiểm tra.', 'receipt' => $data, 'remaining' => null, 'indexed' => 'unknown'];
        }

        return ['status' => $data['success'] === count($urls) ? 'submitted' : ($data['success'] > 0 ? 'partial' : 'rejected'),
            'error' => null, 'receipt' => $data, 'remaining' => $data['remain'] ?? null, 'indexed' => 'unknown'];
    }
}
