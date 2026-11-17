<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE website_create_operations (
 id uuid PRIMARY KEY, created_by bigint NOT NULL REFERENCES users(id), request_key varchar(160) NOT NULL,
 request_hash char(64) NOT NULL, status text NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','completed','partial')),
 total integer NOT NULL CHECK(total > 0), preset_manifest_json jsonb NOT NULL, options_json jsonb NOT NULL,
 last_dispatched_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(created_by,request_key)
);
CREATE TABLE website_create_items (
 id bigserial PRIMARY KEY, operation_id uuid NOT NULL REFERENCES website_create_operations(id), position integer NOT NULL,
 domain varchar(253) NOT NULL, base_url varchar(500) NOT NULL, name varchar(200) NOT NULL,
 preset_id bigint NOT NULL, preset_version_id bigint NOT NULL, preset_name varchar(160) NOT NULL,
 theme_id bigint NOT NULL REFERENCES movie_themes(id), theme_name varchar(200) NOT NULL, definition_json jsonb NOT NULL,
 status text NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','ready','error')), site_id bigint REFERENCES sites(id),
 attempts integer NOT NULL DEFAULT 0, error_code varchar(120), error_message text,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(operation_id,position), UNIQUE(operation_id,domain), FOREIGN KEY(preset_id,preset_version_id) REFERENCES website_preset_versions(preset_id,id)
);
CREATE INDEX website_create_items_pending ON website_create_items(operation_id,position) WHERE status = 'queued';
CREATE TABLE website_preset_rotations (key char(64) PRIMARY KEY, next_position bigint NOT NULL DEFAULT 0);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa lịch sử tạo website hàng loạt.');
    }
};
