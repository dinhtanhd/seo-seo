<?php

namespace App\Domain\Ingestion;

use App\Domain\Publishing\CatalogMetadata;
use App\Domain\Publishing\PublicCatalogCache;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Ingestion\RunMovieLibraryOperation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Shared source records retain immutable revisions; website publications remain frozen. */
final class MovieLibraryService
{
    public const MAX_ITEMS = 10000;

    public const CHUNK = 50;

    private const FIELDS = ['title', 'genre', 'category_external_id', 'category_name', 'area', 'year', 'language',
        'actors', 'directors', 'remarks', 'poster_url', 'source_url', 'body_html', 'episodes'];

    public function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Kho phim dùng chung cần quản trị toàn hệ thống.');
    }

    /** A bounded page can project these columns without reading movie bodies or episode lists. */
    public function listingQuery(array $filters, int $actorId): Builder
    {
        $this->authorize($actorId);
        $query = DB::table('content_items as c')->join('source_connectors as s', 's.id', '=', 'c.source_connector_id')
            ->leftJoin('content_revisions as r', 'r.id', '=', 'c.current_revision_id')->where('c.kind', 'movie');
        CatalogAvailability::filter($query, 'content_items', 'c');
        $metadata = app(CatalogMetadata::class);
        $indexed = $metadata->available();
        if ($indexed) {
            $query->leftJoin('catalog_revision_metadata as m', 'm.id', '=', 'c.current_revision_id');
        }
        $content = $indexed ? 'm.fields_json' : 'r.payload_json';
        $status = $filters['status'] ?? 'active';
        if (! in_array($status, ['active', 'archived', 'all'], true)) {
            throw ValidationException::withMessages(['status' => 'Trạng thái kho phim không hợp lệ.']);
        }
        if ($status !== 'all') {
            $query->where('c.library_status', $status);
        }
        if (! empty($filters['source_id'])) {
            $query->where('c.source_connector_id', (int) $filters['source_id']);
        }
        if (array_key_exists('manual_update_locked', $filters) && $filters['manual_update_locked'] !== '') {
            $value = filter_var($filters['manual_update_locked'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                throw ValidationException::withMessages(['manual_update_locked' => 'Lựa chọn khóa cập nhật không hợp lệ.']);
            }
            $query->where('c.manual_update_locked', $value);
        }
        if (isset($filters['category_external_id']) && $filters['category_external_id'] !== '') {
            $query->whereRaw("COALESCE($content->>'category_external_id', '') = ?", [(string) $filters['category_external_id']]);
        }
        $metadata->filters($query, $content, array_intersect_key($filters, array_flip(['q', 'year'])), $indexed ? 'm.search_grams' : null);
        $query->select('c.id', 'c.source_connector_id', 'c.external_id', 'c.current_revision_id', 'c.library_status', 'c.row_version',
            'c.manual_update_locked', 'c.last_seen_at', 'c.updated_at', 's.name as source_name', 's.kind as source_kind');
        foreach (['title', 'genre', 'area', 'year', 'language', 'category_external_id'] as $field) {
            $query->selectRaw("r.payload_json->>'$field' AS $field");
        }

        return $query;
    }

    public function find(int $id, int $actorId): array
    {
        $this->authorize($actorId);
        $query = DB::table('content_items as c')->join('source_connectors as s', 's.id', '=', 'c.source_connector_id')
            ->where('c.id', $id)->where('c.kind', 'movie');
        $item = CatalogAvailability::filter($query, 'content_items', 'c')->first(['c.*', 's.name as source_name', 's.kind as source_kind']) ?? abort(404);
        $revision = $item->current_revision_id ? DB::table('content_revisions')->find($item->current_revision_id) : null;
        $pages = DB::table('pages')->where('content_item_id', $id);

        return ['item' => $item, 'revision' => $revision, 'record' => Json::decode($revision?->payload_json),
            'page_count' => (clone $pages)->count(), 'live_page_count' => (clone $pages)->where('status', 'live')->count()];
    }

    public function save(?int $id, array $data, int $actorId): object
    {
        $this->authorize($actorId);
        $this->validateInput($data, $id !== null);
        if ($id !== null) {
            return $this->locked($id, $actorId, (int) $data['row_version'], function (object $item) use ($data, $actorId): object {
                if (isset($data['source_id']) && (int) $data['source_id'] !== (int) $item->source_connector_id
                    || isset($data['external_id']) && (string) $data['external_id'] !== $item->external_id) {
                    throw new Conflict('MOVIE_LIBRARY_IDENTITY_IMMUTABLE', 'Nguồn và ID phim gốc không được thay đổi.');
                }
                $old = Json::decode(DB::table('content_revisions')->where('id', $item->current_revision_id)->value('payload_json'));
                $record = $this->formRecord($data, $old, $item->external_id);

                return $this->persist($item, $record, (bool) ($data['manual_update_locked'] ?? true), $actorId, 'movie.library.updated');
            });
        }

        return DB::transaction(function () use ($data, $actorId): object {
            $this->authorize($actorId);
            $sourceId = empty($data['source_id']) ? $this->manualSource() : (int) $data['source_id'];
            $source = DB::table('source_connectors')->where('id', $sourceId)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $this->siteLocks($sourceId);
            $externalId = isset($data['external_id']) && trim((string) $data['external_id']) !== '' ? (string) $data['external_id'] : 'manual-'.Str::uuid();
            if (DB::table('content_items')->where(['source_connector_id' => $sourceId, 'external_id' => $externalId])->exists()) {
                throw new Conflict('MOVIE_LIBRARY_EXTERNAL_ID_EXISTS', 'Nguồn này đã có phim dùng ID đó.');
            }
            $record = $this->formRecord($data, [], $externalId);
            $id = DB::table('content_items')->insertGetId(['source_connector_id' => $source->id, 'external_id' => $record['external_id'],
                'kind' => 'movie', 'library_status' => 'active', 'manual_update_locked' => (bool) ($data['manual_update_locked'] ?? true),
                'row_version' => 1, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $item = DB::table('content_items')->find($id);

            return $this->persist($item, $record, (bool) ($data['manual_update_locked'] ?? true), $actorId, 'movie.library.created', true);
        }, 3);
    }

    /** Network access and source-config checks are performed before this immutable commit. */
    public function saveFromSource(int $id, array $canonicalRecord, int $expectedRowVersion, int $actorId): object
    {
        return $this->locked($id, $actorId, $expectedRowVersion, function (object $item) use ($canonicalRecord, $actorId): object {
            if ($item->library_status !== 'active') {
                throw new Conflict('MOVIE_LIBRARY_ARCHIVED');
            }
            if ($item->manual_update_locked) {
                throw new Conflict('MOVIE_LIBRARY_CRAWL_LOCKED', 'Mở khóa cập nhật nguồn trước khi lấy lại dữ liệu.');
            }
            if (($canonicalRecord['kind'] ?? null) !== 'movie' || (string) ($canonicalRecord['external_id'] ?? '') !== $item->external_id) {
                throw new Conflict('MOVIE_LIBRARY_IDENTITY_IMMUTABLE');
            }
            $record = app(CanonicalRecord::class)->normalize($canonicalRecord);

            return $this->persist($item, $record, false, $actorId, 'movie.library.source.refreshed');
        });
    }

    public function changeStatus(int $id, string $status, int $expectedRowVersion, int $actorId): object
    {
        if (! in_array($status, ['active', 'archived'], true)) {
            throw ValidationException::withMessages(['status' => 'Trạng thái phim không hợp lệ.']);
        }

        return $this->locked($id, $actorId, $expectedRowVersion,
            fn (object $item): object => $this->status($item, $status, $actorId));
    }

    /** Source → sites → item → regular pages matches ingestion's write ordering. */
    private function locked(int $id, int $actorId, ?int $expectedVersion, callable $change): object
    {
        $this->authorize($actorId);
        $initial = DB::table('content_items')->where('kind', 'movie')->find($id) ?? abort(404);

        return DB::transaction(function () use ($id, $actorId, $expectedVersion, $initial, $change): object {
            DB::table('source_connectors')->where('id', $initial->source_connector_id)->lockForUpdate()->first() ?? abort(404);
            $this->siteLocks((int) $initial->source_connector_id, $id);
            $item = DB::table('content_items')->where('kind', 'movie')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            CatalogAvailability::assertWritable($item, 'MOVIE_LIBRARY_DELETED');
            $this->authorize($actorId);
            if ($expectedVersion !== null && (int) $item->row_version !== $expectedVersion) {
                throw new Conflict('MOVIE_LIBRARY_VERSION_CONFLICT', 'Phim đã thay đổi. Tải lại trước khi lưu.');
            }
            DB::table('pages')->where('content_item_id', $id)->where('page_kind', 'regular')->orderBy('id')->lockForUpdate()->get(['id']);

            return $change($item);
        }, 3);
    }

    private function siteLocks(int $sourceId, ?int $itemId = null): void
    {
        app(MovieWebsiteSync::class)->lockSourceSites($sourceId, [], $itemId === null ? [] : [$itemId]);
    }

    private function persist(object $item, array $record, bool $locked, int $actorId, string $action, bool $created = false): object
    {
        $previous = $item->current_revision_id ? DB::table('content_revisions')->find($item->current_revision_id) : null;
        $hashData = $record;
        unset($hashData['source_url']);
        $hash = hash('sha256', CanonicalRecord::stableJson($hashData));
        $revisionId = $previous?->id;
        $manualProvenanceChanged = $previous && $action === 'movie.library.updated'
            && (Json::decode($previous->payload_json)['source_url'] ?? null) !== ($record['source_url'] ?? null);
        if (! $previous || ! hash_equals($previous->raw_hash, $hash) || $manualProvenanceChanged) {
            $revisionId = DB::table('content_revisions')->insertGetId(['content_item_id' => $item->id,
                'revision_no' => (int) ($previous->revision_no ?? 0) + 1, 'raw_hash' => $hash,
                'payload_json' => CanonicalRecord::stableJson($record), 'source_url' => $record['source_url'] ?? null,
                'fetched_at' => now(), 'created_at' => now()]);
        }
        $values = ['current_revision_id' => $revisionId, 'manual_update_locked' => $locked,
            'row_version' => $created ? 1 : DB::raw('row_version+1'), 'updated_at' => now()];
        if ($action === 'movie.library.source.refreshed') {
            $values['last_seen_at'] = now();
        }
        DB::table('content_items')->where('id', $item->id)->update($values);
        if ((int) $item->current_revision_id !== (int) $revisionId) {
            $sync = app(MovieWebsiteSync::class);
            if ($sync->available() && $item->library_status === 'active') {
                $sync->invalidate($sync->sync((int) $item->id, (int) $revisionId));
            } else {
                $pages = DB::table('pages')->where('content_item_id', $item->id)->where('page_kind', 'regular')
                    ->whereRaw("COALESCE(input_snapshot_json->>'source_update_locked', 'false') <> 'true'");
                $siteIds = (clone $pages)->distinct()->pluck('site_id');
                $pages->where('working_content_revision_id', '<>', $revisionId)->update(['working_content_revision_id' => $revisionId,
                    'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
                foreach ($siteIds as $siteId) {
                    app(PublicCatalogCache::class)->invalidate((int) $siteId);
                }
            }
        }
        Access::audit($action, $actorId, null, ['content_item_id' => $item->id, 'content_revision_id' => $revisionId,
            'manual_update_locked' => $locked, 'previous_revision_id' => $item->current_revision_id]);

        return DB::table('content_items')->find($item->id);
    }

    private function status(object $item, string $status, int $actorId): object
    {
        if ($item->library_status === $status) {
            return $item;
        }
        DB::table('content_items')->where('id', $item->id)->update(['library_status' => $status,
            'archived_at' => $status === 'archived' ? now() : null, 'archived_by' => $status === 'archived' ? $actorId : null,
            'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
        $pages = DB::table('pages')->where('content_item_id', $item->id)->where('page_kind', 'regular');
        $siteIds = (clone $pages)->distinct()->pluck('site_id');
        $pages->update(['row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
        foreach ($siteIds as $siteId) {
            app(PublicCatalogCache::class)->invalidate((int) $siteId);
        }
        Access::audit('movie.library.'.$status, $actorId, null, ['content_item_id' => $item->id,
            'previous_status' => $item->library_status, 'keeps_existing_publications' => true]);

        return DB::table('content_items')->find($item->id);
    }

    private function manualSource(): int
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['movie-library-manual-source']);
        $id = DB::table('source_connectors')->where('kind', 'import')->where('status', 'active')->whereNull('deleted_at')
            ->whereRaw("config_json->>'manual_library' = 'true'")->orderBy('id')->value('id');

        return $id ? (int) $id : DB::table('source_connectors')->insertGetId(['kind' => 'import', 'name' => 'Kho phim nhập thủ công',
            'status' => 'active', 'config_json' => Json::encode(['manual_library' => true]), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function validateInput(array $data, bool $updating): void
    {
        if (array_diff(array_keys($data), [...self::FIELDS, 'source_id', 'external_id', 'row_version', 'manual_update_locked'])) {
            throw ValidationException::withMessages(['movie' => 'Trường dữ liệu phim không được hỗ trợ.']);
        }
        $rules = ['source_id' => 'nullable|integer|min:1', 'external_id' => 'nullable|string|max:255',
            'row_version' => ($updating ? 'required' : 'nullable').'|integer|min:1', 'title' => 'required|string|max:1000',
            'body_html' => 'nullable|string|max:2097152', 'poster_url' => 'nullable|string|max:4096', 'source_url' => 'nullable|string|max:4096',
            'actors' => 'nullable|array|max:500', 'actors.*' => 'string|max:1000', 'directors' => 'nullable|array|max:500', 'directors.*' => 'string|max:1000',
            'episodes' => 'nullable|array|max:10000', 'manual_update_locked' => 'nullable|boolean'];
        foreach (['genre', 'category_external_id', 'category_name', 'area', 'year', 'language', 'remarks'] as $field) {
            $rules[$field] = 'nullable|string|max:1000';
        }
        Validator::make($data, $rules)->validate();
    }

    private function formRecord(array $data, array $previous, string $externalId): array
    {
        $record = array_replace($previous, array_intersect_key($data, array_flip(self::FIELDS)), ['external_id' => $externalId, 'kind' => 'movie']);
        if (array_key_exists('poster_url', $data)) {
            $record['poster'] = $data['poster_url'];
        }
        if (array_key_exists('episodes', $data)) {
            $record['episodes'] = $this->episodes($data['episodes'] ?? [], $previous['episodes'] ?? []);
        }
        $record['parser_version'] = $previous['parser_version'] ?? 'movie-library-manual-v1';

        return app(CanonicalRecord::class)->normalize($record);
    }

    private function episodes(array $episodes, array $previous): array
    {
        $result = [];
        $byId = [];
        foreach ($previous as $old) {
            if (isset($old['external_id'])) {
                $byId[(string) $old['external_id']] = $old;
            }
        }
        foreach ($episodes as $index => $episode) {
            if (! is_array($episode) || array_diff(array_keys($episode), ['title', 'source_link', 'provider', 'external_id', 'sequence_no'])) {
                throw ValidationException::withMessages(['episodes' => 'Mỗi tập cần tên tập, URL phát và nhóm nguồn.']);
            }
            $values = Validator::make($episode, ['title' => 'nullable|string|max:1000', 'source_link' => 'nullable|string|max:4096',
                'provider' => 'nullable|string|max:255', 'external_id' => 'nullable|string|max:255', 'sequence_no' => 'nullable|integer|min:1'])->validate();
            if (trim($values['title'] ?? '') === '' && trim($values['source_link'] ?? '') === '') {
                continue;
            }
            if (trim($values['title'] ?? '') === '') {
                throw ValidationException::withMessages(['episodes' => 'Điền tên cho mỗi tập phim có URL phát.']);
            }
            $old = ! empty($values['external_id']) ? ($byId[$values['external_id']] ?? []) : [];
            if ($old === [] && ! isset($previous[$index]['external_id'])) {
                $old = $previous[$index] ?? [];
            }
            $values['source_link'] = CanonicalRecord::dataUrl($values['source_link'] ?? null);
            $values['sequence_no'] = count($result) + 1;
            $values['external_id'] = trim($values['external_id'] ?? '') !== ''
                ? $values['external_id'] : ($old['external_id'] ?? 'manual-episode-'.Str::uuid());
            $result[] = array_replace(['trusted' => false], $old, $values);
        }

        return $result;
    }

    public function submitBulk(array $ids, string $action, int $actorId, string $requestKey): object
    {
        $this->authorize($actorId);
        if (! array_is_list($ids) || $ids === [] || count($ids) > self::MAX_ITEMS || trim($requestKey) === '' || strlen($requestKey) > 160
            || ! in_array($action, ['archive', 'restore', 'unlock_crawl', 'refresh'], true)) {
            throw ValidationException::withMessages(['content_ids' => 'Chọn 1–10.000 phim và thao tác hợp lệ; có thể gửi nhiều lượt.']);
        }
        foreach ($ids as &$id) {
            if ((! is_int($id) && ! is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages(['content_ids' => 'ID phim không hợp lệ.']);
            }
            $id = (int) $id;
        }
        unset($id);
        $ids = array_values(array_unique($ids));
        sort($ids);
        $hash = Json::hash([$ids, $action]);

        return DB::transaction(function () use ($ids, $action, $actorId, $requestKey, $hash): object {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['movie-library-request:'.$actorId.':'.$requestKey]);
            $this->authorize($actorId);
            $existing = DB::table('movie_library_operations')->where(['actor_id' => $actorId, 'request_key' => $requestKey])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new Conflict('MOVIE_LIBRARY_REQUEST_CHANGED');
                }

                return $existing;
            }
            $items = CatalogAvailability::filter(DB::table('content_items')->where('kind', 'movie')->whereIn('id', $ids), 'content_items')->orderBy('id')->get(['id', 'row_version']);
            if ($items->count() !== count($ids)) {
                throw ValidationException::withMessages(['content_ids' => 'Có phim không tồn tại hoặc không thuộc kho phim.']);
            }
            $id = (string) Str::uuid();
            DB::table('movie_library_operations')->insert(['id' => $id, 'actor_id' => $actorId, 'request_key' => $requestKey, 'request_hash' => $hash,
                'action' => $action, 'status' => 'queued', 'total' => count($ids), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items->chunk(500) as $chunk) {
                DB::table('movie_library_items')->insert($chunk->map(fn ($item): array => ['operation_id' => $id, 'content_item_id' => $item->id,
                    'expected_row_version' => $item->row_version, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()])->all());
            }
            Access::audit('movies.library.bulk.requested', $actorId, null, ['operation_id' => $id, 'action' => $action, 'total' => count($ids)]);
            DB::afterCommit(fn () => RunMovieLibraryOperation::dispatch($id));

            return DB::table('movie_library_operations')->find($id);
        }, 3);
    }

    public function report(string $id, int $actorId): array
    {
        $this->authorize($actorId);
        $operation = DB::table('movie_library_operations')->find($id) ?? abort(404);
        abort_unless((int) $operation->actor_id === $actorId, 403);
        $counts = array_fill_keys(['pending', 'completed', 'conflict', 'failed'], 0);
        foreach (DB::table('movie_library_items')->where('operation_id', $id)->selectRaw('status,count(*) total')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->total;
        }

        return ['operation' => $operation, 'id' => $id, 'status' => $operation->status, 'action' => $operation->action,
            'total' => (int) $operation->total, 'counts' => $counts,
            'items' => DB::table('movie_library_items')->where('operation_id', $id)->orderBy('id')->limit(100)->get()];
    }

    public function process(string $id): bool
    {
        $key = 'movie-library-operation:'.$id;
        if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) acquired', [$key])->acquired) {
            return false;
        }
        try {
            $operation = DB::table('movie_library_operations')->find($id);
            if (! $operation || ! in_array($operation->status, ['queued', 'processing'], true)) {
                return false;
            }
            DB::table('movie_library_operations')->where('id', $id)->update(['status' => 'processing', 'updated_at' => now()]);
            $items = DB::table('movie_library_items')->where('operation_id', $id)->where('status', 'pending')->orderBy('id')
                ->limit($operation->action === 'refresh' ? 5 : self::CHUNK)->get();
            foreach ($items as $item) {
                $this->processItem($operation, $item);
            }
            if (DB::table('movie_library_items')->where('operation_id', $id)->where('status', 'pending')->exists()) {
                return true;
            }
            $failures = DB::table('movie_library_items')->where('operation_id', $id)->whereIn('status', ['conflict', 'failed'])->count();
            $success = DB::table('movie_library_items')->where('operation_id', $id)->where('status', 'completed')->exists();
            DB::table('movie_library_operations')->where('id', $id)->update(['status' => $failures === 0 ? 'completed' : ($success ? 'partial' : 'failed'),
                'completed_at' => now(), 'updated_at' => now()]);

            return false;
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
        }
    }

    private function processItem(object $operation, object $item): void
    {
        try {
            if ($operation->action === 'refresh') {
                app(MovieSourceRefresh::class)->refresh((int) $item->content_item_id, (int) $item->expected_row_version,
                    (int) $operation->actor_id, function (object $saved) use ($item): void {
                        $this->complete((int) $item->id);
                    });

                return;
            }
            $this->locked((int) $item->content_item_id, (int) $operation->actor_id, (int) $item->expected_row_version, function (object $movie) use ($operation, $item): object {
                if ($operation->action === 'unlock_crawl') {
                    if ($movie->manual_update_locked) {
                        DB::table('content_items')->where('id', $movie->id)->update(['manual_update_locked' => false,
                            'row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
                        Access::audit('movie.library.crawl.unlocked', (int) $operation->actor_id, null, ['content_item_id' => $movie->id]);
                    }
                } else {
                    $this->status($movie, $operation->action === 'archive' ? 'archived' : 'active', (int) $operation->actor_id);
                }
                $this->complete((int) $item->id);

                return DB::table('content_items')->find($movie->id);
            });
        } catch (Conflict $error) {
            $this->failure((int) $item->id, $error->reason, true);
        } catch (HttpExceptionInterface $error) {
            $this->failure((int) $item->id, $error->getStatusCode() === 403 ? 'MOVIE_LIBRARY_PERMISSION_CHANGED' : 'MOVIE_LIBRARY_UNAVAILABLE', true);
        } catch (ValidationException|\InvalidArgumentException $error) {
            $this->failure((int) $item->id, 'MOVIE_LIBRARY_CONFIGURATION_INVALID', true);
        } catch (Throwable $error) {
            report($error);
            $this->failure((int) $item->id, 'MOVIE_LIBRARY_PROCESSING_FAILED', false);
        }
    }

    private function complete(int $itemId): void
    {
        DB::table('movie_library_items')->where('id', $itemId)->where('status', 'pending')->update(['status' => 'completed',
            'attempts' => DB::raw('attempts+1'), 'error_code' => null, 'completed_at' => now(), 'updated_at' => now()]);
    }

    private function failure(int $itemId, string $reason, bool $conflict): void
    {
        DB::transaction(function () use ($itemId, $reason, $conflict): void {
            $item = DB::table('movie_library_items')->where('id', $itemId)->lockForUpdate()->first();
            if (! $item || $item->status !== 'pending') {
                return;
            }
            $attempts = (int) $item->attempts + 1;
            $status = $conflict ? 'conflict' : ($attempts < 3 ? 'pending' : 'failed');
            DB::table('movie_library_items')->where('id', $itemId)->update(['status' => $status, 'attempts' => $attempts,
                'error_code' => $reason, 'completed_at' => $status === 'pending' ? null : now(), 'updated_at' => now()]);
        }, 3);
    }

    public function retry(string $id, int $actorId): void
    {
        $this->authorize($actorId);
        DB::transaction(function () use ($id, $actorId): void {
            if (! DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0)) acquired', ['movie-library-operation:'.$id])->acquired) {
                throw new Conflict('MOVIE_LIBRARY_OPERATION_BUSY');
            }
            $operation = DB::table('movie_library_operations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $operation->actor_id === $actorId, 403);
            if (in_array($operation->status, ['queued', 'processing'], true)) {
                throw new Conflict('MOVIE_LIBRARY_OPERATION_BUSY');
            }
            $count = DB::table('movie_library_items')->where('operation_id', $id)->where('status', 'failed')->update([
                'status' => 'pending', 'attempts' => 0, 'error_code' => null, 'completed_at' => null, 'updated_at' => now()]);
            if ($count > 0) {
                DB::table('movie_library_operations')->where('id', $id)->update(['status' => 'queued', 'completed_at' => null, 'updated_at' => now()]);
                DB::afterCommit(fn () => RunMovieLibraryOperation::dispatch($id));
            }
        }, 3);
    }

    public function dispatchPending(): int
    {
        $ids = DB::table('movie_library_operations')->whereIn('status', ['queued', 'processing'])->orderBy('created_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            RunMovieLibraryOperation::dispatch($id);
        }

        return $ids->count();
    }
}
