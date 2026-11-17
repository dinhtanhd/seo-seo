<?php

namespace App\Console\Commands\Sites;

use App\Domain\Sites\WebsiteBulkCreate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('websites:dispatch')]
#[Description('Khôi phục lượt tạo website chưa xử lý xong từ database')]
class DispatchWebsiteCreation extends Command
{
    public function handle(WebsiteBulkCreate $service): int
    {
        $this->info('Đã đưa '.$service->dispatchPending().' lượt tạo website vào hàng đợi.');

        return self::SUCCESS;
    }
}
