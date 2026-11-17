<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('url_suffixes', function (Blueprint $table): void {
            $table->id();
            $table->string('suffix', 13)->unique();
            $table->string('label', 120);
            $table->unsignedBigInteger('row_version')->default(1);
            $table->softDeletesTz();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE url_suffixes ADD CONSTRAINT url_suffixes_format CHECK (suffix ~ '^\\.[a-z][a-z0-9]{0,11}$')");
        DB::table('url_suffixes')->insert([
            ['suffix' => '.html', 'label' => 'Trang HTML', 'created_at' => now(), 'updated_at' => now()],
            ['suffix' => '.asp', 'label' => 'Đuôi ASP', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('url_suffixes');
    }
};
