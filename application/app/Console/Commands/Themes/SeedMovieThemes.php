<?php

namespace App\Console\Commands\Themes;

use App\Domain\Themes\MovieThemeService;
use Illuminate\Console\Command;

final class SeedMovieThemes extends Command
{
    protected $signature = 'tram:themes-seed {--actor= : ID owner toàn hệ thống hiện hữu}';

    protected $description = 'Cài các giao diện phim mẫu chưa có, giữ mọi phiên bản đã chỉnh sửa';

    public function handle(MovieThemeService $service): int
    {
        if (! $this->option('actor')) {
            $this->error('Cần --actor là owner hiện hữu.');

            return self::FAILURE;
        }
        $this->info(json_encode($service->seed((int) $this->option('actor')), JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
