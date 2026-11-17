<?php

namespace App\Domain\Publishing;

use App\Domain\Ingestion\SourceTypeService;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Current film cards retain their published URL and eligible first-publication identity. */
final class OnDemandCatalog
{
    private const PER_PAGE = 24;

    private const CARD_JSON_FIELDS = ['title', 'poster_url', 'year', 'genre', 'area', 'language', 'actors', 'director',
        'directors', 'remarks', 'score', 'hits', 'hits_week', 'hits_month', 'source_updated_at'];

    public function paginate(int $siteId, ?int $categoryId = null, array $filters = []): LengthAwarePaginator
    {
        return $this->read($siteId, $categoryId, $filters);
    }

    /**
     * Latest eligible cards without request pagination or a catalog-wide count.
     *
     * Exclusions and the descending ID cursor are applied before each branch LIMIT,
     * including the lateral draft candidate scan. Repeated bounded calls can top up
     * a recommendation list after projection/deduplication without skipping matches.
     *
     * @param  array{page_ids?:array<int,int>,content_item_ids?:array<int,int>,paths?:array<int,string>}  $exclude
     * @return array<int,object>
     */
    public function sample(int $siteId, ?int $categoryId = null, int $limit = 12, array $filters = [], bool $includeDrafts = true, array $exclude = [], ?int $beforeId = null): array
    {
        if ($limit < 1 || $limit > self::PER_PAGE) {
            throw new \InvalidArgumentException('Mỗi nhóm trang chủ nhận từ 1 đến 24 phim.');
        }
        $exclude = $this->sampleExclusions($exclude);
        if ($beforeId !== null && $beforeId < 1) {
            throw new \InvalidArgumentException('Mốc ID gợi ý phải là số nguyên dương.');
        }

        return $this->read($siteId, $categoryId, $filters, $limit, $includeDrafts, $exclude, $beforeId);
    }

    /** @return LengthAwarePaginator|array<int,object> */
    private function read(int $siteId, ?int $categoryId, array $filters, ?int $sampleLimit = null, bool $includeDrafts = true, array $exclude = [], ?int $beforeId = null): LengthAwarePaginator|array
    {
        $sampling = $sampleLimit !== null;
        $page = $sampling ? 1 : max(1, Paginator::resolveCurrentPage());
        $site = DB::table('sites')->where('id', $siteId)->where('status', 'active')->first(['id', 'presentation_json', 'public_content_epoch']);
        if (! $site) {
            return $sampling ? [] : $this->paginator([], 0, $page);
        }
        $categories = CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $siteId)->get(['id', 'parent_id', 'name'])->keyBy('id');
        $categoryIds = $categoryId === null ? [] : $this->descendants($categories->all(), $categoryId);
        if ($categoryId !== null && $categoryIds === []) {
            return $sampling ? [] : $this->paginator([], 0, $page);
        }
        $metadata = app(CatalogMetadata::class);
        $indexed = $metadata->available();
        [$live, $draft, $policies, $unfiltered, $searchFilters, $languageFilter] = $this->branches(
            $siteId, $categoryId, $categoryIds, $filters, $indexed, $sampling, $includeDrafts, $exclude, $beforeId);

