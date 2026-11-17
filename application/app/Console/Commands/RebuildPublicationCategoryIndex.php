<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RebuildPublicationCategoryIndex extends Command
{
    protected $signature = 'tram:catalog-index {--rebuild : Dựng các dòng index còn thiếu từ publication bất biến} {--database-name= : Tên database đích phải khớp kết nối thực} {--restore-schema : Khôi phục cả bảng/trigger dẫn xuất khi bảng đã mất}';

    protected $description = 'Khôi phục index phân loại; không thay publication, keyword hoặc dữ liệu phim';

    public function handle(): int
    {
        if (! $this->option('rebuild') || DB::connection()->getDriverName() !== 'pgsql'
            || ! is_string($this->option('database-name')) || $this->option('database-name') === '') {
            $this->error('Cần --rebuild và --database-name khớp database PostgreSQL thực trước khi ghi index.');

            return self::FAILURE;
        }
        if ($this->option('database-name') !== DB::selectOne('SELECT current_database() AS name')->name) {
            $this->error('Database đích không khớp kết nối thực; chưa ghi dữ liệu.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('publication_category_index') && ! $this->option('restore-schema')) {
            $this->error('Thiếu bảng index. Thêm --restore-schema để khôi phục bảng và trigger từ migration đã kiểm thử.');

            return self::FAILURE;
        }

        $inserted = DB::transaction(function (): int {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");
            DB::select('SELECT pg_advisory_xact_lock(?)', [721939028]);
            if (! Schema::hasTable('publication_category_index')) {
                if (! $this->option('restore-schema')) {
                    throw new \RuntimeException('Bảng index đã mất; cần --restore-schema để khôi phục schema.');
                }
                DB::unprepared('DROP TRIGGER IF EXISTS publication_category_index_insert ON page_publications');
                (require database_path('migrations/2026_10_25_025026_create_publication_category_index_table.php'))->up();
                (require database_path('migrations/2026_10_25_025407_guard_publication_category_index_when_unavailable.php'))->up();

                return (int) DB::table('publication_category_index')->count();
            }

            return DB::affectingStatement(<<<'SQL'
INSERT INTO publication_category_index(publication_id, site_id, page_id, category_id, ancestor_ids, legacy_category_id)
 SELECT pp.id, pp.site_id, pp.page_id,
   pp.snapshot_json->'page'->>'category_id',
   pp.snapshot_json->'category'->'ancestor_ids',
   COALESCE(pp.snapshot_json->'category'->>'id', pp.snapshot_json->'page'->>'category_id')
 FROM page_publications pp
 WHERE NOT EXISTS (SELECT 1 FROM publication_category_index ci WHERE ci.publication_id = pp.id)
 ON CONFLICT (publication_id) DO NOTHING
SQL);
        });
        $this->info('Đã dựng '.$inserted.' dòng index; publication và keyword giữ nguyên.');

        return self::SUCCESS;
    }
}
