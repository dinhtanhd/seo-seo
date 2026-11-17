<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tram_publication_category_index_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF to_regclass('publication_category_index') IS NULL THEN
   RETURN NEW;
 END IF;
 INSERT INTO publication_category_index(publication_id, site_id, page_id, category_id, ancestor_ids, legacy_category_id)
 VALUES (NEW.id, NEW.site_id, NEW.page_id,
   NEW.snapshot_json->'page'->>'category_id',
   NEW.snapshot_json->'category'->'ancestor_ids',
   COALESCE(NEW.snapshot_json->'category'->>'id', NEW.snapshot_json->'page'->>'category_id'))
 ON CONFLICT (publication_id) DO NOTHING;
 RETURN NEW;
EXCEPTION WHEN undefined_table THEN
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Không gỡ khả năng phục hồi index dẫn xuất; dùng migration tiến.');
    }
};
