<?php

namespace App\Domain\Support;

use App\Domain\Ingestion\MovieWebsiteSync;
use App\Domain\Ingestion\SourceCatalogService;
use App\Domain\Keywords\KeywordCatalogService;
use App\Domain\Keywords\PoolCatalogService;
use App\Domain\Publishing\CatalogMetadata;
use App\Domain\Sites\UrlSuffixCatalog;
use App\Jobs\Support\RunCatalogDeletion;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Unused-only, reversible removal; immutable revisions and files are retained. */
final class CatalogDeletionService
{
    private ?bool $ready = null;

    public const MAX_ITEMS = 10000;

    public const CHUNK = 50;

    public const KINDS = ['movie' => 'Phim trong kho', 'template' => 'Template SEO', 'theme' => 'Giao diện phim',
        'preset' => 'Bộ cấu hình website', 'category' => 'Danh mục', 'rule' => 'Ngoại lệ nâng cao',
        'keyword' => 'Keyword', 'pool' => 'Kho keyword', 'group' => 'Nhóm keyword', 'source' => 'Nguồn nội dung', 'suffix' => 'Đuôi URL'];

    private const TABLES = ['movie' => 'content_items', 'template' => 'templates', 'theme' => 'movie_themes',
        'preset' => 'website_presets', 'category' => 'categories', 'rule' => 'rules', 'keyword' => 'keywords',
        'pool' => 'keyword_pools', 'group' => 'keyword_groups', 'source' => 'source_connectors', 'suffix' => 'url_suffixes'];

