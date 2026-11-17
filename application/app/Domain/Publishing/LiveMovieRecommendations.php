<?php

namespace App\Domain\Publishing;

use App\Domain\Fanmulu\FanmuluNavigation;
use App\Domain\Fanmulu\FanmuluPresentation;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeCatalog;
use Illuminate\Support\Facades\DB;

/** Project bounded on-demand recommendation tabs without changing publication membership. */
final class LiveMovieRecommendations
{
    private const LIMIT = 12;

    private const SAMPLE_LIMIT = 24;

    private const MAX_CANDIDATES = 96;

    public function overlay(array $snapshot): array
    {
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        $pageId = (int) ($snapshot['page']['id'] ?? 0);
        if ($siteId < 1 || $pageId < 1 || ($snapshot['page']['type'] ?? null) !== 'movie'
            || ! MovieThemeCatalog::supports($snapshot['theme'] ?? null)
            || ! app(SiteDeliverySettings::class)->enabled($siteId)) {
            return $snapshot;
        }
        $fanmulu = FanmuluPresentation::enabled($snapshot);
        if ($fanmulu && ! FanmuluNavigation::enabled($snapshot)) {
            return $snapshot;
        }
        $excludedIds = array_values(array_unique(array_filter([$pageId, (int) ($snapshot['fanmulu']['source_page_id'] ?? 0)])));
        $ownPages = DB::table('pages')->where('site_id', $siteId)->whereIn('id', $excludedIds)
            ->get(['id', 'content_item_id', 'category_id', 'canonical_path'])->keyBy('id');
        if (! $ownPages->has($pageId)) {
            return $snapshot;
        }
        $excludedContentIds = $ownPages->pluck('content_item_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $excludedPaths = array_values(array_unique(array_filter([
            $snapshot['page']['path'] ?? null, $snapshot['fanmulu']['source_path'] ?? null,
            ...$ownPages->pluck('canonical_path')->all(),
        ], 'is_string')));
        $exclude = ['page_ids' => $excludedIds, 'content_item_ids' => $excludedContentIds, 'paths' => $excludedPaths];
        $genre = is_string($snapshot['content']['genre'] ?? null) ? trim($snapshot['content']['genre']) : '';
        $categoryId = (int) ($snapshot['category']['id'] ?? $ownPages[$pageId]->category_id ?? 0);
        $rootId = $this->rootCategory($siteId, $snapshot['category'] ?? [], $ownPages[$pageId]->category_id);
        $phases = [];
        if ($genre !== '') {
            $phases[] = ['category' => null, 'filters' => ['genre' => $genre]];
            $genres = $this->genres($genre);
            if ($genres !== [] && $genres !== [$genre]) {
                $phases[] = ['category' => null, 'filters' => ['genres_any' => $genres]];
            }
        }
        foreach (array_unique(array_filter([$categoryId, $rootId])) as $category) {
            $phases[] = ['category' => (int) $category, 'filters' => []];
        }
        // With no genre/category evidence, the only known type is the movie catalog itself.
        if ($phases === []) {
            $phases[] = ['category' => null, 'filters' => []];
        }
        $actors = $this->actors($snapshot['content']['actors'] ?? []);
        $actorPhases = $actors === [] ? [] : [['category' => null, 'filters' => ['actors_any' => $actors]]];
        $index = app(FanmuluRecommendationIndex::class);
        $prepared = $index->load($siteId, [...$phases, ...$actorPhases]);
        $entries = $this->select($snapshot, $phases, $exclude, $fanmulu, $prepared);
        $actorEntries = $this->select($snapshot, $actorPhases, $exclude, $fanmulu, $prepared);
        if ($prepared !== null && ! $index->current($siteId, $prepared['generation'])) {
            $entries = $this->select($snapshot, $phases, $exclude, $fanmulu);
            $actorEntries = $this->select($snapshot, $actorPhases, $exclude, $fanmulu);
        }
        $snapshot['aggregate']['entries'] = $entries;
        $snapshot['_live_recommendation_tabs'] = ['genre' => $entries, 'actors' => $actorEntries];
        $snapshot['_live_recommendations_changed'] = true;
        unset($snapshot['_snapshot_cards'], $snapshot['_snapshot_aggregate_hash']);

        return $snapshot;
    }

    /** Register links for serving GET HTML or its cache; HEAD and pure projection never reserve paths. */
    public function remember(array $snapshot, int $publicationId): void
    {
        if ($publicationId > 0 && ! empty($snapshot['_live_recommendations_changed']) && FanmuluNavigation::enabled($snapshot)) {
            $entries = [];
            foreach ([...($snapshot['aggregate']['entries'] ?? []), ...($snapshot['_live_recommendation_tabs']['actors'] ?? [])] as $entry) {
                $entries[$entry['page_id']] = $entry;
            }
            app(FanmuluNavigation::class)->remember($snapshot, $publicationId, array_values($entries));
        }
    }

    /** Seek bounded windows and remove ineligible/self/duplicate targets before taking the final limit. */
    private function select(array $snapshot, array $phases, array $exclude, bool $fanmulu, ?array $prepared = null): array
    {
        $catalog = app(OnDemandCatalog::class);
        $index = app(FanmuluRecommendationIndex::class);
        $navigation = app(FanmuluNavigation::class);
        $siteId = (int) $snapshot['site']['id'];
        $entries = [];
        $seenContent = array_fill_keys($exclude['content_item_ids'], true);
        $seenPaths = array_fill_keys($exclude['paths'], true);
        foreach ($phases as $phase) {
            [$pageIds, $complete] = $prepared === null ? [[], false] : $index->window($prepared, $phase);
            $before = null;
            $remaining = self::MAX_CANDIDATES;
            while ($remaining > 0) {
                $window = min($remaining, $before === null ? self::LIMIT - count($entries) : self::SAMPLE_LIMIT);
                $remaining -= $window;
                $movies = $catalog->samplePrepared($siteId, $pageIds, $window, $exclude, $before);
                if (! $complete && count($movies) < $window) {
                    $movies = $catalog->sample($siteId, $phase['category'], $window, $phase['filters'], true, $exclude, $before);
                }
                if ($movies === []) {
                    break;
                }
                $before = min(array_map(fn ($movie): int => (int) $movie->id, $movies));
                $batch = [];
                foreach ($movies as $movie) {
                    $id = (int) $movie->id;
                    $path = $movie->canonical_path;
                    $contentId = (int) $movie->content_item_id;
                    $exclude['page_ids'][] = $id;
                    if (isset($seenContent[$contentId]) || isset($seenPaths[$path])) {
                        continue;
                    }
                    $card = Json::decode($movie->snapshot_json);
                    $profile = SitePresentationService::profile($card['routing'] ?? null);
                    $key = $card['page']['route_key'] ?? SitePresentationService::keyFromPath($path, $profile, 'detail');
                    $batch[] = ['page_id' => $id, 'content_item_id' => $contentId,
                        'path' => $path, 'content' => $card['content'] ?? [], 'category' => $card['category'] ?? [],
                        'route_key' => $key, 'play_path' => ! empty($card['content']['episodes'])
                            ? SitePresentationService::playPath($profile, $key, 1) : ''];
                    $seenContent[$contentId] = true;
                    $seenPaths[$path] = true;
                    $exclude['content_item_ids'][] = $contentId;
                    $exclude['paths'][] = $path;
                }
                if ($fanmulu) {
                    $batch = array_values(array_filter($navigation->prepareEntries($snapshot, $batch),
                        fn (array $entry): bool => is_array($entry['fanmulu_link'] ?? null)));
                }
                $entries = array_slice([...$entries, ...$batch], 0, self::LIMIT);
                if (count($entries) >= self::LIMIT) {
                    return $entries;
                }
                if (count($movies) < $window) {
                    break;
                }
            }
        }

        return $entries;
    }

    private function actors(mixed $actors): array
    {
        if ((! is_array($actors) && ! is_string($actors)) || (is_array($actors) && ! array_is_list($actors))) {
            return [];
        }
        $actors = is_array($actors) ? $actors : preg_split('~[/,，、|;；]+~u', $actors);
        $names = [];
        foreach ($actors as $actor) {
            if (! is_string($actor)) {
                continue;
            }
            $actor = trim(\Normalizer::normalize($actor, \Normalizer::FORM_C));
            if ($actor !== '' && mb_strlen($actor) <= 100 && ! preg_match('/[\p{Cc}\p{Cf}]/u', $actor)
                && ! in_array($actor, ['未知', '不详', '暂无', 'N/A'], true)) {
                $names[$actor] = $actor;
            }
        }

        return array_slice(array_values($names), 0, 3);
    }

    private function genres(string $genre): array
    {
        $genres = [];
        foreach (preg_split('~[/,，、|;；]+~u', $genre) as $name) {
            $name = trim(\Normalizer::normalize($name, \Normalizer::FORM_C));
            if ($name !== '' && mb_strlen($name) <= 100 && ! preg_match('/[\p{Cc}\p{Cf}]/u', $name)) {
                $genres[$name] = $name;
            }
        }

        return array_slice(array_values($genres), 0, 6);
    }

    private function rootCategory(int $siteId, array $category, ?int $fallbackId): ?int
    {
        $root = (int) ($category['root_id'] ?? 0);
        if ($root > 0) {
            return $root;
        }
        $id = (int) ($category['id'] ?? $fallbackId ?? 0);
        if ($id < 1) {
            return null;
        }
        $parents = DB::table('categories')->where('site_id', $siteId)->pluck('parent_id', 'id');
        $seen = [];
        while ($parents->has($id) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $parent = (int) $parents[$id];
            if ($parent < 1) {
                return $id;
            }
            $id = $parent;
        }

        return null;
    }
}
