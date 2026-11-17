<?php

namespace App\Domain\Keywords;

use App\Domain\Rules\Html;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class KeywordService
{
    public static function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('UTF-8 không hợp lệ');
        }
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if ($text === false) {
            throw new \InvalidArgumentException('Unicode không hợp lệ');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', $text)) {
            throw new \InvalidArgumentException('Ký tự điều khiển không hợp lệ');
        }
        $text = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text));
        if (mb_strlen($text) > 255) {
            throw new \InvalidArgumentException('Keyword vượt 255 ký tự');
        }

        return $text;
    }

    public static function parse(string $input, string $format = 'txt'): array
    {
        if (! mb_check_encoding($input, 'UTF-8')) {
            throw new \InvalidArgumentException('UTF-8 không hợp lệ');
        }
        $input = preg_replace('/^\x{FEFF}/u', '', $input);
        if ($format === 'json') {
            $data = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            $rows = is_array($data) ? ($data['keywords'] ?? $data['records'] ?? $data) : null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new \InvalidArgumentException('JSON cần danh sách keyword hoặc object có keywords/records là danh sách');
            }

            return $rows;
        }
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $input);
            rewind($stream);
            $headers = fgetcsv($stream, 0, ',', '"', '');
            $rows = [];
            if (! $headers || ! in_array('text', $headers)) {
                throw new \InvalidArgumentException('CSV cần cột text');
            }
            while (($line = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $rows[] = $line === [null] ? ['text' => ''] : (count($line) === count($headers) ? array_combine($headers, $line) : ['_error' => 'Số cột không khớp']);
            }
            fclose($stream);

            return $rows;
        }

        return array_map(fn ($text) => ['text' => $text], preg_split('/\r\n|\r|\n/', $input));
    }

    public function import(int $poolId, array $rows, array $defaults = [], bool $dryRun = false, array $previousSeen = []): array
    {
        if (count($rows) > 100000) {
            throw new \InvalidArgumentException('Tối đa 100.000 dòng mỗi import');
        }
        $pool = DB::table('keyword_pools')->find($poolId);
        if (! $pool) {
            abort(404);
        }
        if (($pool->deleted_at ?? null) !== null) {
            throw new Conflict('POOL_DELETED_RESTORE_FIRST');
        }
        if ($pool->status === 'archived') {
            throw new Conflict('POOL_ARCHIVED');
        }
        $report = ['new' => 0, 'duplicate' => 0, 'blank' => 0, 'restored' => 0, 'error' => 0, 'rows' => []];
        $seen = array_fill_keys($previousSeen, true);
        $perform = function () use ($poolId, $pool, $rows, $defaults, $dryRun, &$report, &$seen) {
            if (! $dryRun) {
                DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
                $pool = DB::table('keyword_pools')->where('id', $poolId)->lockForUpdate()->first();
                abort_unless($pool, 404);
                if (($pool->deleted_at ?? null) !== null) {
                    throw new Conflict('POOL_DELETED_RESTORE_FIRST');
                }
                if ($pool->status === 'archived') {
                    throw new Conflict('POOL_ARCHIVED');
                }
            }
            foreach ($rows as $index => $raw) {
                try {
                    if (! is_string($raw) && ! is_array($raw)) {
                        throw new \InvalidArgumentException('Dòng keyword phải là chuỗi hoặc object');
                    }
                    $raw = is_string($raw) ? ['text' => $raw] : $raw;
                    if (isset($raw['_error'])) {
                        throw new \InvalidArgumentException(is_string($raw['_error']) ? $raw['_error'] : 'Dòng dữ liệu không hợp lệ');
                    }
                    $row = array_merge($defaults, $raw);
                    $original = $row['text'] ?? $row['original_text'] ?? '';
                    if (! is_string($original)) {
                        throw new \InvalidArgumentException('Text keyword phải là chuỗi');
                    }$text = self::normalize($original);
                    if ($text === '') {
                        $report['blank']++;
                        $report['rows'][] = ['line' => $index + 1, 'text' => '', 'status' => 'blank'];

                        continue;
                    }
                    $role = $row['role'] ?? 'primary';
                    $locale = $row['locale'] ?? $pool->locale;
                    if (! in_array($role, ['primary', 'secondary', 'anchor'])) {
                        throw new \InvalidArgumentException('Role không hợp lệ');
                    }
                    if (! is_string($locale) || ! preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', $locale)) {
                        throw new \InvalidArgumentException('Locale không hợp lệ');
                    }
                    $weight = $row['weight'] ?? 1;
                    if (! filter_var($weight, FILTER_VALIDATE_INT) || (int) $weight < 1) {
                        throw new \InvalidArgumentException('Weight phải là số nguyên dương');
                    }
                    $priority = $row['priority'] ?? 0;
                    if (filter_var($priority, FILTER_VALIDATE_INT) === false) {
                        throw new \InvalidArgumentException('Priority phải là số nguyên');
                    }
                    $recordStatus = $row['status'] ?? 'active';
                    if (! in_array($recordStatus, ['active', 'paused', 'archived'])) {
                        throw new \InvalidArgumentException('Status không hợp lệ');
                    }
                    $cap = $row['max_active_pages'] ?? $row['default_max_active_pages'] ?? null;
                    if ($cap === '') {
                        $cap = null;
                    }
                    if ($cap !== null && (filter_var($cap, FILTER_VALIDATE_INT) === false || (int) $cap < 0)) {
                        throw new \InvalidArgumentException('Cap phải >= 0 hoặc trống');
                    }
                    $target = $row['target_url'] ?? null;
                    if ($target === '') {
                        $target = null;
                    }
                    if ($target !== null && ! is_string($target)) {
                        throw new \InvalidArgumentException('Target URL phải là chuỗi');
                    }
                    if (($role === 'anchor' || $target !== null) && ! Html::safeUrl((string) $target, true)) {
                        throw new \InvalidArgumentException('Anchor cần URL HTTP(S) hoặc đường dẫn nội bộ hợp lệ');
                    }
                    $filters = $row['eligibility_json'] ?? [];
                    if (is_string($filters)) {
                        $filters = Json::decode($filters);
                    }
                    if (! is_array($filters)) {
                        throw new \InvalidArgumentException('Eligibility phải là object');
                    }
                    if (! empty($row['genres'])) {
                        if (! is_array($row['genres']) && ! is_string($row['genres'])) {
                            throw new \InvalidArgumentException('Genres phải là danh sách hoặc chuỗi phân cách bằng |');
                        }
                        $filters['genres'] = is_array($row['genres']) ? $row['genres'] : explode('|', $row['genres']);
                    }
                    self::validateFilters($filters);
                    $groupNames = $row['groups'] ?? [];
                    if (is_string($groupNames)) {
                        $groupNames = explode('|', $groupNames);
                    }
                    if (! is_array($groupNames)) {
                        throw new \InvalidArgumentException('Nhóm keyword phải là danh sách tên hợp lệ');
                    }
                    if (! empty($row['group_key']) || ! empty($row['group'])) {
                        $groupNames[] = $row['group_key'] ?? $row['group'];
                    }
                    if (! array_is_list($groupNames) || count($groupNames) > 50 || count(array_filter($groupNames, fn ($n) => ! is_string($n) || mb_strlen($n) > 200))) {
                        throw new \InvalidArgumentException('Nhóm keyword phải là danh sách tên hợp lệ');
                    }
                    $groupNames = array_values(array_unique(array_filter(array_map(fn ($name) => self::normalize($name), $groupNames), fn ($name) => $name !== '')));
                    $key = Json::hash([$role, $locale, $text]);
                    $existing = DB::table('keywords')->where(['pool_id' => $poolId, 'role' => $role, 'locale' => $locale, 'normalized_text' => $text])->first();
                    $state = isset($seen[$key]) ? 'duplicate' : ($existing ? ($existing->deleted_at || $existing->status === 'archived' ? 'restored' : 'duplicate') : 'new');
                    if ($state === 'new' && DB::table('keyword_groups')->where('pool_id', $poolId)->whereIn('name', array_unique($groupNames))->whereNotNull('deleted_at')->exists()) {
                        throw new \InvalidArgumentException('Nhóm keyword đã bị xóa. Hãy khôi phục nhóm trước khi thêm keyword mới.');
                    }
                    $seen[$key] = true;
                    $report[$state]++;
                    if (! $dryRun && $state === 'new') {
                        $id = DB::table('keywords')->insertGetId(['pool_id' => $poolId, 'role' => $role, 'locale' => $locale, 'original_text' => $original, 'normalized_text' => $text, 'weight' => (int) $weight, 'priority' => (int) $priority, 'status' => $recordStatus, 'default_max_active_pages' => $cap, 'target_url' => $target, 'eligibility_json' => Json::encode($filters)]);
                        foreach (array_unique($groupNames) as $groupName) {
                            if (! is_string($groupName) || $groupName === '') {
                                continue;
                            }
                            DB::table('keyword_groups')->insertOrIgnore(['pool_id' => $poolId, 'name' => $groupName]);
                            $groupId = DB::table('keyword_groups')->where(['pool_id' => $poolId, 'name' => $groupName])->value('id');
                            if (DB::table('keyword_group_members')->insertOrIgnore(['pool_id' => $poolId, 'group_id' => $groupId, 'keyword_id' => $id])) {
                                DB::table('keyword_groups')->where('id', $groupId)->increment('row_version');
                            }
                        }
                    } elseif (! $dryRun && $state === 'restored') {
                        DB::table('keywords')->where('id', $existing->id)->update(['status' => 'active', 'deleted_at' => null, 'row_version' => DB::raw('row_version+1')]);
                    }
                    $report['rows'][] = ['line' => $index + 1, 'text' => $text, 'status' => $state, 'identity_key' => $key];
                } catch (\InvalidArgumentException|\JsonException $e) {
                    $report['error']++;
                    $report['rows'][] = ['line' => $index + 1, 'status' => 'error', 'error' => $e->getMessage()];
                }
            }
            if (! $dryRun && ($report['new'] || $report['restored'])) {
                DB::table('keyword_pools')->where('id', $poolId)->increment('row_version');
                DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            }

            return $report;
        };

        return $dryRun ? $perform() : DB::transaction($perform, 3);
    }

    public function usage(int $siteId, int $keywordId): array
    {
        $rows = $this->usageRows($siteId, [$keywordId])->get();
        $live = $rows->where('is_live', 1)->pluck('page_id')->all();
        $held = $rows->where('is_held', 1)->pluck('page_id')->all();

        return ['live' => count(array_unique($live)), 'held' => count(array_unique($held)), 'total' => count(array_unique(array_merge($live, $held))), 'page_ids' => array_values(array_unique(array_merge($live, $held)))];
    }

    /**
     * Exact capacity totals with membership limited to the requested pages.
     * Expired held claims remain counted until the fenced reconciler releases them.
     *
     * @param  array<int>  $keywordIds
     * @param  array<int>  $pageIds
     * @return array<int, array{live: int, held: int, total: int, page_ids: array<int>}>
     */
    public function usageSummaries(int $siteId, array $keywordIds, array $pageIds = []): array
    {
        $keywordIds = array_values(array_unique(array_map('intval', $keywordIds)));
        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        $result = array_fill_keys($keywordIds, ['live' => 0, 'held' => 0, 'total' => 0, 'page_ids' => []]);
        foreach (array_chunk($keywordIds, 2000) as $chunk) {
            $pairs = DB::query()->fromSub($this->usageRows($siteId, $chunk), 'usage_rows')
                ->select('keyword_id', 'page_id')
                ->selectRaw('MAX(is_live) AS is_live, MAX(is_held) AS is_held')
                ->groupBy('keyword_id', 'page_id');
            $membership = $pageIds === [] ? "'[]'::json" : 'COALESCE(JSON_AGG(page_id ORDER BY page_id) FILTER (WHERE page_id IN ('.implode(',', $pageIds).")), '[]'::json)";
            $rows = DB::query()->fromSub($pairs, 'usage_pairs')->select('keyword_id')
                ->selectRaw('SUM(is_live) AS live, SUM(is_held) AS held, COUNT(*) AS total')
                ->selectRaw($membership.' AS page_ids')->groupBy('keyword_id')->get();
            foreach ($rows as $row) {
                $result[$row->keyword_id] = ['live' => (int) $row->live, 'held' => (int) $row->held,
                    'total' => (int) $row->total, 'page_ids' => Json::decode($row->page_ids)];
            }
        }

        return $result;
    }

    /** @return array{live: int, held: int, total: int, page_counted: bool} */
    public function usageSummary(int $siteId, int $keywordId, ?int $pageId = null): array
    {
        $summary = $this->usageSummaries($siteId, [$keywordId], $pageId === null ? [] : [$pageId])[$keywordId];

        return ['live' => $summary['live'], 'held' => $summary['held'], 'total' => $summary['total'],
            'page_counted' => $pageId !== null && in_array($pageId, $summary['page_ids'], true)];
    }

    /**
     * Membership projection for the current preview and its simulation, never the
     * entire site's page identifiers. Totals are prepared separately by the allocator.
     *
     * @param  array<int>  $keywordIds
     * @param  array<int>  $pageIds
     * @return array<int, array<int, true>>
     */
    public function usagePageMembership(int $siteId, array $keywordIds, array $pageIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $keywordIds))), 2000) as $keywords) {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $pageIds))), 1000) as $pages) {
                foreach (DB::query()->fromSub($this->usageRows($siteId, $keywords, $pages), 'usage_rows')
                    ->distinct()->get(['keyword_id', 'page_id']) as $pair) {
                    $result[$pair->keyword_id][$pair->page_id] = true;
                }
            }
        }

        return $result;
    }

    /** @param array<int> $keywordIds @param array<int>|null $pageIds */
    private function usageRows(int $siteId, array $keywordIds, ?array $pageIds = null): Builder
    {
        $live = DB::table('pages as p')->join('sites as s', 's.id', '=', 'p.site_id')
            ->join('page_keyword_assignments as a', fn ($join) => $join->on('a.page_id', '=', 'p.id')->on('a.publication_id', '=', 'p.current_publication_id'))
            ->where(['p.site_id' => $siteId, 'p.status' => 'live', 's.status' => 'active'])->where('p.page_kind', '<>', 'fanmulu')
            ->whereIn('a.keyword_id', $keywordIds)->select('a.keyword_id', 'p.id as page_id')
            ->selectRaw('1 AS is_live, 0 AS is_held');
        $held = DB::table('keyword_capacity_claims as c')->join('pages as p', 'p.id', '=', 'c.page_id')
            ->where(['c.site_id' => $siteId, 'c.status' => 'held'])->where('p.page_kind', '<>', 'fanmulu')
            ->whereIn('c.keyword_id', $keywordIds)->select('c.keyword_id', 'c.page_id')->selectRaw('0 AS is_live, 1 AS is_held');
        if ($pageIds !== null) {
            $live->whereIn('p.id', $pageIds);
            $held->whereIn('c.page_id', $pageIds);
        }

        return $live->unionAll($held);
    }

    public function update(int $keywordId, array $changes, int $actorId): object
    {
        $initial = DB::table('keywords')->find($keywordId);
        if (! $initial) {
            abort(404);
        }$pool = DB::table('keyword_pools')->find($initial->pool_id);
        if (! DB::table('users')->where('id', $actorId)->value('is_global_admin')) {
            if ($pool->scope !== 'site') {
                abort(403);
            }Access::site($actorId, $pool->owner_site_id, true);
        }

        return DB::transaction(function () use ($keywordId, $changes, $actorId, $pool) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $lockedPool = DB::table('keyword_pools')->where('id', $pool->id)->lockForUpdate()->first();
            if (($lockedPool->deleted_at ?? null) !== null) {
                throw new Conflict('POOL_DELETED_RESTORE_FIRST');
            }
            $keyword = DB::table('keywords')->where('id', $keywordId)->lockForUpdate()->first();
            if ($keyword->deleted_at !== null) {
                throw new Conflict('KEYWORD_DELETED_RESTORE_FIRST');
            }
            if (isset($changes['row_version']) && $keyword->row_version !== (int) $changes['row_version']) {
                throw new Conflict('KEYWORD_VERSION_CONFLICT');
            }
            $data = array_intersect_key($changes, array_flip(['weight', 'priority', 'status', 'default_max_active_pages', 'target_url', 'eligibility_json', 'locale']));
            if (array_key_exists('locale', $data) && (! is_string($data['locale']) || strlen($data['locale']) > 16 || ! preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', $data['locale']))) {
                throw new \InvalidArgumentException('Locale không hợp lệ');
            }
            if (isset($data['weight']) && (! is_numeric($data['weight']) || (int) $data['weight'] < 1 || (string) (int) $data['weight'] !== (string) $data['weight'])) {
                throw new \InvalidArgumentException('Weight phải là số nguyên dương');
            }
            if (isset($data['priority']) && filter_var($data['priority'], FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException('Priority phải là số nguyên');
            }
            if (isset($data['status']) && ! in_array($data['status'], ['active', 'paused', 'archived'])) {
                throw new \InvalidArgumentException('Status không hợp lệ');
            }
            if (array_key_exists('default_max_active_pages', $data) && $data['default_max_active_pages'] !== null && (filter_var($data['default_max_active_pages'], FILTER_VALIDATE_INT) === false || (int) $data['default_max_active_pages'] < 0)) {
                throw new \InvalidArgumentException('Cap phải >=0 hoặc null');
            }
            if (isset($data['eligibility_json'])) {
                $filters = is_array($data['eligibility_json']) ? $data['eligibility_json'] : Json::decode($data['eligibility_json']);
                self::validateFilters($filters, Json::decode($keyword->eligibility_json));
                $data['eligibility_json'] = Json::encode($filters);
            }
            $role = $changes['role'] ?? $keyword->role;
            if (! in_array($role, ['primary', 'secondary', 'anchor'])) {
                throw new \InvalidArgumentException('Role không hợp lệ');
            }
            $target = array_key_exists('target_url', $data) ? $data['target_url'] : $keyword->target_url;
            if (($role === 'anchor' || $target !== null) && ! Html::safeUrl((string) $target, true)) {
                throw new \InvalidArgumentException('Anchor cần URL đích hợp lệ');
            }
            $text = $changes['text'] ?? $changes['original_text'] ?? $keyword->original_text;
            $normalized = self::normalize($text);
            if ($normalized === '') {
                throw new \InvalidArgumentException('Keyword không được trống');
            }
            if ($role !== $keyword->role) {
                $row = (array) $keyword;
                unset($row['id'],$row['deleted_at'],$row['created_at'],$row['updated_at'],$row['row_version']);
                $row = array_merge($row, $data, ['text' => $text, 'role' => $role, 'target_url' => $target, 'status' => 'active']);
                $report = $this->import($pool->id, [$row]);
                if ($report['error']) {
                    throw new \InvalidArgumentException($report['rows'][0]['error'] ?? 'Role migration failed');
                }
                $targetKeyword = DB::table('keywords')->where(['pool_id' => $pool->id, 'role' => $role, 'locale' => $row['locale'], 'normalized_text' => $normalized])->first();
                if (! $targetKeyword) {
                    throw new Conflict('KEYWORD_ROLE_TARGET_MISSING');
                }
                foreach (DB::table('keyword_group_members')->where('keyword_id', $keywordId)->pluck('group_id') as $groupId) {
                    if (DB::table('keyword_group_members')->insertOrIgnore(['pool_id' => $pool->id, 'group_id' => $groupId, 'keyword_id' => $targetKeyword->id])) {
                        DB::table('keyword_groups')->where('id', $groupId)->increment('row_version');
                    }
                }
                DB::table('keywords')->where('id', $keywordId)->update(['status' => 'archived', 'row_version' => DB::raw('row_version+1')]);
                DB::table('keyword_pools')->where('id', $pool->id)->increment('row_version');
                DB::table('system_settings')->where('id', 1)->increment('config_epoch');
                Access::audit('keyword.role.migrated', $actorId, $pool->owner_site_id, ['from_id' => $keywordId, 'to_id' => $targetKeyword->id]);

                return $targetKeyword;
            }
            if (DB::table('keywords')->where(['pool_id' => $pool->id, 'role' => $role, 'locale' => $data['locale'] ?? $keyword->locale, 'normalized_text' => $normalized])->where('id', '<>', $keywordId)->exists()) {
                throw new Conflict('KEYWORD_TEXT_ALREADY_EXISTS');
            }
            DB::table('keywords')->where('id', $keywordId)->update($data + ['original_text' => $text, 'normalized_text' => $normalized, 'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
            DB::table('keyword_pools')->where('id', $pool->id)->increment('row_version');
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            Access::audit('keyword.version.updated', $actorId, $pool->owner_site_id, ['keyword_id' => $keywordId, 'changes' => array_keys($changes)]);

            return DB::table('keywords')->find($keywordId);
        }, 3);
    }

    public static function spreadsheetCell(string $text): string
    {
        return preg_match('/^[\s]*[=+@-]/u', $text) ? "'".$text : $text;
    }

    public static function validateFilters(array $filters, array $current = []): void
    {
        foreach ($filters as $key => $value) {
            if ($key === 'schema_version' && $value === 1) {
                continue;
            }
            if (! in_array($key, ['genres', 'kinds', 'page_types', 'intents', 'tags']) && array_key_exists($key, $current) && Json::hash($value) === Json::hash($current[$key])) {
                continue;
            }
            if (! in_array($key, ['genres', 'kinds', 'page_types', 'intents', 'tags']) || ! is_array($value) || ! array_is_list($value) || count($value) > 100 || count(array_filter($value, fn ($v) => ! is_string($v) || $v === '' || mb_strlen($v) > 200))) {
                throw new \InvalidArgumentException('Eligibility filter không hợp lệ: '.$key);
            }
        }
    }
}
