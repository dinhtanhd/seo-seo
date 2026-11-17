<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE source_site_bindings ADD COLUMN deleted_at timestamptz;
ALTER TABLE source_site_bindings ADD COLUMN link_generation bigint NOT NULL DEFAULT 1 CHECK (link_generation > 0);
ALTER TABLE source_site_bindings ADD CONSTRAINT source_binding_deleted_status CHECK (deleted_at IS NULL OR status = 'archived');
CREATE INDEX source_binding_attached_lookup ON source_site_bindings(connector_id,site_id) WHERE deleted_at IS NULL;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Destructive rollback disabled; restore a reviewed backup into a new database.');
    }
};
