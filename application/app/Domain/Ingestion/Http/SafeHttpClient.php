<?php

namespace App\Domain\Ingestion\Http;

use App\Domain\Ingestion\Connectors\RetryableFetchException;

final class SafeHttpClient implements HttpTransport
{
    public function __construct(private readonly NetworkGuard $guard = new NetworkGuard) {}

    public function request(string $method, string $url, array $policy, ?string $body = null, array $headers = []): HttpResponse
    {
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('Cần extension cURL cho connector HTTP.');
        }
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('HTTP method không được hỗ trợ.');
        }
        $maxRedirects = min(5, max(0, (int) ($policy['max_redirects'] ?? 3)));
        $deadline = microtime(true) + min(60, max(1, (int) ($policy['total_timeout'] ?? 25)));
        $receivedBytes = 0;
        for ($redirect = 0; ; $redirect++) {
            $target = $this->guard->validate($url, $policy);
            $result = $this->once($method, $url, $target, $policy, $body, $headers, $deadline, $receivedBytes);
            if (! in_array($result->status, [301, 302, 303, 307, 308], true)) {
                return $result;
            }
            if ($method !== 'GET' || $redirect >= $maxRedirects || ! isset($result->headers['location'])) {
                throw new \RuntimeException('Redirect bị chặn hoặc vượt giới hạn.');
            }
            $url = self::resolveUrl($url, $result->headers['location']);
        }
    }

    private function once(string $method, string $url, array $target, array $policy, ?string $body, array $headers, float $deadline, int &$receivedBytes): HttpResponse
    {
        $curl = curl_init($url);
        $responseHeaders = [];
        $payload = '';
        $budgetError = null;
        $maxBytes = min(20 * 1024 * 1024, max(1024, (int) ($policy['max_bytes'] ?? 5 * 1024 * 1024)));
        $maxWire = min($maxBytes, max(1024, (int) ($policy['max_wire_bytes'] ?? $maxBytes)));
        $maxRatio = min(100, max(1, (int) ($policy['max_decompression_ratio'] ?? 30)));
        $remainingMs = max(1, (int) (($deadline - microtime(true)) * 1000));
        $ip = $target['addresses'][0];
        $resolvedIp = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['User-Agent: TramSEO/1.0 metadata-import', 'Accept: application/json, text/html;q=0.8', 'Connection: close'], $headers),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $remainingMs),
            CURLOPT_TIMEOUT_MS => $remainingMs,
            CURLOPT_ENCODING => '',
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$resolvedIp],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function ($handle, $downloadTotal, $downloadNow) use (&$budgetError, $maxWire) {
                if ($downloadTotal > $maxWire || $downloadNow > $maxWire) {
                    $budgetError = 'Payload truyền vượt giới hạn.';

                    return 1;
                }

                return 0;
            },
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$responseHeaders, &$budgetError, $maxWire): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                    if (strtolower(trim($key)) === 'content-length' && (int) trim($value) > $maxWire) {
                        $budgetError = 'Content-Length vượt giới hạn.';

                        return 0;
                    }
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$payload, &$receivedBytes, &$budgetError, $maxBytes): int {
                $length = strlen($chunk);
                $receivedBytes += $length;
                if ($receivedBytes > $maxBytes) {
                    $budgetError = 'Payload giải nén vượt ngân sách.';

                    return 0;
                }
                $payload .= $chunk;

                return $length;
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorCode = curl_errno($curl);
        $error = curl_error($curl);
        $downloadedBytes = (int) curl_getinfo($curl, CURLINFO_SIZE_DOWNLOAD_T);
        curl_close($curl);
        if ($budgetError === null && $downloadedBytes > $maxWire) {
            $budgetError = 'Payload truyền vượt giới hạn.';
        }
        if ($budgetError === null && $ok !== false && strlen($payload) > max(1024, $downloadedBytes * $maxRatio)) {
            $budgetError = 'Payload giải nén vượt ngân sách.';
        }
        if ($ok === false || $budgetError) {
            $message = $budgetError ?? 'Lỗi truyền HTTP: '.$error;
            if ($budgetError === null && $method === 'GET' && $this->transientCurlError($errorCode)) {
                throw new RetryableFetchException($message, 30);
            }
            throw new \RuntimeException($message);
        }

        return new HttpResponse($status, $responseHeaders, $payload, $url);
    }

    /** HTTP/2's stable libcurl error codes are not exposed as constants by every PHP cURL build. */
    private function transientCurlError(int $code): bool
    {
        return in_array($code, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_PARTIAL_FILE,
            CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_SEND_ERROR, CURLE_RECV_ERROR, 16, 92], true);
    }

    public static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        if (str_starts_with($location, '?')) {
            return $origin.($parts['path'] ?? '/').$location;
        }
        $path = dirname($parts['path'] ?? '/').'/'.$location;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $origin.'/'.implode('/', $segments);
    }
}
