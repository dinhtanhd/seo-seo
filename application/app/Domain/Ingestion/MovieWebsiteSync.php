<?php

namespace App\Domain\Ingestion;

use App\Domain\Publishing\PublicCatalogCache;
use App\Domain\Publishing\PublicHtmlCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Change only mutable serving pointers; history, URL identity and keyword pins stay immutable. */
final class MovieWebsiteSync
{
    public function available(): bool
    {
        return Schema::hasColumn('pages', 'serving_content_revision_id') && Schema::hasColumn('sites', 'public_content_epoch');
    }

    /** Caller first locks the source. Acquire every affected site in order before item/page locks. */
    public function lockSourceSites(int $sourceId, array $extraSiteIds = [], ?array $itemIds = null): array
    {
        $this->transaction();
        $sites = DB::table('source_site_bindings')->where('connector_id', $sourceId)->pluck('site_id')->all();
        $usage = DB::table('pages as p')->join('content_items as c', 'c.id', '=', 'p.content_item_id')
            ->where('c.source_connector_id', $sourceId)->when($itemIds !== null, fn ($query) => $query->whereIn('c.id', $itemIds))
            ->distinct()->pluck('p.site_id')->all();
        $ids = array_values(array_unique(array_map('intval', [...$sites, ...$usage, ...$extraSiteIds])));
        sort($ids);
        $query = DB::table('sites')->whereIn('id', $ids)->orderBy('id');
        ($this->available() ? $query->lockForUpdate() : $query->sharedLock())->get(['id']);

        return $ids;
    }

    /** Return changed site IDs; callers invalidate once after all records in the chunk have committed their pointers. */
    public function sync(int $itemId, int $revisionId): array
    {
        $this->transaction();
        if (! $this->available()) {
            return [];
        }
        $item = DB::table('content_items')->where('kind', 'movie')->find($itemId);
        if (! $item || $item->library_status !== 'active') {
            return [];
        }
        if (! DB::table('content_revisions')->where(['id' => $revisionId, 'content_item_id' => $itemId])->exists()) {
            throw new RuntimeException('MOVIE_SERVING_REVISION_OWNER_INVALID');
        }
        $pages = DB::table('pages')->where('content_item_id', $itemId)->where('page_type', 'movie')->whereIn('status', ['draft', 'live'])
            ->whereIn('site_id', DB::table('sites')->where('status', 'active')->where('content_type', 'movie')->select('id'))
            ->whereNotExists(function ($removed) use ($item): void {
                $removed->selectRaw('1')->from('source_site_bindings as removed_source')
                    ->whereColumn('removed_source.site_id', 'pages.site_id')->where('removed_source.connector_id', $item->source_connector_id)
                    ->whereNotNull('removed_source.deleted_at');
            })
            ->orderBy('id')->lockForUpdate()
            ->get(['id', 'site_id', 'page_kind', 'source_page_id', 'input_snapshot_json', 'working_content_revision_id', 'serving_content_revision_id']);
        $sourceIds = $pages->where('page_kind', 'fanmulu')->pluck('source_page_id')->filter()->all();
        $sourceLocks = $sourceIds === [] ? [] : DB::table('pages')->whereIn('id', $sourceIds)->pluck('input_snapshot_json', 'id')->all();
        $dirty = [];
        $regular = [];
        $fanmulu = [];
        foreach ($pages as $page) {
            if ($this->locked($page, $sourceLocks)) {
                continue;
            }
            $changes = [];
            if ((int) $page->serving_content_revision_id !== $revisionId) {
                $changes['serving_content_revision_id'] = $revisionId;
            }
            if ($page->page_kind === 'regular' && (int) $page->working_content_revision_id !== $revisionId) {
                $changes['working_content_revision_id'] = $revisionId;
            }
            if ($changes !== []) {
                if ($page->page_kind === 'regular') {
                    $regular[] = (int) $page->id;
                } else {
                    $fanmulu[] = (int) $page->id;
                }
                $dirty[] = (int) $page->site_id;
            }
        }
        foreach ([$regular, $fanmulu] as $kind => $ids) {
            $values = ['serving_content_revision_id' => $revisionId, 'row_version' => DB::raw('row_version+1'), 'updated_at' => now()];
            if ($kind === 0) {
                $values['working_content_revision_id'] = $revisionId;
            }
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('pages')->whereIn('id', $chunk)->update($values);
            }
        }

