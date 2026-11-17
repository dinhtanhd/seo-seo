<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("SET lock_timeout='2s'");
        try {
            $existing = DB::selectOne('SELECT indisvalid,indisready FROM pg_index WHERE indexrelid=to_regclass(?)', ['pages_movie_category_delivery']);
            if ($existing && (! $existing->indisvalid || ! $existing->indisready)) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pages_movie_category_delivery');
            }
            DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_movie_category_delivery
 ON pages (site_id, category_id, status, id DESC)
 INCLUDE (content_item_id, working_content_revision_id, current_publication_id, serving_content_revision_id, publish_locked)
 WHERE page_type='movie' AND page_kind='regular' AND status IN ('draft','live')
SQL);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pages_movie_category_delivery');
    }
};
