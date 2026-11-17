<?php

namespace App\Domain\Fanmulu;

final class ChineseSearchSpider
{
    public static function matches(?string $userAgent): bool
    {
        return preg_match('/Baiduspider|Sogou(?:\s+(?:web|inst|orion))?\s*spider|360Spider|HaosouSpider|YisouSpider|ShenmaSpider/i', $userAgent ?? '') === 1;
    }
}
