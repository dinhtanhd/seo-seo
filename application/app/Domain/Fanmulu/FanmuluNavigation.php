<?php

namespace App\Domain\Fanmulu;

use App\Domain\Keywords\AssignmentPurpose;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Frozen labels propose links; only a target's first visit allocates a page and quota. */
final class FanmuluNavigation
{
    private const MAX_KEYWORDS = 512;

    private const MAX_SOURCES = 120;

    public function freeze(array $snapshot): array
    {
        if (! FanmuluPresentation::enabled($snapshot) || self::enabled($snapshot)) {
            return $snapshot;
        }
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        $pageId = (int) ($snapshot['page']['id'] ?? 0);
        $root = $snapshot['page']['path'] ?? '';
        if ($siteId < 1 || $pageId < 1 || ! is_string($root) || ! self::safePath($root)) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }
        $settings = FanmuluSettings::fromSite($snapshot['routing'] ?? []);
        // Existing canonical roots remain valid when an administrator removes a prefix.
        $rootPrefix = FanmuluSettings::matchedPrefix($settings, $root) ?? substr($root, 0, strrpos($root, '/'));
        if (! self::validPrefix($rootPrefix)) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }
        $entries = array_slice($snapshot['aggregate']['entries'] ?? [], 0, self::MAX_SOURCES);
        $sources = $this->sourceMetadata($siteId, array_column($entries, 'page_id'), self::publicationPins($entries), excludeUnlinked: true);
        foreach ($entries as $entry) {
            $id = (int) ($entry['page_id'] ?? 0);
            if (! isset($sources[$id])) {
                continue;
            }
            $data = $sources[$id];
            if (is_array($entry['content'] ?? null)) {
                $data['content'] = array_replace($data['content'], $entry['content']);
            }
            if (array_key_exists('category', $entry)) {
                $data['category_id'] = $entry['category']['id'] ?? null;
            }
            if (is_string($entry['path'] ?? null)) {
                $data['path'] = $entry['path'];
            }
            $sources[$id] = self::metadata($data);
        }
        $ownSourceId = (int) ($snapshot['fanmulu']['source_page_id'] ?? 0);
        if ($ownSourceId > 0) {
            $own = $this->sourceMetadata($siteId, [$ownSourceId], [$ownSourceId => (int) ($snapshot['fanmulu']['source_publication_id'] ?? 0)])[$ownSourceId] ?? [];
            $sources[$ownSourceId] = self::metadata(['content' => $snapshot['content'] ?? [],
                'category_id' => $snapshot['page']['category_id'] ?? $snapshot['category']['id'] ?? null,
                'content_item_id' => $own['content_item_id'] ?? null, 'path' => $own['path'] ?? $snapshot['fanmulu']['source_path'] ?? '']);
        }
        $token = bin2hex(random_bytes(12));
        $snapshot['fanmulu']['presentation_version'] = max(2, (int) ($snapshot['fanmulu']['presentation_version'] ?? 2));
        $snapshot['fanmulu']['navigation'] = ['schema_version' => 2, 'root_path' => $root,
            'prefix' => $rootPrefix, 'suffix' => $settings['suffix'] ?? '', 'token' => $token,
            'keywords' => $this->keywords($snapshot, $token), 'sources' => $sources];
        if (in_array($settings['url_rule']['mode'], ['random', 'pattern', 'tokens'], true)) {
            $snapshot['fanmulu']['navigation']['url_rule'] = FanmuluUrlRegistry::freezeRule($settings['url_rule']);
        }

        return $snapshot;
    }

    public static function enabled(array $snapshot): bool
    {
        $navigation = $snapshot['fanmulu']['navigation'] ?? null;

        return FanmuluPresentation::enabled($snapshot) && is_array($navigation)
            && ($navigation['schema_version'] ?? null) === 2
            && is_string($navigation['root_path'] ?? null) && self::safePath($navigation['root_path'])
            && is_string($navigation['token'] ?? null) && preg_match('/^[a-f0-9]{24}$/D', $navigation['token']) === 1
            && self::validPrefix($navigation['prefix'] ?? null)
            && is_string($navigation['suffix'] ?? null) && preg_match('/^(?:\.[a-z][a-z0-9]{0,11})?$/D', $navigation['suffix']) === 1
            && is_array($navigation['keywords'] ?? null) && array_is_list($navigation['keywords']) && count($navigation['keywords']) <= self::MAX_KEYWORDS
            && is_array($navigation['sources'] ?? null);
    }

    public static function rootPath(array $snapshot): string
    {
        return self::enabled($snapshot) ? $snapshot['fanmulu']['navigation']['root_path'] : ($snapshot['page']['path'] ?? '/');
    }

    public static function scopePath(array $snapshot, string $view, string|int|null $key = null): string
    {
        if (! self::enabled($snapshot) || ! in_array($view, ['home', 'list', 'search', 'play'], true)
            || ($key !== null && preg_match('/^[A-Za-z0-9_-]{1,120}$/D', (string) $key) !== 1)) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }

        return self::rootPath($snapshot).'/_scope/'.$view.($key === null ? '' : '/'.rawurlencode((string) $key))
            .$snapshot['fanmulu']['navigation']['suffix'];
    }

    /** @return array{path: string, text: string, keyword_id: int, source_page_id: int}|null */
    public static function link(array $snapshot, int $sourceId, array $sourceMeta = []): ?array
    {
        return self::linkFromRoster($snapshot, $sourceId, $sourceMeta, null);
    }

    private static function linkFromRoster(array $snapshot, int $sourceId, array $sourceMeta, ?array $movieRoster): ?array
    {
        if (! self::enabled($snapshot) || $sourceId < 1) {
            return null;
        }
        $own = AssignmentPurpose::display($snapshot['assignments'] ?? []);
        if ($sourceId === (int) ($snapshot['fanmulu']['source_page_id'] ?? 0)) {
            if (! is_string($own['text'] ?? null) || ! is_int($own['keyword_id'] ?? null)) {
                return null;
            }

            return ['path' => self::rootPath($snapshot), 'text' => $own['text'], 'keyword_id' => $own['keyword_id'], 'source_page_id' => $sourceId];
        }
        $navigation = $snapshot['fanmulu']['navigation'];
        $source = $navigation['sources'][$sourceId] ?? ($sourceMeta === [] ? null : self::metadata($sourceMeta));
        if ($source === null) {
            return null;
        }
        $eligible = ($source['page_type'] ?? null) === 'movie' && $movieRoster !== null
            ? $movieRoster : self::eligibleRoster($navigation['keywords'], $source, $own);
        if ($eligible === []) {
            return null;
        }
        $indices = array_keys($eligible);
        $index = $indices[hexdec(substr(hash('sha256', $navigation['token'].':'.$sourceId), 0, 8)) % count($indices)];
        $keyword = $eligible[$index];

        $path = self::opaque($snapshot)
            ? FanmuluUrlRegistry::candidate(self::identity($snapshot, $sourceId, $keyword['keyword_id']), $navigation['url_rule'], $navigation['prefix'], $navigation['suffix'])
            : $navigation['prefix'].'/'.$sourceId.'-fm'.(int) $snapshot['page']['id'].'-'.$navigation['token'].'-'.$index.$navigation['suffix'];

        return ['path' => $path,
            'text' => $keyword['text'], 'keyword_id' => $keyword['keyword_id'], 'source_page_id' => $sourceId];
    }

    /** An unrestricted name roster can be reused across all movie cards in a batch. */
    private static function movieRoster(array $snapshot): ?array
    {
        return self::unrestrictedMovieRoster($snapshot['fanmulu']['navigation']['keywords'], AssignmentPurpose::display($snapshot['assignments'] ?? []));
    }

    private static function unrestrictedMovieRoster(array $keywords, ?array $own): ?array
    {
        foreach ($keywords as $keyword) {
            $bindings = $keyword['bindings'] ?? null;
            if (($keyword['eligibility'] ?? null) !== [] || ! is_array($bindings)) {
                return null;
            }
            foreach ($bindings as $binding) {
                if (is_array($binding) && (($binding['category_id'] ?? null) !== null
                    || ($binding['content_item_id'] ?? null) !== null || ! empty($binding['filters']))) {
                    return null;
                }
            }
        }

        return self::eligibleRoster($keywords, ['page_type' => 'movie'], $own);
    }

    private static function eligibleRoster(array $keywords, array $source, ?array $own): array
    {
        $byText = [];
        foreach ($keywords as $index => $keyword) {
            if (self::eligible($keyword, $source)) {
                $existing = $byText[$keyword['text']] ?? null;
                if ($existing === null || ($keyword['pool_order'] ?? PHP_INT_MAX) < ($existing['keyword']['pool_order'] ?? PHP_INT_MAX)) {
                    $byText[$keyword['text']] = ['index' => $index, 'keyword' => $keyword];
                }
            }
        }
        $eligible = [];
        foreach ($byText as $choice) {
            $eligible[$choice['index']] = $choice['keyword'];
        }
        ksort($eligible, SORT_NUMERIC);
        if (count($eligible) > 1 && $own) {
            $eligible = array_filter($eligible, fn ($keyword): bool => $keyword['keyword_id'] !== $own['keyword_id'] && $keyword['text'] !== $own['text']);
        }

        return $eligible;
    }

    public static function opaque(array $snapshot): bool
    {
        return self::enabled($snapshot) && in_array($snapshot['fanmulu']['navigation']['url_rule']['mode'] ?? null, ['random', 'pattern', 'tokens'], true);
    }

    private static function identity(array $snapshot, int $sourceId, int $keywordId): string
    {
        return Json::canonicalEncode(['navigation', $snapshot['site']['id'], $snapshot['page']['id'],
            $snapshot['fanmulu']['navigation']['token'], $sourceId, $keywordId]);
    }

    /** Only real activation/GET responses register paths; preview and pure projection never do. */
    public function remember(array $snapshot, int $publicationId, ?array $entries = null): void
    {
        if (! self::opaque($snapshot)) {
            return;
        }
        $nav = $snapshot['fanmulu']['navigation'];
        $sources = $nav['sources'];
        if ($entries !== null) {
            $missing = array_values(array_filter(array_column($entries, 'page_id'), fn ($id): bool => is_int($id) && $id > 0 && ! isset($sources[$id])));
            $sources = $sources + $this->sourceMetadata((int) $snapshot['site']['id'], $missing, self::publicationPins($entries));
            $ids = array_column($entries, 'page_id');
        } else {
            $ids = array_keys($sources);
        }
        $requests = [];
        $movieRoster = self::movieRoster($snapshot);
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id === (int) $snapshot['fanmulu']['source_page_id'] || ! isset($sources[$id])) {
                continue;
            }
            $link = self::linkFromRoster($snapshot, $id, $sources[$id], $movieRoster);
            if ($link === null) {
                continue;
            }
            $requests[] = ['identity' => self::identity($snapshot, $id, $link['keyword_id']), 'path' => $link['path'],
                'rule' => $nav['url_rule'], 'prefix' => $nav['prefix'], 'suffix' => $nav['suffix'],
                'provenance' => ['origin' => 'nav', 'source_page_id' => $id,
                    'parent_page_id' => (int) $snapshot['page']['id'], 'parent_publication_id' => $publicationId,
                    'navigation_token' => $nav['token'], 'keyword_id' => $link['keyword_id'], 'keyword_text' => $link['text'],
                    'metadata' => $sources[$id]]];
        }
        foreach (array_chunk($requests, 120) as $chunk) {
            app(FanmuluUrlRegistry::class)->remember((int) $snapshot['site']['id'], $chunk);
        }
    }

    /** Dynamic galleries fetch missing eligibility metadata once, never once per card. */
    public function prepareEntries(array $snapshot, array $entries): array
    {
        if (! self::enabled($snapshot)) {
            return $entries;
        }
        $frozen = $snapshot['fanmulu']['navigation']['sources'];
        $missing = array_values(array_filter(array_column($entries, 'page_id'), fn ($id): bool => is_int($id) && $id > 0 && ! isset($frozen[$id])));
        $metadata = $this->sourceMetadata((int) $snapshot['site']['id'], $missing, self::publicationPins($entries));
        $movieRoster = self::movieRoster($snapshot);
        foreach ($entries as &$entry) {
            $id = (int) ($entry['page_id'] ?? 0);
            $link = self::linkFromRoster($snapshot, $id, $metadata[$id] ?? [], $movieRoster);
            if ($link !== null) {
                $entry['fanmulu_link'] = $link;
            } else {
                unset($entry['fanmulu_link']);
            }
        }
        unset($entry);

        return $entries;
    }

    public static function looksLikeIntentPath(string $path): bool
    {
        return preg_match('~/[0-9]+-fm~', $path) === 1;
    }

    /** Resolve a label only from an actual publication in this website's immutable history. */
    public function intent(int $siteId, string $path): ?array
    {
        if ($registered = app(FanmuluUrlRegistry::class)->find($siteId, $path)) {
            if ($registered['origin'] !== 'nav') {
                $intent = ['source_page_id' => $registered['source_page_id'], 'origin' => $registered['origin']];
                if ($registered['selected_primary'] !== null) {
                    $intent['selected_primary'] = $registered['selected_primary'];
                    $intent['discovery_provenance'] = ['path' => $registered['path'], 'identity_hash' => $registered['identity_hash'],
                        'metadata' => $registered['metadata']];
                }

                return $intent;
            }

            return $this->registeredIntent($siteId, $path, $registered);
        }
        if (! self::looksLikeIntentPath($path)) {
            return null;
        }
        if (strlen($path) > 2000 || ! preg_match('~^(?<prefix>/[A-Za-z0-9][A-Za-z0-9_/-]*)/(?<source>[1-9][0-9]{0,18})-fm(?<parent>[1-9][0-9]{0,18})-(?<token>[a-f0-9]{24})-(?<index>0|[1-9][0-9]{0,2})(?<suffix>\.[a-z][a-z0-9]{0,11})?$~D', $path, $parts)) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }
        $sourceId = filter_var($parts['source'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $parentId = filter_var($parts['parent'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($sourceId === false || $parentId === false || (int) $parts['index'] >= self::MAX_KEYWORDS) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }
        $publication = DB::table('page_publications as pp')->join('pages as p', function ($join): void {
            $join->on('p.id', '=', 'pp.page_id')->on('p.site_id', '=', 'pp.site_id');
        })->where('pp.site_id', $siteId)->where('pp.page_id', $parentId)
            ->where(['p.page_kind' => 'fanmulu', 'p.page_type' => 'movie', 'p.status' => 'live'])->whereNotNull('p.current_publication_id')
            ->where('pp.snapshot_json->fanmulu->navigation->token', $parts['token'])->orderBy('pp.id')
            ->first(['pp.id', 'pp.snapshot_json']);
        if (! $publication) {
            throw new Conflict('FANMULU_NAVIGATION_UNAVAILABLE');
        }
        $snapshot = Json::decode($publication->snapshot_json);
        if (! self::enabled($snapshot) || (int) ($snapshot['site']['id'] ?? 0) !== $siteId || (int) ($snapshot['page']['id'] ?? 0) !== $parentId) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }
        $metadata = $snapshot['fanmulu']['navigation']['sources'][$sourceId] ?? ($this->sourceMetadata($siteId, [$sourceId])[$sourceId] ?? []);
        $link = self::link($snapshot, $sourceId, $metadata);
        if ($link === null || $link['path'] !== $path) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }

        return ['source_page_id' => $sourceId, 'selected_primary' => ['keyword_id' => $link['keyword_id'], 'text' => $link['text']],
            'parent_page_id' => $parentId, 'parent_publication_id' => (int) $publication->id, 'navigation_token' => $parts['token']];
    }

    private function registeredIntent(int $siteId, string $path, array $registered): array
    {
        $parentId = $registered['parent_page_id'];
        $publication = DB::table('page_publications as pp')->join('pages as p', function ($join): void {
            $join->on('p.id', '=', 'pp.page_id')->on('p.site_id', '=', 'pp.site_id');
        })->where(['pp.id' => $registered['parent_publication_id'], 'pp.site_id' => $siteId, 'pp.page_id' => $parentId,
            'p.page_kind' => 'fanmulu', 'p.status' => 'live'])->whereNotNull('p.current_publication_id')->first(['pp.id', 'pp.snapshot_json']);
        $snapshot = Json::decode($publication?->snapshot_json);
        if (! $publication || ! self::opaque($snapshot) || (int) ($snapshot['site']['id'] ?? 0) !== $siteId
            || (int) ($snapshot['page']['id'] ?? 0) !== $parentId
            || ($snapshot['fanmulu']['navigation']['token'] ?? null) !== $registered['navigation_token']) {
            throw new Conflict('FANMULU_NAVIGATION_UNAVAILABLE');
        }
        $link = self::link($snapshot, $registered['source_page_id'], $registered['metadata'] ?? []);
        if ($link === null || $link['path'] !== $path || $link['keyword_id'] !== $registered['keyword_id'] || $link['text'] !== $registered['keyword_text']) {
            throw new Conflict('FANMULU_NAVIGATION_INVALID');
        }

        return ['source_page_id' => $link['source_page_id'], 'selected_primary' => ['keyword_id' => $link['keyword_id'], 'text' => $link['text']],
            'parent_page_id' => $parentId, 'parent_publication_id' => (int) $publication->id, 'navigation_token' => $registered['navigation_token']];
    }

    private static function safePath(string $path): bool
    {
        return strlen($path) <= 1800 && str_starts_with($path, '/') && ! str_starts_with($path, '//')
            && ! preg_match('/[\x00-\x20\x7f\\\\?#]/', $path);
    }

    private static function validPrefix(mixed $prefix): bool
    {
        return is_string($prefix) && strlen($prefix) <= 120
            && preg_match('~^/[A-Za-z0-9][A-Za-z0-9_-]*(?:/[A-Za-z0-9][A-Za-z0-9_-]*)*$~D', $prefix) === 1;
    }

    private static function metadata(array $data): array
    {
        $content = $data['content'] ?? $data;

        return ['page_type' => 'movie', 'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : (isset($data['category']['id']) ? (int) $data['category']['id'] : null),
            'content_item_id' => isset($data['content_item_id']) ? (int) $data['content_item_id'] : null,
            'path' => is_string($data['path'] ?? null) ? $data['path'] : '',
            'content' => ['title' => $content['title'] ?? '', 'genre' => $content['genre'] ?? null, 'kind' => $content['kind'] ?? 'movie', 'intent' => $content['intent'] ?? null, 'tags' => $content['tags'] ?? []]];
    }

    private static function publicationPins(array $entries): array
    {
        $pins = [];
        foreach ($entries as $entry) {
            if (is_int($entry['page_id'] ?? null) && $entry['page_id'] > 0 && is_int($entry['publication_id'] ?? null) && $entry['publication_id'] > 0) {
                $pins[$entry['page_id']] = $entry['publication_id'];
            }
        }

        return $pins;
    }

    private function sourceMetadata(int $siteId, array $ids, array $publicationPins = [], bool $excludeUnlinked = false): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id): bool => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }
        $publicationPins = array_filter(array_intersect_key($publicationPins, array_fill_keys($ids, true)), fn ($id): bool => is_int($id) && $id > 0);
        $query = DB::table('pages as p')->leftJoin('page_publications as pp', function ($join) use ($publicationPins): void {
            $join->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
            if ($publicationPins === []) {
                $join->on('pp.id', '=', 'p.current_publication_id');

                return;
            }
            $join->where(function ($match) use ($publicationPins): void {
                $match->where(fn ($current) => $current->whereNotIn('p.id', array_keys($publicationPins))->whereColumn('pp.id', 'p.current_publication_id'));
                foreach ($publicationPins as $pageId => $publicationId) {
                    $match->orWhere(fn ($pinned) => $pinned->where('p.id', $pageId)->where('pp.id', $publicationId));
                }
            });
        })->leftJoin('content_revisions as frozen_revision', 'frozen_revision.id', '=', 'pp.content_revision_id')
            ->leftJoin('content_revisions as r', 'r.id', '=', 'p.working_content_revision_id')
            ->where('p.site_id', $siteId)->whereIn('p.id', array_slice($ids, 0, 10000))->where(['p.page_kind' => 'regular', 'p.page_type' => 'movie'])->whereIn('p.status', ['draft', 'live']);
        if ($excludeUnlinked) {
            $query->whereNotExists(fn ($removed) => $removed->selectRaw('1')->from('source_site_bindings as removed_navigation_source')
                ->join('content_items as navigation_content', 'navigation_content.source_connector_id', '=', 'removed_navigation_source.connector_id')
                ->whereColumn('removed_navigation_source.site_id', 'p.site_id')->whereColumn('navigation_content.id', 'p.content_item_id')
                ->whereNotNull('removed_navigation_source.deleted_at'));
        }
        $fields = [];
        foreach (['title', 'genre', 'kind', 'intent', 'tags'] as $field) {
            $fields[] = "'$field', CASE WHEN pp.id IS NOT NULL THEN COALESCE(pp.snapshot_json->'content'->'$field', "
                ."pp.snapshot_json->'_catalog_card'->'content'->'$field', frozen_revision.payload_json->'$field') ELSE r.payload_json->'$field' END";
        }
        $category = "CASE WHEN pp.id IS NOT NULL THEN CASE WHEN jsonb_exists(pp.snapshot_json->'page', 'category_id') THEN (pp.snapshot_json->'page'->>'category_id')::bigint"
            ." ELSE COALESCE((pp.snapshot_json->'category'->>'id')::bigint, (pp.snapshot_json->'_catalog_card'->'category'->>'id')::bigint) END ELSE p.category_id END";
        $path = "CASE WHEN pp.id IS NOT NULL THEN COALESCE(pp.snapshot_json->'page'->>'path', pp.snapshot_json->'_catalog_card'->>'path') ELSE p.canonical_path END";
        $result = [];
        foreach ($query->get(['p.id', 'pp.id as publication_id', DB::raw($category.' AS category_id'),
            DB::raw('CASE WHEN pp.id IS NOT NULL THEN pp.content_item_id ELSE p.content_item_id END AS content_item_id'),
            DB::raw($path.' AS source_path'), DB::raw('jsonb_build_object('.implode(', ', $fields).') AS metadata_json')]) as $page) {
            if (isset($publicationPins[$page->id]) && $page->publication_id === null) {
                continue;
            }
            $result[(int) $page->id] = self::metadata(['content' => Json::decode($page->metadata_json), 'category_id' => $page->category_id, 'content_item_id' => $page->content_item_id, 'path' => $page->source_path]);
        }

        return $result;
    }

    private function keywords(array $snapshot, string $token): array
    {
        $siteId = (int) $snapshot['site']['id'];
        $settings = FanmuluSettings::fromSite(DB::table('sites')->where('id', $siteId)->first(['presentation_json']));
        if (! empty($settings['display_name_pool_ids'])) {
            // Name pools are an explicit purpose, independent of ordinary SEO bindings.
            $bindings = collect(array_map(fn ($poolId) => (object) ['pool_id' => $poolId, 'group_id' => null,
                'page_type' => 'movie', 'category_id' => null, 'content_item_id' => null, 'filters_json' => '{}'], $settings['display_name_pool_ids']));
        } else {
            $bindings = DB::table('keyword_bindings')->where(['site_id' => $siteId, 'status' => 'active'])->orderByDesc('priority')->orderBy('id')->get();
        }
        $poolOrder = array_flip($bindings->pluck('pool_id')->unique()->values()->all());
        $activeGroups = DB::table('keyword_groups')->whereIn('id', $bindings->pluck('group_id')->filter()->unique())
            ->where('status', 'active')->whereNull('deleted_at')->pluck('id')->all();
        $bindings = $bindings->filter(fn ($binding): bool => ($binding->group_id === null || in_array($binding->group_id, $activeGroups, true))
            && ($binding->page_type === null || $binding->page_type === 'movie'));
        if ($bindings->isEmpty()) {
            return [];
        }
        $query = DB::table('keywords as k')->join('keyword_pools as pool', 'pool.id', '=', 'k.pool_id')
            ->where(['k.status' => 'active', 'k.role' => 'primary', 'k.locale' => $snapshot['site']['locale'] ?? 'zh-CN', 'pool.status' => 'active'])
            ->whereNull('k.deleted_at')->whereNull('pool.deleted_at')
            ->where(fn ($query) => $query->where('pool.scope', '<>', 'site')->orWhere('pool.owner_site_id', $siteId))
            ->where(function ($query) use ($bindings): void {
                foreach ($bindings as $binding) {
                    $query->orWhere(function ($pool) use ($binding): void {
                        $pool->where('k.pool_id', $binding->pool_id);
                        if ($binding->group_id !== null) {
                            $pool->whereExists(fn ($member) => $member->selectRaw('1')->from('keyword_group_members as m')
                                ->whereColumn('m.keyword_id', 'k.id')->where('m.group_id', $binding->group_id));
                        }
                    });
                }
            });
        $first = (clone $query)->orderBy('k.id')->value('k.id');
        if ($first === null) {
            return [];
        }
        $last = (clone $query)->orderByDesc('k.id')->value('k.id') ?? $first;
        $range = max(1, (int) $last - (int) $first + 1);
        $start = (int) $first + (int) (hexdec(substr(hash('sha256', $snapshot['page']['id'].':'.$token), 0, 12)) % $range);
        $columns = ['k.id', 'k.pool_id', 'k.normalized_text', 'k.eligibility_json'];
        $keywords = (clone $query)->where('k.id', '>=', $start)->orderBy('k.id')->limit(self::MAX_KEYWORDS)->get($columns);
        if ($keywords->count() < self::MAX_KEYWORDS && $start > $first) {
            $keywords = $keywords->concat((clone $query)->where('k.id', '<', $start)
                ->orderBy('k.id')->limit(self::MAX_KEYWORDS - $keywords->count())->get($columns));
        }
        // Within one pool, role and locale the schema already guarantees unique text.
        if ($bindings->pluck('pool_id')->unique()->count() === 1) {
            $keywords = $keywords->sortBy('id');
        } else {
            $keywords = $this->completeTextGroups($query, $keywords, $columns);
        }
        $memberships = DB::table('keyword_group_members')->whereIn('keyword_id', $keywords->pluck('id'))->whereIn('group_id', $activeGroups)->get()
            ->groupBy('keyword_id')->map(fn ($rows): array => $rows->pluck('group_id')->all());
        $roster = [];
        foreach ($keywords as $keyword) {
            $policies = [];
            foreach ($bindings as $binding) {
                if ($binding->pool_id === $keyword->pool_id && ($binding->group_id === null || in_array($binding->group_id, $memberships[$keyword->id] ?? [], true))) {
                    $policies[] = ['page_type' => $binding->page_type, 'category_id' => $binding->category_id, 'content_item_id' => $binding->content_item_id, 'filters' => Json::decode($binding->filters_json)];
                }
            }
            $roster[] = ['keyword_id' => (int) $keyword->id, 'text' => $keyword->normalized_text,
                'pool_order' => $poolOrder[$keyword->pool_id], 'eligibility' => Json::decode($keyword->eligibility_json), 'bindings' => $policies];
        }

        return $roster;
    }

    private function completeTextGroups(Builder $query, Collection $keywords, array $columns): Collection
    {
        $texts = $keywords->pluck('normalized_text')->unique()->values()->all();
        $duplicates = (clone $query)->whereIn('k.normalized_text', $texts)->groupBy('k.normalized_text')
            ->selectRaw('k.normalized_text, count(*) AS total')->pluck('total', 'normalized_text');
        $selectedTexts = [];
        $selectedCount = 0;
        foreach ($texts as $text) {
            $total = (int) $duplicates[$text];
            if ($selectedCount + $total <= self::MAX_KEYWORDS) {
                $selectedTexts[] = $text;
                $selectedCount += $total;
            }
        }

        // Keep complete text groups so manual pinning sees the same first eligible pool as Allocator.
        return (clone $query)->whereIn('k.normalized_text', $selectedTexts)->orderBy('k.id')->get($columns);
    }

    /** Uses the same active binding, locale, category and eligibility rules as fanmulu cards. */
    public function discoveryChoices(int $siteId, string $locale, array $sourceIds, string $token, ?int $excludeSourceId = null): array
    {
        $roster = $this->keywords(['site' => ['id' => $siteId, 'locale' => $locale], 'page' => ['id' => 0]], $token);
        $common = self::unrestrictedMovieRoster($roster, null);
        // Metadata reads stay bounded to this candidate window; never scan full revision JSON.
        $sources = $this->sourceMetadata($siteId, array_values(array_unique([...$sourceIds, ...($excludeSourceId === null ? [] : [$excludeSourceId])])), excludeUnlinked: true);
        $origin = $excludeSourceId === null ? null : ($sources[$excludeSourceId] ?? null);
        if ($excludeSourceId !== null && $origin === null) {
            // A still-served origin may be unlinked; retain its identity only for duplicate exclusion.
            $origin = $this->sourceMetadata($siteId, [$excludeSourceId])[$excludeSourceId] ?? null;
        }
        $choices = [];
        foreach ($sources as $sourceId => $source) {
            if (! in_array($sourceId, $sourceIds, true) || $sourceId === $excludeSourceId
                || ($origin !== null && (($origin['content_item_id'] !== null && $origin['content_item_id'] === $source['content_item_id'])
                    || (self::normalizedTitle($origin['content']['title']) !== ''
                        && self::normalizedTitle($origin['content']['title']) === self::normalizedTitle($source['content']['title']))))) {
                continue;
            }
            if ($common !== null) {
                if ($common !== []) {
                    $choices[(int) $sourceId] = ['metadata' => $source, 'keywords' => array_values($common)];
                }

                continue;
            }
            $byText = [];
            foreach ($roster as $keyword) {
                if (! self::eligible($keyword, $source)) {
                    continue;
                }
                $existing = $byText[$keyword['text']] ?? null;
                if ($existing === null || $keyword['pool_order'] < $existing['pool_order']) {
                    $byText[$keyword['text']] = $keyword;
                }
            }
            if ($byText !== []) {
                $choices[(int) $sourceId] = ['metadata' => $source, 'keywords' => array_values($byText)];
            }
        }

        return $choices;
    }

    private static function normalizedTitle(string $title): string
    {
        $title = \Normalizer::normalize($title, \Normalizer::FORM_C);

        return mb_strtolower(preg_replace('/[\s\p{Z}]+/u', '', $title), 'UTF-8');
    }

    private static function eligible(array $keyword, array $source): bool
    {
        if (! is_int($keyword['keyword_id'] ?? null) || $keyword['keyword_id'] < 1 || ! is_string($keyword['text'] ?? null) || trim($keyword['text']) === ''
            || ! is_array($keyword['eligibility'] ?? null) || ! is_array($keyword['bindings'] ?? null) || ! self::filters($keyword['eligibility'], $source)) {
            return false;
        }

        foreach ($keyword['bindings'] as $binding) {
            if (is_array($binding) && self::bindingMatches($binding, $source)) {
                return true;
            }
        }

        return false;
    }

    private static function bindingMatches(array $binding, array $source): bool
    {
        foreach (['page_type', 'category_id', 'content_item_id'] as $field) {
            if (($binding[$field] ?? null) !== null && $binding[$field] !== ($source[$field] ?? null)) {
                return false;
            }
        }

        return self::filters($binding['filters'] ?? [], $source);
    }

    private static function filters(array $filters, array $source): bool
    {
        if ($filters === []) {
            return true;
        }
        $content = $source['content'] ?? [];
        foreach (['genres' => $content['genre'] ?? null, 'kinds' => $content['kind'] ?? 'movie', 'page_types' => 'movie', 'intents' => $content['intent'] ?? null, 'tags' => $content['tags'] ?? []] as $key => $value) {
            if (! empty($filters[$key]) && ! array_intersect((array) $filters[$key], is_array($value) ? $value : ($value === null ? [] : [$value]))) {
                return false;
            }
        }

        return true;
    }
}
