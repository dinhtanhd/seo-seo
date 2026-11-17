<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE source_type_catalogs (
    connector_id bigint PRIMARY KEY REFERENCES source_connectors(id) ON DELETE CASCADE,
    config_hash char(64), catalog_hash char(64),
    types_json jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(types_json) = 'array'),
    fetched_at timestamptz, last_error text, last_error_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa catalog đang được lựa chọn nguồn tham chiếu.');
    }
};
