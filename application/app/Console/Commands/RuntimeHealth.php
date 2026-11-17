<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class RuntimeHealth extends Command
{
    protected $signature = 'tram:health {--worker} {--scheduler}';

    protected $description = 'Kiểm tra readiness DB/Redis và heartbeat tiến trình.';

    public function handle(): int
    {
        try {
            DB::select('SELECT 1');
            Redis::connection()->ping();
            foreach (['worker', 'scheduler'] as $process) {
                if ($this->option($process)) {
                    $key = $process === 'worker' ? 'runtime:worker:'.env('RUNTIME_WORKER_ROLE', 'publish').':heartbeat' : 'runtime:scheduler:heartbeat';
                    $heartbeat = Cache::store('redis')->get($key);
                    $maxAge = $process === 'worker' ? 390 : 150;
                    if (! $heartbeat || now()->timestamp - $heartbeat > $maxAge) {
                        $this->error($process.': chưa có heartbeat hiện thời.');

                        return self::FAILURE;
                    }
                }
            }
            $this->info('DB/Redis và tiến trình được yêu cầu sẵn sàng.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Readiness chưa đạt; xem log nội bộ.');

            return self::FAILURE;
        }
    }
}
