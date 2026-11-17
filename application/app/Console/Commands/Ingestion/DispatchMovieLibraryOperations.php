<?php

namespace App\Console\Commands\Ingestion;

use App\Domain\Ingestion\MovieLibraryService;
use Illuminate\Console\Command;

final class DispatchMovieLibraryOperations extends Command
{
    protected $signature = 'movies:library-dispatch';

    protected $description = 'Khôi phục tiến độ các thao tác hàng loạt trong kho phim dùng chung.';

    public function handle(MovieLibraryService $library): int
    {
        $this->info('Đã xếp '.$library->dispatchPending().' lượt quản lý kho phim đang chờ.');

        return self::SUCCESS;
    }
}
