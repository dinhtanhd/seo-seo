<?php

namespace App\Domain\Ingestion\Connectors;

use App\Domain\Ingestion\CanonicalRecord;
use App\Domain\Ingestion\Http\HttpTransport;
use App\Domain\Ingestion\Http\SafeHttpClient;

class MacCmsConnector implements Connector
{
    public const PARSER_VERSION = 'maccms-json-v1';

    public function __construct(private readonly HttpTransport $http = new SafeHttpClient) {}

    public function validateConfiguration(array $config): void
    {
        $parts = parse_url($config['endpoint'] ?? '');
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($config['allowed_hosts'])
            || ! in_array(strtolower($parts['host'] ?? ''), array_map('strtolower', $config['allowed_hosts']), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Endpoint MacCMS và allowlist không hợp lệ.');
        }
        new \DateTimeZone($config['source_timezone'] ?? 'Asia/Shanghai');
        if (isset($config['hours']) && ((int) $config['hours'] < 1 || (int) $config['hours'] > 720)) {
            throw new \InvalidArgumentException('Cửa sổ incremental hours phải từ 1 đến 720.');
        }
    }

    public function fetchBatch(array $config, ?string $cursor = null): FetchBatch
    {
        $this->validateConfiguration($config);
        $page = $cursor === null ? 1 : filter_var($cursor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $page) {
            throw new \InvalidArgumentException('Cursor MacCMS không hợp lệ.');
        }
        $params = ['ac' => 'detail', 'pg' => $page];
        foreach (['hours' => 'h', 'category_id' => 't'] as $option => $parameter) {
            if (isset($config[$option])) {
                $params[$parameter] = $config[$option];
            }
        }
        $url = rtrim($config['endpoint'], '?&').(str_contains($config['endpoint'], '?') ? '&' : '?').http_build_query($params);
        $response = $this->http->request('GET', $url, $config);
        if ($response->status === 429 || $response->status >= 500) {
            $retryAfter = min(3600, max(1, (int) ($response->headers['retry-after'] ?? 30)));
            throw new RetryableFetchException('Nguồn tạm thời không sẵn sàng (HTTP '.$response->status.').', $retryAfter);
        }
        if ($response->status !== 200) {
            throw new \RuntimeException('Nguồn từ chối HTTP '.$response->status.'.');
        }
        $mime = strtolower(explode(';', $response->headers['content-type'] ?? '')[0]);
        if (! in_array($mime, $config['json_mime_types'] ?? ['application/json', 'text/json', 'text/plain'], true)) {
            throw new \RuntimeException('MacCMS trả MIME không hợp lệ: '.$mime);
        }
        $data = $response->json();
        $responsePage = filter_var($data['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageCount = filter_var($data['pagecount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ((int) ($data['code'] ?? 0) !== 1 || ! isset($data['list'], $data['page'], $data['pagecount'], $data['total'])
            || ! is_array($data['list']) || ! array_is_list($data['list']) || is_bool($data['page']) || is_bool($data['pagecount'])
            || $responsePage !== $page || $pageCount === false || count($data['list']) > 1000) {
            throw new \RuntimeException('Schema MacCMS không khớp; không coi response lỗi là nguồn rỗng.');
        }
        $records = [];
        foreach ($data['list'] as $record) {
            try {
                $records[] = $this->normalizeRecord($record, $url, $config);
            } catch (\Throwable $e) {
                $records[] = ['external_id' => is_array($record) ? ($record['vod_id'] ?? null) : null, '_parse_error' => $e->getMessage(), '_raw' => $record];
            }
        }
        $hasMore = $page < $pageCount;

        return new FetchBatch($records, $hasMore ? (string) ($page + 1) : null, $hasMore, $url, [], ['page' => $page, 'pagecount' => $pageCount, 'total' => (int) $data['total']]);
    }

    /** Fetch a bounded set of identities without incremental or category filters. */
    public function fetchByIds(array $config, array $ids): FetchBatch
    {
        $this->validateConfiguration($config);
        if ($ids === [] || count($ids) > 50 || ! array_is_list($ids)) {
            throw new \InvalidArgumentException('Mỗi yêu cầu nhận từ 1 đến 50 ID phim.');
        }
        $requested = [];
        foreach ($ids as $id) {
            $normalized = self::typeId($id);
            if ($normalized === null) {
                throw new \InvalidArgumentException('ID phim MacCMS phải là số nguyên dương.');
            }
            $requested[$normalized] = $normalized;
        }
        $parts = parse_url($config['endpoint']);
        parse_str($parts['query'] ?? '', $query);
        unset($query['ac'], $query['pg'], $query['h'], $query['t'], $query['ids']);
        $query['ids'] = implode(',', $requested);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $config['endpoint'] = $parts['scheme'].'://'.$parts['host'].$port.($parts['path'] ?? '/').'?'.http_build_query($query);
        unset($config['hours'], $config['category_id']);
        $batch = $this->fetchBatch($config);
        foreach ($batch->records as $record) {
            if (! is_array($record) || ! isset($record['external_id']) || ! isset($requested[(string) $record['external_id']])) {
                throw new \RuntimeException('API trả phim nằm ngoài danh sách ID đã yêu cầu.');
            }
        }

        return $batch;
    }

    /** The catalog is independent of video pagination and the single-type API t filter. */
    public function fetchTypes(array $config): array
    {
        $this->validateConfiguration($config);
        $parts = parse_url($config['endpoint']);
        parse_str($parts['query'] ?? '', $query);
        unset($query['t'], $query['pg'], $query['h'], $query['ids']);
        $query['ac'] = 'list';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $url = $parts['scheme'].'://'.$parts['host'].$port.($parts['path'] ?? '/').'?'.http_build_query($query);
        $response = $this->http->request('GET', $url, $config);
        if ($response->status === 429 || $response->status >= 500) {
            throw new RetryableFetchException('Nguồn tạm thời không trả danh sách thể loại.', min(3600, max(1, (int) ($response->headers['retry-after'] ?? 30))));
        }
        if ($response->status !== 200) {
            throw new \RuntimeException('Nguồn từ chối catalog HTTP '.$response->status.'.');
        }
        $mime = strtolower(trim(explode(';', $response->headers['content-type'] ?? '')[0]));
        if (! in_array($mime, $config['json_mime_types'] ?? ['application/json', 'text/json', 'text/plain'], true)) {
            throw new \RuntimeException('Catalog MacCMS trả MIME không hợp lệ: '.$mime);
        }
        $data = $response->json();
        if (! in_array($data['code'] ?? null, [1, '1'], true) || ! isset($data['class']) || ! is_array($data['class'])
            || ! array_is_list($data['class']) || count($data['class']) > 5000) {
            throw new \RuntimeException('Schema class của catalog MacCMS không hợp lệ.');
        }
        $types = [];
        $seen = [];
        foreach ($data['class'] as $row) {
            if (! is_array($row) || ! is_string($row['type_name'] ?? null) || trim($row['type_name']) === ''
                || ! mb_check_encoding($row['type_name'], 'UTF-8') || mb_strlen($row['type_name']) > 200 || str_contains($row['type_name'], "\0")) {
                throw new \RuntimeException('Tên thể loại MacCMS không hợp lệ.');
            }
            $id = self::typeId($row['type_id'] ?? null);
            if ($id === null || isset($seen[$id])) {
                throw new \RuntimeException('ID thể loại MacCMS thiếu, sai hoặc bị trùng.');
            }
            $parent = null;
            if (isset($row['type_pid']) && ! in_array($row['type_pid'], [0, '0', ''], true)) {
                $parent = self::typeId($row['type_pid']);
                if ($parent === null || $parent === $id) {
                    throw new \RuntimeException('ID cha thể loại MacCMS không hợp lệ.');
                }
            }
            $seen[$id] = true;
            $types[] = ['id' => $id, 'name' => trim($row['type_name']), 'parent_id' => $parent];
        }
        // Only preserve an explicitly supplied parent; never infer it from numeric IDs.
        $indexed = array_column($types, null, 'id');
        foreach ($types as $type) {
            if ($type['parent_id'] !== null && ! isset($seen[$type['parent_id']])) {
                throw new \RuntimeException('Catalog có thể loại cha không tồn tại.');
            }
            $parents = [];
            $cursor = $type['id'];
            while ($cursor !== null) {
                if (isset($parents[$cursor])) {
                    throw new \RuntimeException('Catalog có chu trình thể loại.');
                }
                $parents[$cursor] = true;
                $cursor = $indexed[$cursor]['parent_id'] ?? null;
            }
        }
        usort($types, fn ($a, $b) => strnatcmp($a['id'], $b['id']));

        return $types;
    }

    public static function typeId(mixed $id): ?string
    {
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^[1-9][0-9]{0,18}$/', (string) $id)) {
            return null;
        }

        return (string) $id;
    }

    public function normalizeRecord(array $record, string $sourceUrl, array $config = []): array
    {
        foreach (['vod_class', 'type_name', 'vod_actor', 'vod_director', 'vod_year', 'vod_pic', 'vod_content', 'vod_play_from', 'vod_play_url', 'vod_time'] as $field) {
            if (isset($record[$field]) && ! is_scalar($record[$field])) {
                throw new \InvalidArgumentException('MacCMS field phải là scalar: '.$field);
            }
        }
        if (! isset($record['vod_id'], $record['vod_name']) || ! is_scalar($record['vod_id']) || ! is_string($record['vod_name'])
            || trim((string) $record['vod_id']) === '' || trim($record['vod_name']) === '') {
            throw new \InvalidArgumentException('MacCMS record thiếu vod_id/vod_name.');
        }
        $splitPeople = fn ($value) => array_values(array_filter(array_map('trim', preg_split('/[,，、\/]+/u', (string) $value))));
        $episodes = [];
        $providers = explode('$$$', (string) ($record['vod_play_from'] ?? ''));
        foreach (explode('$$$', (string) ($record['vod_play_url'] ?? '')) as $providerIndex => $list) {
            foreach (array_filter(explode('#', $list)) as $index => $entry) {
                [$label, $link] = array_pad(explode('$', $entry, 2), 2, null);
                if ($link === null) {
                    continue;
                }
                try {
                    $link = CanonicalRecord::dataUrl(str_replace('\\/', '/', $link));
                } catch (\InvalidArgumentException) {
                    $link = null;
                }
                $episodes[] = ['external_id' => (string) $record['vod_id'].'/'.($providers[$providerIndex] ?? $providerIndex).'/'.($index + 1), 'sequence_no' => $index + 1,
                    'title' => $label, 'provider' => $providers[$providerIndex] ?? null, 'source_link' => $link, 'trusted' => false];
            }
        }
        $updated = $record['vod_time'] ?? null;
        $warnings = [];
        $updatedAt = null;
        if ($updated) {
            try {
                $updatedAt = (new \DateTimeImmutable($updated, new \DateTimeZone($config['source_timezone'] ?? 'Asia/Shanghai')))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
            } catch (\Throwable) {
                $warnings[] = 'source_updated_at_invalid';
            }
        }

        return ['external_id' => (string) $record['vod_id'], 'kind' => 'movie', 'title' => $record['vod_name'],
            'genre' => (string) (($record['vod_class'] ?? '') ?: ($record['type_name'] ?? '')), 'category_external_id' => isset($record['type_id']) ? (string) $record['type_id'] : null,
            'category_name' => $record['type_name'] ?? null, 'author' => null, 'directors' => $splitPeople($record['vod_director'] ?? ''),
            'actors' => $splitPeople($record['vod_actor'] ?? ''), 'year' => $record['vod_year'] ?? null, 'area' => $record['vod_area'] ?? null,
            'language' => $record['vod_lang'] ?? null, 'remarks' => $record['vod_remarks'] ?? null,
            'poster' => isset($record['vod_pic']) ? str_replace('\\/', '/', $record['vod_pic']) : null, 'body_html' => $record['vod_content'] ?? '', 'episodes' => $episodes,
            'source_url' => $sourceUrl, 'source_updated_at' => $updatedAt, 'parser_version' => self::PARSER_VERSION,
            'warnings' => $warnings, 'source_payload_hash' => hash('sha256', CanonicalRecord::stableJson($record))];
    }
}
