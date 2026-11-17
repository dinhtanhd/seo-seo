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
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tram_catalog_reference_key(value jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS $$
DECLARE number_value numeric; text_value text;
BEGIN
 IF jsonb_typeof(value) = 'number' THEN
  number_value := (value #>> '{}')::numeric;
  IF number_value >= 1 AND number_value <= 9223372036854775807 AND trunc(number_value) = number_value THEN
   RETURN to_jsonb(number_value::bigint);
  END IF;
 ELSIF jsonb_typeof(value) = 'string' THEN
  text_value := value #>> '{}';
  IF text_value ~ '^[1-9][0-9]{0,18}$' THEN
   IF text_value::numeric <= 9223372036854775807 THEN RETURN value; END IF;
  END IF;
 END IF;
 RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION tram_catalog_theme_references(document jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE SET jit=off AS $$
 WITH RECURSIVE candidates(value, depth) AS (
  SELECT value, 0 FROM (
   SELECT value FROM jsonb_path_query(document, '$.theme.id') AS first_path(value)
   UNION ALL
   SELECT value FROM jsonb_path_query(document, '$.resolved.theme.id') AS second_path(value)
  ) initial_values
  UNION ALL
  SELECT child.value, parent.depth+1 FROM candidates parent
  CROSS JOIN LATERAL jsonb_array_elements(
   CASE WHEN jsonb_typeof(parent.value)='array' THEN parent.value ELSE '[]'::jsonb END
  ) child(value)
  WHERE parent.depth < 2
 )
 SELECT COALESCE(jsonb_agg(DISTINCT reference_key) FILTER (WHERE reference_key IS NOT NULL),'[]'::jsonb)
 FROM (SELECT public.tram_catalog_reference_key(value) AS reference_key FROM candidates) normalized
$$;
SQL);
            $indexes = [
                'catalog_pub_theme_refs' => ['page_publications', 'snapshot_json'],
                'catalog_preview_theme_refs' => ['publish_batch_items', "(preview_json->'snapshot')"],
            ];
            foreach ($indexes as $name => [$table, $document]) {
                // Recover safely if an interrupted concurrent build left an invalid index.
                $existing = DB::selectOne('SELECT indisvalid,indisready FROM pg_index WHERE indexrelid=to_regclass(?)', [$name]);
                if ($existing && (! $existing->indisvalid || ! $existing->indisready)) {
                    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
                }
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS $name ON $table USING gin (tram_catalog_theme_references($document) jsonb_path_ops)");
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự gỡ chỉ mục kiểm tra giao diện đang được sử dụng.');
    }
};
