<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TABLE site_bulk_operations (
            id uuid PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id),
            site_ids_json jsonb NOT NULL, changes_json jsonb NOT NULL, manifest_json jsonb NOT NULL,
            fingerprint char(64) NOT NULL, request_hash char(64) NOT NULL,
            status text NOT NULL DEFAULT 'inspected' CHECK(status IN ('inspected','applied')),
            result_json jsonb NOT NULL DEFAULT '{}', expires_at timestamptz NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
        ); CREATE INDEX site_bulk_operations_actor_created ON site_bulk_operations(actor_id,created_at DESC)");
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ lịch sử sửa website hàng loạt để đối chiếu và phục hồi.');
    }
};
