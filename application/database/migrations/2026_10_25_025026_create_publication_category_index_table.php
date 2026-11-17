<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '30s'");
        DB::unprepared(<<<'SQL'
CREATE TABLE publication_category_index (
 publication_id bigint PRIMARY KEY,
 site_id bigint NOT NULL,
 page_id bigint NOT NULL,
 category_id text,
 ancestor_ids jsonb,
 legacy_category_id text,
 CONSTRAINT publication_category_index_owner FOREIGN KEY (site_id, page_id, publication_id)
   REFERENCES page_publications(site_id, page_id, id) ON DELETE RESTRICT
);
CREATE INDEX publication_category_index_site_category ON publication_category_index(site_id, category_id);
CREATE OR REPLACE FUNCTION tram_publication_category_index_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO publication_category_index(publication_id, site_id, page_id, category_id, ancestor_ids, legacy_category_id)
 VALUES (NEW.id, NEW.site_id, NEW.page_id,
   NEW.snapshot_json->'page'->>'category_id',
   NEW.snapshot_json->'category'->'ancestor_ids',
   COALESCE(NEW.snapshot_json->'category'->>'id', NEW.snapshot_json->'page'->>'category_id'))
 ON CONFLICT (publication_id) DO NOTHING;
 RETURN NEW;
END $$;
CREATE TRIGGER publication_category_index_insert AFTER INSERT ON page_publications
 FOR EACH ROW EXECUTE FUNCTION tram_publication_category_index_insert();
INSERT INTO publication_category_index(publication_id, site_id, page_id, category_id, ancestor_ids, legacy_category_id)
 SELECT id, site_id, page_id,
   snapshot_json->'page'->>'category_id',
   snapshot_json->'category'->'ancestor_ids',
   COALESCE(snapshot_json->'category'->>'id', snapshot_json->'page'->>'category_id')
 FROM page_publications
 ON CONFLICT (publication_id) DO NOTHING;
ANALYZE publication_category_index;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS publication_category_index_insert ON page_publications;
DROP FUNCTION IF EXISTS tram_publication_category_index_insert();
DROP TABLE IF EXISTS publication_category_index;
SQL);
    }
};
