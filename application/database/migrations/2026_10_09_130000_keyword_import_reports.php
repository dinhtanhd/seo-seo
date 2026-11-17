<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE keyword_import_runs(id bigserial PRIMARY KEY, pool_id bigint NOT NULL REFERENCES keyword_pools(id), actor_id bigint NOT NULL REFERENCES users(id), idempotency_key text NOT NULL, request_hash text NOT NULL, status text NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','succeeded','partial','failed')), cursor integer NOT NULL DEFAULT 0 CHECK(cursor>=0), counts jsonb NOT NULL DEFAULT '{}', dry_run boolean NOT NULL DEFAULT false, error text, created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now(), UNIQUE(pool_id,actor_id,idempotency_key));
CREATE TABLE keyword_import_rows(id bigserial PRIMARY KEY, run_id bigint NOT NULL REFERENCES keyword_import_runs(id), row_no integer NOT NULL CHECK(row_no>=1), status text NOT NULL CHECK(status IN ('new','duplicate','blank','restored','error')), normalized_text text, identity_key text, error text, data_json jsonb NOT NULL DEFAULT '{}', UNIQUE(run_id,row_no));
CREATE INDEX keyword_import_identity ON keyword_import_rows(run_id,identity_key);
CREATE TABLE keyword_import_seen(run_id bigint NOT NULL REFERENCES keyword_import_runs(id), identity_key text NOT NULL, PRIMARY KEY(run_id,identity_key));
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Destructive migration rollback disabled.');
    }
};
