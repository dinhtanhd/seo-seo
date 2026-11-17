<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tram_catalog_exact_terms(field jsonb) RETURNS text[]
LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$
    SELECT CASE jsonb_typeof(field)
        WHEN 'array' THEN ARRAY(SELECT value #>> '{}' FROM jsonb_array_elements(field) WHERE jsonb_typeof(value) = 'string')
        WHEN 'string' THEN regexp_split_to_array(btrim(field #>> '{}'), '[[:space:]]*[/,，、|;；][[:space:]]*')
        ELSE ARRAY[]::text[]
    END
$$
SQL);

        DB::statement("SET lock_timeout = '2s'");
        try {
            foreach (['revision', 'publication'] as $kind) {
                foreach (['actors', 'genre'] as $field) {
                    $name = "catalog_{$kind}_{$field}_terms";
                    // A cancelled concurrent build can leave an invalid index under the same name.
                    if (DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name])) {
                        DB::statement("DROP INDEX CONCURRENTLY $name");
                    }
                    DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS $name
                        ON catalog_{$kind}_metadata USING gin(tram_catalog_exact_terms(fields_json->'{$field}'))");
                }
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        foreach (['revision', 'publication'] as $kind) {
            foreach (['actors', 'genre'] as $field) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS catalog_{$kind}_{$field}_terms");
            }
        }
        DB::statement('DROP FUNCTION IF EXISTS tram_catalog_exact_terms(jsonb)');
    }
};
