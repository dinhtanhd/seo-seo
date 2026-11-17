<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_live_routes ON pages (site_id,page_type,id) INCLUDE (current_publication_id) WHERE status='live' AND page_type IN ('home','category')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pages_live_routes');
    }
};
