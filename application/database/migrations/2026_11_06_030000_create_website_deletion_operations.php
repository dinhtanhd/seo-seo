<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestampTz('deleted_at')->nullable()->index();
        });
        Schema::create('site_delete_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('request_key', 160);
            $table->string('request_hash', 64);
            $table->string('action', 20);
            $table->string('status', 20)->default('preview');
            $table->unsignedInteger('total');
            $table->bigInteger('row_version')->default(1);
            $table->string('preview_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'request_key']);
            $table->index(['status', 'last_dispatched_at']);
        });
        Schema::create('site_delete_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('site_delete_operations');
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->bigInteger('expected_row_version');
            $table->jsonb('snapshot_json');
            $table->jsonb('receipt_json')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['operation_id', 'site_id']);
            $table->index(['operation_id', 'status', 'id']);
        });
        Schema::create('site_trash_entries', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained('sites')->restrictOnDelete();
            $table->unsignedBigInteger('delete_item_id');
            $table->foreign('delete_item_id')->references('id')->on('site_delete_items');
            $table->foreignId('deleted_by')->constrained('users');
            $table->timestampTz('deleted_at');
            $table->timestampTz('restored_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa receipt và lịch sử xóa/khôi phục website.');
    }
};
