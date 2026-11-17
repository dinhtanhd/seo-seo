<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Publishing\QuickApplyWorkflow;
use Illuminate\Console\Command;

final class DispatchQuickApply extends Command
{
    protected $signature = 'quick-apply:dispatch';

    protected $description = 'Khôi phục các yêu cầu áp dụng từng trang từ dữ liệu bền vững.';

    public function handle(QuickApplyWorkflow $workflow): int
    {
        $this->info('Đã xếp '.$workflow->dispatchPending().' yêu cầu áp dụng đang chờ.');

        return self::SUCCESS;
    }
}
