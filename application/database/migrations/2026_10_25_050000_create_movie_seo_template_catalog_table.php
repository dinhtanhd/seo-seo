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
        Schema::create('movie_seo_template_catalog', function (Blueprint $table): void {
            $table->string('stable_key', 100)->primary();
            $table->unsignedBigInteger('template_id')->unique();
            $table->unsignedBigInteger('initial_version_id');
            $table->char('initial_hash', 64);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['template_id', 'initial_version_id'])->references(['template_id', 'id'])->on('template_versions');
        });
        DB::unprepared('CREATE TRIGGER immutable_movie_seo_template_catalog BEFORE UPDATE OR DELETE ON movie_seo_template_catalog FOR EACH ROW EXECUTE FUNCTION tram_immutable()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie_seo_template_catalog');
    }
};
