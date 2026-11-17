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
        Schema::create('activity_record_states', function (Blueprint $table) {
            $table->string('kind', 16);
            $table->bigInteger('record_id');
            $table->foreignId('deleted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('deleted_at');
            $table->primary(['kind', 'record_id']);
        });
        DB::statement("ALTER TABLE activity_record_states ADD CONSTRAINT activity_record_kind CHECK (kind IN ('audit','failed_job')), ADD CONSTRAINT activity_record_positive_id CHECK (record_id > 0)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Giữ trạng thái thùng rác lịch sử để có thể khôi phục.');
    }
};
