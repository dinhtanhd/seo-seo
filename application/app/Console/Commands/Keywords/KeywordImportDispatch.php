<?php

namespace App\Console\Commands\Keywords;

use App\Domain\Keywords\KeywordImportService;
use Illuminate\Console\Command;

final class KeywordImportDispatch extends Command
{
    protected $signature = 'keywords:dispatch {--run= : Chạy một import từ checkpoint theo CLI}';

    protected $description = 'Khôi phục và dispatch import keyword bền vững từ database.';

    public function handle(KeywordImportService $service): int
    {
        if ($this->option('run')) {
            $run = $service->execute((int) $this->option('run'));
            $this->line(json_encode(['run_id' => $run->id, 'status' => $run->status, 'cursor' => $run->cursor, 'counts' => json_decode($run->counts, true)], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->info('Đã khôi phục '.$service->recoverInterrupted().' import; xếp '.$service->dispatchPending().' jobs keyword.');

        return self::SUCCESS;
    }
}
