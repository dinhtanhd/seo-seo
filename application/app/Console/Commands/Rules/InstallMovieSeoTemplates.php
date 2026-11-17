<?php

namespace App\Console\Commands\Rules;

use App\Domain\Rules\MovieSeoTemplateLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('tram:seo-movie-library {--actor= : ID quản trị viên hệ thống} {--database-name= : Tên database cần cập nhật}')]
#[Description('Thêm 12 bộ Template SEO phim, giữ nguyên template và cấu hình website hiện hữu')]
class InstallMovieSeoTemplates extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MovieSeoTemplateLibrary $library): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('database-name') !== $database || ! ctype_digit((string) $this->option('actor')) || (int) $this->option('actor') < 1) {
            $this->error('Cần --actor là ID quản trị viên và --database-name trùng database đang kết nối.');

            return self::FAILURE;
        }
        $result = $library->install((int) $this->option('actor'));
        $this->info('Đã thêm '.$result['created'].' bộ; đã có '.$result['existing'].' bộ. Không đổi cấu hình website.');
        if (($result['skipped_deleted'] ?? 0) > 0) {
            $this->warn('Bỏ qua '.$result['skipped_deleted'].' bộ trong thùng rác. Khôi phục tại /admin/deletions?kind=template&status=trash để dùng lại đúng ID cũ.');
        }

        return self::SUCCESS;
    }
}
