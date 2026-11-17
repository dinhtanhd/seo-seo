<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeRenderer;
use Illuminate\Support\Facades\DB;

/** Immutable, site-scoped fragments. Reading never resolves a draft or current publication. */
final class SnapshotStorage
{
    private const MARKER = '_snapshot_storage';

    public function enabled(int $siteId): bool
    {
        return in_array($siteId, array_map('intval', config('publication_storage.compact_site_ids', [])), true);
    }

    public function decode(mixed $value): array
    {
        return $this->hydrate(Json::decode($value));
    }

    public function compact(array $snapshot, bool $force = false): array
    {
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        if (isset($snapshot[self::MARKER])) {
            $this->hydrate($snapshot);

            return $snapshot;
        }
        if (! $force && ! $this->enabled($siteId)) {
            return $snapshot;
        }
        $pageId = (int) ($snapshot['page']['id'] ?? 0);
        if ($siteId < 1 || $pageId < 1) {
            throw new Conflict('SNAPSHOT_STORAGE_OWNER_INVALID');
        }
        if (count($snapshot['aggregate']['entries'] ?? []) > 10000) {
            throw new Conflict('SNAPSHOT_STORAGE_ENTRIES_LIMIT');
        }
        $fragments = [];
        $marker = ['schema_version' => 1, 'site_id' => $siteId, 'page_id' => $pageId,
            'aggregate_hash' => $snapshot['_snapshot_aggregate_hash'] ?? Json::hash($snapshot['aggregate'] ?? [])];
        // First-visit recommendations are public catalog projections, including eligible drafts without a publication pin.
        $compactEntries = isset($snapshot['aggregate']['entries']) && is_array($snapshot['aggregate']['entries'])
            && ! (FanmuluDetailArtifact::exists($snapshot) && ! empty($snapshot['_live_recommendations_changed']));
        $cards = $compactEntries ? $this->projectEntries($snapshot) : [];
        $this->assertPins($siteId, $cards);
        if (in_array($snapshot['page']['type'] ?? null, ['movie', 'novel'], true)) {
            $own = $snapshot;
            $own['aggregate']['entries'] = [['path' => $snapshot['page']['path'] ?? '/', 'route_key' => $snapshot['page']['route_key'] ?? '',
                'content' => $snapshot['content'] ?? [], 'category' => $snapshot['category'] ?? [], 'fanmulu' => $snapshot['fanmulu'] ?? null]];
            $snapshot['_catalog_card'] = $this->projectEntries($own)[0];
        }
        if (is_array($snapshot['theme'] ?? null)) {
            $marker['theme'] = $this->fragment($fragments, $siteId, 'theme', $snapshot['theme']);
            $snapshot['theme'] = array_intersect_key($snapshot['theme'], array_flip(['id', 'version_id', 'version_no', 'hash', 'schema_version', 'slug', 'name', 'runtime']));
        }
        if ($compactEntries) {
            $references = [];
            foreach ($cards as $entry) {
                $references[] = ['hash' => $this->fragment($fragments, $siteId, 'card', $entry),
                    'page_id' => $entry['page_id'] ?? null, 'publication_id' => $entry['publication_id'] ?? null];
            }
            $index = ['entries' => $references];
            if (array_key_exists('entry_categories', $snapshot['theme_catalog'] ?? [])) {
                $index['entry_categories'] = $snapshot['theme_catalog']['entry_categories'];
                unset($snapshot['theme_catalog']['entry_categories']);
            }
            $marker['entries'] = $this->fragment($fragments, $siteId, 'catalog', $index);
            $snapshot['aggregate']['entries'] = [];
        }
        unset($snapshot['_snapshot_cards'], $snapshot['_snapshot_aggregate_hash']);
        $snapshot[self::MARKER] = $marker;
        foreach (array_chunk(array_values($fragments), 200) as $chunk) {
            DB::table('snapshot_fragments')->insertOrIgnore($chunk);
        }

        return $snapshot;
    }

