<?php

namespace App\Domain\Publishing;

final class HtmlCacheReader
{
    /**
     * @param  array<string, mixed>  $server  Trusted FastCGI parameters, not HTTP headers.
     * @return array{stream: resource, size: int}|null
     */
    public function open(array $server): ?array
    {
        if (($server['TRAM_PUBLIC_HTML_CACHE_REQUEST'] ?? '') !== '1'
            || ! in_array($server['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
            || ($server['HTTP_COOKIE'] ?? '') !== '' || ($server['HTTP_AUTHORIZATION'] ?? '') !== ''
            || ($server['QUERY_STRING'] ?? '') !== '') {
            return null;
        }
        $root = rtrim((string) ($server['TRAM_PUBLIC_HTML_CACHE_ROOT'] ?? ''), '/');
        $database = (string) ($server['TRAM_PUBLIC_HTML_CACHE_DATABASE'] ?? '');
        $host = (string) ($server['TRAM_PUBLIC_HTML_CACHE_HOST'] ?? '');
        $relative = (string) ($server['TRAM_PUBLIC_HTML_CACHE_FILE'] ?? '');
        if (! str_starts_with($root, '/') || is_link($root)
            || preg_match('/^[A-Za-z0-9_-]{1,63}$/D', $database) !== 1 || strlen($host) > 253
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $host) !== 1) {
            return null;
        }
        $prefix = '/'.$database.'/'.$host.'/';
        if (! str_starts_with($relative, $prefix)) {
            return null;
        }
        $parts = explode('/', substr($relative, strlen($prefix)));
        $name = array_pop($parts);
        if (! in_array($name, [
            'index.html', 'index.spider.html', 'index.runtime.html', 'index.runtime.spider.html',
            'index.advertising.html', 'index.advertising.spider.html',
            'index.runtime.advertising.html', 'index.runtime.advertising.spider.html',
        ], true)) {
            return null;
        }
        $suffix = ($parts[0] ?? null) === '.suffix';
        if ($suffix) {
            array_shift($parts);
        }
        $path = implode('/', $parts);
        $pattern = $suffix
            ? '~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.[a-z][a-z0-9]{0,11}$~D'
            : '~^(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)?$~D';
        if (preg_match($pattern, $path) !== 1 || preg_match('~^(admin|login|logout|api|up|readyz|theme-assets|theme-bundles|robots|sitemap|__tram_ads|__tram_public_cache_policy|__tram_public_html_cache|__tram_public_runtime_html_cache)(/|\.|$)~i', $path)
            || str_ends_with(strtolower($path), '.js')) {
            return null;
        }
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false) {
            return null;
        }
        $file = $canonicalRoot;
        foreach (explode('/', ltrim($relative, '/')) as $part) {
            $file .= '/'.$part;
            if (is_link($file)) {
                return null;
            }
        }
        if (! is_file($file) || realpath($file) !== $file) {
            return null;
        }
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            return null;
        }
        $stat = fstat($stream);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            fclose($stream);

            return null;
        }

        return ['stream' => $stream, 'size' => (int) $stat['size']];
    }
}
