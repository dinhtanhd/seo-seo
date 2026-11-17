<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("SET lock_timeout='2s'");
        try {
            DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_movie_revision_lookup
ON pages(site_id,working_content_revision_id,id DESC)
INCLUDE(content_item_id,category_id,current_publication_id,publish_locked)
WHERE page_kind='regular' AND page_type='movie' AND status='draft'
SQL);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pages_movie_revision_lookup');
    }
};
