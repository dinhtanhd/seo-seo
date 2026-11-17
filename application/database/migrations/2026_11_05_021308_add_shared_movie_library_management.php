<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->string('library_status', 16)->default('active');
            $table->unsignedBigInteger('row_version')->default(1);
            $table->boolean('manual_update_locked')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['kind', 'library_status', 'id'], 'content_library_inventory');
        });
        Schema::create('movie_library_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 160);
            $table->char('request_hash', 64);
            $table->string('action', 24);
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('total');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'request_key'], 'movie_library_request_unique');
        });
        Schema::create('movie_library_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('operation_id')->constrained('movie_library_operations')->restrictOnDelete();
            $table->foreignId('content_item_id')->constrained('content_items')->restrictOnDelete();
            $table->unsignedBigInteger('expected_row_version');
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 120)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['operation_id', 'content_item_id'], 'movie_library_item_unique');
            $table->index(['operation_id', 'status', 'id'], 'movie_library_items_pending');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa lịch sử quản lý kho phim dùng chung.');
    }
};
