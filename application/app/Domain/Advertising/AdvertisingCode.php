<?php

namespace App\Domain\Advertising;

use App\Domain\Ingestion\Http\NetworkGuard;
use Illuminate\Validation\ValidationException;

final class AdvertisingCode
{
    /** @return array{code:string,compiled_js:string,origins:array,inline_hashes:array,has_code:bool} */
    public static function compile(string $code, mixed $extraOrigins = []): array
    {
        if (strlen($code) > 65536 || ! mb_check_encoding($code, 'UTF-8') || str_contains($code, "\0")) {
            self::fail('code', 'Mã quảng cáo cần là UTF-8, tối đa 64 KiB và không chứa ký tự NUL.');
        }
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        $origins = self::extraOrigins($extraOrigins);
        $inlineHashes = [];
        $compiled = $code;
        $remaining = self::skipComments($code);
        if ($remaining === '') {
            $compiled = '';
        } elseif (preg_match('~^https://[^\s]+$~iD', trim($code)) === 1) {
            $url = trim($code);
            $origins[] = self::origin($url, 'code');
            $fragment = '<script src="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"></script>';
            $compiled = self::write($fragment);
        } elseif (str_starts_with($remaining, '<')) {
            $rest = $code;
            $scripts = 0;
            $hasExecutableCode = false;
            while (($rest = self::skipComments($rest)) !== '') {
                if (! preg_match('~\A<script(?=[\s>])((?:[^>\'"<]|"[^"]*"|\'[^\']*\')*)>~i', $rest, $open)) {
                    self::fail('code', 'Chỉ dán JavaScript hoặc các thẻ script; không chèn HTML khác giữa các thẻ.');
                }
                $attributes = self::attributes($open[1]);
                $bodyStart = strlen($open[0]);
                if (! preg_match('~</script(?=[\s/>])[^>]*>~i', $rest, $close, PREG_OFFSET_CAPTURE, $bodyStart)
                    || preg_match('~^</script\s*>$~iD', $close[0][0]) !== 1) {
                    self::fail('code', 'Thẻ script cần có thẻ đóng hợp lệ.');
                }
                $body = substr($rest, $bodyStart, $close[0][1] - $bodyStart);
                if (isset($attributes['src'])) {
                    $origins[] = self::origin($attributes['src'], 'code');
                    $hasExecutableCode = true;
                } else {
                    $inlineHashes[] = 'sha256-'.base64_encode(hash('sha256', $body, true));
                    $hasExecutableCode = $hasExecutableCode || trim($body) !== '';
                }
                $scripts++;
                if ($scripts > 64) {
                    self::fail('code', 'Mã quảng cáo có tối đa 64 thẻ script.');
                }
                $rest = substr($rest, $close[0][1] + strlen($close[0][0]));
            }
            $compiled = $hasExecutableCode ? self::write($code) : '';
        }
        preg_match_all('~https://[^\s"\'<>`\\\\]+~iu', str_replace('\\/', '/', $code), $urls);
        foreach ($urls[0] as $url) {
            $origins[] = self::origin($url, 'code');
        }
        $origins = array_values(array_unique($origins));
        sort($origins, SORT_STRING);
        $inlineHashes = array_values(array_unique($inlineHashes));
        if (count($origins) > 64 || strlen(implode(' ', [...$origins, ...$inlineHashes])) > 8192) {
            self::fail('code', 'Danh sách miền và mã script quá lớn; rút gọn mã hoặc các miền bổ sung.');
        }

        return ['code' => $code, 'compiled_js' => $compiled, 'origins' => $origins,
            'inline_hashes' => $inlineHashes, 'has_code' => trim($compiled) !== ''];
    }

    private static function write(string $fragment): string
    {
        return 'document.write('.json_encode($fragment, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR).');';
    }

    private static function skipComments(string $text): string
    {
        do {
            $text = ltrim($text);
            if (! str_starts_with($text, '<!--')) {
                return $text;
            }
            $end = strpos($text, '-->');
            if ($end === false) {
                self::fail('code', 'Chú thích HTML chưa được đóng.');
            }
            $text = substr($text, $end + 3);
        } while (true);
    }

