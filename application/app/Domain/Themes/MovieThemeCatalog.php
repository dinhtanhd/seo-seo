<?php

namespace App\Domain\Themes;

use App\Domain\Fanmulu\FanmuluPresentation;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

final class MovieThemeCatalog
{
    private const PROJECTION = "jsonb_build_object('content', CASE WHEN jsonb_typeof(pp.snapshot_json->'_catalog_card') = 'object' THEN pp.snapshot_json->'_catalog_card'->'content' ELSE pp.snapshot_json->'content' END, '_catalog_card', pp.snapshot_json->'_catalog_card', 'category', pp.snapshot_json->'category', 'page', pp.snapshot_json->'page', 'routing', pp.snapshot_json->'routing', 'fanmulu', pp.snapshot_json->'fanmulu') AS snapshot_json";

    public function __construct(private readonly PublicationReadMemo $memo = new PublicationReadMemo,
        private readonly PublicationProjectionCache $sharedReads = new PublicationProjectionCache) {}

    public static function supports(?array $theme): bool
    {
        foreach ($theme['views'] ?? [] as $html) {
            if (is_string($html) && preg_match('/\{\{\s*#(?:each|if)\s+(?:navigation|sections|category_sections|featured_movies)\s*\}\}/', $html)) {
                return true;
            }
        }

        return false;
    }

    public static function references(array $aggregate): array
    {
        if (isset($aggregate['selected_page_ids']) && is_array($aggregate['selected_page_ids'])) {
            return array_map(fn ($id) => ['page_id' => (int) $id], $aggregate['selected_page_ids']);
        }

        return $aggregate['entries'] ?? [];
    }

    public function sourceState(int $siteId, array $entries): array
    {
        if (! $entries) {
            return [];
        }
        $entries = $this->resolveReferences($siteId, $entries);
        $ids = array_values(array_filter(array_column($entries, 'page_id'), fn ($id) => is_int($id) && $id > 0));
        $paths = array_values(array_filter(array_column($entries, 'path'), 'is_string'));

        return DB::table('pages')->where('site_id', $siteId)->where('page_kind', 'regular')->whereIn('page_type', ['movie', 'novel'])->where(function ($query) use ($ids, $paths) {
            $query->whereIn('id', $ids)->orWhereIn('canonical_path', $paths);
        })->orderBy('id')->sharedLock()->get(['id', 'status', 'current_publication_id', 'working_content_revision_id', 'canonical_path', 'row_version'])->all();
    }

    public function publishedEntries(int $siteId, array $entries, bool $includeFanmulu = false): array
    {
        if (! $entries) {
            return [];
        }
        if (count($entries) > 10000) {
            throw new \InvalidArgumentException('Danh sách tổng hợp tối đa 10.000 trang.');
        }
        $entries = $this->resolveReferences($siteId, $entries);
        $ids = array_values(array_filter(array_column($entries, 'page_id'), fn ($id) => is_int($id) && $id > 0));
        $paths = array_values(array_filter(array_column($entries, 'path'), 'is_string'));
        $query = DB::table('pages as p')->join('page_publications as pp', function ($join) {
            $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
        })->where('p.site_id', $siteId)->where('p.status', 'live')->whereIn('p.page_type', ['movie', 'novel'])
            ->when(! $includeFanmulu, fn ($query) => $query->where('p.page_kind', 'regular'))
            ->where(function ($query) use ($ids, $paths) {
                $query->whereIn('p.id', $ids)->orWhereIn('p.canonical_path', $paths);
            });
        $columns = ['p.id', 'p.canonical_path', 'pp.id as publication_id', 'pp.content_revision_id'];
        $pages = $this->memo->active() ? $query->get($columns) : $query->get([...$columns, DB::raw(self::PROJECTION)]);
        if ($this->memo->active()) {
            $reads = $this->immutableReads($siteId, $pages->all(), 'projection');
            foreach ($pages as $page) {
                $page->snapshot_json = $reads[$page->publication_id]['snapshot_json'];
            }
        }
        $byId = $pages->keyBy('id');
        $byPath = $pages->keyBy('canonical_path');
        $published = [];
        foreach ($entries as $entry) {
            $page = isset($entry['page_id']) ? $byId->get($entry['page_id']) : $byPath->get($entry['path'] ?? '');
            if (! $page) {
                continue;
            }
            $snapshot = Json::decode($page->snapshot_json);
            $publishedEntry = ['page_id' => (int) $page->id, 'publication_id' => (int) $page->publication_id,
                'path' => $snapshot['page']['path'] ?? $page->canonical_path, 'content_revision_id' => $page->content_revision_id,
                'content' => $snapshot['content'] ?? [], 'category' => $snapshot['category'] ?? []];
            $routeKey = $snapshot['page']['route_key'] ?? SitePresentationService::keyFromPath($snapshot['page']['path'] ?? $page->canonical_path, SitePresentationService::profile($snapshot['routing'] ?? null), 'detail');
            if ($routeKey !== '') {
                $publishedEntry['route_key'] = $routeKey;
            }
            if (! empty($snapshot['content']['episodes']) && $routeKey !== '') {
                $publishedEntry['play_path'] = SitePresentationService::playPath(SitePresentationService::profile($snapshot['routing'] ?? null), $routeKey, 1);
            }
            if (FanmuluPresentation::enabled($snapshot)) {
                $publishedEntry['fanmulu'] = $snapshot['fanmulu'];
            }
            if (is_array($snapshot['_catalog_card']['_card'] ?? null)) {
                $publishedEntry['_card'] = $snapshot['_catalog_card']['_card'];
                $publishedEntry['_catalog_card_frozen'] = true;
                $publishedEntry['play_path'] = $snapshot['_catalog_card']['play_path'];
            }
            $published[] = $publishedEntry;
        }

        return $published;
    }

