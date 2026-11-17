<?php

namespace App\Console\Commands\Ingestion;

use App\Domain\Ingestion\ExistingMovieCatalogBackfill;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillMovieCatalog extends Command
{
    protected $signature = 'tram:backfill-movie-catalog
        {site : ID website phim dùng chế độ mở lần đầu}
        {--source= : Chỉ dùng nguồn đã gắn có ID này}
        {--chunk=500 : Tối đa 1.000 phim mỗi transaction}
        {--database= : Tên database dự kiến}
        {--apply : Ghi trang nháp và danh mục còn thiếu}';

    protected $description = 'Gắn phim đã có trong kho PostgreSQL vào website đang bật mở lần đầu.';

    public function handle(ExistingMovieCatalogBackfill $backfill): int
    {
        $range = ['min_range' => 1];
        $siteId = filter_var($this->argument('site'), FILTER_VALIDATE_INT, ['options' => $range]);
        $sourceId = $this->option('source');
        $sourceId = $sourceId === null ? null : filter_var($sourceId, FILTER_VALIDATE_INT, ['options' => $range]);
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => $range + ['max_range' => 1000]]);
        if ($siteId === false || $sourceId === false || $chunk === false) {
            $this->error('Cần ID hợp lệ và mỗi lượt từ 1 đến 1.000 phim.');

            return self::FAILURE;
        }
        $database = DB::getDatabaseName();
        if (DB::getDriverName() !== 'pgsql' || ($this->option('database') !== null && $this->option('database') !== $database)) {
            $this->error('Database không khớp PostgreSQL đích.');

            return self::FAILURE;
        }
        $sources = $backfill->sources($siteId);
        if ($sourceId !== null) {
            $sources = array_values(array_intersect($sources, [$sourceId]));
        }
        if ($sources === []) {
            $this->error('Website cần mở lần đầu và ít nhất một nguồn đang hoạt động có bật tự áp dụng.');

            return self::FAILURE;
        }
        $dryRun = ! $this->option('apply');
        $this->line(Json::encode(['database' => $database, 'site' => $siteId, 'dry_run' => $dryRun, 'sources' => $sources]));
        foreach ($sources as $source) {
            $cursor = 0;
            $batches = 0;
            $totals = ['scanned' => 0, 'eligible' => 0, 'added' => 0, 'mapped' => 0, 'conflicts' => 0, 'unmapped' => 0];
            try {
                do {
                    $result = $backfill->linkChunk($siteId, $source, $cursor, $chunk, $dryRun);
                    $cursor = $result['cursor'];
                    foreach ($totals as $key => $value) {
                        $totals[$key] = $value + $result[$key];
                    }
                    if (++$batches % 20 === 0 || ! $result['more']) {
                        $this->line(Json::encode(['site' => $siteId, 'source' => $source, 'cursor' => $cursor, 'more' => $result['more']] + $totals));
                    }
                } while ($result['more']);
            } catch (Conflict $failure) {
                $this->error('Nguồn #'.$source.' · '.$failure->reason.' · cập nhật ghép thể loại rồi chạy lại.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