    public function hydrate(array $snapshot): array
    {
        $marker = $this->marker($snapshot);
        if ($marker === null) {
            return $snapshot;
        }
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        $roots = $this->read($siteId, array_filter(['theme' => $marker['theme'] ?? null, 'catalog' => $marker['entries'] ?? null], fn ($hash) => $hash !== null));
        if (isset($marker['theme'])) {
            $snapshot['theme'] = $roots['theme'];
        }
        if (isset($marker['entries'])) {
            $index = $roots['catalog'];
            if (! is_array($index['entries'] ?? null) || ! array_is_list($index['entries']) || count($index['entries']) > 10000) {
                throw new Conflict('SNAPSHOT_FRAGMENT_INVALID');
            }
            $hashes = [];
            foreach ($index['entries'] as $reference) {
                if (! is_array($reference) || ! is_string($reference['hash'] ?? null)) {
                    throw new Conflict('SNAPSHOT_FRAGMENT_INVALID');
                }
                $hashes[$reference['hash']] = $reference['hash'];
            }
            $cards = $this->read($siteId, $hashes, 'card');
            $entries = [];
            foreach ($index['entries'] as $reference) {
                $entry = $cards[$reference['hash']];
                if (($entry['page_id'] ?? null) !== ($reference['page_id'] ?? null)
                    || ($entry['publication_id'] ?? null) !== ($reference['publication_id'] ?? null)) {
                    throw new Conflict('SNAPSHOT_FRAGMENT_PIN_MISMATCH');
                }
                $entries[] = $entry;
            }
            $snapshot['aggregate']['entries'] = $entries;
            if (array_key_exists('entry_categories', $index)) {
                $snapshot['theme_catalog']['entry_categories'] = $index['entry_categories'];
            }
            $snapshot['_snapshot_cards'] = true;
        }
        unset($snapshot[self::MARKER]);
        $snapshot['_snapshot_aggregate_hash'] = $marker['aggregate_hash'];

        return $snapshot;
    }

    /** Read frozen layout metadata without loading the immutable catalog or its cards. */
    public function presentation(mixed $value): array
    {
        $snapshot = Json::decode($value);
        $marker = $this->marker($snapshot);
        if ($marker !== null && isset($marker['theme'])) {
            $snapshot['theme'] = $this->read((int) $marker['site_id'], ['theme' => $marker['theme']])['theme'];
        }
        unset($snapshot[self::MARKER], $snapshot['aggregate'], $snapshot['content'], $snapshot['managed_body'],
            $snapshot['_catalog_card'], $snapshot['_snapshot_cards'], $snapshot['_snapshot_aggregate_hash']);
        if (is_array($snapshot['theme_catalog'] ?? null)) {
            unset($snapshot['theme_catalog']['entry_categories']);
        }

        return $snapshot;
    }

