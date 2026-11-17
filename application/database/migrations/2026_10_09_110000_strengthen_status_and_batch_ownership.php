<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE publish_batches ADD CONSTRAINT batch_site_identity UNIQUE(site_id,id);
ALTER TABLE publish_batch_items ADD CONSTRAINT batch_item_site_fk FOREIGN KEY(site_id,batch_id) REFERENCES publish_batches(site_id,id);
ALTER TABLE ingest_runs ADD CHECK(status IN ('pending','queued','running','paused','succeeded','partial','failed','retry_wait'));
ALTER TABLE baidu_submissions ADD CHECK(status IN ('pending','queued','submitting','submitted','partial','rejected','unknown','not_configured','failed','retry_wait'));
ALTER TABLE baidu_configs ADD CHECK(status IN ('active','paused','not_configured'));
ALTER TABLE source_site_bindings ADD CHECK(status IN ('active','paused','archived'));
ALTER TABLE keyword_bindings ADD CHECK(status IN ('active','paused','archived'));
ALTER TABLE keyword_groups ADD CHECK(status IN ('active','paused','archived'));
ALTER TABLE site_groups ADD CHECK(status IN ('active','paused','archived'));
ALTER TABLE templates ADD CHECK(status IN ('active','paused','archived'));
ALTER TABLE rules ADD CHECK(status IN ('active','paused','archived'));
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a reviewed backup into an isolated DB instead.');
    }
};