    /** @return array<string,string|null> */
    private static function attributes(string $text): array
    {
        $attributes = [];
        while (($text = ltrim($text)) !== '') {
            if (! preg_match('/\A([a-zA-Z][a-zA-Z0-9_.:-]*)(.*)\z/s', $text, $match)) {
                self::fail('code', 'Thuộc tính trong thẻ script không hợp lệ.');
            }
            $name = strtolower($match[1]);
            if (array_key_exists($name, $attributes) || (! in_array($name, ['src', 'async', 'defer', 'type', 'charset', 'integrity', 'crossorigin', 'id', 'nomodule'], true)
                && preg_match('/^data-[a-z0-9][a-z0-9_.:-]*$/D', $name) !== 1)) {
                self::fail('code', 'Thuộc tính script không được hỗ trợ: '.$name.'.');
            }
            $text = ltrim($match[2]);
            $value = null;
            if (str_starts_with($text, '=')) {
                $text = ltrim(substr($text, 1));
                $quote = $text[0] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $end = strpos($text, $quote, 1);
                    if ($end === false) {
                        self::fail('code', 'Giá trị thuộc tính script chưa đóng dấu nháy.');
                    }
                    $value = substr($text, 1, $end - 1);
                    $text = substr($text, $end + 1);
                    if ($text !== '' && ! preg_match('/^\s/', $text)) {
                        self::fail('code', 'Các thuộc tính script cần được ngăn cách bằng khoảng trắng.');
                    }
                } elseif (preg_match('/\A([^\s"\'`=<>]+)(.*)\z/s', $text, $unquoted)) {
                    $value = $unquoted[1];
                    $text = $unquoted[2];
                } else {
                    self::fail('code', 'Giá trị thuộc tính script không hợp lệ.');
                }
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (! in_array($name, ['async', 'defer', 'nomodule'], true) && $value === null) {
                self::fail('code', 'Thuộc tính '.$name.' cần có giá trị.');
            }
            if ($name === 'type' && ! in_array(strtolower(trim($value)), ['', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'module'], true)) {
                self::fail('code', 'Chỉ hỗ trợ script JavaScript hoặc module.');
            }
            if ($name === 'charset' && preg_match('/^[a-zA-Z0-9._-]{1,40}$/D', $value) !== 1) {
                self::fail('code', 'Charset của script không hợp lệ.');
            }
            if ($name === 'integrity' && preg_match('~^(?:sha(?:256|384|512)-[A-Za-z0-9+/]+={0,2})(?:\s+sha(?:256|384|512)-[A-Za-z0-9+/]+={0,2})*$~D', $value) !== 1) {
                self::fail('code', 'Integrity của script không hợp lệ.');
            }
            if ($name === 'crossorigin' && ! in_array(strtolower($value), ['', 'anonymous', 'use-credentials'], true)) {
                self::fail('code', 'Crossorigin của script không hợp lệ.');
            }
            if ($name === 'id' && ($value === '' || preg_match('/[\s<>\x00-\x1f\x7f]/u', $value))) {
                self::fail('code', 'ID của script không hợp lệ.');
            }
            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /** @return list<string> */
    private static function extraOrigins(mixed $input): array
    {
        $input ??= [];
        if (is_string($input)) {
            if (strlen($input) > 20000 || ! mb_check_encoding($input, 'UTF-8')) {
                self::fail('extra_origins', 'Danh sách địa chỉ HTTPS quá dài hoặc không phải UTF-8.');
            }
            $input = array_values(array_filter(array_map('trim', preg_split('/\R/u', $input) ?: []), fn (string $item): bool => $item !== ''));
        }
        if (! is_array($input) || ! array_is_list($input) || count($input) > 64) {
            self::fail('extra_origins', 'Điền tối đa 64 địa chỉ gốc HTTPS, mỗi dòng một địa chỉ.');
        }
        $origins = [];
        foreach ($input as $url) {
            if (! is_string($url)) {
                self::fail('extra_origins', 'Địa chỉ gốc phải là một chuỗi HTTPS.');
            }
            $origins[] = self::origin(trim($url), 'extra_origins', true);
        }

        return array_values(array_unique($origins));
    }

    private static function origin(string $url, string $field, bool $originOnly = false): string
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            self::fail($field, 'Địa chỉ HTTPS có ký tự không hợp lệ.');
        }
        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            $parts = false;
        }
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ($originOnly && (isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)))) {
            self::fail($field, 'Chỉ chấp nhận HTTPS công khai, không tài khoản; miền bổ sung không có đường dẫn, query hoặc fragment.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! NetworkGuard::isPublicAddress($host)) {
                self::fail($field, 'Không sử dụng địa chỉ mạng nội bộ hoặc dành riêng.');
            }
            $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;
        } else {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
            if (strlen($host) > 253 || ! str_contains($host, '.') || str_ends_with($host, '.')
                || preg_match('/(^|\.)(?:localhost|local|internal|test|invalid|example)$/iD', $host)) {
                self::fail($field, 'Hostname phải là một tên miền công khai hợp lệ.');
            }
            foreach (explode('.', $host) as $label) {
                if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                    self::fail($field, 'Hostname phải là một tên miền công khai hợp lệ.');
                }
            }
            $last = substr($host, strrpos($host, '.') + 1);
            if (preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/D', $last) !== 1) {
                self::fail($field, 'Tên miền công khai cần có phần mở rộng hợp lệ.');
            }
            $displayHost = $host;
        }
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            self::fail($field, 'Port HTTPS không hợp lệ.');
        }

        return 'https://'.$displayHost.($port === 443 ? '' : ':'.$port);
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
