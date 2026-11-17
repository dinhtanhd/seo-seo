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
        Schema::create('site_delivery_settings', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained('sites')->restrictOnDelete();
            $table->string('mode', 16)->default('prepublish');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->bigInteger('row_version')->default(1);
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement("ALTER TABLE site_delivery_settings ADD CONSTRAINT site_delivery_mode CHECK (mode IN ('prepublish','on_demand'))");
        DB::statement('ALTER TABLE site_delivery_settings ADD CONSTRAINT site_delivery_version CHECK (row_version >= 1)');
        DB::statement("ALTER TABLE site_delivery_settings ADD CONSTRAINT site_delivery_actor CHECK (mode <> 'on_demand' OR actor_id IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_delivery_settings');
    }
};
