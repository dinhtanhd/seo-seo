<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Support\SchemaAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Derived, website-scoped prefixes of the regular movie catalog. */
final class FanmuluRecommendationIndex
{
    private const WINDOW = 128;

    public function rebuild(int $siteId): bool
    {
        if (! app(CatalogMetadata::class)->available()) {
            return false;
        }

        DB::table('fanmulu_recommendation_states')->insertOrIgnore(['site_id' => $siteId]);
        try {
            return DB::transaction(function () use ($siteId): bool {
                $locked = DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0)) AS acquired', ['tram:fanmulu:recommendations:'.$siteId]);
                if (! $locked->acquired) {
                    return false;
                }
                $state = DB::table('fanmulu_recommendation_states')->where('site_id', $siteId)->first();
                if ($state->built_generation === $state->generation) {
                    return true;
                }

                $candidates = app(OnDemandCatalog::class)->recommendationCandidates($siteId);
                $this->buildWindows($siteId, $candidates);

                $updated = DB::table('fanmulu_recommendation_states')->where('site_id', $siteId)->where('generation', $state->generation)
                    ->update(['built_generation' => $state->generation, 'rebuilt_at' => now()]);
                if ($updated !== 1) {
                    throw new Conflict('FANMULU_RECOMMENDATIONS_MOVED');
                }

                return true;
            }, 3);
        } catch (Conflict $error) {
            if ($error->reason !== 'FANMULU_RECOMMENDATIONS_MOVED') {
                throw $error;
            }

            return false;
        }
    }

    /** @return array{generation:int, windows:array<string,object>}|null */
    public function load(int $siteId, array $phases): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $keys = array_values(array_unique(array_merge(...array_map($this->keys(...), $phases))));
        $rows = $this->ready($siteId)->leftJoin('fanmulu_recommendation_windows as w', function ($join) use ($keys): void {
            $join->on('w.site_id', '=', 'state.site_id')->whereIn('w.scope', $keys);
        })->get(['state.generation', 'w.scope', 'w.page_ids', 'w.total']);

        return $rows->isEmpty() ? null : ['generation' => (int) $rows->first()->generation,
            'windows' => $rows->whereNotNull('scope')->keyBy('scope')->all()];
    }

    public function current(int $siteId, int $generation): bool
    {
        return $this->ready($siteId)->where('state.generation', $generation)->exists();
    }

    /** Prove the draft and its first publication belong to the same prepared windows. */
    public function preservesMembership(object $page, array $snapshot): bool
    {
        $siteId = (int) $page->site_id;
        if (! $this->available() || ! $this->ready($siteId)->exists()
            || (int) ($snapshot['content_revision_id'] ?? 0) !== (int) $page->working_content_revision_id
            || ($snapshot['page']['category_id'] ?? null) !== $page->category_id
            || ! app(OnDemandCatalog::class)->recommendationCandidates($siteId, (int) $page->id)->exists()) {
            return false;
        }
        $category = $snapshot['category'] ?? [];
        $parents = DB::table('categories')->where('site_id', $siteId)->whereNull('deleted_at')->pluck('parent_id', 'id')->all();
        $ancestors = [];
        for ($id = $page->category_id; array_key_exists($id, $parents) && ! isset($ancestors[$id]); $id = $parents[$id]) {
            $ancestors[$id] = true;
        }
        if (! is_array($category['ancestor_ids'] ?? null)) {
            return ($category['id'] ?? $page->category_id) === $page->category_id;
        }

        $frozen = array_intersect_key(array_fill_keys([$page->category_id, ...array_filter($category['ancestor_ids'], 'is_int')], true), $parents);

        return array_diff_key($ancestors, $frozen) === [] && array_diff_key($frozen, $ancestors) === [];
    }

    private function available(): bool
    {
        return config('public_on_demand.search_index_enabled') && app(SchemaAvailability::class)->hasTable('fanmulu_recommendation_states');
    }

    /** The top K of a union is contained in the union of each term's top K. */
    public function window(array $prepared, array $phase): array
    {
        $ids = [];
        $complete = true;
        foreach ($this->keys($phase) as $key) {
            $row = $prepared['windows'][$key] ?? null;
            if ($row !== null) {
                $ids = [...$ids, ...Json::decode($row->page_ids)];
                $complete = $complete && $row->total <= self::WINDOW;
            }
        }
        $ids = array_values(array_unique($ids));
        rsort($ids, SORT_NUMERIC);

        return [array_slice($ids, 0, self::WINDOW), $complete && count($ids) <= self::WINDOW];
    }

    private function ready(int $siteId): Builder
    {
        return DB::table('fanmulu_recommendation_states as state')->where('state.site_id', $siteId)
            ->whereColumn('state.generation', 'state.built_generation')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('catalog_metadata_state')->where(['id' => 1, 'ready' => true]));
    }

    private function keys(array $phase): array
    {
        $filters = $phase['filters'];

        return match (true) {
            $phase['category'] !== null => ['category:'.hash('sha256', (string) $phase['category'])],
            isset($filters['genre']) => ['genre:'.hash('sha256', $filters['genre'])],
            isset($filters['genres_any']) => array_map(fn ($term) => 'genre_term:'.hash('sha256', $term), $filters['genres_any']),
            isset($filters['actors_any']) => array_map(fn ($term) => 'actor:'.hash('sha256', $term), $filters['actors_any']),
            default => ['all:'.hash('sha256', '')],
        };
    }

    private function buildWindows(int $siteId, Builder $candidates): void
    {
        $sql = <<<'SQL'
WITH RECURSIVE categories_in_site AS MATERIALIZED (
    SELECT id, parent_id FROM categories WHERE site_id=? AND deleted_at IS NULL
), ancestry(category_id, ancestor_id, seen) AS (
    SELECT id, id, ARRAY[id] FROM categories_in_site
    UNION ALL
    SELECT a.category_id, parent.id, a.seen || parent.id
    FROM ancestry a JOIN categories_in_site child ON child.id=a.ancestor_id
    JOIN categories_in_site parent ON parent.id=child.parent_id AND NOT parent.id=ANY(a.seen)
), members AS MATERIALIZED (
    SELECT c.id,c.category_id,c.is_draft,
        CASE WHEN c.revision_id IS NULL THEN pm.fields_json ELSE rm.fields_json END AS fields,
        pp.snapshot_json->'page'->>'category_id' AS frozen_category,
        pp.snapshot_json->'category'->'ancestor_ids' AS frozen_ancestors,
        COALESCE(pp.snapshot_json->'category'->>'id',pp.snapshot_json->'page'->>'category_id') AS legacy_category
    FROM (%s) c
    LEFT JOIN catalog_revision_metadata rm ON rm.id=c.revision_id AND rm.content_item_id=c.content_item_id
    LEFT JOIN catalog_publication_metadata pm ON pm.id=c.publication_id AND pm.page_id=c.id
    LEFT JOIN page_publications pp ON pp.id=c.publication_id AND pp.page_id=c.id
), grouped AS (
    SELECT scope.kind, scope.term, count(DISTINCT m.id) AS total,
        (array_agg(DISTINCT m.id ORDER BY m.id DESC))[1:?] AS page_ids
    FROM members m
    CROSS JOIN LATERAL (
        SELECT 'all' AS kind, '' AS term
        UNION ALL SELECT 'genre', m.fields->>'genre' WHERE m.fields->>'genre' IS NOT NULL
        UNION ALL SELECT 'genre_term', term FROM unnest(tram_catalog_exact_terms(m.fields->'genre')) term WHERE length(term) BETWEEN 1 AND 200
        UNION ALL SELECT 'actor', term FROM unnest(tram_catalog_exact_terms(m.fields->'actors')) term WHERE length(term) BETWEEN 1 AND 200
        UNION ALL
        SELECT 'category', a.ancestor_id::text FROM ancestry a
        WHERE (m.is_draft AND a.category_id=m.category_id)
           OR (NOT m.is_draft AND jsonb_typeof(m.frozen_ancestors) IS DISTINCT FROM 'array' AND a.category_id::text=m.legacy_category)
        UNION ALL
        SELECT 'category', c.id::text FROM categories_in_site c
        WHERE NOT m.is_draft AND (c.id::text=m.frozen_category OR m.frozen_ancestors @> to_jsonb(ARRAY[c.id]))
    ) scope
    GROUP BY scope.kind, scope.term
), projected AS MATERIALIZED (
    SELECT kind||':'||encode(sha256(convert_to(term,'UTF8')),'hex') AS scope,
        to_jsonb(page_ids) AS page_ids,total FROM grouped
), removed AS (
    DELETE FROM fanmulu_recommendation_windows w WHERE w.site_id=?
      AND NOT EXISTS (SELECT 1 FROM projected p WHERE p.scope=w.scope)
)
INSERT INTO fanmulu_recommendation_windows (site_id, scope, page_ids, total)
SELECT ?, p.scope, p.page_ids, p.total FROM projected p
LEFT JOIN fanmulu_recommendation_windows w ON w.site_id=? AND w.scope=p.scope
WHERE (w.page_ids,w.total) IS DISTINCT FROM (p.page_ids,p.total)
ON CONFLICT (site_id,scope) DO UPDATE SET page_ids=EXCLUDED.page_ids,total=EXCLUDED.total
SQL;

        DB::statement(sprintf($sql, $candidates->toSql()), [$siteId, ...$candidates->getBindings(), self::WINDOW, $siteId, $siteId, $siteId]);
    }
}