    /** Keep legacy curated paths bound to the same site/page after a deliberate URL move. */
    private function resolveReferences(int $siteId, array $entries): array
    {
        $paths = array_values(array_filter(array_map(fn ($entry) => ! isset($entry['page_id']) && is_string($entry['path'] ?? null) ? $entry['path'] : null, $entries)));
        if ($paths === []) {
            return $entries;
        }
        $aliases = DB::table('page_redirects')->where('site_id', $siteId)->whereIn('from_path', $paths)->pluck('to_path', 'from_path');
        $targets = $aliases->isEmpty() ? collect() : DB::table('pages')->where('site_id', $siteId)->whereIn('canonical_path', $aliases->values())->pluck('id', 'canonical_path');
        foreach ($entries as &$entry) {
            if (! isset($entry['page_id']) && isset($aliases[$entry['path'] ?? ''], $targets[$aliases[$entry['path']]])) {
                $entry['page_id'] = (int) $targets[$aliases[$entry['path']]];
            }
        }
        unset($entry);

        return $entries;
    }

    public function runtimeSourceState(int $siteId, int $excludePageId): array
    {
        $limit = app(SiteDeliverySettings::class)->enabled($siteId)
            ? max(20, min(1000, (int) config('public_on_demand.recommendation_limit', 120))) : 1000;

        return DB::table('pages')->where('site_id', $siteId)->where('id', '<>', $excludePageId)
            ->where('status', 'live')->where('page_type', 'movie')->where('page_kind', 'regular')->whereNotNull('current_publication_id')
            ->orderByDesc('id')->limit($limit)->sharedLock()->get(['id', 'status', 'current_publication_id', 'canonical_path'])->all();
    }

    public function runtimeEntries(int $siteId, int $excludePageId): array
    {
        return $this->publishedEntries($siteId, array_map(fn ($page) => ['page_id' => (int) $page->id], $this->runtimeSourceState($siteId, $excludePageId)));
    }

    public function recommendationState(int $siteId, array $entries): array
    {
        $ids = array_values(array_filter(array_column($entries, 'page_id'), fn ($id) => is_int($id) && $id > 0));
        if ($ids === []) {
            return [];
        }

        $query = DB::table('pages as p')->join('page_publications as pp', function ($join) {
            $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
        })->where('p.site_id', $siteId)->where('p.page_kind', 'regular')->whereIn('p.id', $ids)->orderBy('p.id')->sharedLock();
        if (! $this->memo->active()) {
            return $query->get(['p.id', 'p.status', DB::raw("pp.snapshot_json->'page'->>'path' AS path"),
                DB::raw("md5((pp.snapshot_json->'content')::text) AS content_fingerprint"),
                DB::raw("md5((pp.snapshot_json->'category')::text) AS category_fingerprint")])->all();
        }
        $pages = $query->get(['p.id', 'p.status', 'pp.id as publication_id']);
        $reads = $this->immutableReads($siteId, $pages->all(), 'fingerprint');

        return $pages->map(fn ($page) => (object) ['id' => $page->id, 'status' => $page->status,
            'path' => $reads[$page->publication_id]['path'], 'content_fingerprint' => $reads[$page->publication_id]['content_fingerprint'],
            'category_fingerprint' => $reads[$page->publication_id]['category_fingerprint']])->all();
    }

