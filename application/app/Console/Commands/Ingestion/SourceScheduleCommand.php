<?php

namespace App\Console\Commands\Ingestion;

use App\Domain\Ingestion\AutoPublisher;
use App\Domain\Ingestion\CrawlService;
use App\Domain\Ingestion\SourceScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SourceScheduleCommand extends Command
{
    protected $signature = 'sources:schedule';

    protected $description = 'Xếp đợt crawl đến lịch, có khóa/idempotency để tránh chạy lặp.';

    public function handle(SourceScheduler $scheduler, CrawlService $crawler, AutoPublisher $publisher): int
    {
        $lock = 'tram:sources:schedule';
        $connection = DB::connection();
        if (! $connection->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) acquired', [$lock])->acquired) {
            $this->info('Một lượt điều phối nguồn đang chạy; bỏ qua lượt trùng.');

            return self::SUCCESS;
        }
        try {
            $this->info('Đã khôi phục '.$scheduler->recoverWork().' đợt bị gián đoạn từ checkpoint DB.');
            $this->info('Đã xếp '.$scheduler->dispatchDue().' đợt định kỳ và '.$crawler->dispatchQueued().' đợt chờ từ admin.');
            $this->info('Đã chuyển '.$publisher->dispatchPending().' đợt auto-publish qua preview/validation/xuất bản chuẩn.');

            foreach ($scheduler->lastErrors as $error) {
                $this->error('Nguồn #'.$error['connector_id'].': '.$error['error']);
            }

            return $scheduler->lastErrors === [] ? self::SUCCESS : self::FAILURE;
        } finally {
            $connection->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
        }
    }
}
