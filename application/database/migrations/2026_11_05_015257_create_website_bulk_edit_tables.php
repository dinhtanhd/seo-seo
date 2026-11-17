<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_bulk_edit_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 160);
            $table->char('request_hash', 64);
            $table->jsonb('changes_json');
            $table->unsignedBigInteger('theme_version_id')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('total');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'request_key'], 'website_bulk_edit_request_unique');
        });
        Schema::create('website_bulk_edit_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('operation_id')->constrained('website_bulk_edit_operations')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->unsignedBigInteger('expected_site_version');
            $table->unsignedBigInteger('expected_advertising_version');
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 100)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['operation_id', 'site_id'], 'website_bulk_edit_site_unique');
            $table->index(['operation_id', 'status', 'id'], 'website_bulk_edit_pending');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa lịch sử sửa domain hàng loạt.');
    }
};
