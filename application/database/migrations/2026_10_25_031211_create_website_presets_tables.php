<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE website_presets (
    id bigserial PRIMARY KEY, name varchar(160) NOT NULL UNIQUE, description text NOT NULL DEFAULT '',
    default_key text UNIQUE, status text NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
    row_version bigint NOT NULL DEFAULT 1 CHECK(row_version > 0), current_version_id bigint,
    created_by bigint REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE website_preset_versions (
    id bigserial PRIMARY KEY, preset_id bigint NOT NULL REFERENCES website_presets(id), version_no bigint NOT NULL CHECK(version_no > 0),
    schema_version integer NOT NULL DEFAULT 1 CHECK(schema_version = 1), definition_json jsonb NOT NULL CHECK(jsonb_typeof(definition_json) = 'object'),
    hash char(64) NOT NULL, created_by bigint REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(preset_id,version_no), UNIQUE(preset_id,id)
);
ALTER TABLE website_presets ADD CONSTRAINT website_preset_current_version_fk FOREIGN KEY(id,current_version_id) REFERENCES website_preset_versions(preset_id,id);
CREATE TRIGGER immutable_website_preset BEFORE UPDATE OR DELETE ON website_preset_versions FOR EACH ROW EXECUTE FUNCTION tram_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa bộ cấu hình và phiên bản có thể đang được website tham chiếu.');
    }
};
