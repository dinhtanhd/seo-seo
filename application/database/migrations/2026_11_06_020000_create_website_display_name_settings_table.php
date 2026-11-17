<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_display_name_settings', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->jsonb('names_json');
            $table->bigInteger('row_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement("ALTER TABLE website_display_name_settings ADD CONSTRAINT website_display_names_singleton CHECK (id=1),
            ADD CONSTRAINT website_display_names_version CHECK (row_version>0),
            ADD CONSTRAINT website_display_names_array CHECK (jsonb_typeof(names_json)='array' AND jsonb_array_length(names_json) BETWEEN 1 AND 1000)");
        // Freeze the installation defaults here; future service defaults must not rewrite saved lists.
        DB::table('website_display_name_settings')->insert(['id' => 1, 'names_json' => json_encode([
            'xinghedianying', 'shandianyingyuan', 'yunhaidianying', 'qingfengyingyuan', 'liuguangdianying',
            'xingkongyingyuan', 'yueguangdianying', 'jinseyingyuan', 'lanhaidianying', 'huanxiangyingyuan',
        ], JSON_THROW_ON_ERROR), 'row_version' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_display_name_settings');
    }
};
