<?php

namespace App\Console\Commands\Sites;

use App\Domain\Sites\WebsiteBulkEdit;
use Illuminate\Console\Command;

final class DispatchWebsiteBulkEdits extends Command
{
    protected $signature = 'websites:bulk-edit-dispatch';

    protected $description = 'Khôi phục các lượt sửa cấu hình nhiều domain từ tiến độ bền vững.';

    public function handle(WebsiteBulkEdit $service): int
    {
        $this->info('Đã xếp '.$service->dispatchPending().' lượt sửa domain đang chờ.');

        return self::SUCCESS;
    }
}
