<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('snapshot_fragments', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->char('hash', 64);
            $table->string('kind', 16);
            $table->jsonb('payload_json');
            $table->unsignedInteger('payload_bytes');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['site_id', 'hash']);
        });
        DB::statement("ALTER TABLE snapshot_fragments ADD CONSTRAINT snapshot_fragments_kind CHECK (kind IN ('theme', 'catalog', 'card'))");
        DB::statement("ALTER TABLE snapshot_fragments ADD CONSTRAINT snapshot_fragments_hash CHECK (hash ~ '^[a-f0-9]{64}$')");
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_snapshot_fragment_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'snapshot_fragments are immutable'; END; $$");
        DB::unprepared('CREATE TRIGGER snapshot_fragments_immutable BEFORE UPDATE OR DELETE ON snapshot_fragments FOR EACH ROW EXECUTE FUNCTION reject_snapshot_fragment_mutation()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('snapshot_fragments');
        DB::statement('DROP FUNCTION IF EXISTS reject_snapshot_fragment_mutation()');
    }
};
