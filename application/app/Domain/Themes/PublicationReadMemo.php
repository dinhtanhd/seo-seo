<?php

namespace App\Domain\Themes;

/** Bounded immutable read data, explicitly scoped to one transaction attempt. */
final class PublicationReadMemo
{
    private array $contexts = [];

    public function within(callable $callback, int $maxBytes = 16777216, int $maxEntries = 2048): mixed
    {
        if ($maxBytes < 0 || $maxEntries < 0) {
            throw new \InvalidArgumentException('Memo budgets must be non-negative.');
        }
        $this->contexts[] = ['entries' => [], 'bytes' => 0, 'max_bytes' => $maxBytes, 'max_entries' => $maxEntries];
        try {
            return $callback();
        } finally {
            array_pop($this->contexts);
        }
    }

    public function active(): bool
    {
        return $this->contexts !== [];
    }

    public function get(string $key): ?array
    {
        return $this->contexts[array_key_last($this->contexts)]['entries'][$key] ?? null;
    }

    public function put(string $key, array $entry): void
    {
        if (! $this->active()) {
            return;
        }
        $index = array_key_last($this->contexts);
        if (isset($this->contexts[$index]['entries'][$key])) {
            return;
        }
        $bytes = strlen($key);
        foreach ($entry as $field => $value) {
            if (! is_string($value) && $value !== null) {
                throw new \InvalidArgumentException('Immutable memo values must be raw strings or null.');
            }
            $bytes += strlen((string) $field) + strlen($value ?? '');
        }
        if (count($this->contexts[$index]['entries']) >= $this->contexts[$index]['max_entries']
            || $bytes > $this->contexts[$index]['max_bytes'] - $this->contexts[$index]['bytes']) {
            return;
        }
        $this->contexts[$index]['entries'][$key] = $entry;
        $this->contexts[$index]['bytes'] += $bytes;
    }
}
