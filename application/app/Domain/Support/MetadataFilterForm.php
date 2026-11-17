<?php

namespace App\Domain\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MetadataFilterForm
{
    public const FIELDS = ['genres', 'kinds', 'page_types', 'intents', 'tags'];

    /** @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request, string $field, array $current = []): array
    {
        $data = $request->validate([$field => 'required|array:'.implode(',', self::FIELDS)]);
        $input = $data[$field];
        foreach (self::FIELDS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if ($value === null) {
                $values = [];
            } elseif (is_string($value) && mb_strlen($value) <= 22000) {
                $values = preg_split('/\r\n|\r|\n/u', $value);
            } elseif (is_array($value) && array_is_list($value) && count($value) <= 100) {
                $values = $value;
            } else {
                throw ValidationException::withMessages([$field.'.'.$key => 'Nhập mỗi dòng một giá trị, tối đa 100 giá trị.']);
            }
            $normalized = [];
            foreach ($values as $entry) {
                if (! is_string($entry) || mb_strlen($entry) > 200) {
                    throw ValidationException::withMessages([$field.'.'.$key => 'Mỗi giá trị phải là văn bản, tối đa 200 ký tự.']);
                }
                $entry = trim($entry);
                if ($entry !== '' && ! in_array($entry, $normalized, true)) {
                    $normalized[] = $entry;
                }
            }
            if (count($normalized) > 100) {
                throw ValidationException::withMessages([$field.'.'.$key => 'Mỗi điều kiện tối đa 100 giá trị.']);
            }
            if ($normalized === []) {
                if (! array_key_exists($key, $current) || $current[$key] !== []) {
                    unset($current[$key]);
                }
            } else {
                $current[$key] = $normalized;
            }
        }

        return $current;
    }

    /** @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public static function values(array $filters): array
    {
        $values = [];
        foreach (self::FIELDS as $key) {
            $entries = $filters[$key] ?? [];
            $entries = is_array($entries) ? $entries : [$entries];
            $values[$key] = implode("\n", array_filter($entries, fn ($entry) => is_string($entry)));
        }

        return $values;
    }
}
