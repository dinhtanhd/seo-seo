<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_variable_sets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 200)->unique();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->bigInteger('row_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::create('template_variable_set_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('set_id')->constrained('template_variable_sets')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->jsonb('values_json');
            $table->string('hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['set_id', 'version_no']);
        });
        Schema::create('template_variables', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('slug', 60)->unique();
            $table->string('name', 200);
            $table->string('source_kind', 20);
            $table->foreignId('set_id')->nullable()->constrained('template_variable_sets')->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->text('fallback')->nullable();
            $table->string('status', 20)->default('active');
            $table->bigInteger('row_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::create('template_variable_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('variable_id')->constrained('template_variables')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('source_kind', 20);
            $table->foreignId('set_version_id')->nullable()->constrained('template_variable_set_versions')->restrictOnDelete();
            $table->jsonb('values_json');
            $table->string('hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['variable_id', 'version_no']);
        });
        Schema::create('page_template_variable_values', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('page_id')->constrained('pages')->restrictOnDelete();
            $table->foreignId('variable_id')->constrained('template_variables')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('template_variable_versions')->restrictOnDelete();
            $table->bigInteger('generation')->default(1);
            $table->text('text');
            $table->boolean('pending')->default(true);
            $table->timestampsTz();
            $table->unique(['page_id', 'variable_id']);
        });
        Schema::create('template_variable_decks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('template_variable_versions')->restrictOnDelete();
            $table->jsonb('order_json');
            $table->unsignedInteger('position')->default(0);
            $table->bigInteger('cycle')->default(0);
            $table->timestampsTz();
            $table->unique(['site_id', 'version_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa nguồn biến và giá trị đã chốt theo trang.');
    }
};
