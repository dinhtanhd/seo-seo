<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Publishing\SitePresentationApply;
use Illuminate\Console\Command;

final class DispatchSitePresentations extends Command
{
    protected $signature = 'site-presentation:dispatch';

    protected $description = 'Khôi phục các lượt cập nhật giao diện website từ tiến độ đã lưu.';

    public function handle(SitePresentationApply $apply): int
    {
        $this->info('Đã xếp '.$apply->dispatchPending().' lượt cập nhật giao diện đang chờ.');

        return self::SUCCESS;
    }
}
