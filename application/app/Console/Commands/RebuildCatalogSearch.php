<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RebuildCatalogSearch extends Command
{
    protected $signature = 'tram:catalog-search {--rebuild : Dựng metadata còn thiếu} {--database-name= : Tên database đích thực} {--chunk=1000 : Số bản ghi mỗi transaction} {--restore-schema : Khôi phục bảng phụ đã mất trước khi dựng lại}';

    protected $description = 'Dựng chỉ mục tìm kiếm PostgreSQL từ revision/publication gốc, giữ nguyên phim và keyword';

    public function handle(): int
    {
        if (! $this->option('rebuild') || DB::connection()->getDriverName() !== 'pgsql'
            || $this->option('database-name') !== DB::selectOne('SELECT current_database() AS name')->name) {
            $this->error('Cần --rebuild và --database-name khớp chính xác database PostgreSQL đích.');

            return self::FAILURE;
        }
        if (! DB::selectOne('SELECT pg_try_advisory_lock(721939029) AS acquired')->acquired) {
            $this->error('Một lượt dựng chỉ mục khác đang chạy.');

            return self::FAILURE;
        }
        try {
            if ($this->option('restore-schema')) {
                DB::transaction(fn () => (require database_path('migrations/2026_10_26_010000_create_catalog_search_metadata.php'))->up());
            }
            foreach (['catalog_metadata_state', 'catalog_revision_metadata', 'catalog_publication_metadata'] as $table) {
                if (! Schema::hasTable($table)) {
                    $this->error('Thiếu schema chỉ mục. Chạy migration additive trước rồi dựng lại.');

                    return self::FAILURE;
                }
            }
            $chunk = min(10000, max(100, (int) $this->option('chunk')));
            $inserted = 0;
            foreach (['revision', 'publication'] as $kind) {
                $source = $kind === 'revision' ? 'content_revisions' : 'page_publications';
                $target = 'catalog_'.$kind.'_metadata';
                $cursor = 0;
                $maximum = (int) DB::table($source)->max('id');
                do {
                    $ids = DB::table($source)->where('id', '>', $cursor)->where('id', '<=', $maximum)->orderBy('id')->limit($chunk)->pluck('id');
                    if ($ids->isEmpty()) {
                        break;
                    }
                    $end = (int) $ids->last();
                    $inserted += DB::transaction(function () use ($kind, $source, $target, $cursor, $end): int {
                        DB::statement("SET LOCAL lock_timeout = '2s'");
                        DB::statement("SET LOCAL statement_timeout = '30s'");
                        $columns = $kind === 'revision' ? 'id,content_item_id,fields_json' : 'id,site_id,page_id,fields_json';
                        $values = $kind === 'revision' ? 's.id,s.content_item_id,tram_catalog_fields(s.payload_json)'
                            : "s.id,s.site_id,s.page_id,tram_catalog_fields(s.snapshot_json->'content')";

                        $payload = $kind === 'revision' ? 's.payload_json' : "s.snapshot_json->'content'";

                        return DB::affectingStatement("INSERT INTO $target($columns) SELECT $values FROM $source s
                            WHERE s.id>? AND s.id<=? AND NOT EXISTS(SELECT 1 FROM $target m
                                WHERE m.id=s.id AND m.fields_json=tram_catalog_fields($payload))
                            ON CONFLICT(id) DO UPDATE SET fields_json=EXCLUDED.fields_json", [$cursor, $end]);
                    });
                    $cursor = $end;
                    if ($this->output->isVerbose()) {
                        $this->line($kind.': '.$cursor.'/'.$maximum);
                    }
                } while ($cursor < $maximum);
            }
            $ready = DB::transaction(function (): bool {
                // Prevent a concurrent removal from certifying incomplete projections.
                DB::statement("SET LOCAL lock_timeout = '2s'");
                DB::statement("SET LOCAL statement_timeout = '30s'");
                DB::statement('LOCK TABLE catalog_revision_metadata,catalog_publication_metadata IN SHARE MODE');
                $missing = DB::selectOne('SELECT EXISTS(SELECT 1 FROM content_revisions r WHERE NOT EXISTS
                    (SELECT 1 FROM catalog_revision_metadata m WHERE m.id=r.id)) OR EXISTS
                    (SELECT 1 FROM page_publications p WHERE NOT EXISTS
                    (SELECT 1 FROM catalog_publication_metadata m WHERE m.id=p.id)) AS missing')->missing;
                DB::table('catalog_metadata_state')->where('id', 1)->update(['ready' => ! $missing]);

                return ! $missing;
            });
            DB::statement('ANALYZE catalog_revision_metadata');
            DB::statement('ANALYZE catalog_publication_metadata');
            $this->info(json_encode(['database' => $this->option('database-name'), 'inserted' => $inserted, 'ready' => $ready], JSON_UNESCAPED_UNICODE));

            return $ready ? self::SUCCESS : self::FAILURE;
        } finally {
            DB::select('SELECT pg_advisory_unlock(721939029)');
        }
    }
}