        $scope = null;
        $total = 0;
        $counts = ['live' => 0, 'draft' => 0];
        $boundary = null;
        $reverse = false;
        $offset = 0;
        $limit = $sampleLimit;
        $noLive = false;
        $noDraft = ! $includeDrafts;
        if (! $sampling) {
            $eligible = DB::query()->fromSub((clone $live)->unionAll(clone $draft), 'eligible');
            $count = function () use ($eligible): array {
                $counts = ['live' => 0, 'draft' => 0];
                foreach ((clone $eligible)->select('is_draft')->selectRaw('count(*) AS total')->groupBy('is_draft')->get() as $row) {
                    $counts[$row->is_draft ? 'draft' : 'live'] = (int) $row->total;
                }

                return $counts;
            };
            $scope = Json::hash(['database' => config('database.connections.pgsql.database'), 'site' => $siteId,
                'category' => $categoryId, 'descendants' => $categoryIds, 'filters' => $filters, 'policies' => $policies, 'indexed' => $indexed,
                'public_content_epoch' => (int) $site->public_content_epoch]);
            $counts = app(PublicCatalogCache::class)->counts($siteId, $scope, $count);
            $total = array_sum($counts);
            $requestToken = request()->query('catalog_cursor');
            $boundary = CatalogPaginator::boundary(is_string($requestToken) ? $requestToken : null, $scope, $page);
            $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
            if ($total === 0 || $page > $lastPage) {
                return $this->paginator([], $total, $page, $scope);
            }
            // A first publication moves a card between branches without changing the
            // cached total. Never prune a branch from a stale zero alone.
            $noLive = $counts['live'] === 0 && ! (clone $live)->exists();
            $noDraft = $counts['draft'] === 0 && ! (clone $draft)->exists();
            $reverse = $boundary !== null ? $boundary['direction'] === 'before' : $page > $lastPage / 2;
            $offset = $boundary !== null ? 0 : ($reverse ? max(0, $total - $page * self::PER_PAGE) : ($page - 1) * self::PER_PAGE);
            $limit = $boundary !== null ? self::PER_PAGE : min(self::PER_PAGE, $total - ($page - 1) * self::PER_PAGE);
        }
        if ($noLive) {
            $live->whereRaw('false');
        }
        if ($noDraft) {
            $draft->whereRaw('false');
        }
        $sampleByTerm = $sampling && $indexed && (isset($filters['actors_any']) || isset($filters['genres_any']));
        $fencedSearch = ! $sampling && $indexed && ! $unfiltered && ! $languageFilter && $counts['draft'] > 0 && $counts['draft'] < 10000;
        $fallbackDraft = $sampleByTerm ? clone $draft : $draft;
        if ($fencedSearch || ($sampleByTerm && ! $noDraft)) {
            // Fence the small matching ID set before ordering pages. Otherwise
            // LIMIT can choose thousands of random metadata lookups in ID order.
            $matches = DB::table('catalog_revision_metadata as matches')->select('matches.id');
            $metadata->filters($matches, 'matches.fields_json', $searchFilters, 'matches.search_grams');
            $fallbackDraft->joinSub($matches->offset(0), 'matched_revision', function ($join): void {
                $join->on('matched_revision.id', '=', 'p.working_content_revision_id');
            });
        }
        $eligible = DB::query()->fromSub((clone $live)->unionAll(clone $draft), 'eligible');
        if ($boundary !== null) {
            $operator = $reverse ? '>' : '<';
            $live->where('p.id', $operator, $boundary['id']);
            $draft->where('p.id', $operator, $boundary['id']);
        }
        if ($boundary !== null || $offset < 2400) {
            $window = $offset + $limit;
            $direction = $reverse ? 'asc' : 'desc';
            $topLive = (clone $live)->orderBy('p.id', $direction)->limit($window);
            $topDraft = (clone $fallbackDraft)->orderBy('p.id', $direction)->limit($window);
            if ($noLive) {
                $topLive->whereRaw('false');
            }
            if ($unfiltered || ($indexed && $counts['draft'] >= 10000)) {
                $topDraft = DB::table('pages as candidate')->joinLateral((clone $draft)
                    ->whereColumn('p.id', 'candidate.id')->limit(1), 'approved')
                    ->where('candidate.site_id', $siteId)->where('candidate.status', 'draft')
                    ->where('candidate.page_kind', 'regular')->where('candidate.page_type', 'movie')
                    ->whereNull('candidate.current_publication_id')->where('candidate.publish_locked', false)
                    ->when($categoryId !== null, fn (Builder $query) => $query->whereIn('candidate.category_id', $categoryIds))
                    ->when($boundary !== null, fn (Builder $query) => $query->where('candidate.id', $reverse ? '>' : '<', $boundary['id']))
                    ->select('approved.*')->orderBy('candidate.id', $direction)->limit($window);
                $this->excludeSamples($topDraft, $exclude, $beforeId, 'candidate');
            }
            if ($noDraft) {
                $topDraft->whereRaw('false');
            }
            $eligible = DB::query()->fromSub($topLive->unionAll($topDraft), 'eligible');
            if ($sampleByTerm || ($sampling && $indexed && ! empty($filters['genre']))) {
                $eligible = $this->preferSamplePrefix($live, $draft, $eligible, $siteId, $window, $sampleByTerm ? 256 : 2048, $noDraft, $exclude, $beforeId);
            }
        }
        $candidates = $eligible->orderBy('id', $reverse ? 'asc' : 'desc')->offset($offset)->limit($limit)->get();
        if ($reverse) {
            $candidates = $candidates->reverse()->values();
        }
        $movies = $this->hydrate($site, $categories, $candidates);

