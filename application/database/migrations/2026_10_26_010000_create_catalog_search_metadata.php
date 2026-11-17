<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '2s'");
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS catalog_metadata_state (id integer PRIMARY KEY CHECK(id=1), ready boolean NOT NULL DEFAULT false);
INSERT INTO catalog_metadata_state(id) VALUES (1) ON CONFLICT(id) DO UPDATE SET ready=false;

CREATE OR REPLACE FUNCTION tram_catalog_fields(data jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
 SELECT jsonb_build_object('title',data->'title','director',data->'director','directors',data->'directors',
   'actors',data->'actors','genre',data->'genre','area',data->'area','year',data->'year',
   'category_external_id',COALESCE(data->>'category_external_id',data->'_raw'->>'type_id'))
$$;
CREATE OR REPLACE FUNCTION tram_catalog_grams(data jsonb) RETURNS text[] LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
 WITH fields AS MATERIALIZED (
   SELECT lower(value) AS value FROM jsonb_each_text(data)
   WHERE key IN ('title','director','directors','actors','genre') AND value IS NOT NULL
 ), grams AS (
   SELECT n::text || ':' || substr(value,pos,n) AS gram FROM fields
   CROSS JOIN (VALUES (1),(2)) sizes(n)
   CROSS JOIN LATERAL generate_series(1,char_length(value)-n+1) positions(pos)
 ) SELECT COALESCE(array_agg(DISTINCT gram ORDER BY gram),ARRAY[]::text[]) FROM grams
$$;
CREATE OR REPLACE FUNCTION tram_catalog_query_grams(query text) RETURNS text[] LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
 SELECT COALESCE(array_agg(DISTINCT n::text || ':' || substr(lower(query),pos,n)),ARRAY[]::text[])
 FROM (SELECT LEAST(2,char_length(query)) AS n) sizes
 CROSS JOIN LATERAL generate_series(1,char_length(query)-n+1) positions(pos)
 WHERE n>0
$$;

CREATE TABLE IF NOT EXISTS catalog_revision_metadata (
 id bigint PRIMARY KEY, content_item_id bigint NOT NULL,
 fields_json jsonb NOT NULL,
 search_grams text[] GENERATED ALWAYS AS (tram_catalog_grams(fields_json)) STORED,
 FOREIGN KEY(content_item_id,id) REFERENCES content_revisions(content_item_id,id)
);
CREATE TABLE IF NOT EXISTS catalog_publication_metadata (
 id bigint PRIMARY KEY, site_id bigint NOT NULL, page_id bigint NOT NULL,
 fields_json jsonb NOT NULL,
 search_grams text[] GENERATED ALWAYS AS (tram_catalog_grams(fields_json)) STORED,
 FOREIGN KEY(site_id,page_id,id) REFERENCES page_publications(site_id,page_id,id)
);
CREATE INDEX IF NOT EXISTS catalog_revision_search ON catalog_revision_metadata USING gin(search_grams);
CREATE INDEX IF NOT EXISTS catalog_publication_search ON catalog_publication_metadata USING gin(search_grams);
CREATE INDEX IF NOT EXISTS catalog_publication_site ON catalog_publication_metadata(site_id,page_id);
CREATE INDEX IF NOT EXISTS catalog_revision_year ON catalog_revision_metadata(md5(fields_json->>'year'),id);
CREATE INDEX IF NOT EXISTS catalog_revision_area ON catalog_revision_metadata(md5(fields_json->>'area'),id);
CREATE INDEX IF NOT EXISTS catalog_revision_genre ON catalog_revision_metadata(md5(fields_json->>'genre'),id);
CREATE INDEX IF NOT EXISTS catalog_publication_year ON catalog_publication_metadata(site_id,md5(fields_json->>'year'),page_id);
CREATE INDEX IF NOT EXISTS catalog_publication_area ON catalog_publication_metadata(site_id,md5(fields_json->>'area'),page_id);
CREATE INDEX IF NOT EXISTS catalog_publication_genre ON catalog_publication_metadata(site_id,md5(fields_json->>'genre'),page_id);

CREATE OR REPLACE FUNCTION tram_catalog_revision_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF to_regclass('catalog_revision_metadata') IS NULL THEN
   IF to_regclass('catalog_metadata_state') IS NOT NULL THEN
     UPDATE catalog_metadata_state SET ready=false WHERE id=1 AND ready;
   END IF;
   RETURN NEW;
 END IF;
 INSERT INTO catalog_revision_metadata(id,content_item_id,fields_json)
 VALUES(NEW.id,NEW.content_item_id,tram_catalog_fields(NEW.payload_json)) ON CONFLICT(id) DO NOTHING;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION tram_catalog_publication_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF to_regclass('catalog_publication_metadata') IS NULL THEN
   IF to_regclass('catalog_metadata_state') IS NOT NULL THEN
     UPDATE catalog_metadata_state SET ready=false WHERE id=1 AND ready;
   END IF;
   RETURN NEW;
 END IF;
 INSERT INTO catalog_publication_metadata(id,site_id,page_id,fields_json)
 VALUES(NEW.id,NEW.site_id,NEW.page_id,tram_catalog_fields(NEW.snapshot_json->'content')) ON CONFLICT(id) DO NOTHING;
 RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS catalog_revision_insert ON content_revisions;
DROP TRIGGER IF EXISTS catalog_publication_insert ON page_publications;
CREATE TRIGGER catalog_revision_insert AFTER INSERT ON content_revisions FOR EACH ROW EXECUTE FUNCTION tram_catalog_revision_insert();
CREATE TRIGGER catalog_publication_insert AFTER INSERT ON page_publications FOR EACH ROW EXECUTE FUNCTION tram_catalog_publication_insert();

CREATE OR REPLACE FUNCTION tram_catalog_metadata_dirty() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF to_regclass('catalog_metadata_state') IS NOT NULL THEN
   UPDATE catalog_metadata_state SET ready=false WHERE id=1 AND ready;
 END IF;
 RETURN NULL;
END $$;
DROP TRIGGER IF EXISTS catalog_revision_dirty ON catalog_revision_metadata;
DROP TRIGGER IF EXISTS catalog_publication_dirty ON catalog_publication_metadata;
CREATE TRIGGER catalog_revision_dirty AFTER UPDATE OR DELETE OR TRUNCATE ON catalog_revision_metadata FOR EACH STATEMENT EXECUTE FUNCTION tram_catalog_metadata_dirty();
CREATE TRIGGER catalog_publication_dirty AFTER UPDATE OR DELETE OR TRUNCATE ON catalog_publication_metadata FOR EACH STATEMENT EXECUTE FUNCTION tram_catalog_metadata_dirty();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ chỉ mục dẫn xuất; dùng migration tiến hoặc dựng lại từ dữ liệu gốc.');
    }
};
