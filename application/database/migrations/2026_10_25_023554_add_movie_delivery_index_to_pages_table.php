<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_movie_delivery
ON pages (site_id, status, id DESC)
INCLUDE (content_item_id, working_content_revision_id, current_publication_id, category_id, publish_locked)
WHERE page_kind = 'regular' AND page_type = 'movie' AND status IN ('draft', 'live')
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pages_movie_delivery');
    }
};
