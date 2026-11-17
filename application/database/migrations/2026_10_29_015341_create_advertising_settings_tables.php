<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertising_settings', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->text('code')->default('');
            $table->text('compiled_js')->default('');
            $table->jsonb('extra_origins_json')->default('[]');
            $table->jsonb('origins_json')->default('[]');
            $table->jsonb('inline_hashes_json')->default('[]');
            $table->boolean('has_code')->default(false);
            $table->bigInteger('row_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE advertising_settings ADD CONSTRAINT advertising_singleton CHECK (id=1)');
        DB::table('advertising_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('site_advertising_settings', function (Blueprint $table) {
            $table->foreignId('site_id')->primary()->constrained('sites');
            $table->boolean('regular_enabled')->default(false);
            $table->boolean('fanmulu_enabled')->default(false);
            $table->bigInteger('row_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_advertising_settings');
        Schema::dropIfExists('advertising_settings');
    }
};
