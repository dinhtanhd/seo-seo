<?php

namespace App\Domain\Publishing;

final class PublicRequestPolicyReader
{
    public static function file(string $root, string $database, string $host, ?string $path = null): ?string
    {
        $root = rtrim($root, '/');
        if (! str_starts_with($root, '/') || str_contains($root, "\0") || str_contains($root, '/../')
            || preg_match('/^[A-Za-z0-9_-]{1,63}$/D', $database) !== 1 || strlen($host) > 253
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $host) !== 1) {
            return null;
        }
        if ($path !== null && (strlen($path) > 2000 || preg_match('~^/(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*(?:\.[a-z][a-z0-9]{0,11})?)?$~D', $path) !== 1)) {
            return null;
        }
        $hash = $path !== null ? hash('sha256', $path) : null;

        return $path === null ? $root.'/'.$database.'/'.$host.'/.domain-policy.json'
            : $root.'/'.$database.'/.request-policies/'.$host.'/'.substr($hash, 0, 2).'/'.$hash.'.json';
    }

    public static function safe(string $file): bool
    {
        $parts = explode('/', ltrim($file, '/'));
        $current = '';
        foreach ($parts as $index => $part) {
            $current .= '/'.$part;
            if (is_link($current) || (file_exists($current) && $index < count($parts) - 1 && ! is_dir($current))) {
                return false;
            }
        }

        return ! file_exists($file) || is_file($file);
    }

    /** @return array{plain?: string, runtime?: string}|null */
    public function read(array $server): ?array
    {
        $policy = ($server['TRAM_PUBLIC_CACHE_POLICY_REQUEST'] ?? '') === '1';
        if (! $policy && ($server['TRAM_DOMAIN_AUTH_REQUEST'] ?? '') !== '1') {
            return null;
        }
        $host = strtolower(rtrim(preg_replace('/:[0-9]+$/D', '', (string) ($server['HTTP_HOST'] ?? '')), '.'));
        $admins = explode(',', (string) ($server['ADMIN_HOSTS'] ?? getenv('ADMIN_HOSTS') ?: 'localhost,127.0.0.1,admin.localhost'));
        if (in_array($host, array_map('trim', $admins), true)) {
            return null;
        }
        $root = (string) ($server['TRAM_REQUEST_POLICY_ROOT'] ?? '');
        $database = (string) ($server['TRAM_REQUEST_POLICY_DATABASE'] ?? '');
        $domain = self::file($root, $database, $host);
        if ($domain === null || ($entry = $this->json($domain)) === null
            || ($entry['schema'] ?? null) !== 1 || ($entry['kind'] ?? null) !== 'domain'
            || ($entry['database'] ?? null) !== $database || ($entry['host'] ?? null) !== $host
            || ! is_int($entry['site_id'] ?? null) || $entry['site_id'] < 1) {
            return null;
        }
        if (! $policy) {
            return [];
        }
        $path = (string) ($server['TRAM_PUBLIC_CACHE_POLICY_PATH'] ?? '/');
        $file = self::file($root, $database, $host, $path);
        if ($file === null || ($csp = $this->json($file)) === null || ($csp['schema'] ?? null) !== 1
            || ($csp['kind'] ?? null) !== 'csp' || ($csp['database'] ?? null) !== $database
            || ($csp['host'] ?? null) !== $host || ($csp['site_id'] ?? null) !== $entry['site_id']
            || ($csp['path_hash'] ?? null) !== hash('sha256', $path)) {
            return null;
        }
        foreach (['plain', 'runtime'] as $key) {
            if (! is_string($csp[$key] ?? null) || $csp[$key] === '' || strlen($csp[$key]) > 16000
                || preg_match('/[\r\n\x00]/', $csp[$key])) {
                return null;
            }
        }

        return ['plain' => $csp['plain'], 'runtime' => $csp['runtime']];
    }

    private function json(string $file): ?array
    {
        if (! self::safe($file) || ! is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file, false, null, 0, 32769);
        if ($json === false || strlen($json) > 32768) {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }
}
