<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            Schema::table('keyword_pools', function (Blueprint $table): void {
                $table->timestampTz('deleted_at')->nullable();
            });
            Schema::table('keyword_groups', function (Blueprint $table): void {
                $table->bigInteger('row_version')->default(1);
                $table->timestampTz('deleted_at')->nullable();
            });
            Schema::table('keyword_bindings', function (Blueprint $table): void {
                $table->bigInteger('row_version')->default(1);
            });
            DB::statement("ALTER TABLE keyword_pools ADD CONSTRAINT pool_deleted_status CHECK (deleted_at IS NULL OR status='archived')");
            DB::statement("ALTER TABLE keyword_groups ADD CONSTRAINT group_deleted_status CHECK (deleted_at IS NULL OR status='archived')");
            DB::statement('ALTER TABLE keyword_groups ADD CONSTRAINT group_row_version_positive CHECK (row_version > 0)');
            DB::statement('ALTER TABLE keyword_bindings ADD CONSTRAINT binding_row_version_positive CHECK (row_version > 0)');
            $changed = DB::table('keyword_pools')->where('scope', 'site')->update([
                'scope' => 'shared', 'owner_site_id' => null,
                'row_version' => DB::raw('row_version+1'), 'updated_at' => now(),
            ]);
            if ($changed > 0) {
                DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            }
        }, 3);
    }

    public function down(): void
    {
        throw new RuntimeException('Không rollback schema hoặc định danh kho đã dùng; phục hồi bằng migration tiến hoặc backup được chọn.');
    }
};
