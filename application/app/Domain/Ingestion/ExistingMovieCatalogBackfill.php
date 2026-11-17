<?php

namespace App\Domain\Ingestion;

use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ExistingMovieCatalogBackfill
{
    /** @return list<int> */
    public function sources(int $siteId): array
    {
        if (! app(SiteDeliverySettings::class)->enabled($siteId)
            || ! CatalogAvailability::filter(DB::table('sites'), 'sites')->where(['id' => $siteId, 'status' => 'active', 'content_type' => 'movie'])->exists()) {
            return [];
        }

        return $this->bindings($siteId)->join('source_connectors as source', 'source.id', '=', 'source_site_bindings.connector_id')
            ->where('source.status', 'active')->whereNull('source.deleted_at')->orderBy('source.id')
            ->pluck('source.id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Import drafts from stored revisions. The crawler and this operation both lock source before site. */
    public function linkChunk(int $siteId, int $sourceId, int $afterId = 0, int $limit = 500, bool $dryRun = false): array
    {
        if ($limit < 1 || $limit > 1000 || $afterId < 0) {
            throw new \InvalidArgumentException('Cursor không âm và mỗi lượt tối đa 1.000 phim.');
        }

        return DB::transaction(function () use ($siteId, $sourceId, $afterId, $limit, $dryRun): array {
            $result = ['cursor' => $afterId, 'more' => false, 'scanned' => 0, 'eligible' => 0, 'added' => 0, 'mapped' => 0, 'conflicts' => 0, 'unmapped' => 0];
            $source = CatalogAvailability::filter(DB::table('source_connectors'), 'source_connectors')
                ->where(['id' => $sourceId, 'status' => 'active'])->when(! $dryRun, fn (Builder $query) => $query->lockForUpdate())->first();
            if (! $source) {
                return $result;
            }
            $site = CatalogAvailability::filter(DB::table('sites'), 'sites')
                ->where(['id' => $siteId, 'status' => 'active', 'content_type' => 'movie'])
                ->when(! $dryRun, fn (Builder $query) => $query->lockForUpdate())->first();
            $binding = $site && app(SiteDeliverySettings::class)->enabled($siteId)
                ? $this->bindings($siteId)->where('connector_id', $sourceId)->first()
                : null;
            if (! $binding) {
                return $result;
            }
            try {
                $policy = SourceTypeService::policy($binding);
            } catch (\RuntimeException $failure) {
                if (str_starts_with($failure->getMessage(), 'BINDING_SELECTION_INVALID:')) {
                    throw new Conflict('BINDING_SELECTION_INVALID');
                }
                throw $failure;
            }
            SourceTypeService::assertCurrentPolicy($policy, $source);
            $profile = SitePresentationService::profile($site);
            $items = $this->items($sourceId, $afterId, $limit + 1);
            $result['more'] = $items->count() > $limit;
            $items = $items->take($limit);
            if ($items->isEmpty()) {
                return $result;
            }
            $result['scanned'] = $items->count();
            $result['cursor'] = (int) $items->last()->id;
            $pages = DB::table('pages')->where('site_id', $siteId)->where('page_kind', 'regular')
                ->whereIn('content_item_id', $items->pluck('id'))->orderBy('id')
                ->get(['id', 'content_item_id', 'working_content_revision_id', 'status', 'current_publication_id', 'category_id',
                    'publish_locked', 'assignment_locked', 'input_snapshot_json', 'row_version'])
                ->groupBy('content_item_id');
            $categoryIds = CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $siteId)
                ->whereIn('id', array_filter($policy['category_map']))->pluck('id')->flip();
            $add = [];
            $map = [];
            foreach ($items as $movie) {
                $typeId = SourceTypeService::recordType((array) $movie);
                if (! SourceTypeService::accepts($policy, $typeId)) {
                    continue;
                }
                $result['eligible']++;
                $categoryId = $policy['category_map'][$typeId] ?? null;
                if ($categoryId === null || ! $categoryIds->has($categoryId)) {
                    $categoryId = null;
                    $result['unmapped']++;
                }
                $matches = $pages->get($movie->id);
                if ($matches !== null) {
                    $existing = $matches->first();
                    if ($matches->count() === 1 && $categoryId !== null && $existing->category_id === null
                        && $existing->status === 'draft' && $existing->current_publication_id === null
                        && (int) $existing->working_content_revision_id === (int) $movie->current_revision_id
                        && ! $existing->assignment_locked && ! $existing->publish_locked
                        && ! (Json::decode($existing->input_snapshot_json)['source_update_locked'] ?? false)) {
                        $map[$categoryId][] = $existing->id;
                    }

                    continue;
                }
                $key = SitePresentationService::movieKey($sourceId, $movie->external_id, ['title' => $movie->title], $profile);
                $path = $profile['configured'] ? SitePresentationService::detailPath($profile, $key) : '/movie/'.$key;
                if (isset($add[$path])) {
                    $result['conflicts']++;

                    continue;
                }
                $add[$path] = ['site_id' => $siteId, 'content_item_id' => $movie->id, 'working_content_revision_id' => $movie->current_revision_id,
                    'category_id' => $categoryId, 'page_type' => 'movie', 'canonical_path' => $path, 'status' => 'draft'];
            }
            foreach (['pages' => 'canonical_path', 'page_redirects' => 'from_path', 'fanmulu_url_registry' => 'path'] as $table => $column) {
                if ($add !== []) {
                    $used = DB::table($table)->where('site_id', $siteId)->whereIn($column, array_keys($add))->pluck($column);
                    $add = array_diff_key($add, $used->flip()->all());
                    $result['conflicts'] += $used->count();
                }
            }
            $result['added'] = $dryRun ? count($add) : DB::table('pages')->insertOrIgnore(array_values($add));
            $result['conflicts'] += count($add) - $result['added'];
            foreach ($map as $categoryId => $draftIds) {
                $mapped = DB::table('pages')->whereIn('id', $draftIds)->whereNull('category_id')
                    ->where('status', 'draft')->whereNull('current_publication_id')
                    ->where('publish_locked', false)->where('assignment_locked', false)
                    ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('page_publications')->whereColumn('page_id', 'pages.id'))
                    ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('publish_batch_items')->whereColumn('page_id', 'pages.id')
                        ->where('accepted_for_publish', true)->whereIn('status', ['pending', 'claimed', 'rendering', 'activating']));
                $result['mapped'] += $dryRun ? $mapped->count() : $mapped->update([
                    'category_id' => $categoryId, 'row_version' => DB::raw('row_version+1'), 'updated_at' => now(),
                ]);
            }
            if (! $dryRun && ($result['added'] > 0 || $result['mapped'] > 0)) {
                app(MovieWebsiteSync::class)->invalidate([$siteId]);
            }

            return $result;
        }, 3);
    }

    private function bindings(int $siteId): Builder
    {
        return DB::table('source_site_bindings')->where('source_site_bindings.site_id', $siteId)
            ->where('source_site_bindings.status', 'active')->whereNull('source_site_bindings.deleted_at')
            ->whereRaw("source_site_bindings.auto_publish_policy->>'enabled' = 'true'");
    }

    private function items(int $sourceId, int $afterId, int $limit): Collection
    {
        $items = CatalogAvailability::filter(DB::table('content_items'), 'content_items')
            ->where(['source_connector_id' => $sourceId, 'kind' => 'movie', 'library_status' => 'active'])
            ->whereNotNull('current_revision_id')->where('id', '>', $afterId)->orderBy('id')->limit($limit);

        return DB::query()->fromSub($items, 'movie')->join('content_revisions as revision', function ($join): void {
            $join->on('revision.id', '=', 'movie.current_revision_id')->on('revision.content_item_id', '=', 'movie.id');
        })->leftJoin('catalog_revision_metadata as metadata', function ($join): void {
            $join->on('metadata.id', '=', 'movie.current_revision_id')->on('metadata.content_item_id', '=', 'movie.id');
        })->select('movie.id', 'movie.external_id', 'movie.current_revision_id')
            ->selectRaw("COALESCE(metadata.fields_json->>'title', revision.payload_json->>'title') AS title,
                COALESCE(metadata.fields_json->>'category_external_id', revision.payload_json->>'category_external_id',
                    revision.payload_json#>>'{_raw,type_id}') AS category_external_id")
            ->orderBy('movie.id')->get();
    }
}
