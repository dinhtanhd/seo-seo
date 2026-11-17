<?php

namespace App\Domain\Keywords;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class Allocator
{
    private ?int $preparedSite = null;

    private bool $checksCapacity = true;

    private ?int $eligibilitySite = null;

    private string $locale;

    private array $pools = [];

    private array $bindings = [];

    private array $groupMembers = [];

    private array $keywords = [];

    private array $keywordRows = [];

    private array $usagePages = [];

    private array $usageCounts = [];

    private array $cappedKeywordIds = [];

    private array $checkedUsagePages = [];

    private array $limits = [];

    private array $candidateCache = [];

    private bool $contentSpecific = false;

    private array $displayNamePoolIds = [];

    /** Batch-scoped read projection. Capacity is checked again under row locks at publish. */
    public function prepare(int $siteId, ?array $committedVersion = null, ?array $resolved = null, bool $checksCapacity = true): void
    {
        if ($this->preparedSite === $siteId && $this->checksCapacity === $checksCapacity) {
            return;
        }
        $this->prepareEligibility($siteId);
        $this->preparedSite = $siteId;
        $this->checksCapacity = $checksCapacity;
        $this->keywords = [];
        $this->keywordRows = [];
        $this->usagePages = [];
        $this->usageCounts = [];
        $this->cappedKeywordIds = [];
        $this->checkedUsagePages = [];
        $this->limits = [];
        $this->candidateCache = [];
        $poolIds = array_values(array_unique([...array_keys($this->bindings), ...$this->displayNamePoolIds]));
        $scope = null;
        if ($resolved !== null) {
            $scope = [];
            foreach (['primary', 'secondary', 'anchor', 'fanmulu_title'] as $slot) {
                $policy = $resolved['slots']['allocation.'.$slot] ?? false;
                if (! is_array($policy) || ($policy['enabled'] ?? true) === false || (int) ($policy['count'] ?? 1) < 1) {
                    continue;
                }
                $pools = array_values(array_intersect($poolIds, array_merge($policy['pool_ids'] ?? [], $policy['fallback_pool_ids'] ?? [])));
                if ($slot !== 'fanmulu_title' && ($policy['_purpose'] ?? null) !== 'fanmulu_title') {
                    $pools = array_values(array_diff($pools, $this->displayNamePoolIds));
                }
                $scope[] = ['role' => $slot === 'fanmulu_title' ? 'primary' : $slot, 'pools' => $pools,
                    'manual' => ($policy['mode'] ?? 'weighted') === 'manual' ? array_values(array_unique(array_map('intval', $policy['keyword_ids'] ?? $policy['manual_ids'] ?? []))) : null];
            }
            $poolIds = array_values(array_unique(array_merge(...array_column($scope, 'pools'))));
        }
        foreach ($this->readKeywords($siteId, $poolIds, $committedVersion, $scope) as $keyword) {
            $this->keywords[$keyword->pool_id][$keyword->role][] = $keyword;
            $this->keywordRows[$keyword->id] = $keyword;
        }
        if (! $checksCapacity) {
            return;
        }
        foreach (DB::table('site_keyword_limits')->where('site_id', $siteId)->get() as $limit) {
            $this->limits[$limit->keyword_id] = $limit->max_active_pages;
        }
        foreach ($this->keywords as $roles) {
            foreach ($roles as $keywords) {
                foreach ($keywords as $keyword) {
                    $cap = array_key_exists($keyword->id, $this->limits) ? $this->limits[$keyword->id] : $keyword->default_max_active_pages;
                    if ($cap !== null) {
                        $this->cappedKeywordIds[] = $keyword->id;
                    }
                }
            }
        }
        foreach (app(KeywordService::class)->usageSummaries($siteId, $this->cappedKeywordIds) as $keywordId => $summary) {
            $this->usageCounts[$keywordId] = $summary['total'];
        }
    }

