<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_delete_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('request_key', 100);
            $table->string('request_hash', 64);
            $table->string('kind', 30);
            $table->string('action', 20);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'request_key']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('catalog_delete_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('catalog_delete_operations');
            $table->string('kind', 30);
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('expected_row_version');
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['operation_id', 'kind', 'item_id']);
            $table->index(['operation_id', 'status', 'id']);
            $table->index(['kind', 'item_id', 'status']);
        });
        Schema::create('catalog_trash_entries', function (Blueprint $table): void {
            $table->string('kind', 30);
            $table->unsignedBigInteger('item_id');
            $table->jsonb('previous_state_json');
            $table->foreignId('deleted_by')->constrained('users');
            $table->timestampTz('deleted_at');
            $table->timestampTz('restored_at')->nullable();
            $table->primary(['kind', 'item_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa lịch sử thao tác và thùng rác danh mục.');
    }
};
