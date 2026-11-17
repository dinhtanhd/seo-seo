<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_connectors', function (Blueprint $table) {
            $table->smallInteger('latest_max_pages')->default(3);
        });
        Schema::table('ingest_runs', function (Blueprint $table) {
            $table->string('crawl_mode', 16)->nullable();
            $table->integer('page_limit')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('binding_hash', 64)->nullable();
        });
        DB::statement('ALTER TABLE source_connectors ADD CONSTRAINT source_latest_pages_check CHECK(latest_max_pages BETWEEN 3 AND 5)');
        DB::statement("ALTER TABLE ingest_runs ADD CONSTRAINT ingest_crawl_plan_check CHECK(CASE WHEN crawl_mode IS NULL THEN page_limit IS NULL WHEN crawl_mode='initial' THEN page_limit IS NULL OR page_limit BETWEEN 1 AND 1000000 WHEN crawl_mode='latest' THEN page_limit IS NOT NULL AND page_limit BETWEEN 3 AND 5 ELSE FALSE END)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ingest_runs DROP CONSTRAINT ingest_crawl_plan_check');
        DB::statement('ALTER TABLE source_connectors DROP CONSTRAINT source_latest_pages_check');
        Schema::table('ingest_runs', function (Blueprint $table) {
            $table->dropColumn(['crawl_mode', 'page_limit', 'next_attempt_at', 'binding_hash']);
        });
        Schema::table('source_connectors', function (Blueprint $table) {
            $table->dropColumn('latest_max_pages');
        });
    }
};