    public function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Xóa danh mục dùng chung cần quản trị toàn hệ thống.');
        $this->ready ??= Schema::hasTable('catalog_delete_operations') && Schema::hasColumn('templates', 'deleted_at');
        abort_unless($this->ready, 503, 'Chức năng xóa đang được cập nhật. Vui lòng tải lại sau.');
    }

    public function table(string $kind): string
    {
        return self::TABLES[$kind] ?? throw ValidationException::withMessages(['kind' => 'Loại danh mục không được hỗ trợ.']);
    }

    private function query(string $kind): Builder
    {
        $query = DB::table($this->table($kind).' as c');
        if ($kind === 'movie') {
            $query->where('c.kind', 'movie')->leftJoin('content_revisions as r', 'r.id', '=', 'c.current_revision_id');
        }

        return $query;
    }

    private function label(string $kind): string
    {
        return match ($kind) {
            'movie' => "COALESCE(r.payload_json->>'title', 'Phim #' || c.id)",
            'keyword' => 'c.original_text', 'suffix' => "c.label || ' · ' || c.suffix",
            'rule' => "c.slot || ' · #' || c.id", default => 'c.name',
        };
    }

    public function listing(string $kind, array $filters, int $actorId): Builder
    {
        $this->authorize($actorId);
        $query = $this->query($kind);
        ($filters['status'] ?? 'active') === 'trash' ? $query->whereNotNull('c.deleted_at') : $query->whereNull('c.deleted_at');
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            if ($kind === 'movie') {
                $metadata = app(CatalogMetadata::class);
                $indexed = $metadata->available();
                if ($indexed) {
                    $query->leftJoin('catalog_revision_metadata as m', 'm.id', '=', 'c.current_revision_id');
                }
                $query->where(function (Builder $search) use ($metadata, $indexed, $term): void {
                    $metadata->filters($search, $indexed ? 'm.fields_json' : 'r.payload_json', ['q' => $term], $indexed ? 'm.search_grams' : null);
                    if (ctype_digit($term) && strlen($term) < 19) {
                        $search->orWhere('c.id', (int) $term);
                    }
                });
            } else {
                $query->where(function (Builder $search) use ($kind, $term): void {
                    $search->whereRaw('('.$this->label($kind).') ILIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%']);
                    if (ctype_digit($term) && strlen($term) < 19) {
                        $search->orWhere('c.id', (int) $term);
                    }
                });
            }
        }

        return $query->select('c.id', 'c.row_version', 'c.deleted_at')->selectRaw($this->label($kind).' AS label')->orderByDesc('c.id');
    }

    public function item(string $kind, int $id, int $actorId): object
    {
        $this->authorize($actorId);

        return $this->query($kind)->where('c.id', $id)->select('c.id', 'c.row_version', 'c.deleted_at')
            ->selectRaw($this->label($kind).' AS label')->first() ?? abort(404);
    }

    public function mutate(string $kind, int $id, int $version, string $action, int $actorId, ?string $operationId = null): object
    {
        $this->authorize($actorId);
        $this->table($kind);
        if (! in_array($action, ['delete', 'restore'], true) || $version < 1) {
            throw ValidationException::withMessages(['action' => 'Thao tác hoặc phiên bản không hợp lệ.']);
        }

        return app(CatalogUsageGuard::class)->withinOperation($operationId, function () use ($kind, $id, $version, $action, $actorId): object {
            if (in_array($kind, ['keyword', 'pool', 'group', 'source', 'suffix'], true)) {
                $service = match ($kind) {
                    'keyword' => app(KeywordCatalogService::class), 'pool', 'group' => app(PoolCatalogService::class),
                    'source' => app(SourceCatalogService::class), 'suffix' => app(UrlSuffixCatalog::class),
                };
                $method = ($action === 'delete' ? 'delete' : 'restore').($kind === 'group' ? 'Group' : '');

                return $service->$method($id, $version, $actorId);
            }

            return DB::transaction(function () use ($kind, $id, $version, $action, $actorId): object {
                $initial = DB::table($this->table($kind))->find($id) ?? abort(404);
                if ($kind === 'movie') {
                    DB::table('source_connectors')->where('id', $initial->source_connector_id)->lockForUpdate()->first();
                    app(MovieWebsiteSync::class)->lockSourceSites((int) $initial->source_connector_id, [], [$id]);
                } elseif ($kind === 'category') {
                    DB::table('sites')->where('id', $initial->site_id)->lockForUpdate()->first();
                } else {
                    DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
                    if ($kind === 'rule' && $initial->site_id !== null) {
                        DB::table('sites')->where('id', $initial->site_id)->lockForUpdate()->first();
                    }
                }
                $row = app(CatalogUsageGuard::class)->lock($kind, $id);
                $this->authorize($actorId);
                if ((int) $row->row_version !== $version) {
                    throw new Conflict('CATALOG_VERSION_CONFLICT', 'Dữ liệu đã đổi. Tải lại trước khi xóa hoặc khôi phục.');
                }
                $deleted = $row->deleted_at !== null;
                if ($action === 'restore' && ! $deleted) {
                    throw new Conflict('CATALOG_NOT_DELETED');
                }
                if ($action === 'delete' && $deleted) {
                    return $row;
                }
                $stateField = $kind === 'movie' ? 'library_status' : ($kind === 'category' ? null : 'status');
                $values = ['deleted_at' => $action === 'delete' ? now() : null, 'row_version' => DB::raw('row_version+1')];
                if (property_exists($row, 'updated_at')) {
                    $values['updated_at'] = now();
                }
                if ($action === 'delete') {
                    app(CatalogUsageGuard::class)->assertUnused($kind, $id);
                    $previous = $stateField ? [$stateField => $row->$stateField] : [];
                    DB::table('catalog_trash_entries')->updateOrInsert(['kind' => $kind, 'item_id' => $id],
                        ['previous_state_json' => Json::encode($previous), 'deleted_by' => $actorId, 'deleted_at' => now(), 'restored_at' => null]);
                    if ($stateField) {
                        $values[$stateField] = 'archived';
                    }
                } else {
                    $entry = DB::table('catalog_trash_entries')->where(['kind' => $kind, 'item_id' => $id])->first();
                    $previous = Json::decode($entry?->previous_state_json);
                    if ($stateField) {
                        $values[$stateField] = $previous[$stateField] ?? 'active';
                    }
                    DB::table('catalog_trash_entries')->where(['kind' => $kind, 'item_id' => $id])->update(['restored_at' => now()]);
                }
                DB::table($this->table($kind))->where('id', $id)->update($values);
                Access::audit('catalog.'.$action.'d', $actorId, $kind === 'category' ? $row->site_id : null,
                    ['kind' => $kind, 'item_id' => $id, 'unused_only' => $action === 'delete']);

                return DB::table($this->table($kind))->find($id);
            }, 3);
        });
    }

    public function submit(string $kind, string $action, array $ids, array $versions, int $actorId, string $key): object
    {
        $this->authorize($actorId);
        $this->table($kind);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === [] || count($ids) > self::MAX_ITEMS || $ids[0] < 1 || ! in_array($action, ['delete', 'restore'], true) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['selection' => 'Chọn từ 1 đến 10.000 mục, thao tác và mã yêu cầu hợp lệ.']);
        }
        $hash = Json::hash([$kind, $action, $ids, $versions]);

        return DB::transaction(function () use ($kind, $action, $ids, $versions, $actorId, $key, $hash): object {
            DB::table('users')->where('id', $actorId)->lockForUpdate()->first();
            $this->authorize($actorId);
            $old = DB::table('catalog_delete_operations')->where(['actor_id' => $actorId, 'request_key' => $key])->first();
            if ($old) {
                if ($old->request_hash !== $hash) {
                    throw new Conflict('CATALOG_REQUEST_KEY_REUSED');
                }

                return $old;
            }
            $rows = $this->query($kind)->whereIn('c.id', $ids)->get(['c.id', 'c.row_version'])->keyBy('id');
            abort_unless($rows->count() === count($ids), 404);
            $operationId = (string) Str::uuid();
            DB::table('catalog_delete_operations')->insert(['id' => $operationId, 'actor_id' => $actorId, 'request_key' => $key,
                'request_hash' => $hash, 'kind' => $kind, 'action' => $action, 'status' => 'queued', 'total' => count($ids), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('catalog_delete_items')->insert(array_map(fn ($id) => ['operation_id' => $operationId, 'kind' => $kind, 'item_id' => $id,
                    'expected_row_version' => (int) ($versions[$id] ?? $rows->get($id)->row_version), 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()], $chunk));
            }
            RunCatalogDeletion::dispatch($operationId)->onQueue('websites')->afterCommit();

            return DB::table('catalog_delete_operations')->find($operationId);
        }, 3);
    }

    public function process(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $operation = DB::table('catalog_delete_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $operation || in_array($operation->status, ['completed', 'partial'], true)) {
                return false;
            }
            $items = DB::table('catalog_delete_items')->where('operation_id', $id)->where('status', 'pending')->orderBy('item_id')->limit(self::CHUNK)->lockForUpdate()->get();
            foreach ($items as $item) {
                $values = ['attempts' => $item->attempts + 1, 'completed_at' => now(), 'updated_at' => now()];
                try {
                    $this->mutate($item->kind, (int) $item->item_id, (int) $item->expected_row_version, $operation->action, (int) $operation->actor_id, $id);
                    $values += ['status' => 'completed', 'error_code' => null, 'error_message' => null];
                } catch (CatalogItemInUse $error) {
                    $values += ['status' => 'blocked', 'error_code' => $error->reason, 'error_message' => 'Mục đang được sử dụng. Mở mục để xem các liên kết cần gỡ.'];
                } catch (Conflict $error) {
                    $values += ['status' => 'conflict', 'error_code' => $error->reason, 'error_message' => 'Dữ liệu đã đổi hoặc không còn đúng trạng thái. Chọn lại sau khi kiểm tra.'];
                } catch (Throwable $error) {
                    if ($error instanceof QueryException && $error->getCode() === '23514'
                        && str_contains($error->getMessage(), 'CATALOG_REFERENCE_DELETED:')) {
                        DB::table('catalog_delete_items')->where('id', $item->id)->update($values + ['status' => 'blocked',
                            'error_code' => 'CATALOG_REFERENCE_DELETED', 'error_message' => 'Mục còn phụ thuộc dữ liệu đã xóa. Khôi phục mục liên quan trước.']);

                        continue;
                    }
                    if ($error instanceof QueryException && in_array($error->getCode(), ['40P01', '40001'], true)) {
                        throw $error;
                    }
                    report($error);
                    $retry = $item->attempts + 1 < 3 && ! $error instanceof HttpExceptionInterface;
                    $values += ['status' => $retry ? 'pending' : 'failed', 'error_code' => 'CATALOG_ACTION_FAILED', 'error_message' => 'Chưa thực hiện được. Kiểm tra quyền hoặc thử lại.'];
                    $values['completed_at'] = $retry ? null : now();
                }
                DB::table('catalog_delete_items')->where('id', $item->id)->update($values);
            }
            $pending = DB::table('catalog_delete_items')->where('operation_id', $id)->where('status', 'pending')->exists();
            $errors = DB::table('catalog_delete_items')->where('operation_id', $id)->whereNotIn('status', ['pending', 'completed'])->exists();
            DB::table('catalog_delete_operations')->where('id', $id)->update(['status' => $pending ? 'running' : ($errors ? 'partial' : 'completed'), 'completed_at' => $pending ? null : now(), 'updated_at' => now()]);
            if ($pending) {
                RunCatalogDeletion::dispatch($id)->onQueue('websites')->afterCommit();
            }

            return $pending;
        }, 3);
    }

    public function dispatchPending(): int
    {
        if (! Schema::hasTable('catalog_delete_operations')) {
            return 0;
        }
        $ids = DB::table('catalog_delete_operations')->whereIn('status', ['queued', 'running'])->orderBy('created_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            RunCatalogDeletion::dispatch($id)->onQueue('websites');
        }

        return $ids->count();
    }

    public function retry(string $id, int $actorId): void
    {
        $this->authorize($actorId);
        DB::transaction(function () use ($id): void {
            DB::table('catalog_delete_operations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            DB::table('catalog_delete_items')->where('operation_id', $id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'completed_at' => null, 'updated_at' => now()]);
            DB::table('catalog_delete_operations')->where('id', $id)->update(['status' => 'queued', 'completed_at' => null, 'updated_at' => now()]);
            RunCatalogDeletion::dispatch($id)->onQueue('websites')->afterCommit();
        });
    }
}
