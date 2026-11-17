<?php

namespace App\Domain\Support;

use App\Domain\Ingestion\Connectors\HtmlSelectorConnector;
use App\Domain\Ingestion\Connectors\ShandianConnector;
use App\Domain\Ingestion\Http\NetworkGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SourceConfigurationForm
{
    /**
     * The legacy JSON payload remains a full replacement. Form payloads only
     * replace fields represented by the form, retaining other stored settings.
     */
    public static function fromRequest(Request $request, string $kind, array $current = []): array
    {
        if (! $request->has('source_form_present')) {
            $raw = $request->input('config_json', '{}');
            if (! is_string($raw)) {
                self::fail('config_json', 'Cấu hình nguồn phải là một đối tượng JSON.');
            }
            try {
                $object = json_decode($raw, false, 24, JSON_THROW_ON_ERROR);
                $config = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::fail('config_json', 'Cấu hình nguồn không phải JSON hợp lệ.');
            }
            if (! $object instanceof \stdClass) {
                self::fail('config_json', 'Cấu hình nguồn phải là một đối tượng JSON.');
            }

            return $config;
        }
        if (! is_scalar($request->input('source_form_present')) || (string) $request->input('source_form_present') !== '1') {
            self::fail('source_form_present', 'Dấu nhận diện form nguồn không hợp lệ.');
        }
        $form = $request->input('source_form', []);
        if (! is_array($form)) {
            self::fail('source_form', 'Các trường cấu hình nguồn không hợp lệ.');
        }
        $remote = in_array($kind, ['maccms', 'shandian', 'html'], true);
        $rules = [
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'source_timezone' => ['sometimes', 'required', 'string', 'timezone'],
            'max_pages' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'min_host_interval_ms' => ['sometimes', 'required', 'integer', 'min:250', 'max:5000'],
            'total_timeout' => ['sometimes', 'required', 'integer', 'min:1', 'max:60'],
            'max_redirects' => ['sometimes', 'required', 'integer', 'min:0', 'max:5'],
            'max_bytes' => ['sometimes', 'required', 'integer', 'min:1024', 'max:20971520'],
            'allowed_hosts' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'allowed_ports' => ['sometimes', 'nullable', 'string', 'max:200'],
            'json_mime_types' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'record_selector' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'next_selector' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'selector_type' => ['sometimes', 'required', 'in:css,xpath'],
            'content_kind' => ['sometimes', 'required', 'in:movie,novel,episode,chapter,editorial'],
        ];
        $allowed = array_merge(array_keys($rules), ['query_rows', 'query_rows_present', 'field_rows', 'field_rows_present', 'file_mapping_rows', 'file_mapping_rows_present']);
        foreach ($form as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                self::fail('source_form.'.$key, 'Trường cấu hình này không được hỗ trợ trong form.');
            }
            if (str_ends_with($key, '_rows_present') && (! is_scalar($value) || (string) $value !== '1')) {
                self::fail('source_form.'.$key, 'Dấu nhận diện danh sách cấu hình không hợp lệ.');
            }
        }
        $validation = Validator::make($form, $rules);
        if ($validation->fails()) {
            $errors = [];
            foreach ($validation->errors()->messages() as $key => $messages) {
                $errors['source_form.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $config = $current;
        foreach (['source_timezone', 'selector_type', 'content_kind'] as $key) {
            if (array_key_exists($key, $form)) {
                $config[$key] = $form[$key];
            }
        }
        foreach (['max_pages', 'min_host_interval_ms', 'total_timeout', 'max_redirects', 'max_bytes'] as $key) {
            if (array_key_exists($key, $form)) {
                $config[$key] = (int) $form[$key];
            }
        }
        foreach (['hours', 'category_id', 'record_selector', 'next_selector'] as $key) {
            if (! array_key_exists($key, $form)) {
                continue;
            }
            if ($form[$key] === null || trim((string) $form[$key]) === '') {
                unset($config[$key]);
            } else {
                $config[$key] = in_array($key, ['hours', 'category_id'], true) ? (int) $form[$key] : trim($form[$key]);
            }
        }
        if (array_key_exists('allowed_hosts', $form)) {
            $config['allowed_hosts'] = self::listValues($form['allowed_hosts'], 'allowed_hosts', 50);
            foreach ($config['allowed_hosts'] as &$host) {
                $host = strtolower($host);
                if (! self::safeHost($host)) {
                    self::fail('source_form.allowed_hosts', 'Chỉ nhập hostname công khai dạng ASCII/IDNA, không URL, wildcard hoặc địa chỉ nội bộ.');
                }
            }
            unset($host);
        }
        if (array_key_exists('allowed_ports', $form)) {
            $ports = self::listValues($form['allowed_ports'], 'allowed_ports', 20);
            $config['allowed_ports'] = [];
            foreach ($ports as $port) {
                if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                    self::fail('source_form.allowed_ports', 'Port phải là số từ 1 đến 65535.');
                }
                $config['allowed_ports'][] = (int) $port;
            }
        }
        if (array_key_exists('json_mime_types', $form)) {
            $config['json_mime_types'] = self::listValues($form['json_mime_types'], 'json_mime_types', 20);
            foreach ($config['json_mime_types'] as $mime) {
                if (! preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~i', $mime)) {
                    self::fail('source_form.json_mime_types', 'MIME phải có dạng application/json hoặc text/html.');
                }
            }
        }
        if (array_key_exists('endpoint', $form)) {
            $config['endpoint'] = trim($form['endpoint'] ?? '');
        }
        if (isset($form['query_rows_present'])) {
            $rows = self::rows($form, 'query_rows');
            $query = [];
            $normalizedRows = [];
            foreach ($rows as $index => $row) {
                $key = self::rowString($row, 'key', 'query_rows', $index, 150);
                $value = self::rowString($row, 'value', 'query_rows', $index, 2000, false);
                if ($key === '' && $value === '') {
                    continue;
                }
                if ($key === '' || ! preg_match('/^[a-zA-Z0-9_.\[\]-]+$/', $key)) {
                    self::fail('source_form.query_rows.'.$index.'.key', 'Tên tham số chỉ dùng chữ, số, dấu chấm, gạch và ngoặc vuông.');
                }
                $query[] = rawurlencode($key).'='.rawurlencode($value);
                $normalizedRows[] = ['key' => $key, 'value' => $value];
            }
            $enteredEndpoint = (string) ($config['endpoint'] ?? '');
            [$endpoint, $inlineQuery] = array_pad(explode('?', (string) ($config['endpoint'] ?? ''), 2), 2, '');
            $rowKeys = array_map(fn (string $pair): string => urldecode(explode('=', $pair, 2)[0]), $query);
            $inlinePairs = array_filter(explode('&', $inlineQuery), fn (string $pair): bool => $pair !== '' && ! in_array(urldecode(explode('=', $pair, 2)[0]), $rowKeys, true));
            $query = array_merge(array_values($inlinePairs), $query);
            $config['endpoint'] = $endpoint.($query ? '?'.implode('&', $query) : '');
            if (is_string($current['endpoint'] ?? null)) {
                $previous = self::values($current, $kind);
                if ((! array_key_exists('endpoint', $form) || $enteredEndpoint === $previous['endpoint']) && $normalizedRows === $previous['query_rows']) {
                    $config['endpoint'] = $current['endpoint'];
                }
            }
        }
        if (isset($form['file_mapping_rows_present'])) {
            $mapping = self::fileMapping(self::rows($form, 'file_mapping_rows'));
            if ($mapping !== [] || array_key_exists('field_mapping', $current)) {
                $config['field_mapping'] = $mapping;
            }
        }
        if ($kind === 'html' && isset($form['field_rows_present'])) {
            $config['fields'] = self::htmlFields(self::rows($form, 'field_rows'), is_array($current['fields'] ?? null) ? $current['fields'] : []);
        }
        if ($remote) {
            self::validateEndpoint($config);
        }
        if ($kind === 'html') {
            try {
                (new HtmlSelectorConnector)->validateConfiguration($config);
            } catch (\Throwable $exception) {
                self::fail('source_form.record_selector', 'Cấu hình selector HTML không hợp lệ: '.$exception->getMessage());
            }
        }

        return $config;
    }

    /** @return array<string, mixed> Plain form values, never executable markup. */
    public static function values(array $config, string $kind = 'maccms'): array
    {
        $defaults = ShandianConnector::defaultConfiguration();
        if (! in_array($kind, ['maccms', 'shandian'], true)) {
            $defaults['endpoint'] = '';
            $defaults['allowed_hosts'] = [];
            $defaults['json_mime_types'] = ['application/json', 'text/json', 'text/plain'];
        }
        $values = array_replace($defaults, $config);
        foreach (['allowed_hosts', 'allowed_ports', 'json_mime_types'] as $key) {
            $values[$key] = implode("\n", is_array($values[$key] ?? null) ? $values[$key] : []);
        }
        $endpoint = (string) ($values['endpoint'] ?? '');
        [$values['endpoint'], $query] = array_pad(explode('?', $endpoint, 2), 2, '');
        $values['query_rows'] = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $values['query_rows'][] = ['key' => urldecode($key), 'value' => urldecode($value)];
        }
        $values['file_mapping_rows'] = [];
        foreach (is_array($config['field_mapping'] ?? null) ? $config['field_mapping'] : [] as $canonical => $source) {
            $values['file_mapping_rows'][] = ['canonical' => (string) $canonical, 'source' => is_string($source) ? $source : ''];
        }
        $values['field_rows'] = [];
        foreach (is_array($config['fields'] ?? null) ? $config['fields'] : [] as $field => $definition) {
            $definition = is_string($definition) ? ['selector' => $definition] : $definition;
            if (! is_array($definition)) {
                continue;
            }
            $values['field_rows'][] = ['field' => (string) $field, 'selector' => (string) ($definition['selector'] ?? ''),
                'attribute' => (string) ($definition['attribute'] ?? ''), 'mode' => (string) ($definition['mode'] ?? 'text')];
        }
        if ($kind === 'html' && $values['field_rows'] === []) {
            $values['field_rows'] = [
                ['field' => 'external_id', 'selector' => '', 'attribute' => 'data-id', 'mode' => 'text'],
                ['field' => 'title', 'selector' => '', 'attribute' => '', 'mode' => 'text'],
            ];
        }
        $values['hours'] ??= '';
        $values['category_id'] ??= '';
        $values['record_selector'] ??= '';
        $values['next_selector'] ??= '';
        $values['selector_type'] ??= 'css';
        $values['content_kind'] ??= 'movie';

        $values = array_intersect_key($values, array_flip([
            'endpoint', 'allowed_hosts', 'allowed_ports', 'json_mime_types',
            'source_timezone', 'min_host_interval_ms', 'total_timeout',
            'max_redirects', 'max_bytes', 'hours', 'category_id', 'record_selector',
            'next_selector', 'selector_type', 'content_kind', 'query_rows',
            'file_mapping_rows', 'field_rows',
        ]));
        foreach ($values as $key => $value) {
            if (! in_array($key, ['query_rows', 'field_rows', 'file_mapping_rows'], true) && ! is_scalar($value) && $value !== null) {
                $values[$key] = '';
            }
        }

        return $values;
    }

    private static function fileMapping(array $rows): array
    {
        $mapping = [];
        foreach ($rows as $index => $row) {
            $canonical = self::rowString($row, 'canonical', 'file_mapping_rows', $index, 100);
            $source = self::rowString($row, 'source', 'file_mapping_rows', $index, 200);
            if ($canonical === '' && $source === '') {
                continue;
            }
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $canonical) || $source === '') {
                self::fail('source_form.file_mapping_rows.'.$index.'.canonical', 'Điền tên trường đích và tên cột/key nguồn; trường đích dùng chữ, số và gạch dưới.');
            }
            if (array_key_exists($canonical, $mapping)) {
                self::fail('source_form.file_mapping_rows.'.$index.'.canonical', 'Mỗi trường đích chỉ được ghép một lần.');
            }
            $mapping[$canonical] = $source;
        }

        return $mapping;
    }

    private static function htmlFields(array $rows, array $current): array
    {
        $fields = [];
        foreach ($rows as $index => $row) {
            $field = self::rowString($row, 'field', 'field_rows', $index, 100);
            $selector = self::rowString($row, 'selector', 'field_rows', $index, 1000);
            $attribute = self::rowString($row, 'attribute', 'field_rows', $index, 100);
            $mode = self::rowString($row, 'mode', 'field_rows', $index, 10);
            if ($field === '' && $selector === '' && $attribute === '') {
                continue;
            }
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field) || $selector === '') {
                self::fail('source_form.field_rows.'.$index.'.selector', 'Điền trường đích hợp lệ và selector cho trường đó.');
            }
            if (array_key_exists($field, $fields)) {
                self::fail('source_form.field_rows.'.$index.'.field', 'Mỗi trường đích chỉ được ghép một lần.');
            }
            if (! in_array($mode, ['text', 'html'], true) || ($attribute !== '' && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $attribute))) {
                self::fail('source_form.field_rows.'.$index.'.attribute', 'Thuộc tính hoặc kiểu lấy dữ liệu không hợp lệ.');
            }
            $original = $current[$field] ?? [];
            $projected = is_string($original) ? ['selector' => $original] : $original;
            if (is_array($projected) && ($projected['selector'] ?? '') === $selector
                && ($projected['attribute'] ?? '') === $attribute && ($projected['mode'] ?? 'text') === $mode) {
                $fields[$field] = $original;

                continue;
            }
            $definition = is_array($original) ? $original : [];
            $definition['selector'] = $selector;
            $definition['mode'] = $mode;
            if ($attribute === '') {
                unset($definition['attribute']);
            } else {
                $definition['attribute'] = $attribute;
            }
            $fields[$field] = $definition;
        }

        return $fields;
    }

    private static function validateEndpoint(array $config): void
    {
        $endpoint = $config['endpoint'] ?? '';
        if (! is_string($endpoint) || strlen($endpoint) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)) {
            self::fail('source_form.endpoint', 'Endpoint HTTPS không hợp lệ hoặc quá dài.');
        }
        $parts = parse_url($endpoint);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ! self::safeHost($host)) {
            self::fail('source_form.endpoint', 'Endpoint phải dùng HTTPS và hostname công khai; không user-info, fragment hoặc địa chỉ nội bộ.');
        }
        if (! in_array($host, $config['allowed_hosts'] ?? [], true)) {
            self::fail('source_form.allowed_hosts', 'Hostname của endpoint phải nằm trong danh sách được phép.');
        }
        if (! in_array((int) ($parts['port'] ?? 443), $config['allowed_ports'] ?? [443], true)) {
            self::fail('source_form.allowed_ports', 'Port của endpoint phải nằm trong danh sách được phép.');
        }
    }

    private static function safeHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return NetworkGuard::isPublicAddress($host);
        }
        if (strlen($host) > 253 || ! str_contains($host, '.') || preg_match('/(^|\.)(localhost|local|internal|test|invalid|example)$/i', $host)) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private static function listValues(?string $value, string $field, int $max): array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value ?? '') ?: []), fn (string $item): bool => $item !== '')));
        if (count($values) > $max) {
            self::fail('source_form.'.$field, 'Danh sách vượt giới hạn '.$max.' mục.');
        }

        return $values;
    }

    private static function rows(array $form, string $field): array
    {
        $rows = $form[$field] ?? [];
        if (! is_array($rows) || count($rows) > 100) {
            self::fail('source_form.'.$field, 'Danh sách phải có tối đa 100 dòng.');
        }
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                self::fail('source_form.'.$field.'.'.$index, 'Dòng cấu hình phải có các ô nhập riêng.');
            }
        }

        return $rows;
    }

    private static function rowString(array $row, string $key, string $field, int|string $index, int $max, bool $trim = true): string
    {
        $value = $row[$key] ?? '';
        if (! is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            self::fail('source_form.'.$field.'.'.$index.'.'.$key, 'Giá trị phải là văn bản hợp lệ, tối đa '.$max.' ký tự.');
        }

        return $trim ? trim($value) : $value;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
