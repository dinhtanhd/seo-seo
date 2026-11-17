<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '2s'");
        DB::unprepared(<<<'SQL'
CREATE TABLE fanmulu_recommendation_states (
    site_id bigint PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
    generation bigint NOT NULL DEFAULT 0,
    invalidated_xid xid8 NOT NULL DEFAULT '0',
    built_generation bigint,
    rebuilt_at timestamptz
);
CREATE TABLE fanmulu_recommendation_windows (
    site_id bigint NOT NULL REFERENCES fanmulu_recommendation_states(site_id) ON DELETE CASCADE,
    scope text NOT NULL,
    page_ids jsonb NOT NULL,
    total bigint NOT NULL,
    PRIMARY KEY (site_id, scope)
);

CREATE OR REPLACE FUNCTION tram_invalidate_fanmulu_recommendations(affected_sites bigint[]) RETURNS void LANGUAGE sql AS $$
    UPDATE fanmulu_recommendation_states
    SET generation = generation + 1, invalidated_xid = pg_current_xact_id()
    WHERE site_id = ANY(affected_sites)
      AND (invalidated_xid <> pg_current_xact_id() OR built_generation = generation)
$$;

CREATE OR REPLACE FUNCTION tram_fanmulu_recommendation_input_changed() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'catalog_metadata_state' THEN
        PERFORM tram_invalidate_fanmulu_recommendations(ARRAY(SELECT site_id FROM fanmulu_recommendation_states));
    ELSIF TG_TABLE_NAME = 'content_items' THEN
        PERFORM tram_invalidate_fanmulu_recommendations(ARRAY(
            SELECT DISTINCT site_id FROM pages WHERE content_item_id = NEW.id AND page_kind='regular' AND page_type='movie'
        ));
    ELSIF TG_TABLE_NAME = 'source_connectors' THEN
        PERFORM tram_invalidate_fanmulu_recommendations(ARRAY(
            SELECT DISTINCT site_id FROM source_site_bindings WHERE connector_id = NEW.id
        ));
    ELSIF TG_OP = 'INSERT' THEN
        PERFORM tram_invalidate_fanmulu_recommendations(ARRAY[NEW.site_id]);
    ELSIF TG_OP = 'DELETE' THEN
        PERFORM tram_invalidate_fanmulu_recommendations(ARRAY[OLD.site_id]);
    ELSE
        PERFORM tram_invalidate_fanmulu_recommendations(ARRAY[OLD.site_id, NEW.site_id]);
    END IF;
    RETURN NULL;
END
$$;

CREATE TRIGGER fanmulu_recommendation_metadata_update AFTER UPDATE ON catalog_metadata_state FOR EACH ROW
WHEN (OLD.ready IS DISTINCT FROM NEW.ready)
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_page_insert AFTER INSERT ON pages FOR EACH ROW
WHEN (NEW.page_kind='regular' AND NEW.page_type='movie' AND NEW.status IN ('draft','live'))
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_page_delete AFTER DELETE ON pages FOR EACH ROW
WHEN (OLD.page_kind='regular' AND OLD.page_type='movie' AND OLD.status IN ('draft','live'))
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_page_update AFTER UPDATE ON pages FOR EACH ROW
WHEN (((OLD.page_kind='regular' AND OLD.page_type='movie' AND OLD.status IN ('draft','live'))
    OR (NEW.page_kind='regular' AND NEW.page_type='movie' AND NEW.status IN ('draft','live')))
    AND ROW(OLD.site_id,OLD.page_kind,OLD.page_type,OLD.status,OLD.publish_locked,OLD.content_item_id,OLD.category_id,
        OLD.current_publication_id,OLD.working_content_revision_id,OLD.serving_content_revision_id,OLD.canonical_path)
    IS DISTINCT FROM ROW(NEW.site_id,NEW.page_kind,NEW.page_type,NEW.status,NEW.publish_locked,NEW.content_item_id,NEW.category_id,
        NEW.current_publication_id,NEW.working_content_revision_id,NEW.serving_content_revision_id,NEW.canonical_path))
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();

CREATE TRIGGER fanmulu_recommendation_content_update AFTER UPDATE ON content_items FOR EACH ROW
WHEN (ROW(OLD.kind,OLD.current_revision_id,OLD.library_status,OLD.source_connector_id)
    IS DISTINCT FROM ROW(NEW.kind,NEW.current_revision_id,NEW.library_status,NEW.source_connector_id))
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_connector_update AFTER UPDATE ON source_connectors FOR EACH ROW
WHEN (ROW(OLD.status,OLD.deleted_at,OLD.row_version,OLD.config_json)
    IS DISTINCT FROM ROW(NEW.status,NEW.deleted_at,NEW.row_version,NEW.config_json))
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_binding_membership AFTER INSERT OR DELETE ON source_site_bindings FOR EACH ROW
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_binding_update AFTER UPDATE ON source_site_bindings FOR EACH ROW
WHEN (ROW(OLD.site_id,OLD.connector_id,OLD.status,OLD.deleted_at,OLD.mapping_version,OLD.mapping_json,OLD.auto_publish_policy)
    IS DISTINCT FROM ROW(NEW.site_id,NEW.connector_id,NEW.status,NEW.deleted_at,NEW.mapping_version,NEW.mapping_json,NEW.auto_publish_policy))
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_category_membership AFTER INSERT OR DELETE ON categories FOR EACH ROW
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
CREATE TRIGGER fanmulu_recommendation_category_update AFTER UPDATE ON categories FOR EACH ROW
WHEN (ROW(OLD.site_id,OLD.parent_id,OLD.deleted_at) IS DISTINCT FROM ROW(NEW.site_id,NEW.parent_id,NEW.deleted_at))
EXECUTE FUNCTION tram_fanmulu_recommendation_input_changed();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS fanmulu_recommendation_category_update ON categories;
DROP TRIGGER IF EXISTS fanmulu_recommendation_category_membership ON categories;
DROP TRIGGER IF EXISTS fanmulu_recommendation_binding_update ON source_site_bindings;
DROP TRIGGER IF EXISTS fanmulu_recommendation_binding_membership ON source_site_bindings;
DROP TRIGGER IF EXISTS fanmulu_recommendation_connector_update ON source_connectors;
DROP TRIGGER IF EXISTS fanmulu_recommendation_content_update ON content_items;
DROP TRIGGER IF EXISTS fanmulu_recommendation_page_update ON pages;
DROP TRIGGER IF EXISTS fanmulu_recommendation_page_delete ON pages;
DROP TRIGGER IF EXISTS fanmulu_recommendation_page_insert ON pages;
DROP TRIGGER IF EXISTS fanmulu_recommendation_metadata_update ON catalog_metadata_state;
DROP FUNCTION IF EXISTS tram_fanmulu_recommendation_input_changed();
DROP FUNCTION IF EXISTS tram_invalidate_fanmulu_recommendations(bigint[]);
DROP TABLE fanmulu_recommendation_windows;
DROP TABLE fanmulu_recommendation_states;
SQL);
    }
};
