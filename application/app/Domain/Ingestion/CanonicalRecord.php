<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Http\NetworkGuard;

final class CanonicalRecord
{
    public const VERSION = 1;

    public function __construct(private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer) {}

    public function normalize(array $record): array
    {
        foreach (['external_id', 'kind', 'title'] as $field) {
            if (! isset($record[$field]) || ! is_scalar($record[$field]) || trim((string) $record[$field]) === '') {
                throw new \InvalidArgumentException('Thiếu trường bắt buộc: '.$field);
            }
        }
        if (! in_array($record['kind'], ['movie', 'novel', 'episode', 'chapter', 'editorial'], true)) {
            throw new \InvalidArgumentException('Loại nội dung không hợp lệ.');
        }
        $result = $record;
        $result['original_title'] = (string) $record['title'];
        unset($result['key'], $result['source_connector_key']);
        foreach (['external_id', 'title', 'genre', 'author'] as $field) {
            if (isset($record[$field]) && ! is_scalar($record[$field])) {
                throw new \InvalidArgumentException('Trường văn bản phải là scalar: '.$field);
            }
            $result[$field] = isset($record[$field]) ? $this->text((string) $record[$field], $field === 'external_id' ? 255 : 1000) : null;
        }
        if ($result['external_id'] === '' || $result['title'] === '') {
            throw new \InvalidArgumentException('ID và tên nội dung không được trống.');
        }
        $result['body_html'] = $this->sanitizer->sanitize((string) ($record['body_html'] ?? ''));
        $result['source_url'] = self::dataUrl($record['source_url'] ?? null);
        $result['poster'] = self::dataUrl($record['poster_url'] ?? $record['poster'] ?? null);
        $result['poster_url'] = $result['poster'];
        $result['schema_version'] = self::VERSION;
        $result['parser_version'] = $record['parser_version'] ?? 'canonical-v1';

        return $result;
    }

    private function text(string $value, int $limit): string
    {
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Trường văn bản không phải UTF-8 hợp lệ.');
        }
        $value = preg_replace('/^\x{FEFF}/u', '', $value);
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException('Trường văn bản vượt giới hạn '.$limit.' ký tự.');
        }

        return $value;
    }

    public static function dataUrl(mixed $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (! is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Liên kết metadata không hợp lệ.');
        }
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Liên kết metadata chỉ chấp nhận HTTP/HTTPS.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ((filter_var($host, FILTER_VALIDATE_IP) && ! NetworkGuard::isPublicAddress($host))
            || $host === 'localhost' || ! str_contains($host, '.') && ! str_contains($host, ':')
            || preg_match('/\.(localhost|local|internal)$/', $host)) {
            throw new \InvalidArgumentException('Liên kết metadata không được trỏ địa chỉ nội bộ.');
        }

        return $url;
    }

    public static function stableJson(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $entry) {
                if (is_array($entry)) {
                    $value[$key] = $sort($entry);
                }
            }

            return $value;
        };

        return json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
