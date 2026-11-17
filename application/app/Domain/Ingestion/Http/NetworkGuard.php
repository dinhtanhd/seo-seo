<?php

namespace App\Domain\Ingestion\Http;

class NetworkGuard
{
    public function __construct(private readonly DnsResolver $dns = new DnsResolver) {}

    public function validate(string $url, array $policy): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('URL có ký tự không hợp lệ.');
        }
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Chỉ chấp nhận HTTP/HTTPS không có user-info hoặc fragment.');
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || str_ends_with($host, '.') || preg_match('/[^a-z0-9.:-]/', $host)) {
            throw new \InvalidArgumentException('Hostname cần ở dạng ASCII/IDNA đã chuẩn hóa.');
        }
        $allowed = array_map('strtolower', $policy['allowed_hosts'] ?? []);
        if (! in_array($host, $allowed, true)) {
            throw new \InvalidArgumentException('Hostname chưa được cho phép.');
        }
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        if (! in_array($port, $policy['allowed_ports'] ?? [80, 443], true)) {
            throw new \InvalidArgumentException('Port chưa được cho phép.');
        }
        $addresses = $this->dns->resolve($host);
        if (! $addresses) {
            throw new \RuntimeException('DNS nguồn không có địa chỉ.');
        }
        foreach ($addresses as $ip) {
            if (! self::isPublicAddress($ip)) {
                throw new \InvalidArgumentException('DNS chứa địa chỉ nội bộ hoặc địa chỉ dành riêng.');
            }
        }

        return ['host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    public static function isPublicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $bytes = inet_pton($ip);
        if (! $bytes) {
            return false;
        }
        $ranges = strlen($bytes) === 4
            ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']
            : ['::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8'];
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $base = inet_pton($network);
            $whole = intdiv((int) $bits, 8);
            $partial = (int) $bits % 8;
            if (substr($bytes, 0, $whole) === substr($base, 0, $whole)
                && (! $partial || ((ord($bytes[$whole]) ^ ord($base[$whole])) & (0xFF << (8 - $partial))) === 0)) {
                return false;
            }
        }

        // Restrict IPv6 to global unicast, excluding mapped/tunnel/local special ranges above.
        return strlen($bytes) === 4 || ((ord($bytes[0]) & 0xE0) === 0x20);
    }
}
