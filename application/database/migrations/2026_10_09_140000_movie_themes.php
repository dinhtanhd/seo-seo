<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE movie_themes (id bigserial PRIMARY KEY, slug text NOT NULL UNIQUE, name text NOT NULL, description text NOT NULL DEFAULT '', status text NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')), row_version bigint NOT NULL DEFAULT 1, current_version_id bigint, created_by bigint REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE movie_theme_versions (id bigserial PRIMARY KEY, theme_id bigint NOT NULL REFERENCES movie_themes(id), version_no integer NOT NULL, views_json jsonb NOT NULL, css text NOT NULL, assets_json jsonb NOT NULL DEFAULT '{}', hash text NOT NULL, created_by bigint REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(theme_id,version_no), UNIQUE(theme_id,id));
ALTER TABLE movie_themes ADD FOREIGN KEY(id,current_version_id) REFERENCES movie_theme_versions(theme_id,id);
CREATE TABLE theme_assets (hash text PRIMARY KEY, mime text NOT NULL, data_base64 text NOT NULL, size_bytes integer NOT NULL, created_at timestamptz NOT NULL DEFAULT now());
ALTER TABLE sites ADD COLUMN theme_id bigint REFERENCES movie_themes(id);
ALTER TABLE pages ADD COLUMN theme_id bigint REFERENCES movie_themes(id);
CREATE TRIGGER immutable_movie_theme BEFORE UPDATE OR DELETE ON movie_theme_versions FOR EACH ROW EXECUTE FUNCTION tram_immutable();
CREATE TRIGGER immutable_theme_asset BEFORE UPDATE OR DELETE ON theme_assets FOR EACH ROW EXECUTE FUNCTION tram_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Không rollback migration giao diện có thể đang được publications tham chiếu.');
    }
};
