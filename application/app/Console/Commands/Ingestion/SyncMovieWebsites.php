<?php

namespace App\Console\Commands\Ingestion;

use App\Domain\Ingestion\MovieWebsiteSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SyncMovieWebsites extends Command
{
    protected $signature = 'movies:sync-websites {--site= : Chỉ xử lý một website} {--chunk=500 : Tối đa 1.000 trang mỗi transaction}';

    protected $description = 'Bổ sung con trỏ nội dung cho trang phim đang live, không đổi keyword, URL hoặc publication.';

    public function handle(MovieWebsiteSync $sync): int
    {
        $siteId = $this->option('site');
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($chunk === false || $siteId !== null && filter_var($siteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Cần ID website hợp lệ và chunk từ 1 đến 1.000.');

            return self::FAILURE;
        }
        if (! $sync->available()) {
            $this->error('Chạy migration con trỏ nội dung phục vụ trước khi đồng bộ.');

            return self::FAILURE;
        }
        $sites = DB::table('sites')->where('status', 'active')->where('content_type', 'movie')
            ->when($siteId !== null, fn ($query) => $query->where('id', (int) $siteId))->orderBy('id')->pluck('id');
        if ($sites->isEmpty()) {
            $this->error('Không tìm thấy website phim đang hoạt động.');

            return self::FAILURE;
        }
        $total = 0;
        foreach ($sites as $id) {
            $cursor = 0;
            $count = 0;
            do {
                $result = $sync->backfillSiteChunk((int) $id, $cursor, $chunk);
                $cursor = $result['cursor'];
                $count += $result['changed'];
                $this->line('Website #'.$id.' · đã đồng bộ '.$count.' trang · cursor '.$cursor);
            } while ($result['more']);
            $total += $count;
        }
        $this->info('Đã đồng bộ '.$total.' trang phim đang live. Chỉ điền con trỏ còn trống; giữ URL, keyword và lịch sử, bỏ qua các trang khóa nguồn.');

        return self::SUCCESS;
    }
}
