<?php

namespace App\Console\Commands\Ingestion;

use App\Domain\Ingestion\CrawlService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CrawlCommand extends Command
{
    protected $signature = 'sources:crawl {connector?} {--resume= : ID đợt crawl đã checkpoint} {--pages=3 : Số trang mỗi chunk legacy} {--mode= : initial hoặc latest, xử lý qua hàng đợi} {--limit= : Tổng trang: bỏ trống để initial toàn bộ; latest phải 3–5} {--key= : Idempotency key}';

    protected $description = 'Lấy toàn bộ lần đầu hoặc cập nhật những trang mới nhất bằng các chunk có checkpoint.';

    public function handle(CrawlService $service): int
    {
        try {
            $mode = $this->option('mode') ?: null;
            if ($this->option('resume')) {
                $result = $service->queueResume((int) $this->option('resume'), (int) $this->option('pages'));
            } elseif ($mode !== null) {
                $limit = $this->option('limit');
                if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
                    throw new \InvalidArgumentException('Giới hạn trang phải là số nguyên dương.');
                }
                $result = $service->queue((int) $this->argument('connector'), $this->option('key') ?: (string) Str::uuid(), 3, $mode, $limit === null ? null : (int) $limit);
            } else {
                $result = $service->start((int) $this->argument('connector'), $this->option('key') ?: (string) Str::uuid(), (int) $this->option('pages'));
            }
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