    /** Head/status/path membership is read freshly by callers; only publication bytes are memoized. */
    private function immutableReads(int $siteId, array $pages, string $kind): array
    {
        $connection = DB::connection();
        $database = Json::encode([$connection->getName(), $connection->getConfig('driver'), $connection->getConfig('host'),
            $connection->getConfig('port'), $connection->getConfig('database')]);
        $reads = [];
        $missing = [];
        foreach ($pages as $page) {
            $key = Json::encode([$database, $siteId, (int) $page->id, (int) $page->publication_id, $kind]);
            $cached = $this->memo->get($key);
            if ($cached !== null) {
                $reads[$page->publication_id] = $cached;
            } else {
                $missing[$page->publication_id] = ['page_id' => (int) $page->id, 'key' => $key];
            }
        }
        if ($missing !== []) {
            if ($this->sharedReads->enabled()) {
                $cached = $this->sharedReads->rememberMany($siteId, $missing, function (array $wanted) use ($siteId): array {
                    $rows = DB::table('page_publications as pp')->where('pp.site_id', $siteId)->whereIn('pp.id', array_keys($wanted))
                        ->get(['pp.id', 'pp.page_id', DB::raw(self::PROJECTION), DB::raw("pp.snapshot_json->'page'->>'path' AS path"),
                            DB::raw("md5((pp.snapshot_json->'content')::text) AS content_fingerprint"),
                            DB::raw("md5((pp.snapshot_json->'category')::text) AS category_fingerprint")]);
                    $result = [];
                    foreach ($rows as $row) {
                        if ($wanted[$row->id]['page_id'] !== (int) $row->page_id) {
                            throw new \LogicException('Publication projection ownership mismatch.');
                        }
                        $result[$row->id] = ['snapshot_json' => $row->snapshot_json, 'path' => $row->path,
                            'content_fingerprint' => $row->content_fingerprint, 'category_fingerprint' => $row->category_fingerprint];
                    }

                    return $result;
                });
                foreach ($cached as $id => $data) {
                    $entry = $kind === 'projection' ? ['snapshot_json' => $data['snapshot_json']] : array_intersect_key($data, array_flip(['path', 'content_fingerprint', 'category_fingerprint']));
                    $reads[$id] = $entry;
                    $this->memo->put($missing[$id]['key'], $entry);
                }

                return $reads;
            }
            $columns = $kind === 'projection' ? [DB::raw(self::PROJECTION)] : [DB::raw("pp.snapshot_json->'page'->>'path' AS path"),
                DB::raw("md5((pp.snapshot_json->'content')::text) AS content_fingerprint"),
                DB::raw("md5((pp.snapshot_json->'category')::text) AS category_fingerprint")];
            $rows = DB::table('page_publications as pp')->where('pp.site_id', $siteId)->whereIn('pp.id', array_keys($missing))
                ->get(['pp.id', 'pp.page_id', ...$columns]);
            foreach ($rows as $row) {
                if ($missing[$row->id]['page_id'] !== (int) $row->page_id) {
                    throw new \LogicException('Publication memo ownership mismatch.');
                }
                $entry = $kind === 'projection' ? ['snapshot_json' => $row->snapshot_json] : ['path' => $row->path,
                    'content_fingerprint' => $row->content_fingerprint, 'category_fingerprint' => $row->category_fingerprint];
                $reads[$row->id] = $entry;
                $this->memo->put($missing[$row->id]['key'], $entry);
            }
        }

        return $reads;
    }

    public function freeze(int $siteId, array $entries, array $routing, int $entryLimit = 60, array $categoryKeys = []): array
    {
        $categoryKeys = $categoryKeys ?: SitePresentationService::categoryRouteKeys($siteId, $routing);
        $rows = CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $siteId)->orderByRaw('parent_id IS NOT NULL')->orderBy('id')->limit(100)->get()->keyBy('id');
        $categories = [];
        foreach ($rows as $row) {
            $ancestors = [];
            $current = $row;
            while ($current) {
                if (in_array((int) $current->id, $ancestors, true)) {
                    throw new \InvalidArgumentException('Category cycle');
                }
                $ancestors[] = (int) $current->id;
                $current = $current->parent_id ? $rows->get($current->parent_id) : null;
            }
            $categories[] = ['id' => (int) $row->id, 'name' => $row->name, 'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'path' => SitePresentationService::categoryPath($row, $routing, $categoryKeys), 'ancestor_ids' => $ancestors, 'root_id' => end($ancestors)];
        }
        $entries = array_slice($entries, 0, min(10000, max(1, $entryLimit)));
        $ids = array_values(array_filter(array_column($entries, 'page_id'), fn ($id) => is_int($id) && $id > 0));
        $paths = array_values(array_filter(array_column($entries, 'path'), 'is_string'));
        $pages = $entries ? DB::table('pages')->where('site_id', $siteId)->where(function ($query) use ($ids, $paths) {
            $query->whereIn('id', $ids)->orWhereIn('canonical_path', $paths);
        })->get(['id', 'canonical_path', 'category_id']) : collect();
        $byId = $pages->keyBy('id');
        $byPath = $pages->keyBy('canonical_path');
        $byCategory = array_column($categories, null, 'id');
        $entryCategories = [];
        foreach ($entries as $entry) {
            $page = isset($entry['page_id']) ? $byId->get($entry['page_id']) : $byPath->get($entry['path'] ?? '');
            $category = array_key_exists('category', $entry)
                ? array_replace($byCategory[$entry['category']['id'] ?? 0] ?? [], $entry['category'])
                : ($byCategory[$page?->category_id ?? 0] ?? []);
            $entryCategories[] = ['path' => $entry['path'] ?? '/', 'category_id' => $category['id'] ?? null,
                'ancestor_ids' => $category['ancestor_ids'] ?? [], 'root_id' => $category['root_id'] ?? null];
        }

        return ['schema_version' => 1, 'categories' => $categories, 'entry_categories' => $entryCategories];
    }

    public static function category(array $catalog, int $id): ?array
    {
        foreach ($catalog['categories'] ?? [] as $category) {
            if (($category['id'] ?? null) === $id) {
                return $category;
            }
        }

        return null;
    }
}
