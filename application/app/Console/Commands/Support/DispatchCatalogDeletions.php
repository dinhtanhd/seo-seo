<?php

namespace App\Console\Commands\Support;

use App\Domain\Support\CatalogDeletionService;
use Illuminate\Console\Command;

final class DispatchCatalogDeletions extends Command
{
    protected $signature = 'catalog:deletions-dispatch';

    protected $description = 'Tiếp tục các yêu cầu xóa/khôi phục danh mục còn chờ.';

    public function handle(CatalogDeletionService $service): int
    {
        $this->info('Đã gửi '.$service->dispatchPending().' yêu cầu vào hàng đợi.');

        return self::SUCCESS;
    }
}
