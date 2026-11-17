<?php

namespace App\Domain\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SiteDirectorySummary
{
    public function forSites(Collection $sites): array
    {
        $ids = $sites->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        // Project only the metadata needed by the directory, never HTML or assets.
        $themeMetadata = static fn (string $column): string => "jsonb_build_object('id', {$column}->'id', 'name', {$column}->'name', 'version_no', {$column}->'version_no')";
        $appearance = DB::table('sites')->whereIn('id', $ids)->select('id')
            ->selectRaw('live_appearance_json IS NOT NULL AS has_appearance')
            ->selectRaw("jsonb_build_object('schema_version', live_appearance_json->'schema_version', 'site_id', live_appearance_json->'site_id', 'has_theme', jsonb_exists(live_appearance_json, 'theme'), 'theme_type', jsonb_typeof(live_appearance_json->'theme'), 'theme', ".$themeMetadata("live_appearance_json->'theme'").", 'views_type', jsonb_typeof(live_appearance_json->'views'), 'player_type', jsonb_typeof(live_appearance_json->'player')) AS metadata")
            ->get()->keyBy('id');
        $needsPublication = array_values(array_filter($ids, fn (int $id): bool => ! ($appearance[$id]->has_appearance ?? false)));
        $publications = DB::table('pages as p')->join('page_publications as pp', function ($join): void {
            $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
        })->whereIn('p.site_id', $needsPublication)->where('p.status', 'live')->whereNotNull('p.current_publication_id')
            ->where(fn ($query) => $query->where('p.page_type', 'home')->orWhereRaw("(pp.snapshot_json->>'theme') IS NOT NULL OR (pp.snapshot_json->>'routing') IS NOT NULL"))
            ->selectRaw('DISTINCT ON (p.site_id) p.site_id, jsonb_typeof(pp.snapshot_json->\'theme\') AS theme_type, '.$themeMetadata("pp.snapshot_json->'theme'").' AS theme_metadata')
            ->orderBy('p.site_id')->orderByRaw("CASE WHEN p.page_type = 'home' THEN 0 ELSE 1 END")->orderBy('p.id')->get()->keyBy('site_id');
        $selectedThemes = DB::table('movie_themes')->whereIn('id', $sites->pluck('theme_id')->filter()->unique())->get(['id', 'name'])->keyBy('id');
        $sources = DB::table('source_site_bindings as b')->join('source_connectors as s', 's.id', '=', 'b.connector_id')
            ->whereIn('b.site_id', $ids)->whereNull('b.deleted_at')->whereNull('s.deleted_at')
            ->orderBy('s.name')->orderBy('s.id')->get(['b.site_id', 's.id', 's.name', 's.status', 'b.status as binding_status'])->groupBy('site_id');

        $result = [];
        foreach ($sites as $site) {
            $state = $appearance[$site->id] ?? null;
            if ($state?->has_appearance) {
                $metadata = Json::decode($state->metadata);
                $valid = ($metadata['schema_version'] ?? null) === 1 && ($metadata['site_id'] ?? null) === (int) $site->id
                    && ($metadata['has_theme'] ?? false) === true
                    && in_array($metadata['views_type'] ?? null, ['object', 'array'], true)
                    && in_array($metadata['player_type'] ?? null, ['object', 'array'], true);
                $theme = $valid ? $this->theme($metadata['theme'], $metadata['theme_type'], 'appearance') : $this->unknown();
            } elseif ($publication = $publications[$site->id] ?? null) {
                $theme = $this->theme(Json::decode($publication->theme_metadata), $publication->theme_type, 'publication');
            } else {
                $selected = $selectedThemes[$site->theme_id] ?? null;
                $theme = ['id' => $selected ? (int) $selected->id : null, 'name' => $selected?->name ?? 'Giao diện mặc định', 'version_no' => null, 'origin' => 'pending', 'status' => 'pending'];
            }
            $result[$site->id] = ['theme' => $theme, 'sources' => ($sources[$site->id] ?? collect())->all()];
        }

        return $result;
    }

    private function theme(array $metadata, ?string $type, string $origin): array
    {
        if ($type === null || $type === 'null') {
            return ['id' => null, 'name' => 'Giao diện mặc định', 'version_no' => null, 'origin' => 'builtin', 'status' => 'live'];
        }
        if ($type !== 'object' || ! is_int($metadata['id'] ?? null) || $metadata['id'] < 1) {
            return $this->unknown();
        }
        $name = is_string($metadata['name'] ?? null) && trim($metadata['name']) !== '' ? $metadata['name'] : 'Giao diện #'.$metadata['id'];
        $version = is_int($metadata['version_no'] ?? null) && $metadata['version_no'] > 0 ? $metadata['version_no'] : null;

        return ['id' => $metadata['id'], 'name' => $name, 'version_no' => $version, 'origin' => $origin, 'status' => 'live'];
    }

    private function unknown(): array
    {
        return ['id' => null, 'name' => 'Chưa xác định giao diện', 'version_no' => null, 'origin' => 'unknown', 'status' => 'unknown'];
    }
}
