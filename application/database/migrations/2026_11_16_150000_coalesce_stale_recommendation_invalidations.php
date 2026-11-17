<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tram_invalidate_fanmulu_recommendations(affected_sites bigint[]) RETURNS void LANGUAGE sql AS $$
    UPDATE fanmulu_recommendation_states
    SET generation = generation + 1, invalidated_xid = pg_current_xact_id()
    WHERE site_id = ANY(affected_sites)
      AND (invalidated_xid <> pg_current_xact_id() OR built_generation = generation)
      AND CASE
          -- A stale index needs no extra generation while no rebuild can run.
          -- Keep the shared lock through commit so a rebuild cannot miss this write.
          WHEN pg_try_advisory_xact_lock_shared(hashtextextended('tram:fanmulu:recommendations:' || site_id, 0))
              THEN built_generation = generation
          -- A rebuild may have read the old catalog. Invalidate its generation
          -- or let this update run after it commits, invalidating the new index.
          ELSE true
      END
$$;
SQL);
        $this->replacePageUpdateTrigger(<<<'SQL'
AND NOT (
    OLD.page_kind = 'regular' AND OLD.page_type = 'movie' AND OLD.status = 'draft'
    AND NEW.page_kind = 'regular' AND NEW.page_type = 'movie' AND NEW.status = 'live'
    AND NOT OLD.publish_locked AND NOT NEW.publish_locked AND OLD.canonical_path = NEW.canonical_path
    AND OLD.current_publication_id IS NULL AND NEW.current_publication_id IS NOT NULL
    AND ROW(OLD.site_id, OLD.content_item_id, OLD.category_id, OLD.working_content_revision_id)
        IS NOT DISTINCT FROM ROW(NEW.site_id, NEW.content_item_id, NEW.category_id, NEW.serving_content_revision_id)
    AND OLD.working_content_revision_id = NEW.working_content_revision_id
    AND NEW.id::text || ':' || NEW.current_publication_id::text =
        COALESCE(current_setting('tram.recommendation_equivalent_head', true), '')
)
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tram_invalidate_fanmulu_recommendations(affected_sites bigint[]) RETURNS void LANGUAGE sql AS $$
    UPDATE fanmulu_recommendation_states
    SET generation = generation + 1, invalidated_xid = pg_current_xact_id()
    WHERE site_id = ANY(affected_sites)
      AND (invalidated_xid <> pg_current_xact_id() OR built_generation = generation)
$$;
SQL);
        $this->replacePageUpdateTrigger('');
    }

    private function replacePageUpdateTrigger(string $equivalentHeadFilter): void
    {
        DB::unprepared(<<<SQL
CREATE OR REPLACE TRIGGER fanmulu_recommendation_page_update AFTER UPDATE ON pages FOR EACH ROW
WHEN (((OLD.page_kind='regular' AND OLD.page_type='movie' AND OLD.status IN ('draft','live'))
    OR (NEW.page_kind='regular' AND NEW.page_type='movie' AND NEW.status IN ('draft','live')))
    AND ROW(OLD.site_id,OLD.page_kind,OLD.page_type,OLD.status,OLD.publish_locked,OLD.content_item_id,OLD.category_id,
        OLD.current_publication_id,OLD.working_content_revision_id,OLD.serving_content_revision_id,OLD.canonical_path)
    IS DISTINCT FROM ROW(NEW.site_id,NEW.page_kind,NEW.page_type,NEW.status,NEW.publish_locked,NEW.content_item_id,NEW.category_id,
        NEW.current_publication_id,NEW.working_content_revision_id,NEW.serving_content_revision_id,NEW.canonical_path)
    $equivalentHeadFilter)
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
SQL);
    }
};
