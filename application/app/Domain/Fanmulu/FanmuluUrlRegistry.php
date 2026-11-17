<?php

namespace App\Domain\Fanmulu;

use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Support\SchemaAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** URL identity survives configuration/key rotations; this table never allocates pages or keywords. */
final class FanmuluUrlRegistry
{
    public static function normalizeRule(array $rule): array
    {
        $hasIssuedOn = array_key_exists('issued_on', $rule);
        $issuedOn = $rule['issued_on'] ?? null;
        unset($rule['issued_on']);
        $rule = FanmuluSettings::normalizeUrlRule($rule + ['mode' => 'random']);
        if (! in_array($rule['mode'], ['random', 'pattern', 'tokens'], true)) {
            throw new \InvalidArgumentException('Quy tắc URL ngẫu nhiên không hợp lệ.');
        }
        if ($hasIssuedOn) {
            if (! self::requiresDate($rule) || ! is_string($issuedOn)
                || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $issuedOn, $parts)
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new \InvalidArgumentException('Ngày đã ghim của URL không hợp lệ.');
            }
            $rule['issued_on'] = $issuedOn;
        }

        return $rule;
    }

    /** Capture a real calendar day once; pure candidate generation never reads the clock. */
    public static function freezeRule(array $rule, ?string $issuedOn = null): array
    {
        $rule = self::normalizeRule($rule);
        if (self::requiresDate($rule) && ! array_key_exists('issued_on', $rule)) {
            $rule['issued_on'] = $issuedOn ?? CarbonImmutable::now('Asia/Shanghai')->format('Y-m-d');
        }

        return self::normalizeRule($rule);
    }

    public static function requiresDate(array $rule): bool
    {
        if (($rule['mode'] ?? null) === 'pattern') {
            return true;
        }
        if (($rule['mode'] ?? null) !== 'tokens') {
            return false;
        }

        foreach (FanmuluSettings::templateParts($rule['template']) as $part) {
            if ($part['type'] === 'date') {
                return true;
            }
        }

        return false;
    }

    public static function candidate(string $identity, array $rule, string $prefix, string $suffix, int $attempt = 0): string
    {
        $rule = self::normalizeRule($rule);
        [$prefix, $suffix] = self::location($prefix, $suffix);
        if ($identity === '' || strlen($identity) > 16384 || $attempt < 0 || $attempt >= 64) {
            throw new \InvalidArgumentException('Định danh URL hoặc lần thử không hợp lệ.');
        }
        if (self::requiresDate($rule) && ! isset($rule['issued_on'])) {
            throw new \InvalidArgumentException('URL có ngày cần ngày đã được ghim trước khi sinh đường dẫn.');
        }
        if ($rule['mode'] === 'tokens') {
            $date = isset($rule['issued_on']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $rule['issued_on']) : null;
            $parts = FanmuluSettings::templateParts($rule['template']);
            for ($nonce = 0; $nonce < 64; $nonce++) {
                $key = '';
                $ordinal = 0;
                $safeIdentity = $nonce === 0 ? $identity : $identity."\0scope\0".$nonce;
                foreach ($parts as $part) {
                    $key .= match ($part['type']) {
                        'literal' => $part['value'],
                        'date' => $date->format($part['format']),
                        default => self::randomKey($safeIdentity."\0token\0".$ordinal++, $part['length'], $part['type'], $attempt),
                    };
                }
                if (! in_array('_scope', array_map('strtolower', explode('/', $key)), true)) {
                    return $prefix.'/'.$key.$suffix;
                }
            }

            throw new \InvalidArgumentException('Không sinh được URL an toàn từ mẫu quá hẹp; hãy tăng phần ngẫu nhiên.');
        }
        $key = self::randomKey($identity, $rule['length'], $rule['alphabet'], $attempt);
        if ($rule['mode'] === 'random' && $rule['group_length'] > 0) {
            $key = implode($rule['separator'], str_split($key, $rule['group_length']));
        }
        if ($rule['mode'] === 'pattern') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rule['issued_on']);
            $key = $date->format($rule['date_format']).$rule['separator'].$key;
        }

        return $prefix.'/'.$key.$suffix;
    }

    private static function randomKey(string $identity, int $length, string $alphabetType, int $attempt): string
    {
        $alphabet = match ($alphabetType) {
            'letters' => 'abcdefghijklmnopqrstuvwxyz', 'digits' => '0123456789', default => 'abcdefghijklmnopqrstuvwxyz0123456789',
        };
        $key = '';
        for ($block = 0; strlen($key) < $length; $block++) {
            foreach (unpack('C*', hash('sha256', "fanmulu-url\0".$identity."\0".$attempt."\0".$block, true)) as $byte) {
                $key .= $alphabet[$byte % strlen($alphabet)];
                if (strlen($key) === $length) {
                    break;
                }
            }
        }

        return $key;
    }

    public function issue(int $siteId, string $identity, array $rule, string $prefix, string $suffix, array $provenance): string
    {
        return $this->issueBatch($siteId, [compact('identity', 'rule', 'prefix', 'suffix', 'provenance')])[0];
    }

    /** @return list<string> Paths in request order. */
    public function issueBatch(int $siteId, array $requests): array
    {
        return $this->register($siteId, $requests, false);
    }

    /** Register frozen publication paths atomically; a collision must never rewrite its HTML. */
    public function remember(int $siteId, array $requests): array
    {
        return $this->register($siteId, $requests, true);
    }

    public function find(int $siteId, string $path): ?array
    {
        if (! self::safePath($path) || ! app(SchemaAvailability::class)->hasTable('fanmulu_url_registry')) {
            return null;
        }
        $row = DB::table('fanmulu_url_registry')->where(['site_id' => $siteId, 'path' => $path])->first();
        if (! $row) {
            return null;
        }
        $record = $this->decode($row);
        $this->assertPageCompatible($record);

        return $record;
    }

    /** Batch lookup preserves issued choices even after bindings, URL rules, or source data change. */
    public function findIdentities(int $siteId, array $identities): array
    {
        if ($siteId < 1 || $identities === [] || ! app(SchemaAvailability::class)->hasTable('fanmulu_url_registry')) {
            return [];
        }
        $hashes = array_map(fn (string $identity): string => hash('sha256', $identity), $identities);
        $records = [];
        foreach (DB::table('fanmulu_url_registry')->where('site_id', $siteId)->whereIn('identity_hash', $hashes)->get() as $row) {
            $record = $this->decode($row);
            $this->assertPageCompatible($record);
            $records[$row->identity_hash] = $record;
        }

        return $records;
    }

    /** Existing canonical identities may be saved again, but no page may take another reserved URL. */
    public function assertPathWriteAvailable(int $siteId, string $path, ?string $currentPath = null): void
    {
        if ($path === $currentPath || ! app(SchemaAvailability::class)->hasTable('fanmulu_url_registry')) {
            return;
        }
        if (DB::table('fanmulu_url_registry')->where(['site_id' => $siteId, 'path' => $path])->exists()) {
            throw new Conflict('PATH_ALREADY_USED');
        }
    }

    private function register(int $siteId, array $requests, bool $frozen): array
    {
        if ($siteId < 1 || ! array_is_list($requests) || $requests === [] || count($requests) > 120) {
            throw new \InvalidArgumentException('Mỗi lượt đăng ký cần 1–120 URL trong một website.');
        }
        $rows = [];
        $issuedOn = CarbonImmutable::now('Asia/Shanghai')->format('Y-m-d');
        foreach ($requests as $request) {
            $rows[] = $this->row($siteId, $request, $frozen, $issuedOn);
        }
        if (! app(SchemaAvailability::class)->hasTable('fanmulu_url_registry')) {
            throw new Conflict('FANMULU_URL_REGISTRY_UNAVAILABLE');
        }

        return DB::transaction(function () use ($rows, $frozen): array {
            $siteId = $rows[0]['row']['site_id'];
            DB::table('sites')->where('id', $siteId)->sharedLock()->first(['id']);
            $sourceIds = array_values(array_unique(array_column(array_column($rows, 'row'), 'source_page_id')));
            if (DB::table('pages')->where('site_id', $siteId)->whereIn('id', $sourceIds)->where(['page_kind' => 'regular', 'page_type' => 'movie'])->count() !== count($sourceIds)) {
                throw new Conflict('FANMULU_URL_SOURCE_INVALID');
            }
            $keywordIds = array_values(array_unique(array_filter(array_column(array_column($rows, 'row'), 'keyword_id'))));
            if ($keywordIds !== [] && DB::table('keywords')->whereIn('id', $keywordIds)->where('role', 'primary')->count() !== count($keywordIds)) {
                throw new Conflict('FANMULU_URL_KEYWORD_INVALID');
            }
            $hashes = array_column(array_column($rows, 'row'), 'identity_hash');
            $stored = DB::table('fanmulu_url_registry')->where('site_id', $siteId)->whereIn('identity_hash', $hashes)->get()->keyBy('identity_hash');
            $resolved = [];
            $pending = [];
            foreach ($rows as $input) {
                $row = $input['row'];
                $existing = $stored->get($row['identity_hash']);
                if ($existing) {
                    $record = $this->decode($existing);
                    $this->assertReplay($record, $row, $input['pinned_date']);
                    if ($frozen && $record['path'] !== $input['path']) {
                        throw new Conflict('FANMULU_URL_IDENTITY_CONFLICT');
                    }
                    $resolved[$row['identity_hash']] = $record;
                } else {
                    if (isset($pending[$row['identity_hash']])) {
                        $this->assertReplay($this->decode((object) ($pending[$row['identity_hash']]['row'] + ['id' => 0, 'path' => ''])), $row, $input['pinned_date']);
                        if ($frozen && $pending[$row['identity_hash']]['path'] !== $input['path']) {
                            throw new Conflict('FANMULU_URL_IDENTITY_CONFLICT');
                        }

                        continue;
                    }
                    $pending[$row['identity_hash']] = $input;
                }
            }
            for ($attempt = 0; $pending !== [] && $attempt < ($frozen ? 1 : 64); $attempt++) {
                $candidates = [];
                foreach ($pending as $hash => $input) {
                    $row = $input['row'];
                    $candidates[$hash] = $frozen ? $input['path'] : self::candidate($input['identity'], Json::decode($row['rule_json']), $row['prefix'], $row['suffix'], $attempt);
                }
                $occupied = DB::table('pages')->where('site_id', $siteId)->whereIn('canonical_path', $candidates)->pluck('canonical_path')->all();
                $occupied = array_flip([...$occupied, ...DB::table('page_redirects')->where('site_id', $siteId)->whereIn('from_path', $candidates)->pluck('from_path')->all()]);
                $inserts = [];
                foreach ($candidates as $hash => $path) {
                    if (isset($occupied[$path])) {
                        if ($frozen) {
                            throw new Conflict('FANMULU_URL_PATH_COLLISION');
                        }
                    } else {
                        $inserts[] = $pending[$hash]['row'] + ['path' => $path];
                    }
                }
                if ($inserts !== []) {
                    DB::table('fanmulu_url_registry')->insertOrIgnore($inserts);
                    $saved = DB::table('fanmulu_url_registry')->where('site_id', $siteId)->whereIn('identity_hash', array_keys($pending))->get();
                    foreach ($saved as $row) {
                        $record = $this->decode($row);
                        $this->assertReplay($record, $pending[$row->identity_hash]['row'], $pending[$row->identity_hash]['pinned_date']);
                        if ($frozen && $record['path'] !== $candidates[$row->identity_hash]) {
                            throw new Conflict('FANMULU_URL_IDENTITY_CONFLICT');
                        }
                        $resolved[$row->identity_hash] = $record;
                        unset($pending[$row->identity_hash]);
                    }
                }
            }
            if ($pending !== []) {
                throw new Conflict('FANMULU_URL_PATH_COLLISION');
            }
            $pages = DB::table('pages')->where('site_id', $siteId)->whereIn('canonical_path', array_column($resolved, 'path'))->get()->keyBy('canonical_path');
            foreach ($resolved as $record) {
                $this->assertPageCompatible($record, $pages->get($record['path']), false);
            }

            return array_map(fn ($hash): string => $resolved[$hash]['path'], $hashes);
        }, 3);
    }

    private function row(int $siteId, array $input, bool $frozen, string $issuedOn): array
    {
        if (array_diff(array_keys($input), ['identity', 'rule', 'prefix', 'suffix', 'provenance', 'path'])
            || ! is_string($input['identity'] ?? null) || ! is_array($input['rule'] ?? null)
            || ! is_string($input['prefix'] ?? null) || ! is_string($input['suffix'] ?? null) || ! is_array($input['provenance'] ?? null)) {
            throw new \InvalidArgumentException('Dữ liệu đăng ký URL không hợp lệ.');
        }
        $rule = self::normalizeRule($input['rule']);
        $pinnedDate = $frozen || array_key_exists('issued_on', $input['rule']);
        if (! $frozen) {
            $rule = self::freezeRule($rule, $issuedOn);
        }
        [$prefix, $suffix] = self::location($input['prefix'], $input['suffix']);
        self::candidate($input['identity'], $rule, $prefix, $suffix);
        $provenance = array_replace(['parent_page_id' => null, 'parent_publication_id' => null, 'navigation_token' => null,
            'keyword_id' => null, 'keyword_text' => null, 'origin' => 'discovery', 'metadata' => []], $input['provenance']);
        if (array_diff(array_keys($provenance), ['source_page_id', 'parent_page_id', 'parent_publication_id', 'navigation_token', 'keyword_id', 'keyword_text', 'origin', 'metadata'])
            || ! is_int($provenance['source_page_id'] ?? null) || $provenance['source_page_id'] < 1
            || ! in_array($provenance['origin'], ['nav', 'discovery', 'admin'], true) || ! is_array($provenance['metadata'])
            || ($provenance['navigation_token'] !== null && (! is_string($provenance['navigation_token']) || ! preg_match('/^[a-f0-9]{24}$/D', $provenance['navigation_token'])))
            || (($provenance['keyword_id'] === null) !== ($provenance['keyword_text'] === null))
            || ($provenance['keyword_text'] !== null && (! is_string($provenance['keyword_text']) || trim($provenance['keyword_text']) === ''))) {
            throw new \InvalidArgumentException('Nguồn gốc URL không hợp lệ.');
        }
        foreach (['parent_page_id', 'parent_publication_id', 'keyword_id'] as $key) {
            if ($provenance[$key] !== null && (! is_int($provenance[$key]) || $provenance[$key] < 1)) {
                throw new \InvalidArgumentException('ID nguồn gốc URL không hợp lệ.');
            }
        }
        if ($provenance['origin'] === 'nav' && ($provenance['parent_page_id'] === null || $provenance['parent_publication_id'] === null
            || $provenance['navigation_token'] === null || $provenance['keyword_id'] === null)) {
            throw new \InvalidArgumentException('URL điều hướng cần đủ trang cha, publication, token và keyword đã lưu.');
        }
        if ($frozen && (! is_string($input['path'] ?? null) || ! self::safePath($input['path']))) {
            throw new \InvalidArgumentException('Đường dẫn đã lưu không hợp lệ.');
        }
        if ($frozen) {
            $matches = false;
            for ($attempt = 0; $attempt < 64; $attempt++) {
                if ($input['path'] === self::candidate($input['identity'], $rule, $prefix, $suffix, $attempt)) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                throw new \InvalidArgumentException('Đường dẫn đã lưu không khớp định danh và quy tắc URL.');
            }
        }
        $metadata = Json::encode($provenance['metadata']);
        if (strlen($metadata) > 65536) {
            throw new \InvalidArgumentException('Metadata URL vượt giới hạn.');
        }
        $row = ['site_id' => $siteId, 'identity_hash' => hash('sha256', $input['identity']),
            ...array_diff_key($provenance, ['metadata' => true]), 'rule_json' => Json::encode($rule),
            'prefix' => $prefix, 'suffix' => $suffix, 'metadata_json' => $metadata];

        return ['row' => $row, 'identity' => $input['identity'], 'path' => $input['path'] ?? null, 'pinned_date' => $pinnedDate];
    }

    private static function location(string $prefix, string $suffix): array
    {
        $settings = FanmuluSettings::normalize(['prefix' => $prefix, 'suffix' => $suffix, 'enabled' => false]);

        return [$settings['prefix'], $settings['suffix']];
    }

    private static function safePath(string $path): bool
    {
        return strlen($path) <= 512 && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! preg_match('/[\x00-\x20\x7f\\\\?#]/', $path);
    }

    private function decode(object $row): array
    {
        $record = array_diff_key((array) $row, ['rule_json' => true, 'metadata_json' => true, 'created_at' => true]);
        $record['rule'] = Json::decode($row->rule_json);
        $record['metadata'] = Json::decode($row->metadata_json);
        $record['selected_primary'] = $row->keyword_id === null ? null : ['keyword_id' => $row->keyword_id, 'text' => $row->keyword_text];

        return $record;
    }

    private function assertReplay(array $existing, array $row, bool $pinnedDate = true): void
    {
        foreach (['source_page_id', 'parent_page_id', 'navigation_token', 'keyword_id', 'keyword_text', 'origin', 'prefix', 'suffix'] as $key) {
            if ($existing[$key] !== $row[$key]) {
                throw new Conflict('FANMULU_URL_IDENTITY_CONFLICT');
            }
        }
        $existingRule = $existing['rule'];
        $incomingRule = Json::decode($row['rule_json']);
        if (! $pinnedDate) {
            unset($existingRule['issued_on'], $incomingRule['issued_on']);
        }
        if (Json::hash($existingRule) !== Json::hash($incomingRule)
            || ($existing['parent_publication_id'] !== $row['parent_publication_id'] && ($existing['navigation_token'] === null || $existing['parent_publication_id'] === null || $row['parent_publication_id'] === null))) {
            throw new Conflict('FANMULU_URL_IDENTITY_CONFLICT');
        }
    }

    private function assertPageCompatible(array $record, ?object $page = null, bool $fetch = true): void
    {
        if ($fetch) {
            $page = DB::table('pages')->where(['site_id' => $record['site_id'], 'canonical_path' => $record['path']])->first();
        }
        if (! $page) {
            return;
        }
        $selected = Json::decode($page->input_snapshot_json)['fanmulu']['selected_primary'] ?? [];
        if ($page->page_kind !== 'fanmulu' || (int) $page->source_page_id !== $record['source_page_id']
            || ($record['keyword_id'] !== null && (($selected['keyword_id'] ?? null) !== $record['keyword_id'] || ($selected['text'] ?? null) !== $record['keyword_text']))) {
            throw new Conflict('FANMULU_URL_PATH_COLLISION');
        }
    }
}
