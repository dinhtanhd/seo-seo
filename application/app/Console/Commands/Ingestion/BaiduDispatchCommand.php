<?php

namespace App\Console\Commands\Ingestion;

use App\Domain\Delivery\BaiduService;
use App\Jobs\Ingestion\SubmitBaidu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BaiduDispatchCommand extends Command
{
    protected $signature = 'baidu:dispatch {--limit=100}';

    protected $description = 'Xếp URL Baidu đã cấu hình; receipt luôn tách khỏi trạng thái index.';

    public function handle(BaiduService $service): int
    {
        $service->recoverInterrupted();
        $items = DB::table('baidu_submissions')->whereIn('status', ['queued', 'retry_wait'])->where('attempts', '<', 4)->limit(min(1000, max(1, (int) $this->option('limit'))))->get();
        $count = 0;
        foreach ($items as $item) {
            $receipt = json_decode($item->receipt_json ?? '{}', true) ?? [];
            if (($receipt['_transport_retry_after_at'] ?? 0) > time()) {
                continue;
            }
            SubmitBaidu::dispatch($item->id)->onQueue('baidu');
            $count++;
        }
        $this->info('Đã xếp '.$count.' URL; trạng thái index chưa xác định.');

        return self::SUCCESS;
    }
}
