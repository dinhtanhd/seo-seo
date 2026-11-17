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
        foreach (['content_items', 'templates', 'movie_themes', 'website_presets', 'categories', 'rules'] as $name) {
            if (! Schema::hasColumn($name, 'deleted_at')) {
                Schema::table($name, fn (Blueprint $table) => $table->timestampTz('deleted_at')->nullable());
            }
        }
        if (! Schema::hasColumn('movie_themes', 'row_version')) {
            Schema::table('movie_themes', fn (Blueprint $table) => $table->unsignedBigInteger('row_version')->default(1));
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tram_catalog_ref_id(value jsonb) RETURNS bigint LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE text_value text;
BEGIN
 IF jsonb_typeof(value) NOT IN ('number','string') THEN RETURN NULL; END IF;
 text_value := value #>> '{}';
 IF text_value !~ '^[1-9][0-9]{0,18}$' THEN RETURN NULL; END IF;
 IF text_value::numeric > 9223372036854775807 THEN RETURN NULL; END IF;
 RETURN text_value::bigint;
END $$;

CREATE OR REPLACE FUNCTION tram_catalog_assert_reference(target text, target_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE is_deleted boolean; owner_id bigint; owner_table text; owner_column text;
BEGIN
 IF target_id IS NULL THEN RETURN; END IF;
 CASE target
  WHEN 'template_versions' THEN owner_table := 'templates'; owner_column := 'template_id';
  WHEN 'movie_theme_versions' THEN owner_table := 'movie_themes'; owner_column := 'theme_id';
  WHEN 'website_preset_versions' THEN owner_table := 'website_presets'; owner_column := 'preset_id';
  WHEN 'rule_versions' THEN owner_table := 'rules'; owner_column := 'rule_id';
  ELSE owner_table := NULL;
 END CASE;
 IF owner_table IS NOT NULL THEN
  EXECUTE format('SELECT %I FROM %I WHERE id=$1',owner_column,target) INTO owner_id USING target_id;
  PERFORM tram_catalog_assert_reference(owner_table,owner_id);
  RETURN;
 END IF;
 IF target NOT IN ('keyword_pools','keyword_groups','keywords','source_connectors','content_items','url_suffixes',
   'templates','movie_themes','website_presets','categories','rules') THEN
  RAISE EXCEPTION 'Unsupported catalog reference target' USING ERRCODE='22023';
 END IF;
 -- Missing legacy JSON IDs are tolerated. Existing rows remain locked through commit,
 -- so deletion cannot pass its usage check while a new reference is being committed.
 EXECUTE format('SELECT deleted_at IS NOT NULL FROM %I WHERE id=$1 FOR SHARE',target)
  INTO is_deleted USING target_id;
 IF is_deleted THEN
  RAISE EXCEPTION 'CATALOG_REFERENCE_DELETED:%:%',target,target_id USING ERRCODE='23514';
 END IF;
END $$;

CREATE OR REPLACE FUNCTION tram_catalog_json_references(data jsonb, context text DEFAULT '')
RETURNS TABLE(target text,target_id bigint) LANGUAGE plpgsql STABLE AS $$
DECLARE member record; value jsonb; resolved text; reference_id bigint;
BEGIN
 IF jsonb_typeof(data)='object' THEN
  FOR member IN SELECT entry.key,entry.value FROM jsonb_each(data) AS entry LOOP
   resolved := CASE member.key
    WHEN 'pool_id' THEN 'keyword_pools' WHEN 'pool_ids' THEN 'keyword_pools'
    WHEN 'fallback_pool_ids' THEN 'keyword_pools' WHEN 'display_name_pool_ids' THEN 'keyword_pools'
    WHEN 'group_id' THEN 'keyword_groups' WHEN 'group_ids' THEN 'keyword_groups'
    WHEN 'keyword_id' THEN 'keywords' WHEN 'keyword_ids' THEN 'keywords' WHEN 'manual_ids' THEN 'keywords'
    WHEN 'source_id' THEN 'source_connectors' WHEN 'source_ids' THEN 'source_connectors' WHEN 'connector_id' THEN 'source_connectors'
    WHEN 'template_id' THEN 'templates' WHEN 'template_ids' THEN 'templates' WHEN 'template_version_id' THEN 'template_versions'
    WHEN 'theme_id' THEN 'movie_themes' WHEN 'theme_ids' THEN 'movie_themes' WHEN 'theme_version_id' THEN 'movie_theme_versions'
    WHEN 'preset_id' THEN 'website_presets' WHEN 'preset_ids' THEN 'website_presets' WHEN 'preset_version_id' THEN 'website_preset_versions'
    WHEN 'rule_id' THEN 'rules' WHEN 'rule_version_id' THEN 'rule_versions'
    WHEN 'category_id' THEN 'categories' WHEN 'content_item_id' THEN 'content_items'
    WHEN 'id' THEN CASE context WHEN 'theme' THEN 'movie_themes' WHEN 'template' THEN 'templates' WHEN 'preset' THEN 'website_presets' ELSE NULL END
    WHEN 'version_id' THEN CASE context WHEN 'theme' THEN 'movie_theme_versions' WHEN 'template' THEN 'template_versions' WHEN 'preset' THEN 'website_preset_versions' ELSE NULL END
    ELSE NULL END;
   IF member.key IN ('suffix','suffixes','fanmulu_suffix','suffix_choices','fanmulu_suffix_choices') THEN
    FOR value IN SELECT member.value WHERE jsonb_typeof(member.value)='string'
      UNION ALL SELECT item.value FROM jsonb_each(CASE WHEN jsonb_typeof(member.value)='object' THEN member.value ELSE '{}'::jsonb END) AS item
      UNION ALL SELECT item.value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(member.value)='array' THEN member.value ELSE '[]'::jsonb END) AS item(value) LOOP
     IF jsonb_typeof(value)='string' AND value #>> '{}' <> '' THEN
      SELECT suffix.id INTO reference_id FROM url_suffixes AS suffix WHERE suffix.suffix=value #>> '{}';
      IF reference_id IS NOT NULL THEN target:='url_suffixes'; target_id:=reference_id; RETURN NEXT; END IF;
     END IF;
    END LOOP;
   ELSIF member.key='category_map' AND jsonb_typeof(member.value)='object' THEN
    FOR value IN SELECT entry.value FROM jsonb_each(member.value) AS entry LOOP
     reference_id := tram_catalog_ref_id(value);
     IF reference_id IS NOT NULL THEN target:='categories'; target_id:=reference_id; RETURN NEXT; END IF;
    END LOOP;
   ELSIF resolved IS NOT NULL THEN
    IF jsonb_typeof(member.value)='array' THEN
     FOR value IN SELECT jsonb_array_elements(member.value) LOOP
      reference_id := tram_catalog_ref_id(value);
      IF reference_id IS NOT NULL THEN target:=resolved; target_id:=reference_id; RETURN NEXT; END IF;
     END LOOP;
    ELSE
     reference_id := tram_catalog_ref_id(member.value);
     IF reference_id IS NOT NULL THEN target:=resolved; target_id:=reference_id; RETURN NEXT; END IF;
    END IF;
   END IF;
   IF jsonb_typeof(member.value) IN ('object','array') THEN
    RETURN QUERY SELECT nested.target,nested.target_id FROM tram_catalog_json_references(member.value,member.key) AS nested;
   END IF;
  END LOOP;
 ELSIF jsonb_typeof(data)='array' THEN
  FOR value IN SELECT jsonb_array_elements(data) LOOP
   RETURN QUERY SELECT nested.target,nested.target_id FROM tram_catalog_json_references(value,context) AS nested;
  END LOOP;
 END IF;
END $$;

CREATE OR REPLACE FUNCTION tram_catalog_snapshot_references(data jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
 SELECT jsonb_build_object(
  'theme',jsonb_build_object('id',data#>'{theme,id}','version_id',data#>'{theme,version_id}'),
  'template_id',data#>'{seo_template_bundle,template_id}',
  'template_version_id',COALESCE(data#>'{seo_template_bundle,template_version_id}',data#>'{resolved,template_version_id}'),
  'rule_sources',data#>'{resolved,sources}',
  'slots',data#>'{resolved,slots}',
  'assignments',data->'assignments','category_id',data#>'{page,category_id}',
  'suffixes',data#>'{routing,suffixes}','fanmulu',data#>'{routing,fanmulu}')
$$;

CREATE OR REPLACE FUNCTION tram_guard_catalog_references() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_row jsonb; previous_row jsonb := '{}'::jsonb; specification text; field text; target_table text;
 reference_id bigint; reference record; reactivating boolean := false; new_json jsonb; old_json jsonb;
BEGIN
 current_row := to_jsonb(NEW);
 IF TG_OP='UPDATE' THEN
  previous_row := to_jsonb(OLD);
  IF current_row->>'deleted_at' IS NOT NULL THEN
   RETURN NEW;
  END IF;
  reactivating := (previous_row->>'deleted_at' IS NOT NULL AND current_row->>'deleted_at' IS NULL)
    OR ((previous_row->>'status') IS DISTINCT FROM (current_row->>'status')
      AND current_row->>'status' IN ('active','live','draft','queued','running','pending','claimed','rendering','activating','applied','held','retry_wait'));
 END IF;
 FOREACH specification IN ARRAY TG_ARGV LOOP
  field := split_part(specification,':',1);
  target_table := split_part(specification,':',2);
  IF field='json' THEN
   field := target_table;
   IF TG_OP='INSERT' OR reactivating OR (current_row->field IS DISTINCT FROM previous_row->field) THEN
    new_json := current_row->field;
    old_json := previous_row->field;
    IF TG_TABLE_NAME='page_publications' AND field='snapshot_json' THEN
     new_json := tram_catalog_snapshot_references(new_json);
     old_json := tram_catalog_snapshot_references(old_json);
    ELSIF TG_TABLE_NAME='publish_batch_items' AND field='preview_json' THEN
     new_json := tram_catalog_snapshot_references(new_json->'snapshot');
     old_json := tram_catalog_snapshot_references(old_json->'snapshot');
    ELSIF TG_TABLE_NAME='pages' AND field='input_snapshot_json' THEN
     new_json := jsonb_build_object('selected_primary',new_json#>'{fanmulu,selected_primary}','display_name_pool_ids',new_json#>'{fanmulu,display_name_pool_ids}');
     old_json := jsonb_build_object('selected_primary',old_json#>'{fanmulu,selected_primary}','display_name_pool_ids',old_json#>'{fanmulu,display_name_pool_ids}');
    END IF;
    FOR reference IN SELECT DISTINCT refs.target,refs.target_id
      FROM tram_catalog_json_references(new_json) AS refs ORDER BY refs.target,refs.target_id LOOP
     IF TG_OP='INSERT' OR reactivating OR NOT EXISTS (
       SELECT 1 FROM tram_catalog_json_references(old_json) AS prior
       WHERE prior.target=reference.target AND prior.target_id=reference.target_id) THEN
      PERFORM tram_catalog_assert_reference(reference.target,reference.target_id);
     END IF;
    END LOOP;
   END IF;
  ELSIF TG_OP='INSERT' OR reactivating OR (current_row->field IS DISTINCT FROM previous_row->field) THEN
   reference_id := tram_catalog_ref_id(current_row->field);
   PERFORM tram_catalog_assert_reference(target_table,reference_id);
  END IF;
 END LOOP;
 RETURN NEW;
END $$;
SQL);
        $specifications = [
            'sites' => ['template_id:templates', 'theme_id:movie_themes', 'json:presentation_json', 'json:live_appearance_json'],
            'pages' => ['content_item_id:content_items', 'template_id:templates', 'theme_id:movie_themes', 'category_id:categories', 'json:input_snapshot_json'],
            'categories' => ['parent_id:categories'],
            'rules' => ['category_id:categories'],
            'keywords' => ['pool_id:keyword_pools'],
            'keyword_groups' => ['pool_id:keyword_pools'],
            'keyword_bindings' => ['pool_id:keyword_pools', 'group_id:keyword_groups', 'category_id:categories', 'content_item_id:content_items', 'json:filters_json'],
            'source_site_bindings' => ['connector_id:source_connectors', 'json:mapping_json'],
            'keyword_group_members' => ['pool_id:keyword_pools', 'group_id:keyword_groups', 'keyword_id:keywords'],
            'page_keyword_assignments' => ['keyword_id:keywords'],
            'fanmulu_url_registry' => ['keyword_id:keywords'],
            'site_keyword_limits' => ['keyword_id:keywords'],
            'keyword_capacity_claims' => ['keyword_id:keywords'],
            'ingest_runs' => ['connector_id:source_connectors'],
            'content_items' => ['source_connector_id:source_connectors'],
            'content_revisions' => ['content_item_id:content_items'],
            'template_versions' => ['template_id:templates', 'json:definition_json'],
            'template_slots' => ['template_version_id:template_versions'],
            'movie_theme_versions' => ['theme_id:movie_themes'],
            'website_preset_versions' => ['preset_id:website_presets', 'json:definition_json'],
            'rule_versions' => ['rule_id:rules', 'json:config_json'],
            'website_create_items' => ['preset_id:website_presets', 'preset_version_id:website_preset_versions', 'theme_id:movie_themes', 'json:definition_json'],
            'website_create_operations' => ['json:preset_manifest_json', 'json:options_json'],
            'website_bulk_edit_operations' => ['theme_version_id:movie_theme_versions', 'json:changes_json'],
            'page_bulk_operations' => ['json:changes_json', 'json:selected_json'],
            'site_bulk_operations' => ['json:changes_json', 'json:manifest_json'],
            'publish_batch_items' => ['json:preview_json'],
            'page_publications' => ['json:snapshot_json'],
            'movie_library_items' => ['content_item_id:content_items'],
        ];
        // Keyword tombstoning itself appends an intrinsic immutable keyword revision.
        DB::unprepared('DROP TRIGGER IF EXISTS catalog_reference_guard_keyword_versions ON keyword_versions');
        foreach ($specifications as $table => $arguments) {
            $name = 'catalog_reference_guard_'.$table;
            $quoted = implode(',', array_map(fn (string $value): string => "'".$value."'", $arguments));
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name.' ON '.$table);
            DB::unprepared('CREATE TRIGGER '.$name.' BEFORE INSERT OR UPDATE ON '.$table
                .' FOR EACH ROW EXECUTE FUNCTION tram_guard_catalog_references('.$quoted.')');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa dấu vết danh mục hoặc hàng rào tham chiếu đã được triển khai.');
    }
};
