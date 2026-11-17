<?php

namespace App\Domain\Fanmulu;

use App\Domain\Ingestion\SourceTypeService;
use App\Domain\Publishing\CatalogMetadata;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Support\CatalogAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Discover regular source pages without creating publications or reserving keywords. */
final class FanmuluSourceCatalog
{
    public function liveQuery(int $siteId): Builder
    {
        return DB::table('pages as p')->join('page_publications as pp', function ($join): void {
            $join->on('pp.site_id', '=', 'p.site_id')->on('pp.page_id', '=', 'p.id')->on('pp.id', '=', 'p.current_publication_id');
        })->join('content_items as library_content', 'library_content.id', '=', 'p.content_item_id')
            ->where('library_content.library_status', 'active')
            ->whereNotExists(fn (Builder $removed) => $removed->selectRaw('1')->from('source_site_bindings as removed_source')
                ->whereColumn('removed_source.site_id', 'p.site_id')->whereColumn('removed_source.connector_id', 'library_content.source_connector_id')
                ->whereNotNull('removed_source.deleted_at'))
            ->where('p.site_id', $siteId)->whereIn('p.site_id', CatalogAvailability::filter(DB::table('sites'), 'sites')->select('id'))
            ->where('p.page_kind', 'regular')->where('p.page_type', 'movie')
            ->where('p.status', 'live')->whereNotNull('pp.content_revision_id');
    }

    /** @return list<int> Eligible live or first-publication pages, in stable ID order. */
    public function candidateIds(int $siteId, int $limit = 20, ?int $hintId = null, ?int $excludeId = null, ?int $afterId = null): array
    {
        $limit = max(1, min(20, $limit));
        $live = $this->liveQuery($siteId)->when($hintId !== null, fn ($query) => $query->where('p.id', $hintId))
            ->when($excludeId !== null, fn ($query) => $query->where('p.id', '<>', $excludeId))
            ->when($afterId !== null, fn ($query) => $query->where('p.id', '>=', $afterId));
        $ids = $live->orderBy('p.id')->limit($limit)->pluck('p.id')->map(fn ($id): int => (int) $id)->all();
        $draftIds = $this->draftQuery($siteId)->when($hintId !== null, fn ($query) => $query->where('p.id', $hintId))
            ->when($excludeId !== null, fn ($query) => $query->where('p.id', '<>', $excludeId))
            ->when($afterId !== null, fn ($query) => $query->where('p.id', '>=', $afterId))
            ->orderBy('p.id')->limit($limit)->pluck('p.id')->map(fn ($id): int => (int) $id)->all();
        $ids = array_values(array_unique([...$ids, ...$draftIds]));
        sort($ids, SORT_NUMERIC);

        return array_slice($ids, 0, $limit);
    }

    /** Imported revisions with the existing source, binding and type eligibility. */
    public function draftQuery(int $siteId): Builder
    {
        if (! app(SiteDeliverySettings::class)->enabled($siteId)) {
            return DB::table('pages as p')->whereRaw('false');
        }
        $policies = [];
        foreach (DB::table('source_site_bindings as b')->join('source_connectors as s', 's.id', '=', 'b.connector_id')
            ->where(['b.site_id' => $siteId, 'b.status' => 'active', 's.status' => 'active'])->whereNull('s.deleted_at')->whereNull('b.deleted_at')
            ->whereRaw("b.auto_publish_policy->>'enabled' = 'true'")
            ->get(['b.id', 'b.mapping_version', 'b.mapping_json', 's.id as source_id', 's.row_version', 's.config_json']) as $binding) {
            try {
                $policy = SourceTypeService::policy($binding);
                SourceTypeService::assertCurrentPolicy($policy, $binding);
                $policies[] = ['binding' => $binding, 'policy' => $policy];
            } catch (\RuntimeException) {
                continue;
            }
        }
        if ($policies === []) {
            return DB::table('pages as p')->whereRaw('false');
        }
        $draft = DB::table('pages as p')->join('sites as site', 'site.id', '=', 'p.site_id')
            ->join('content_items as c', function ($join): void {
                $join->on('c.id', '=', 'p.content_item_id')
                    ->whereRaw('CASE WHEN c.current_revision_id=p.working_content_revision_id THEN true ELSE false END');
            })->join('source_connectors as s', 's.id', '=', 'c.source_connector_id')
            ->join('source_site_bindings as b', function ($join): void {
                $join->on('b.site_id', '=', 'p.site_id')->on('b.connector_id', '=', 's.id');
            })->where('p.site_id', $siteId)->where(['site.status' => 'active', 'site.content_type' => 'movie', 'c.kind' => 'movie', 'c.library_status' => 'active',
                'p.page_kind' => 'regular', 'p.page_type' => 'movie', 'p.status' => 'draft', 'p.publish_locked' => false,
                's.status' => 'active', 'b.status' => 'active'])
            ->whereNull('p.current_publication_id')->whereNull('s.deleted_at')->whereNull('b.deleted_at')->whereRaw("b.auto_publish_policy->>'enabled' = 'true'")
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('page_publications as history')->whereColumn('history.page_id', 'p.id'));
        $indexed = app(CatalogMetadata::class)->available();
        $draft->where(function (Builder $query) use ($policies, $indexed): void {
            foreach ($policies as $entry) {
                $binding = $entry['binding'];
                $query->orWhere(function (Builder $source) use ($entry, $binding, $indexed): void {
                    $source->where('b.id', $binding->id)->where('b.mapping_version', $binding->mapping_version)
                        ->where('s.id', $binding->source_id)->where('s.row_version', $binding->row_version);
                    if ($entry['policy']['mode'] === 'selected') {
                        $source->whereExists(function (Builder $type) use ($entry, $indexed): void {
                            $content = $indexed ? 'r.fields_json' : 'r.payload_json';
                            $type->selectRaw('1')->from(($indexed ? 'catalog_revision_metadata' : 'content_revisions').' as r')
                                ->whereColumn('r.id', 'p.working_content_revision_id')
                                ->whereIn(DB::raw("COALESCE($content->>'category_external_id', $content->'_raw'->>'type_id')"), $entry['policy']['selected_type_ids']);
                        });
                    }
                });
            }
        });

        return $draft;
    }

    public function canServe(object $fanmulu): bool
    {
        $siteId = (int) $fanmulu->site_id;
        $sourceId = (int) $fanmulu->source_page_id;

        return $this->liveQuery($siteId)->where('p.id', $sourceId)->exists()
            || ($fanmulu->source_publication_id === null && $this->draftQuery($siteId)->where('p.id', $sourceId)->exists());
    }

    /** Random seek avoids sorting an entire imported catalogue for each new discovery origin. */
    public function randomCandidateIds(int $siteId, int $limit = 20, ?int $excludeId = null): array
    {
        $limit = max(1, min(20, $limit));
        $last = DB::table('pages')->where(['site_id' => $siteId, 'page_kind' => 'regular', 'page_type' => 'movie'])
            ->whereIn('status', ['draft', 'live'])->max('id');
        if ($last === null) {
            return [];
        }
        $ids = $this->candidateIds($siteId, $limit, excludeId: $excludeId, afterId: random_int(1, (int) $last));
        if (count($ids) < $limit) {
            $ids = array_values(array_unique([...$ids, ...$this->candidateIds($siteId, $limit, excludeId: $excludeId)]));
        }
        shuffle($ids);

        return array_slice($ids, 0, $limit);
    }
}