    private function readKeywords(int $siteId, array $poolIds, ?array $committedVersion, ?array $scope): array
    {
        if ($committedVersion !== null && $scope !== null && config('publication_storage.shared_keyword_cache', false)) {
            $automatic = array_values(array_filter($scope, fn ($part) => $part['manual'] === null));
            $manual = array_values(array_filter($scope, fn ($part) => $part['manual'] !== null));
            if ($automatic !== [] && $manual !== []) {
                // A page-specific display pin must not force the common SEO pools to be read again.
                $rows = collect($this->readKeywords($siteId, $poolIds, $committedVersion, $automatic))->keyBy('id');
                foreach ($this->readKeywords($siteId, $poolIds, null, $manual) as $keyword) {
                    $rows[$keyword->id] = $keyword;
                }

                return $rows->sortBy([['priority', 'desc'], ['id', 'asc']])->values()->all();
            }
        }
        $read = function () use ($poolIds, $scope): array {
            $query = DB::table('keywords')->whereIn('pool_id', $poolIds)->where('status', 'active')->whereNull('deleted_at');
            if ($scope !== null) {
                $query->where('locale', $this->locale)->where(function ($query) use ($scope): void {
                    $query->whereRaw('false');
                    foreach ($scope as $part) {
                        $query->orWhere(function ($query) use ($part): void {
                            $query->whereIn('pool_id', $part['pools'])->where('role', $part['role']);
                            if ($part['manual'] !== null) {
                                // Include same-text peers so ordered-pool deduplication stays identical.
                                $query->whereIn('normalized_text', DB::table('keywords as chosen')->whereIn('chosen.id', $part['manual'])->select('chosen.normalized_text'));
                            }
                        });
                    }
                });
            }

            return $query->orderByDesc('priority')->orderBy('id')->get()->all();
        };
        $hasManual = $scope !== null && count(array_filter($scope, fn ($part) => $part['manual'] !== null)) > 0;
        if ($committedVersion === null || DB::transactionLevel() !== 1 || $hasManual || ! config('publication_storage.shared_keyword_cache', false)) {
            return $read();
        }
        $namespace = FirstVisitKeywordCache::databaseNamespace();
        $relation = FirstVisitKeywordCache::relation();
        $version = Json::hash([$relation, $committedVersion, $poolIds, $scope]);
        $cache = Cache::store('file');
        $key = 'first-visit-keywords:'.$namespace.':'.$siteId.':'.Json::hash([$poolIds, $scope]);
        $lookup = fn (): ?array => FirstVisitKeywordCache::decode($cache->get($key), $version, $poolIds);
        $acquired = false;
        try {
            $found = $lookup();
            if ($found !== null) {
                return $found;
            }
            if ($scope !== null) {
                $warmed = app(FirstVisitKeywordCache::class)->warmedRows($siteId, $this->locale, $namespace,
                    Json::hash([$relation, $committedVersion]), $scope);
                if ($warmed !== null) {
                    return $warmed;
                }
            }
            $lock = $cache->lock($key.':lock', 30);
            $deadline = hrtime(true) + 3_000_000_000;
            do {
                $found = $lookup();
                if ($found !== null) {
                    return $found;
                }
                $acquired = $lock->get();
                if ($acquired) {
                    break;
                }
                // Readers can share the completed value without taking the writer lock.
                usleep(random_int(8_000, 16_000));
            } while (hrtime(true) < $deadline);
        } catch (\Throwable $error) {
            report($error);
        }
        if (! $acquired) {
            return $read();
        }
        try {
            try {
                $found = $lookup();
            } catch (\Throwable $error) {
                report($error);
                $found = null;
            }
            if ($found !== null) {
                return $found;
            }
            $rows = $read();
            try {
                $payload = FirstVisitKeywordCache::encode($rows, $version);
                if ($payload !== null) {
                    $cache->put($key, $payload, FirstVisitKeywordCache::TTL);
                }
            } catch (\Throwable $error) {
                report($error);
            }

            return $rows;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }

    /** A single eligibility check does not require loading every keyword or capacity usage. */
    private function prepareEligibility(int $siteId): void
    {
        if ($this->eligibilitySite === $siteId) {
            return;
        }
        $this->eligibilitySite = $siteId;
        $this->preparedSite = null;
        $this->pools = $this->bindings = $this->groupMembers = [];
        $this->contentSpecific = false;
        $site = DB::table('sites')->find($siteId);
        $this->locale = $site->locale;
        $this->displayNamePoolIds = FanmuluSettings::fromSite($site)['display_name_pool_ids'] ?? [];
        $groupIds = [];
        foreach (DB::table('keyword_bindings')->where(['site_id' => $siteId, 'status' => 'active'])->orderByDesc('priority')->orderBy('id')->get() as $binding) {
            $this->bindings[$binding->pool_id][] = $binding;
            $this->contentSpecific = $this->contentSpecific || $binding->content_item_id !== null;
            if ($binding->group_id !== null) {
                $groupIds[] = $binding->group_id;
            }
        }
        $poolIds = array_values(array_unique([...array_keys($this->bindings), ...$this->displayNamePoolIds]));
        foreach (DB::table('keyword_pools')->whereIn('id', $poolIds)->get() as $pool) {
            $this->pools[$pool->id] = $pool;
        }
        if ($groupIds !== []) {
            foreach (DB::table('keyword_group_members as m')->join('keyword_groups as g', 'g.id', '=', 'm.group_id')
                ->whereIn('m.pool_id', $poolIds)->whereIn('m.group_id', $groupIds)->where('g.status', 'active')->whereNull('g.deleted_at')
                ->select('m.group_id', 'm.keyword_id')->get() as $member) {
                $this->groupMembers[$member->group_id][$member->keyword_id] = true;
            }
        }
    }

    private function prepareUsagePages(array $pageIds): void
    {
        if ($this->cappedKeywordIds === []) {
            return;
        }
        $pageIds = array_values(array_filter(array_unique(array_map('intval', $pageIds)), fn ($pageId) => ! isset($this->checkedUsagePages[$pageId])));
        foreach (app(KeywordService::class)->usagePageMembership($this->preparedSite, $this->cappedKeywordIds, $pageIds) as $keywordId => $pages) {
            $this->usagePages[$keywordId] = ($this->usagePages[$keywordId] ?? []) + $pages;
        }
        foreach ($pageIds as $pageId) {
            $this->checkedUsagePages[$pageId] = true;
        }
    }

    public function eligible(object $keyword, object $page, array $content, array $policy): bool
    {
        $this->prepareEligibility($page->site_id);
        if ($keyword->status !== 'active' || $keyword->deleted_at || $keyword->locale !== $this->locale) {
            return false;
        }
        $pool = $this->pools[$keyword->pool_id] ?? null;
        if (! $pool || $pool->status !== 'active' || $pool->deleted_at || ($pool->scope === 'site' && $pool->owner_site_id !== $page->site_id)) {
            return false;
        }
        if (! $this->filters(Json::decode($keyword->eligibility_json), $page, $content) || ! $this->filters($policy['filters'] ?? [], $page, $content)) {
            return false;
        }
        if (($policy['_purpose'] ?? null) === 'fanmulu_title') {
            return $keyword->role === 'primary' && in_array((int) $keyword->pool_id, $this->displayNamePoolIds, true);
        }
        if (in_array((int) $keyword->pool_id, $this->displayNamePoolIds, true)) {
            return false;
        }
        $bindings = $this->bindings[$keyword->pool_id] ?? [];
        foreach ($bindings as $binding) {
            if ($binding->page_type !== null && $binding->page_type !== $page->page_type) {
                continue;
            }
            if ($binding->category_id !== null && $binding->category_id !== $page->category_id) {
                continue;
            }
            if ($binding->content_item_id !== null && $binding->content_item_id !== $page->content_item_id) {
                continue;
            }
            if ($binding->group_id !== null && ! isset($this->groupMembers[$binding->group_id][$keyword->id])) {
                continue;
            }
            if ($this->filters(Json::decode($binding->filters_json), $page, $content)) {
                return true;
            }
        }

        return false;
    }

    /** Name selection is separate from normal website SEO pool bindings. */
    public function eligibleDisplayName(object $keyword, object $page, array $content): bool
    {
        return $this->eligible($keyword, $page, $content, ['_purpose' => 'fanmulu_title']);
    }

    /** @return list<object> Raw, unmodified rows for the selected first-visit fingerprint. */
    public function selectedRows(array $ids): array
    {
        sort($ids, SORT_NUMERIC);

        return array_map(fn ($id) => $this->keywordRows[$id], $ids);
    }

    public function prime(object $page, array $content, array $resolved): void
    {
        $this->prepare($page->site_id, checksCapacity: ($page->page_kind ?? 'regular') !== 'fanmulu');
        $this->prepareUsagePages([$page->id]);
        foreach (['primary', 'secondary', 'anchor', 'fanmulu_title'] as $slot) {
            $policy = $resolved['slots']['allocation.'.$slot] ?? false;
            if (! is_array($policy) || ($policy['enabled'] ?? true) === false) {
                continue;
            }
            $role = $slot === 'fanmulu_title' ? 'primary' : $slot;
            if ($slot === 'fanmulu_title') {
                $policy += ['_purpose' => 'fanmulu_title'];
            }
            $this->baseCandidates($role, $policy, $page, $content);
        }
    }

    private function baseCandidates(string $role, array $policy, object $page, array $content): array
    {
        $cacheKey = Json::hash([$role, $policy, $page->page_type, $page->category_id, $this->contentSpecific ? $page->content_item_id : null, $content['genre'] ?? null, $content['kind'] ?? null, $content['intent'] ?? null, $content['tags'] ?? []]);
        if (! isset($this->candidateCache[$cacheKey])) {
            $baseCandidates = [];
            $dedup = [];
            $orderedPools = array_merge(array_map(fn ($id) => [$id, false], $policy['pool_ids'] ?? []), array_map(fn ($id) => [$id, true], $policy['fallback_pool_ids'] ?? []));
            foreach ($orderedPools as [$poolId, $fallback]) {
                foreach ($this->keywords[$poolId][$role] ?? [] as $keyword) {
                    if (isset($dedup[$keyword->normalized_text]) || ! $this->eligible($keyword, $page, $content, $policy)) {
                        continue;
                    }
                    $dedup[$keyword->normalized_text] = true;
                    $candidate = clone $keyword;
                    $candidate->_fallback = $fallback;
                    $baseCandidates[] = $candidate;
                }
            }
            $this->candidateCache[$cacheKey] = $baseCandidates;
        }

        return $this->candidateCache[$cacheKey];
    }

    private function filters(array $filters, object $page, array $content): bool
    {
        $fields = ['genres' => $content['genre'] ?? null, 'kinds' => $content['kind'] ?? $page->page_type, 'page_types' => $page->page_type, 'intents' => $content['intent'] ?? null, 'tags' => $content['tags'] ?? []];
        foreach ($fields as $key => $value) {
            if (! empty($filters[$key])) {
                $allowed = (array) $filters[$key];
                $actual = is_array($value) ? $value : ($value === null ? [] : [$value]);
                if (! array_intersect($allowed, $actual)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function allocate(object $page, array $content, array $resolved, string $seed, string $action, array $options, array &$simulation, array &$sequential, bool $readOnly = false): array
    {
        $this->prepare($page->site_id, checksCapacity: ($page->page_kind ?? 'regular') !== 'fanmulu');
        if (isset($resolved['slots']['allocation.fanmulu_title']) && ! isset($options['_assignment_purpose'])) {
            $nameResolved = $resolved;
            $nameResolved['slots']['allocation.primary'] = $resolved['slots']['allocation.fanmulu_title'] + ['_purpose' => 'fanmulu_title'];
            $nameResolved['sources']['allocation.primary'] = $resolved['sources']['allocation.fanmulu_title'];
            $nameResolved['slots']['allocation.secondary'] = false;
            $nameResolved['slots']['allocation.anchor'] = false;
            unset($nameResolved['slots']['allocation.fanmulu_title']);
            $nameOptions = $options + ['_assignment_purpose' => 'fanmulu_title'];
            unset($nameOptions['manual'][$page->id]['primary']);
            $names = $this->allocate($page, $content, $nameResolved, $seed, 'publish', $nameOptions, $simulation, $sequential, $readOnly);
            $seoResolved = $resolved;
            unset($seoResolved['slots']['allocation.fanmulu_title']);
            $seoOptions = $options + ['_assignment_purpose' => 'seo', '_excluded_keyword_ids' => array_column($names, 'keyword_id')];
            $seo = $this->allocate($page, $content, $seoResolved, $seed, $action, $seoOptions, $simulation, $sequential, $readOnly);

            return [...$names, ...$seo];
        }
        $purpose = $options['_assignment_purpose'] ?? 'seo';
        if ($this->cappedKeywordIds !== []) {
            $usagePages = [$page->id => $page->id];
            foreach ($simulation as $key => $pages) {
                if (str_starts_with((string) $key, $page->site_id.':')) {
                    foreach ($pages as $pageId) {
                        $usagePages[$pageId] = $pageId;
                    }
                }
            }
            $this->prepareUsagePages(array_values($usagePages));
        }
        $existing = $page->current_publication_id ? DB::table('page_keyword_assignments')->where('publication_id', $page->current_publication_id)->orderBy('ordinal')->get() : collect();
        if (isset($options['_assignment_purpose'])) {
            $selectedNameId = Json::decode($page->input_snapshot_json)['fanmulu']['selected_primary']['keyword_id'] ?? null;
            $existing = $existing->filter(function ($assignment) use ($purpose, $selectedNameId): bool {
                $actual = $assignment->purpose ?? 'seo';
                // A legacy fanmulu primary pin becomes the display assignment only
                // when a new separated publication is explicitly prepared.
                if ($assignment->role === 'primary' && $selectedNameId !== null && (int) $assignment->keyword_id === (int) $selectedNameId) {
                    $actual = 'fanmulu_title';
                }

                return $actual === $purpose;
            });
        }
        $result = [];
        $texts = [];
        foreach (['primary', 'secondary', 'anchor'] as $role) {
            $policy = $resolved['slots']['allocation.'.$role] ?? false;
            if ($policy === false || ($policy['enabled'] ?? true) === false) {
                continue;
            }
            $count = min($role === 'primary' ? 1 : 20, (int) ($policy['count'] ?? 1));
            $selectedIds = [];
            $ordinals = [];
            $keep = $existing->where('role', $role);
            $keepMode = ($action !== 'reallocate' || $page->assignment_locked);
            if ($keepMode) {
                foreach ($keep as $assignment) {
                    if ($role !== 'anchor' && isset($texts[$assignment->text_snapshot])) {
                        continue;
                    }
                    $selection = Json::decode($assignment->selection_json);
                    $selection['retained'] = true;
                    $retained = ['keyword_id' => $assignment->keyword_id, 'role' => $role, 'ordinal' => $assignment->ordinal, 'text' => $assignment->text_snapshot, 'target_url' => $assignment->target_url_snapshot, 'selection' => $selection];
                    if ($purpose === 'fanmulu_title' || ($assignment->purpose ?? 'seo') === 'fanmulu_title') {
                        $retained['purpose'] = 'fanmulu_title';
                    }
                    $result[] = $retained;
                    $selectedIds[$assignment->keyword_id] = true;
                    $ordinals[$assignment->ordinal] = true;
                    if ($role !== 'anchor') {
                        $texts[$assignment->text_snapshot] = true;
                    }
                }
            }
            $already = count(array_filter($result, fn ($a) => $a['role'] === $role));
            if ($page->assignment_locked && $already < $count && ($policy['required'] ?? false)) {
                throw new Conflict('ASSIGNMENT_LOCKED_MISSING');
            }
            if ($page->assignment_locked || $already >= $count) {
                continue;
            }
            $candidates = [];
            foreach ($this->baseCandidates($role, $policy, $page, $content) as $keyword) {
                if (isset($selectedIds[$keyword->id]) || in_array((int) $keyword->id, $options['_excluded_keyword_ids'] ?? [], true) || ($role !== 'anchor' && isset($texts[$keyword->normalized_text]))) {
                    continue;
                }
                $cap = array_key_exists($keyword->id, $this->limits) ? $this->limits[$keyword->id] : $keyword->default_max_active_pages;
                if ($this->checksCapacity && $cap !== null) {
                    $simulated = array_fill_keys($simulation[$page->site_id.':'.$keyword->id] ?? [], true);
                    $counted = isset($this->usagePages[$keyword->id][$page->id]) || isset($simulated[$page->id]);
                    $used = $this->usageCounts[$keyword->id] ?? 0;
                    foreach ($simulated as $pageId => $_) {
                        if (! isset($this->usagePages[$keyword->id][$pageId])) {
                            $used++;
                        }
                    }
                    if (! $counted && $used >= $cap) {
                        continue;
                    }
                }
                $candidates[] = $keyword;
            }
            $mode = $policy['mode'] ?? 'weighted';
            $policyKey = null;
            if ($mode === 'manual') {
                $manual = $options['manual'][$page->id][$role] ?? $policy['keyword_ids'] ?? $policy['manual_ids'] ?? [];
                $manual = is_array($manual) ? $manual : [$manual];
                $ids = array_unique(array_map('intval', $manual));
                $ordered = [];
                foreach ($ids as $id) {
                    if (isset($selectedIds[$id])) {
                        continue;
                    }
                    $match = collect($candidates)->firstWhere('id', $id);
                    if (! $match) {
                        throw new Conflict('MANUAL_KEYWORD_INELIGIBLE', (string) $id);
                    }$ordered[] = $match;
                } $candidates = $ordered;
            } elseif ($mode === 'sequential') {
                $policyKey = Json::hash([$page->site_id, $role, $resolved['sources']['allocation.'.$role], $policy]);
                if (! isset($sequential[$policyKey])) {
                    $start = $options['_sequential_start'][$policyKey] ?? null;
                    if ($start === null) {
                        if (! $readOnly) {
                            DB::table('allocation_state')->insertOrIgnore(['policy_key' => $policyKey]);
                        }
                        $state = DB::table('allocation_state')->where('policy_key', $policyKey)->first();
                        $start = ['state_id' => $state?->id, 'start' => $state?->cursor_position ?? 0,
                            'end' => $state?->cursor_position ?? 0, 'cursor_revision' => $state?->cursor_revision ?? 0];
                    }
                    $sequential[$policyKey] = $start;
                }
                if ($candidates) {
                    $primary = array_values(array_filter($candidates, fn ($k) => ! $k->_fallback));
                    $fallback = array_values(array_filter($candidates, fn ($k) => $k->_fallback));
                    if ($primary) {
                        $offset = $sequential[$policyKey]['end'] % count($primary);
                        $candidates = array_merge(array_slice($primary, $offset), array_slice($primary, 0, $offset), $fallback);
                    } else {
                        $offset = $sequential[$policyKey]['end'] % count($fallback);
                        $candidates = array_merge(array_slice($fallback, $offset), array_slice($fallback, 0, $offset));
                    }
                }
            } else {
                $scores = [];
                foreach ($candidates as $candidate) {
                    $hex = substr(hash_hmac('sha256', $page->id.':'.$role.':'.$candidate->id, $seed), 0, 13);
                    $u = (hexdec($hex) + 1) / (4503599627370496 + 1);
                    $scores[$candidate->id] = -log($u) / ($mode === 'weighted' ? $candidate->weight : 1);
                }
                usort($candidates, function ($a, $b) use ($scores) {
                    if ($a->_fallback !== $b->_fallback) {
                        return $a->_fallback <=> $b->_fallback;
                    }

                    return $scores[$a->id] <=> $scores[$b->id] ?: $a->id <=> $b->id;
                });
            }
            for ($filled = $already; $filled < $count; $filled++) {
                $keyword = array_shift($candidates);
                if (! $keyword) {
                    if ($policy['required'] ?? false) {
                        throw new Conflict('NO_ELIGIBLE_KEYWORD', $role);
                    } break;
                }
                $ordinal = 0;
                while (isset($ordinals[$ordinal])) {
                    $ordinal++;
                }
                $selection = ['mode' => $mode, 'algorithm_version' => 'rendezvous-v1', 'seed' => $seed, 'pool_id' => $keyword->pool_id, 'fallback' => $keyword->_fallback ?? false, 'rule_source' => $resolved['sources']['allocation.'.$role], 'reason' => $this->checksCapacity ? 'site/locale/binding/filters/cap matched' : 'site/locale/binding/filters matched', 'keyword_version' => $keyword->row_version];
                if ($policyKey) {
                    $selection['policy_key'] = $policyKey;
                    $selection['ordinal'] = $sequential[$policyKey]['end']++;
                }
                $allocated = ['keyword_id' => $keyword->id, 'role' => $role, 'ordinal' => $ordinal, 'text' => $keyword->normalized_text, 'target_url' => $keyword->target_url, 'priority' => $keyword->priority, 'selection' => $selection];
                if ($purpose === 'fanmulu_title') {
                    $allocated['purpose'] = $purpose;
                }
                $result[] = $allocated;
                $selectedIds[$keyword->id] = true;
                $ordinals[$ordinal] = true;
                if ($role !== 'anchor') {
                    $texts[$keyword->normalized_text] = true;
                }
                if ($this->checksCapacity) {
                    $simulation[$page->site_id.':'.$keyword->id][] = $page->id;
                }
            }
        }

        return $result;
    }
}
