<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '2s'");
        Schema::create('fanmulu_url_registry', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('site_id');
            $table->string('path', 512);
            $table->char('identity_hash', 64);
            $table->bigInteger('source_page_id');
            $table->bigInteger('parent_page_id')->nullable();
            $table->bigInteger('parent_publication_id')->nullable();
            $table->char('navigation_token', 24)->nullable();
            $table->bigInteger('keyword_id')->nullable()->references('id')->on('keywords');
            $table->text('keyword_text')->nullable();
            $table->string('origin', 16);
            $table->jsonb('rule_json');
            $table->string('prefix', 120);
            $table->string('suffix', 13);
            $table->jsonb('metadata_json')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['site_id', 'path']);
            $table->unique(['site_id', 'identity_hash']);
            $table->foreign(['site_id', 'source_page_id'])->references(['site_id', 'id'])->on('pages');
            $table->foreign(['site_id', 'parent_page_id'])->references(['site_id', 'id'])->on('pages');
            $table->foreign(['site_id', 'parent_page_id', 'parent_publication_id'])->references(['site_id', 'page_id', 'id'])->on('page_publications');
        });
        DB::statement("ALTER TABLE fanmulu_url_registry ADD CONSTRAINT fanmulu_registry_shape CHECK (origin IN ('nav','discovery','admin') AND path LIKE '/%' AND path NOT LIKE '//%' AND identity_hash ~ '^[a-f0-9]{64}$' AND (navigation_token IS NULL OR navigation_token ~ '^[a-f0-9]{24}$') AND (parent_publication_id IS NULL OR parent_page_id IS NOT NULL) AND ((keyword_id IS NULL)=(keyword_text IS NULL)))");
        DB::unprepared("CREATE OR REPLACE FUNCTION tram_fanmulu_registry_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Fanmulu URL mappings are immutable'; END \$\$; CREATE TRIGGER fanmulu_registry_immutable BEFORE UPDATE OR DELETE ON fanmulu_url_registry FOR EACH ROW EXECUTE FUNCTION tram_fanmulu_registry_immutable()");
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ ánh xạ URL bất biến; chỉ dùng migration tiến.');
    }
};
