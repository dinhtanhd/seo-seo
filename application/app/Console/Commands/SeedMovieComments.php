<?php

namespace App\Console\Commands;

use App\Domain\Comments\MovieCommentCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tram:seed-movie-comments {--actor= : ID quản trị toàn hệ thống thực hiện seed}')]
#[Description('Thêm đủ10.000bình luận mẫu tiếng Trung, giữ nội dung đã sửa/tạm dừng/xóa.')]
final class SeedMovieComments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MovieCommentCatalog $catalog): int
    {
        $actor = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($actor === false) {
            $this->error('Điền --actor bằng ID quản trị toàn hệ thống.');

            return self::FAILURE;
        }
        $result = $catalog->seed($actor);
        $this->info('Đã thêm '.$result['inserted'].' bình luận mẫu. Bộ '.$result['version'].' có '.$result['total'].' dòng; giao diện ghi rõ 示例评论.');

        return self::SUCCESS;
    }
}
