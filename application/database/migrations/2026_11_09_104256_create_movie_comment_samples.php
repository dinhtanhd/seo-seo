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
        Schema::create('movie_comment_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('seed_key', 100)->unique();
            $table->string('seed_version', 40)->nullable();
            $table->unsignedInteger('seed_ordinal')->nullable()->unique();
            $table->char('selection_key', 64)->unique();
            $table->string('locale', 16)->default('zh-CN');
            $table->text('body');
            $table->char('body_hash', 64)->unique();
            $table->string('source', 20)->default('sample');
            $table->string('status', 20)->default('active');
            $table->bigInteger('row_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement("ALTER TABLE movie_comment_samples ADD CONSTRAINT movie_comment_samples_body_length CHECK (char_length(body) BETWEEN 1 AND 1000), ADD CONSTRAINT movie_comment_samples_source CHECK (source='sample'), ADD CONSTRAINT movie_comment_samples_status CHECK (status IN ('active','paused'))");
        DB::statement("CREATE INDEX movie_comment_samples_serve ON movie_comment_samples (locale,selection_key) WHERE source='sample' AND status='active' AND deleted_at IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Không tự xóa kho bình luận mẫu đã được website sử dụng.');
    }
};