        return $sampling ? $movies : $this->paginator($movies, $total, $page, $scope);
    }

    /** Unfiltered membership shared by the background recommendation build and the public catalog. */
    public function recommendationCandidates(int $siteId, ?int $draftPageId = null): Builder
    {
        [$live, $draft] = $this->branches($siteId, null, [], [], true, false, true, [], null);
        if ($draftPageId !== null) {
            return $draft->where('p.id', $draftPageId);
        }

        return $live->unionAll($draft);
    }

    /** The prepared window supplies membership and ranking. Read serving data for its bounded IDs. */
    public function samplePrepared(int $siteId, array $pageIds, int $limit, array $exclude, ?int $beforeId): array
    {
        if ($pageIds === []) {
            return [];
        }
        $site = DB::table('sites')->where('id', $siteId)->where('status', 'active')->first(['id', 'presentation_json']);
        if (! $site) {
            return [];
        }
        $head = DB::table('page_publications as publication')->whereColumn('publication.id', 'p.current_publication_id')
            ->whereColumn('publication.page_id', 'p.id')->whereColumn('publication.site_id', 'p.site_id')
            ->select('publication.id')->selectRaw("publication.snapshot_json->'page'->>'path' AS path")->limit(1);
        $query = DB::table('pages as p')->leftJoinLateral($head, 'pp')
            ->where('p.site_id', $siteId)->where('p.page_kind', 'regular')->where('p.page_type', 'movie')
            ->whereIntegerInRaw('p.id', $pageIds)
            ->where(fn (Builder $status) => $status->where('p.status', 'live')->whereNotNull('pp.id')
                ->orWhere(fn (Builder $draft) => $draft->where('p.status', 'draft')->whereNull('p.current_publication_id')->where('p.publish_locked', false)));
        $this->excludeSamples($query, $exclude, $beforeId, 'p', 'COALESCE(pp.path, p.canonical_path)');
        $candidates = $query->select(['p.id', 'p.content_item_id', 'p.category_id', 'pp.id as publication_id'])
            ->selectRaw("CASE WHEN p.status='draft' THEN p.working_content_revision_id ELSE p.serving_content_revision_id END AS revision_id,
                p.status='draft' AS is_draft")->orderByDesc('p.id')->limit($limit)->get();
        $categories = CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $siteId)->get(['id', 'parent_id', 'name'])->keyBy('id');

        return $this->hydrate($site, $categories, $candidates);
    }

    private function branches(int $siteId, ?int $categoryId, array $categoryIds, array $filters, bool $indexed,
        bool $sampling, bool $includeDrafts, array $exclude, ?int $beforeId): array
    {
        $metadata = app(CatalogMetadata::class);
        $unfiltered = array_filter($filters, fn ($value) => $value !== null && $value !== '') === [];
        $searchFilters = $filters;
        unset($searchFilters['language']);
        $languageFilter = isset($filters['language']) && $filters['language'] !== '';
        $liveBase = DB::table('pages as p')->join('page_publications as pp', function ($join): void {
            $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')->on('pp.site_id', '=', 'p.site_id');
        })->where('p.site_id', $siteId)->where('p.status', 'live')->where('p.page_kind', 'regular')->where('p.page_type', 'movie');
        $this->excludeSamples($liveBase, $exclude, $beforeId, 'p', "COALESCE(pp.snapshot_json->'page'->>'path', p.canonical_path)");
        $frozen = (clone $liveBase)->whereNull('p.serving_content_revision_id');
        $updated = (clone $liveBase)->join('content_revisions as serving_revision', function ($join): void {
            $join->on('serving_revision.id', '=', 'p.serving_content_revision_id')->on('serving_revision.content_item_id', '=', 'p.content_item_id');
        })->whereNotNull('p.serving_content_revision_id');
        if ($categoryId !== null) {
            $this->frozenCategory($frozen, $categoryId, $categoryIds);
            $this->frozenCategory($updated, $categoryId, $categoryIds);
        }
        if ($indexed && $filters !== []) {
            $frozen->join('catalog_publication_metadata as pm', function ($join): void {
                $join->on('pm.id', '=', 'pp.id')->on('pm.site_id', '=', 'p.site_id')->on('pm.page_id', '=', 'p.id');
            });
            $updated->join('catalog_revision_metadata as serving_metadata', function ($join): void {
                $join->on('serving_metadata.id', '=', 'serving_revision.id')->on('serving_metadata.content_item_id', '=', 'p.content_item_id');
            });
        }
        $metadata->filters($frozen, $indexed && $filters !== [] ? 'pm.fields_json' : "pp.snapshot_json->'content'", $searchFilters,
            $indexed && $filters !== [] ? 'pm.search_grams' : null);
        $metadata->filters($updated, $indexed && $filters !== [] ? 'serving_metadata.fields_json' : 'serving_revision.payload_json', $searchFilters,
            $indexed && $filters !== [] ? 'serving_metadata.search_grams' : null);
        if ($languageFilter) {
            $frozen->whereRaw("pp.snapshot_json->'content'->>'language' = ?", [(string) $filters['language']]);
            $updated->whereRaw("serving_revision.payload_json->>'language' = ?", [(string) $filters['language']]);
        }
        $frozen->select(['p.id', 'p.content_item_id', 'p.category_id', 'pp.id as publication_id', DB::raw('NULL::bigint AS revision_id')])
            ->selectRaw('false AS is_draft');
        $updated->select(['p.id', 'p.content_item_id', 'p.category_id', 'pp.id as publication_id', 'serving_revision.id as revision_id'])
            ->selectRaw('false AS is_draft');
        // Keep publication and revision search predicates in separate branches so
        // CJK gram indexes can still find a small candidate set before page order.
        $live = DB::query()->fromSub($frozen->unionAll($updated), 'p')->select('p.*');

        $draft = DB::table('pages as p')->join('content_items as c', function ($join): void {
            // Revision equality is a residual validity check, not an independent
            // identity join key. CASE avoids a one-row estimate for correlated IDs.
            $join->on('c.id', '=', 'p.content_item_id')
                ->whereRaw('CASE WHEN c.current_revision_id=p.working_content_revision_id THEN true ELSE false END');
        });
        if (! $unfiltered) {
            $draft->join(($indexed ? 'catalog_revision_metadata' : 'content_revisions').' as r', function ($join): void {
                $join->on('r.id', '=', 'p.working_content_revision_id');
            });
        }
        $draft->join('source_connectors as s', 's.id', '=', 'c.source_connector_id')
            ->join('source_site_bindings as b', function ($join): void {
                $join->on('b.connector_id', '=', 's.id')->on('b.site_id', '=', 'p.site_id');
            })->where('p.site_id', $siteId)->where('p.status', 'draft')->where('p.page_kind', 'regular')->where('p.page_type', 'movie')
            ->whereNull('p.current_publication_id')->where('p.publish_locked', false)->where('c.kind', 'movie')->where('c.library_status', 'active')
            ->where('s.status', 'active')->whereNull('s.deleted_at')->where('b.status', 'active')->whereNull('b.deleted_at')
            ->whereRaw("b.auto_publish_policy->>'enabled' = 'true'")
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('page_publications as history')
                ->whereColumn('history.page_id', 'p.id')->when($sampling, fn (Builder $history) => $history->offset(0)));
        $this->excludeSamples($draft, $exclude, $beforeId, 'p');
        $policies = $includeDrafts ? $this->policies($siteId) : [];
        $draftContent = $indexed ? 'r.fields_json' : 'r.payload_json';
        $draft->where(function (Builder $query) use ($policies, $draftContent, $indexed, $unfiltered): void {
            if ($policies === []) {
                $query->whereRaw('false');
            }
            foreach ($policies as $entry) {
                $query->orWhere(function (Builder $source) use ($entry, $draftContent, $indexed, $unfiltered): void {
                    $source->where('b.id', $entry['binding_id'])->where('b.mapping_version', $entry['mapping_version'])
                        ->where('s.id', $entry['source_id'])->where('s.row_version', $entry['source_version']);
                    if ($entry['policy']['mode'] === 'selected') {
                        if ($unfiltered) {
                            $source->whereExists(function (Builder $type) use ($entry, $indexed): void {
                                $content = $indexed ? 'rt.fields_json' : 'rt.payload_json';
                                $type->selectRaw('1')->from(($indexed ? 'catalog_revision_metadata' : 'content_revisions').' as rt')
                                    ->whereColumn('rt.id', 'p.working_content_revision_id')
                                    ->whereIn(DB::raw("COALESCE($content->>'category_external_id', $content->'_raw'->>'type_id')"), $entry['policy']['selected_type_ids']);
                            });
                        } else {
                            $source->whereIn(DB::raw("COALESCE($draftContent->>'category_external_id', $draftContent->'_raw'->>'type_id')"), $entry['policy']['selected_type_ids']);
                        }
                    }
                });
            }
        });
        if ($categoryId !== null) {
            $draft->whereIn('p.category_id', $categoryIds);
        }
        $metadata->filters($draft, $draftContent, $searchFilters, $indexed ? 'r.search_grams' : null);
        if ($languageFilter) {
            if ($indexed) {
                $draft->join('content_revisions as language_revision', 'language_revision.id', '=', 'p.working_content_revision_id');
            }
            $draft->whereRaw(($indexed ? 'language_revision' : 'r').".payload_json->>'language' = ?", [(string) $filters['language']]);
        }
        $draft->select(['p.id', 'p.content_item_id', 'p.category_id', DB::raw('NULL::bigint AS publication_id'), 'p.working_content_revision_id as revision_id'])
            ->selectRaw('true AS is_draft');

        return [$live, $draft, $policies, $unfiltered, $searchFilters, $languageFilter];
    }

    private function hydrate(object $site, Collection $categories, Collection $candidates): array
    {
        $siteId = (int) $site->id;
        $pageMetadata = $candidates->isEmpty() ? collect() : DB::table('pages')->where('site_id', $siteId)->whereIn('id', $candidates->pluck('id'))
            ->get(['id', 'canonical_path', 'created_at'])->keyBy('id');
        $publicationIds = $candidates->where('is_draft', false)->pluck('publication_id')->all();
        $revisionIds = $candidates->pluck('revision_id')->filter()->unique()->all();
        $published = $publicationIds === [] ? collect() : DB::table('page_publications as pp')->where('pp.site_id', $siteId)->whereIn('pp.id', $publicationIds)
            ->joinLateral($this->cardFields("pp.snapshot_json->'content'"), 'card')
            ->select(['pp.id', 'pp.page_id', 'pp.activated_at'])->selectRaw("jsonb_build_object('content', ".$this->contentProjection().",
                'page', pp.snapshot_json->'page', 'category', pp.snapshot_json->'category', 'routing', pp.snapshot_json->'routing') AS snapshot_json")
            ->get()->keyBy('id');
        $revisions = $revisionIds === [] ? collect() : DB::table('content_revisions as r')->whereIn('r.id', $revisionIds)
            ->joinLateral($this->cardFields('r.payload_json'), 'card')
            ->select(['r.id', 'r.content_item_id'])->selectRaw($this->contentProjection().' AS content_json')->get()->keyBy('id');
        $routing = SitePresentationService::profile($site);
        $movies = [];
        $categorySnapshots = [];
        foreach ($candidates as $candidate) {
            $pageData = $pageMetadata->get($candidate->id);
            if (! $pageData) {
                continue;
            }
            if ($candidate->is_draft) {
                $revision = $revisions->get($candidate->revision_id);
                if (! $revision || $revision->content_item_id !== $candidate->content_item_id) {
                    continue;
                }
                $category = $categorySnapshots[$candidate->category_id ?? 0]
                    ??= $this->category($categories->all(), $candidate->category_id, $routing);
                $snapshot = ['content' => Json::decode($revision->content_json), 'routing' => $routing, 'category' => $category,
                    'page' => ['id' => (int) $candidate->id, 'type' => 'movie', 'path' => $pageData->canonical_path]];
            } else {
                $publication = $published->get($candidate->publication_id);
                if (! $publication || $publication->page_id !== $candidate->id) {
                    continue;
                }
                $snapshot = Json::decode($publication->snapshot_json);
                if ($candidate->revision_id !== null) {
                    $revision = $revisions->get($candidate->revision_id);
                    if (! $revision || $revision->content_item_id !== $candidate->content_item_id) {
                        continue;
                    }
                    $snapshot['content'] = Json::decode($revision->content_json);
                }
            }
            $movies[] = (object) ['id' => (int) $candidate->id, 'content_item_id' => (int) $candidate->content_item_id,
                'canonical_path' => $snapshot['page']['path'] ?? $pageData->canonical_path,
                'snapshot_json' => Json::encode($snapshot), 'activated_at' => $candidate->is_draft ? $pageData->created_at : $publication->activated_at];
        }

        return $movies;
    }

    /** A full ordered prefix proves the answer without scanning every matching revision. */
    private function preferSamplePrefix(Builder $live, Builder $draft, Builder $fallback, int $siteId, int $limit, int $prefixSize, bool $noDraft, array $exclude, ?int $beforeId): Builder
    {
        $prefix = DB::table('pages as candidate')->select('candidate.id', 'candidate.status')->where('candidate.site_id', $siteId)
            ->where('candidate.page_kind', 'regular')->where('candidate.page_type', 'movie')
            ->whereIn('candidate.status', $noDraft ? ['live'] : ['live', 'draft'])->orderByDesc('candidate.id')->limit($prefixSize);
        // A live page may have a different frozen path. The approval query checks it.
        $this->excludeSamples($prefix, array_diff_key($exclude, ['paths' => true]), $beforeId, 'candidate');
        $approval = (clone $live)->whereColumn('p.id', 'candidate.id')->where('candidate.status', 'live');
        if (! $noDraft) {
            $approval->unionAll((clone $draft)->whereColumn('p.id', 'candidate.id')->where('candidate.status', 'draft'));
        }
        $probe = DB::query()->fromSub($prefix, 'candidate')->joinLateral($approval->limit(1), 'approved')
            ->select('approved.*')->orderByDesc('candidate.id')->limit($limit);

        // The one-time guards evaluate both branches against the same MVCC snapshot.
        return DB::query()->fromRaw('(
            WITH newest AS MATERIALIZED ('.$probe->toSql().'), sample_size AS MATERIALIZED (SELECT count(*) AS total FROM newest)
            SELECT * FROM newest WHERE (SELECT total FROM sample_size) = ?
            UNION ALL
            SELECT * FROM ('.$fallback->toSql().') AS full_sample WHERE (SELECT total FROM sample_size) < ?
        ) AS preferred_sample', [...$probe->getBindings(), $limit, ...$fallback->getBindings(), $limit]);
    }

    private function sampleExclusions(array $exclude): array
    {
        if (array_diff(array_keys($exclude), ['page_ids', 'content_item_ids', 'paths'])) {
            throw new \InvalidArgumentException('Bộ loại trừ gợi ý có trường không được hỗ trợ.');
        }
        foreach ($exclude as $kind => &$values) {
            if (! is_array($values) || ! array_is_list($values) || count($values) > 500) {
                throw new \InvalidArgumentException('Mỗi bộ loại trừ gợi ý tối đa 500 mục.');
            }
            foreach ($values as &$value) {
                if ($kind === 'paths') {
                    if (! is_string($value) || $value === '' || strlen($value) > 2000 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                        throw new \InvalidArgumentException('Đường dẫn loại trừ gợi ý không hợp lệ.');
                    }
                } elseif ((! is_int($value) && ! is_string($value)) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new \InvalidArgumentException('ID loại trừ gợi ý phải là số nguyên dương.');
                } else {
                    $value = (int) $value;
                }
            }
            unset($value);
            $values = array_values(array_unique($values));
        }
        unset($values);

        return $exclude;
    }

    private function excludeSamples(Builder $query, array $exclude, ?int $beforeId, string $alias, ?string $pathExpression = null): void
    {
        if ($beforeId !== null) {
            $query->where($alias.'.id', '<', $beforeId);
        }
        foreach (['page_ids' => 'id', 'content_item_ids' => 'content_item_id'] as $kind => $column) {
            if (! empty($exclude[$kind])) {
                $query->whereNotIn($alias.'.'.$column, $exclude[$kind]);
            }
        }
        if (! empty($exclude['paths'])) {
            $query->whereNotIn($pathExpression === null ? $alias.'.canonical_path' : DB::raw($pathExpression), $exclude['paths']);
        }
    }

    /** A missing derived row falls back to the same frozen publication predicate. */
    private function frozenCategory(Builder $query, int $categoryId, array $descendants): void
    {
        $placeholders = implode(', ', array_fill(0, count($descendants), '?'));
        $frozen = "(pp.snapshot_json->'page'->>'category_id' = ? OR pp.snapshot_json->'category'->'ancestor_ids' @> ?::jsonb
            OR (jsonb_typeof(pp.snapshot_json->'category'->'ancestor_ids') IS DISTINCT FROM 'array'
                AND COALESCE(pp.snapshot_json->'category'->>'id', pp.snapshot_json->'page'->>'category_id') IN (".$placeholders.')))';
        $bindings = [(string) $categoryId, Json::encode([$categoryId]), ...array_map('strval', $descendants)];
        if (! Schema::hasTable('publication_category_index')) {
            $query->whereRaw($frozen, $bindings);

            return;
        }
        $query->leftJoin('publication_category_index as pci', function ($join): void {
            $join->on('pci.publication_id', '=', 'pp.id')->on('pci.site_id', '=', 'pp.site_id')->on('pci.page_id', '=', 'pp.page_id');
        });
        $indexed = '(pci.category_id = ? OR pci.ancestor_ids @> ?::jsonb
            OR (jsonb_typeof(pci.ancestor_ids) IS DISTINCT FROM \'array\' AND pci.legacy_category_id IN ('.$placeholders.')))';
        $query->whereRaw('CASE WHEN pci.publication_id IS NOT NULL THEN '.$indexed.' ELSE '.$frozen.' END', [...$bindings, ...$bindings]);
    }

    private function policies(int $siteId): array
    {
        $policies = [];
        foreach (DB::table('source_site_bindings as b')->join('source_connectors as s', 's.id', '=', 'b.connector_id')
            ->where('b.site_id', $siteId)->where('b.status', 'active')->whereNull('b.deleted_at')->where('s.status', 'active')->whereNull('s.deleted_at')
            ->whereRaw("b.auto_publish_policy->>'enabled' = 'true'")
            ->get(['b.id as binding_id', 'b.mapping_version', 'b.mapping_json', 's.id as source_id', 's.row_version', 's.config_json']) as $binding) {
            try {
                $policy = SourceTypeService::policy($binding);
                SourceTypeService::assertCurrentPolicy($policy, $binding);
            } catch (\RuntimeException $error) {
                continue;
            }
            $policies[] = ['binding_id' => $binding->binding_id, 'mapping_version' => $binding->mapping_version,
                'source_id' => $binding->source_id, 'source_version' => $binding->row_version, 'policy' => $policy];
        }

        return $policies;
    }

    private function descendants(array $categories, int $categoryId): array
    {
        if (! isset($categories[$categoryId])) {
            return [];
        }
        $ids = [$categoryId => true];
        do {
            $before = count($ids);
            foreach ($categories as $category) {
                if (isset($ids[$category->parent_id ?? 0])) {
                    $ids[(int) $category->id] = true;
                }
            }
        } while (count($ids) > $before);

        return array_keys($ids);
    }

    private function category(array $categories, ?int $categoryId, array $routing): array
    {
        if ($categoryId === null || ! isset($categories[$categoryId])) {
            return [];
        }
        $category = $categories[$categoryId];
        $ancestors = [];
        $current = $category;
        while ($current && ! in_array((int) $current->id, $ancestors, true)) {
            $ancestors[] = (int) $current->id;
            $current = $categories[$current->parent_id] ?? null;
        }

        return ['id' => (int) $category->id, 'name' => $category->name, 'path' => SitePresentationService::categoryPath($category, $routing),
            'parent_id' => $category->parent_id, 'ancestor_ids' => $ancestors, 'root_id' => end($ancestors)];
    }

    private function cardFields(string $content): Builder
    {
        $columns = implode(' jsonb, ', [...self::CARD_JSON_FIELDS, 'metadata', 'episodes', 'body_html']).' text';

        // Unpack the object once instead of extracting each field from the stored JSONB value.
        return DB::query()->fromRaw("jsonb_to_record(CASE WHEN jsonb_typeof($content) = 'object' THEN $content ELSE '{}'::jsonb END) AS fields($columns)")
            ->select('fields.*');
    }

    private function contentProjection(): string
    {
        $fields = array_map(fn ($field): string => "'".$field."', card.".$field, self::CARD_JSON_FIELDS);
        $fields[] = "'metadata', jsonb_build_object('poster_url', card.metadata->'poster_url', 'score', card.metadata->'score', 'vod_score', card.metadata->'vod_score')";
        $fields[] = "'episodes', CASE WHEN jsonb_typeof(card.episodes) = 'array' AND card.episodes <> '[]'::jsonb THEN '[{}]'::jsonb ELSE '[]'::jsonb END";
        $fields[] = "'body_html', left(card.body_html, 4096)";

        return 'jsonb_build_object('.implode(', ', $fields).')';
    }

    private function paginator(array $movies, int $total, int $page, ?string $scope = null): LengthAwarePaginator
    {
        $options = ['path' => Paginator::resolveCurrentPath(), 'query' => Paginator::resolveQueryString()];

        return $scope === null ? new LengthAwarePaginator($movies, $total, self::PER_PAGE, $page, $options)
            : new CatalogPaginator($movies, $total, self::PER_PAGE, $page, $options, $scope);
    }
}
