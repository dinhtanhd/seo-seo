<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movie_theme_versions', function (Blueprint $table) {
            $table->jsonb('runtime_json')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không gỡ runtime của phiên bản giao diện đã có thể được xuất bản.');
    }
};
