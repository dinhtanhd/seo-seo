<?php

namespace App\Console\Commands\Sites;

use App\Domain\Sites\WebsiteDeletionService;
use Illuminate\Console\Command;

final class DispatchWebsiteDeletions extends Command
{
    protected $signature = 'websites:deletions-dispatch';

    protected $description = 'Tiếp tục các yêu cầu xóa hoặc khôi phục website đã được xác nhận.';

    public function handle(WebsiteDeletionService $service): int
    {
        $this->info('Đã gửi '.$service->dispatchPending().' yêu cầu vào hàng đợi.');

        return self::SUCCESS;
    }
}
