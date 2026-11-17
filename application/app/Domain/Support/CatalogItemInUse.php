<?php

namespace App\Domain\Support;

final class CatalogItemInUse extends Conflict
{
    public function __construct(public readonly string $kind, public readonly int $itemId, public readonly array $usage)
    {
        parent::__construct('CATALOG_ITEM_IN_USE', 'Chưa thể xóa: '.($usage['reasons'][0]['label'] ?? 'Mục này đang được sử dụng').'. Mở nơi sử dụng để gỡ liên kết trước.');
    }
}
