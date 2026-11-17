<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->text('page_kind')->default('regular');
            $table->bigInteger('source_page_id')->nullable();
            $table->bigInteger('source_publication_id')->nullable();
            $table->index(['site_id', 'page_kind', 'id'], 'pages_site_kind');
        });
        DB::statement('ALTER TABLE pages ADD CONSTRAINT pages_site_id_content_unique UNIQUE(site_id,id,content_item_id)');
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_fanmulu_shape CHECK ((page_kind='regular' AND source_page_id IS NULL AND source_publication_id IS NULL) OR (page_kind='fanmulu' AND page_type='movie' AND source_page_id IS NOT NULL AND source_publication_id IS NOT NULL AND source_page_id<>id))");
        DB::statement('ALTER TABLE pages ADD CONSTRAINT pages_fanmulu_source FOREIGN KEY(site_id,source_page_id,content_item_id) REFERENCES pages(site_id,id,content_item_id)');
        DB::statement('ALTER TABLE pages ADD CONSTRAINT pages_fanmulu_publication FOREIGN KEY(site_id,source_page_id,source_publication_id) REFERENCES page_publications(site_id,page_id,id)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tram_fanmulu_source_refs() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.page_kind='fanmulu' AND NOT EXISTS (
        SELECT 1 FROM pages source JOIN page_publications publication
          ON publication.site_id=source.site_id AND publication.page_id=source.id
        WHERE source.id=NEW.source_page_id AND source.site_id=NEW.site_id
          AND source.page_kind='regular' AND source.page_type='movie'
          AND publication.id=NEW.source_publication_id
          AND publication.content_item_id=NEW.content_item_id
          AND publication.content_revision_id=NEW.working_content_revision_id
    ) THEN
        RAISE EXCEPTION 'fanmulu source publication ownership mismatch';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER fanmulu_source_refs BEFORE INSERT OR UPDATE OF page_kind,source_page_id,source_publication_id,content_item_id,working_content_revision_id ON pages FOR EACH ROW EXECUTE FUNCTION tram_fanmulu_source_refs();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa định danh fanmulu đã dùng; phục hồi bằng migration tiến hoặc backup được chọn.');
    }
};
