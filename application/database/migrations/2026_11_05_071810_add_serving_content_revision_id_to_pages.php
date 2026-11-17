<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('pages', 'serving_content_revision_id')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->unsignedBigInteger('serving_content_revision_id')->nullable();
                $table->foreign(['content_item_id', 'serving_content_revision_id'], 'pages_serving_content_owner')
                    ->references(['content_item_id', 'id'])->on('content_revisions')->restrictOnDelete();
            });
            DB::statement('ALTER TABLE pages ADD CONSTRAINT pages_serving_content_requires_owner CHECK (serving_content_revision_id IS NULL OR content_item_id IS NOT NULL)');
        }
        if (! Schema::hasColumn('sites', 'public_content_epoch')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->unsignedBigInteger('public_content_epoch')->default(0);
            });
        }
        DB::statement("SET lock_timeout='2s'");
        try {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_content_usage ON pages(content_item_id,site_id,id) WHERE content_item_id IS NOT NULL');
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa con trỏ nội dung phục vụ và lịch sử website.');
    }
};
