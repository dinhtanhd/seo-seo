<?php

namespace App\Domain\Publishing;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

/** Numbered URLs remain compatible; adjacent navigation uses authenticated ID boundaries. */
final class CatalogPaginator extends LengthAwarePaginator
{
    public function __construct($items, int $total, int $perPage, int $page, array $options, private readonly string $scope, private readonly bool $dateOrder = false)
    {
        parent::__construct($items, $total, $perPage, $page, $options);
    }

    public static function boundary(?string $token, string $scope, int $page): ?array
    {
        if ($token === null || strlen($token) > 2048) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || ($data['scope'] ?? null) !== $scope || ($data['page'] ?? null) !== $page
            || ! is_int($data['id'] ?? null) || $data['id'] < 1 || ! in_array($data['direction'] ?? null, ['before', 'after'], true)) {
            return null;
        }

        return $data;
    }

    public function url($page): string
    {
        $page = max(1, (int) $page);
        $parameters = $this->query;
        unset($parameters['catalog_cursor']);
        $parameters[$this->pageName] = $page;
        if ($this->items->isNotEmpty() && in_array($page, [$this->currentPage + 1, $this->currentPage - 1], true) && $page > 1) {
            $after = $page > $this->currentPage;
            $item = $after ? $this->items->last() : $this->items->first();
            $data = ['scope' => $this->scope, 'page' => $page, 'id' => (int) $item->id, 'direction' => $after ? 'after' : 'before'];
            if ($this->dateOrder) {
                $data['time'] = (string) $item->activated_at;
            }
            $parameters['catalog_cursor'] = Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
        }

        return $this->path().(str_contains($this->path(), '?') ? '&' : '?').Arr::query($parameters).$this->buildFragment();
    }
}
