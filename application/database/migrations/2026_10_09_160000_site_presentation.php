<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE sites ADD COLUMN presentation_json jsonb NOT NULL DEFAULT '{}' CHECK(jsonb_typeof(presentation_json)='object')");
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa cấu hình giao diện/URL đang được các publication tham chiếu.');
    }
};
