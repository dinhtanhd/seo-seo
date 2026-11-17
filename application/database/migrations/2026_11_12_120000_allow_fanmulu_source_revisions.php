<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::unprepared(<<<'SQL'
ALTER TABLE pages DROP CONSTRAINT pages_fanmulu_shape,
    ADD CONSTRAINT pages_fanmulu_shape CHECK (
        (page_kind='regular' AND source_page_id IS NULL AND source_publication_id IS NULL) OR
        (page_kind='fanmulu' AND page_type='movie' AND source_page_id IS NOT NULL AND source_page_id<>id
         AND working_content_revision_id IS NOT NULL)
    ) NOT VALID;

CREATE OR REPLACE FUNCTION tram_fanmulu_source_refs() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.page_kind='fanmulu' AND NOT EXISTS (
        SELECT 1 FROM pages source
        WHERE source.id=NEW.source_page_id AND source.site_id=NEW.site_id
          AND source.page_kind='regular' AND source.page_type='movie'
          AND source.content_item_id=NEW.content_item_id
          AND (NEW.source_publication_id IS NULL OR EXISTS (
              SELECT 1 FROM page_publications publication
              WHERE publication.site_id=source.site_id AND publication.page_id=source.id
                AND publication.id=NEW.source_publication_id
                AND publication.content_item_id=NEW.content_item_id
                AND publication.content_revision_id=NEW.working_content_revision_id
          ))
    ) THEN
        RAISE EXCEPTION 'fanmulu source publication ownership mismatch';
    END IF;
    RETURN NEW;
END $$;
SQL);
        });
        DB::statement('ALTER TABLE pages VALIDATE CONSTRAINT pages_fanmulu_shape');
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ định danh và revision của các fanmulu đã tạo.');
    }
};