        return array_values(array_unique($dirty));
    }

    /** The caller still holds each site's exclusive write lock through the filesystem detach. */
    public function invalidate(array $siteIds): void
    {
        $this->transaction();
        $ids = array_values(array_unique(array_map('intval', $siteIds)));
        sort($ids);
        $available = $this->available();
        if ($available) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('sites')->whereIn('id', $chunk)->increment('public_content_epoch');
            }
        }
        foreach ($ids as $siteId) {
            if ($available) {
                app(PublicHtmlCache::class)->invalidateSite($siteId);
            } else {
                app(PublicCatalogCache::class)->invalidate($siteId);
            }
        }
    }

    /** One bounded transaction; rerunning starts safely from remaining null pointers. */
    public function backfillSiteChunk(int $siteId, int $afterId = 0, int $limit = 500): array
    {
        if (! $this->available()) {
            throw new RuntimeException('MOVIE_SERVING_SCHEMA_UNAVAILABLE');
        }
        $limit = min(1000, max(1, $limit));

        return DB::transaction(function () use ($siteId, $afterId, $limit): array {
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            if (! $site || $site->status !== 'active' || $site->content_type !== 'movie') {
                return ['cursor' => $afterId, 'scanned' => 0, 'changed' => 0, 'more' => false];
            }
            $query = DB::table('pages as p')->join('content_items as c', 'c.id', '=', 'p.content_item_id')
                ->join('page_publications as publication', function ($join): void {
                    $join->on('publication.id', '=', 'p.current_publication_id')->on('publication.page_id', '=', 'p.id')->on('publication.site_id', '=', 'p.site_id');
                })
                ->where('p.site_id', $siteId)->where('p.page_type', 'movie')->where('p.status', 'live')->whereNotNull('p.current_publication_id')
                ->whereNull('p.serving_content_revision_id')->where('c.kind', 'movie')->where('c.library_status', 'active')
                ->whereNotNull('c.current_revision_id')
                ->whereNotExists(function ($removed): void {
                    $removed->selectRaw('1')->from('source_site_bindings as removed_source')
                        ->whereColumn('removed_source.site_id', 'p.site_id')->whereColumn('removed_source.connector_id', 'c.source_connector_id')
                        ->whereNotNull('removed_source.deleted_at');
                });
            $rows = (clone $query)->where('p.id', '>', $afterId)->orderBy('p.id')->limit($limit)->lock('FOR UPDATE OF p')
                ->select('p.id', 'p.page_kind', 'p.source_page_id', 'p.input_snapshot_json')
                ->selectRaw('CASE WHEN publication.restored_from_publication_id IS NOT NULL THEN publication.content_revision_id ELSE c.current_revision_id END AS serving_revision_id')->get();
            $sourceIds = $rows->where('page_kind', 'fanmulu')->pluck('source_page_id')->filter()->all();
            $sourceLocks = $sourceIds === [] ? [] : DB::table('pages')->whereIn('id', $sourceIds)->pluck('input_snapshot_json', 'id')->all();
            $changed = 0;
            foreach ($rows as $page) {
                if ($this->locked($page, $sourceLocks)) {
                    continue;
                }
                DB::table('pages')->where('id', $page->id)->update(['serving_content_revision_id' => $page->serving_revision_id,
                    'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
                $changed++;
            }
            if ($changed > 0) {
                $this->invalidate([$siteId]);
            }
            $cursor = $rows->isEmpty() ? $afterId : (int) $rows->last()->id;

            return ['cursor' => $cursor, 'scanned' => $rows->count(), 'changed' => $changed,
                'more' => (clone $query)->where('p.id', '>', $cursor)->exists()];
        }, 3);
    }

    private function locked(object $page, array $sourceLocks): bool
    {
        if ($page->page_kind === 'fanmulu') {
            // Fanmulu has an intrinsic source_update_locked marker because its
            // historical source publication and working revision remain pinned.
            if (! $page->source_page_id || ! array_key_exists($page->source_page_id, $sourceLocks)) {
                return true;
            }
            $data = json_decode($sourceLocks[$page->source_page_id], true) ?? [];
        } else {
            $data = json_decode($page->input_snapshot_json, true) ?? [];
        }

        return (bool) ($data['source_update_locked'] ?? false);
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Movie website synchronization requires the source transaction.');
        }
    }
}
