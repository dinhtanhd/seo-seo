<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout='2s'");
        Schema::table('advertising_settings', function (Blueprint $table): void {
            $table->jsonb('configuration_json')->default('{}');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa cấu hình URL quảng cáo và mã thống kê đã lưu.');
    }
};