    private function marker(array $snapshot): ?array
    {
        if (! array_key_exists(self::MARKER, $snapshot)) {
            return null;
        }
        $marker = $snapshot[self::MARKER];
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        if (! is_array($marker) || ($marker['schema_version'] ?? null) !== 1
            || $siteId < 1 || ($marker['site_id'] ?? null) !== $siteId
            || ($marker['page_id'] ?? null) !== (int) ($snapshot['page']['id'] ?? 0)
            || ! is_string($marker['aggregate_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $marker['aggregate_hash'])
            || array_diff(array_keys($marker), ['schema_version', 'site_id', 'page_id', 'theme', 'entries', 'aggregate_hash'])) {
            throw new Conflict('SNAPSHOT_STORAGE_OWNER_INVALID');
        }
        foreach (['theme', 'entries'] as $key) {
            if (isset($marker[$key]) && (! is_string($marker[$key]) || ! preg_match('/^[a-f0-9]{64}$/D', $marker[$key]))) {
                throw new Conflict('SNAPSHOT_FRAGMENT_INVALID');
            }
        }

        return $marker;
    }

    /** Normalize comparisons to the exact card values used by the renderer. */
    public function projectEntries(array $snapshot): array
    {
        $entries = $snapshot['aggregate']['entries'] ?? [];
        $cards = app(MovieThemeRenderer::class)->context($snapshot, allEntries: true)['movies'];
        $projected = [];
        foreach ($entries as $index => $entry) {
            if (! isset($cards[$index])) {
                break;
            }
            $content = $entry['content'] ?? $entry;
            $copy = array_intersect_key($entry, array_flip(['page_id', 'publication_id', 'path', 'content_revision_id', 'category', 'route_key', 'fanmulu', 'fanmulu_link']));
            $copy['content'] = array_intersect_key($content, array_flip(['title', 'poster_url', 'year', 'genre']));
            $copy['play_path'] = $cards[$index]['play_url'];
            $copy['_card'] = $cards[$index];
            unset($copy['_card']['rank'], $copy['_card']['rank_class']);
            $projected[] = $copy;
        }

        return $projected;
    }

    public function references(array $snapshot): array
    {
        return $this->hydrate($snapshot)['aggregate']['entries'] ?? [];
    }

    /** Storage format is not a publication change; accepted-plan hashing still uses stored bytes. */
    public function equivalent(array $left, array $right): bool
    {
        $cards = isset($left[self::MARKER]) || isset($right[self::MARKER])
            || ! empty($left['_snapshot_cards']) || ! empty($right['_snapshot_cards']);
        if (! $cards) {
            return Json::hash($left) === Json::hash($right);
        }
        foreach ([$left, $right] as $index => $snapshot) {
            $snapshot = $this->hydrate($snapshot);
            if (isset($snapshot['aggregate']['entries'])) {
                $snapshot['aggregate']['entries'] = $this->projectEntries($snapshot);
            }
            unset($snapshot['_snapshot_cards'], $snapshot['_snapshot_aggregate_hash'], $snapshot['_catalog_card']);
            if ($index === 0) {
                $left = $snapshot;
            } else {
                $right = $snapshot;
            }
        }

        return Json::hash($left) === Json::hash($right);
    }

    private function assertPins(int $siteId, array $entries): void
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (isset($entry['page_id']) || isset($entry['publication_id'])) {
                if (! is_int($entry['page_id'] ?? null) || ! is_int($entry['publication_id'] ?? null)
                    || $entry['page_id'] < 1 || $entry['publication_id'] < 1) {
                    throw new Conflict('SNAPSHOT_FRAGMENT_PIN_MISMATCH');
                }
                $ids[] = $entry['publication_id'];
            }
        }
        if ($ids === []) {
            return;
        }
        $pins = DB::table('page_publications')->where('site_id', $siteId)->whereIn('id', array_unique($ids))->pluck('page_id', 'id');
        foreach ($entries as $entry) {
            if (isset($entry['publication_id']) && (int) ($pins[$entry['publication_id']] ?? 0) !== $entry['page_id']) {
                throw new Conflict('SNAPSHOT_FRAGMENT_PIN_MISMATCH');
            }
        }
    }

    private function fragment(array &$fragments, int $siteId, string $kind, array $payload): string
    {
        $json = Json::canonicalEncode($payload);
        $hash = hash('sha256', $kind.':'.$json);
        $fragments[$hash] = ['site_id' => $siteId, 'hash' => $hash, 'kind' => $kind,
            'payload_json' => $json, 'payload_bytes' => strlen($json), 'created_at' => now()];

        return $hash;
    }

    /** @return array<string, array> */
    private function read(int $siteId, array $hashes, ?string $kind = null): array
    {
        if ($hashes === []) {
            return [];
        }
        foreach ($hashes as $hash) {
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new Conflict('SNAPSHOT_FRAGMENT_INVALID');
            }
        }
        $rows = DB::table('snapshot_fragments')->where('site_id', $siteId)->whereIn('hash', array_values($hashes))->get()->keyBy('hash');
        $values = [];
        foreach ($hashes as $key => $hash) {
            $row = $rows->pull($hash);
            $expectedKind = $kind ?? $key;
            if (! $row) {
                throw new Conflict('SNAPSHOT_FRAGMENT_MISSING');
            }
            $payload = Json::decode($row->payload_json);
            if ($row->kind !== $expectedKind || ! hash_equals($hash, hash('sha256', $row->kind.':'.Json::canonicalEncode($payload)))) {
                throw new Conflict('SNAPSHOT_FRAGMENT_HASH_MISMATCH');
            }
            $values[$key] = $payload;
        }

        return $values;
    }
}
