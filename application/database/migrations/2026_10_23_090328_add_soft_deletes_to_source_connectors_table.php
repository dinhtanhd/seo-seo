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
        Schema::table('source_connectors', function (Blueprint $table) {
            $table->timestampTz('deleted_at')->nullable()->index();
        });
        DB::statement("ALTER TABLE source_connectors ADD CONSTRAINT source_deleted_status_check CHECK(deleted_at IS NULL OR status='archived')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE source_connectors DROP CONSTRAINT source_deleted_status_check');
        Schema::table('source_connectors', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
