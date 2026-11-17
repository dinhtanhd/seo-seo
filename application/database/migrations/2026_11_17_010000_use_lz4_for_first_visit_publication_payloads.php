<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $supported = DB::selectOne(<<<'SQL'
SELECT 'lz4' = ANY(enumvals) AS supported
FROM pg_settings WHERE name = 'default_toast_compression'
SQL);
        if ($supported?->supported) {
            $this->setCompression('lz4');
        }
    }

    public function down(): void
    {
        $this->setCompression('default');
    }

    private function setCompression(string $method): void
    {
        DB::statement("SET LOCAL lock_timeout = '2s'");
        // PostgreSQL uses the column setting for new values. Existing tuples stay as stored.
        DB::unprepared("ALTER TABLE page_publications
            ALTER COLUMN snapshot_json SET COMPRESSION $method,
            ALTER COLUMN html_body SET COMPRESSION $method");
        DB::unprepared("ALTER TABLE publish_batch_items ALTER COLUMN preview_json SET COMPRESSION $method");
    }
};
