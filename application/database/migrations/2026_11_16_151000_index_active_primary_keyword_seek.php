<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('keywords_active_primary_seek')");
        if ($index && ! $index->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY keywords_active_primary_seek');
        }
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS keywords_active_primary_seek
ON keywords (pool_id, locale, id)
WHERE status = 'active' AND role = 'primary' AND deleted_at IS NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS keywords_active_primary_seek');
    }
};
