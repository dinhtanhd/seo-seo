<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE keyword_versions(id bigserial PRIMARY KEY, keyword_id bigint NOT NULL REFERENCES keywords(id), version_no bigint NOT NULL, payload_json jsonb NOT NULL, created_at timestamptz DEFAULT now(), UNIQUE(keyword_id,version_no));
INSERT INTO keyword_versions(keyword_id,version_no,payload_json) SELECT id,1,to_jsonb(k) FROM keywords k;
CREATE OR REPLACE FUNCTION tram_keyword_revision() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN INSERT INTO keyword_versions(keyword_id,version_no,payload_json) VALUES(NEW.id,COALESCE((SELECT max(version_no) FROM keyword_versions WHERE keyword_id=NEW.id),0)+1,to_jsonb(NEW));RETURN NEW;END $$;
CREATE TRIGGER keyword_revision AFTER INSERT OR UPDATE ON keywords FOR EACH ROW EXECUTE FUNCTION tram_keyword_revision();
CREATE TRIGGER immutable_keyword_version BEFORE UPDATE OR DELETE ON keyword_versions FOR EACH ROW EXECUTE FUNCTION tram_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Destructive migration rollback disabled.');
    }
};
