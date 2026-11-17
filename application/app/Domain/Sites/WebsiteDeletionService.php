<?php

namespace App\Domain\Sites;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Sites\RunWebsiteDeletion;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Recoverable website deletion: identities/history stay in DB; hostnames are released. */
final class WebsiteDeletionService
{
    public const MAX_SITES = 10000;

    public const CHUNK = 50;

    public function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Chỉ quản trị toàn hệ thống được xóa hoặc khôi phục website.');
        abort_unless(Schema::hasTable('site_delete_operations') && Schema::hasColumn('sites', 'deleted_at'), 503, 'Chức năng xóa website đang được cập nhật.');
    }

    /** Preview is durable but cannot execute until explicitly confirmed. Reads never enqueue deletion. */
    public function preview(array $ids, array $versions, int $actorId, string $requestKey, string $action = 'delete'): object
    {
        $this->authorize($actorId);
        if (! array_is_list($ids) || $ids === [] || count($ids) > self::MAX_SITES || ! in_array($action, ['delete', 'restore'], true)
            || trim($requestKey) === '' || strlen($requestKey) > 160) {
            throw ValidationException::withMessages(['site_ids' => 'Chọn từ 1 đến 10.000 website và dùng mã yêu cầu hợp lệ.']);
        }
        foreach ($ids as &$id) {
            if ((! is_int($id) && ! is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages(['site_ids' => 'Danh sách ID website không hợp lệ.']);
            }
            $id = (int) $id;
        }
        unset($id);
        $ids = array_values(array_unique($ids));
        sort($ids);
        $selectedVersions = [];
        foreach ($ids as $id) {
            if (array_key_exists($id, $versions)) {
                if (filter_var($versions[$id], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw ValidationException::withMessages(['row_version' => 'Phiên bản website không hợp lệ.']);
                }
                $selectedVersions[$id] = (int) $versions[$id];
            }
        }
        $hash = Json::hash([$action, $ids, $selectedVersions]);

        return DB::transaction(function () use ($ids, $selectedVersions, $actorId, $requestKey, $action, $hash): object {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['website-delete-request:'.$actorId.':'.$requestKey]);
            $this->authorize($actorId);
            $existing = DB::table('site_delete_operations')->where(['actor_id' => $actorId, 'request_key' => $requestKey])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new Conflict('WEBSITE_DELETE_REQUEST_CHANGED', 'Mã yêu cầu đã dùng cho lựa chọn khác.');
                }

                return $existing;
            }
            $sites = DB::table('sites')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get();
            abort_unless($sites->count() === count($ids), 404);
            $domains = DB::table('site_domains')->whereIn('site_id', $ids)->orderBy('host_ascii')->get()->groupBy('site_id');
            $pages = DB::table('pages')->whereIn('site_id', $ids)->selectRaw('site_id,count(*) total')->groupBy('site_id')->pluck('total', 'site_id');
            $publications = DB::table('page_publications')->whereIn('site_id', $ids)->selectRaw('site_id,count(*) total')->groupBy('site_id')->pluck('total', 'site_id');
            $trash = $action === 'restore' ? DB::table('site_trash_entries as t')->join('site_delete_items as i', 'i.id', '=', 't.delete_item_id')
                ->whereIn('t.site_id', $ids)->get(['t.site_id', 't.delete_item_id', 'i.receipt_json'])->keyBy('site_id') : collect();
            $items = [];
            foreach ($sites as $site) {
                $this->assertState($site, $action);
                if (isset($selectedVersions[$site->id]) && $selectedVersions[$site->id] !== (int) $site->row_version) {
                    throw new Conflict('WEBSITE_DELETE_SITE_CHANGED', 'Website đã thay đổi. Chọn lại trước khi xác nhận.');
                }
                $saved = $trash->get($site->id);
                if ($action === 'restore' && ! $saved) {
                    throw new Conflict('WEBSITE_DELETE_RECEIPT_MISSING');
                }
                $savedDomains = $action === 'restore' ? (Json::decode($saved->receipt_json)['domains'] ?? []) : ($domains->get($site->id)?->all() ?? []);
                $snapshot = ['name' => $site->name, 'base_url' => $site->base_url, 'domains' => $savedDomains,
                    'pages_count' => (int) ($pages[$site->id] ?? 0), 'publication_count' => (int) ($publications[$site->id] ?? 0),
                    'delete_item_id' => $saved?->delete_item_id, 'original_status' => $site->status];
                $items[] = ['site_id' => (int) $site->id, 'expected_row_version' => (int) $site->row_version, 'snapshot_json' => Json::encode($snapshot),
                    'created_at' => now(), 'updated_at' => now()];
            }
            $id = (string) Str::uuid();
            $previewHash = Json::hash(array_map(fn ($item): array => [$item['site_id'], $item['expected_row_version'], Json::decode($item['snapshot_json'])], $items));
            DB::table('site_delete_operations')->insert(['id' => $id, 'actor_id' => $actorId, 'request_key' => $requestKey, 'request_hash' => $hash,
                'action' => $action, 'status' => 'preview', 'total' => count($items), 'preview_hash' => $previewHash,
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($items, 500) as $chunk) {
                DB::table('site_delete_items')->insert(array_map(fn ($item): array => ['operation_id' => $id] + $item, $chunk));
            }
            Access::audit('websites.'.$action.'.previewed', $actorId, null, ['operation_id' => $id, 'site_ids' => $ids]);

            return DB::table('site_delete_operations')->find($id);
        }, 3);
    }

    public function confirm(string $id, int $actorId, int $rowVersion, string $previewHash): object
    {
        $this->authorize($actorId);
        abort_unless(Str::isUuid($id), 404);

        return DB::transaction(function () use ($id, $actorId, $rowVersion, $previewHash): object {
            $operation = DB::table('site_delete_operations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $operation->actor_id === $actorId, 403);
            if (! hash_equals($operation->preview_hash, $previewHash)) {
                throw new Conflict('WEBSITE_DELETE_PREVIEW_CHANGED');
            }
            if ($operation->status !== 'preview') {
                return $operation;
            }
            if ((int) $operation->row_version !== $rowVersion || now()->gte($operation->expires_at)) {
                throw new Conflict('WEBSITE_DELETE_PREVIEW_STALE', 'Bản xác nhận đã hết hạn hoặc thay đổi. Chọn lại website.');
            }
            $items = DB::table('site_delete_items')->where('operation_id', $id)->orderBy('site_id')->get();
            $sites = DB::table('sites')->whereIn('id', $items->pluck('site_id')->all())->orderBy('id')->sharedLock()->get()->keyBy('id');
            $domains = DB::table('site_domains')->whereIn('site_id', $items->pluck('site_id')->all())->orderBy('host_ascii')->get()->groupBy('site_id');
            foreach ($items as $item) {
                $site = $sites->get($item->site_id) ?? abort(404);
                $this->assertState($site, $operation->action);
                if ((int) $site->row_version !== (int) $item->expected_row_version) {
                    throw new Conflict('WEBSITE_DELETE_SITE_CHANGED');
                }
                $snapshot = Json::decode($item->snapshot_json);
                if ($operation->action === 'delete' && $this->domainManifest($snapshot['domains']) !== $this->domainManifest($domains->get($site->id)?->all() ?? [])) {
                    throw new Conflict('WEBSITE_DELETE_DOMAINS_CHANGED');
                }
                if ($operation->action === 'restore' && $this->domainConflicts($snapshot['domains']) !== []) {
                    throw new Conflict('WEBSITE_RESTORE_DOMAIN_IN_USE', 'Tên miền đã được website khác sử dụng.');
                }
            }
            DB::table('site_delete_operations')->where('id', $id)->update(['status' => 'queued', 'confirmed_at' => now(),
                'row_version' => $rowVersion + 1, 'last_dispatched_at' => null, 'updated_at' => now()]);
            Access::audit('websites.'.$operation->action.'.confirmed', $actorId, null, ['operation_id' => $id, 'total' => $operation->total]);
            DB::afterCommit(fn () => $this->dispatch($id));

            return DB::table('site_delete_operations')->find($id);
        }, 3);
    }

    public function report(string $id, int $actorId): array
    {
        $this->authorize($actorId);
        abort_unless(Str::isUuid($id), 404);
        $operation = DB::table('site_delete_operations')->find($id) ?? abort(404);
        $items = DB::table('site_delete_items')->where('operation_id', $id)->orderBy('id')->paginate(50);
        $items->getCollection()->transform(function (object $item) use ($operation): object {
            $snapshot = Json::decode($item->snapshot_json);
            $item->name = $snapshot['name'];
            $item->domains = $snapshot['domains'];
            $item->domain = $this->domainLabel($snapshot['domains'], $snapshot['base_url']);
            $item->pages_count = $snapshot['pages_count'];
            $item->publication_count = $snapshot['publication_count'];
            $item->row_version = (int) $item->expected_row_version;
            $item->domain_conflicts = $operation->action === 'restore' ? $this->domainConflicts($snapshot['domains']) : [];
            unset($item->snapshot_json, $item->receipt_json);

            return $item;
        });
        $counts = array_fill_keys(['pending', 'completed', 'conflict', 'failed'], 0);
        foreach (DB::table('site_delete_items')->where('operation_id', $id)->selectRaw('status,count(*) total')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->total;
        }
        $counts['total'] = (int) $operation->total;

        return compact('operation', 'items', 'counts');
    }

    public function trash(int $actorId): Builder
    {
        $this->authorize($actorId);

        return DB::table('sites')->join('site_trash_entries as t', 't.site_id', '=', 'sites.id')->join('site_delete_items as i', 'i.id', '=', 't.delete_item_id')
            ->whereNotNull('sites.deleted_at')->select('sites.*', 't.deleted_by', 'i.receipt_json')->orderByDesc('sites.deleted_at')->orderByDesc('sites.id');
    }

    public function decorateTrash(object $site): object
    {
        $receipt = Json::decode($site->receipt_json);
        $site->domains = $receipt['domains'] ?? [];
        $site->pages_count = $receipt['pages_count'] ?? null;
        $site->publication_count = $receipt['publication_count'] ?? null;
        $site->domain_conflicts = $this->domainConflicts($site->domains);
        $site->restore_row_version = (int) $site->row_version;
        unset($site->receipt_json);

        return $site;
    }

    /** At most fifty independent site transactions; DB status recovers dropped/duplicate queue jobs. */
    public function process(string $id): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $lock = 'website-delete-operation:'.$id;
        if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) acquired', [$lock])->acquired) {
            return false;
        }
        $pending = false;
        try {
            $operation = DB::table('site_delete_operations')->find($id);
            if (! $operation || ! in_array($operation->status, ['queued', 'running'], true)) {
                return false;
            }
            DB::table('site_delete_operations')->where('id', $id)->update(['status' => 'running', 'updated_at' => now()]);
            foreach (DB::table('site_delete_items')->where(['operation_id' => $id, 'status' => 'pending'])->orderBy('id')->limit(self::CHUNK)->pluck('id') as $itemId) {
                $this->processItem($operation, (int) $itemId);
            }
            $pending = DB::table('site_delete_items')->where(['operation_id' => $id, 'status' => 'pending'])->exists();
            $errors = DB::table('site_delete_items')->where('operation_id', $id)->whereIn('status', ['conflict', 'failed'])->exists();
            DB::table('site_delete_operations')->where('id', $id)->update(['status' => $pending ? 'running' : ($errors ? 'partial' : 'completed'),
                'last_dispatched_at' => null, 'completed_at' => $pending ? null : now(), 'updated_at' => now()]);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
        }
        // Enqueue only after releasing the operation lock, so a fast second worker can process the next chunk.
        if ($pending) {
            $this->dispatch($id);
        }

        return $pending;
    }

    private function processItem(object $operation, int $itemId): void
    {
        try {
            DB::transaction(function () use ($operation, $itemId): void {
                DB::statement("SET LOCAL lock_timeout='5s'");
                // Public cache writers use this global fence; it precedes host/source/site locks like provisioning.
                DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
                $initial = DB::table('site_delete_items')->where(['id' => $itemId, 'operation_id' => $operation->id])->first();
                if (! $initial || $initial->status !== 'pending') {
                    return;
                }
                $snapshot = Json::decode($initial->snapshot_json);
                $hosts = array_column($snapshot['domains'], 'host_ascii');
                sort($hosts, SORT_STRING);
                foreach ($hosts as $host) {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['website-provision:'.$host]);
                }
                $sourceIds = DB::table('source_site_bindings')->where('site_id', $initial->site_id)->orderBy('connector_id')->pluck('connector_id')->all();
                DB::table('source_connectors')->whereIn('id', $sourceIds)->orderBy('id')->lockForUpdate()->get(['id']);
                $site = DB::table('sites')->where('id', $initial->site_id)->lockForUpdate()->first() ?? abort(404);
                $item = DB::table('site_delete_items')->where('id', $itemId)->lockForUpdate()->first();
                if ($item->status !== 'pending') {
                    return;
                }
                $this->authorize((int) $operation->actor_id);
                $this->assertState($site, $operation->action);
                if ((int) $site->row_version !== (int) $item->expected_row_version) {
                    throw new Conflict('WEBSITE_DELETE_SITE_CHANGED', 'Website đã đổi sau khi xác nhận. Chọn lại để xem thay đổi.');
                }
                $receipt = $operation->action === 'delete' ? $this->deleteSite($site, $item, $snapshot, (int) $operation->actor_id)
                    : $this->restoreSite($site, $snapshot, (int) $operation->actor_id);
                DB::table('site_delete_items')->where('id', $itemId)->update(['status' => 'completed', 'receipt_json' => Json::encode($receipt),
                    'attempts' => DB::raw('attempts+1'), 'error_code' => null, 'error_message' => null, 'completed_at' => now(), 'updated_at' => now()]);
            }, 3);
        } catch (Conflict $error) {
            $this->failure($itemId, $error->reason, $error->getMessage(), true);
        } catch (HttpExceptionInterface $error) {
            $this->failure($itemId, 'WEBSITE_DELETE_PERMISSION_CHANGED', 'Không còn quyền hoặc website không còn khả dụng.', true);
        } catch (Throwable $error) {
            if ($error instanceof QueryException && $error->getCode() === '23505') {
                $this->failure($itemId, 'WEBSITE_RESTORE_DOMAIN_IN_USE', 'Tên miền đã được website khác sử dụng.', true);
            } elseif ($error instanceof QueryException && $error->getCode() === '23514' && str_contains($error->getMessage(), 'CATALOG_REFERENCE_DELETED:')) {
                $this->failure($itemId, 'WEBSITE_RESTORE_DEPENDENCY_DELETED', 'Cấu hình còn tham chiếu mục trong thùng rác. Khôi phục mục liên quan trước.', true);
            } else {
                report($error);
                $this->failure($itemId, 'WEBSITE_DELETE_PROCESSING_FAILED', 'Chưa xử lý được website. Dữ liệu đã được hoàn tác; có thể thử lại.', false);
            }
        }
    }

    private function deleteSite(object $site, object $item, array $snapshot, int $actorId): array
    {
        $domains = DB::table('site_domains')->where('site_id', $site->id)->orderBy('host_ascii')->lockForUpdate()->get()->all();
        if ($this->domainManifest($domains) !== $this->domainManifest($snapshot['domains'])) {
            throw new Conflict('WEBSITE_DELETE_DOMAINS_CHANGED', 'Danh sách domain đã đổi. Chọn lại trước khi xóa.');
        }
        $sources = DB::table('source_site_bindings')->where('site_id', $site->id)->orderBy('id')->lockForUpdate()->get()->all();
        $keywords = DB::table('keyword_bindings')->where('site_id', $site->id)->orderBy('id')->lockForUpdate()->get()->all();
        $receipt = ['site_id' => (int) $site->id, 'original_status' => $site->status, 'domains' => $domains,
            'source_bindings' => $sources, 'keyword_bindings' => $keywords, 'pages_count' => DB::table('pages')->where('site_id', $site->id)->count(),
            'publication_count' => DB::table('page_publications')->where('site_id', $site->id)->count(), 'deleted_by' => $actorId, 'deleted_at' => now()->toIso8601String()];
        // Detach cache while every hostname still belongs to this locked website, then release hostnames.
        app(PublicHtmlCache::class)->invalidateSite((int) $site->id);
        app(PublicHtmlCache::class)->invalidateAll();
        foreach ($sources as $binding) {
            DB::table('source_site_bindings')->where('id', $binding->id)->update(['status' => $binding->deleted_at === null ? 'paused' : 'archived',
                'auto_publish_policy' => Json::encode(array_replace(Json::decode($binding->auto_publish_policy), ['enabled' => false])),
                'mapping_version' => DB::raw('mapping_version+1'), 'link_generation' => DB::raw('link_generation+1')]);
        }
        DB::table('keyword_bindings')->where('site_id', $site->id)->update(['status' => 'paused', 'row_version' => DB::raw('row_version+1')]);
        DB::table('sites')->where('id', $site->id)->update(['status' => 'archived', 'deleted_at' => now(), 'row_version' => DB::raw('row_version+1'),
            'config_epoch' => DB::raw('config_epoch+1'), 'public_content_epoch' => DB::raw('public_content_epoch+1'), 'updated_at' => now()]);
        DB::table('site_domains')->where('site_id', $site->id)->delete();
        DB::table('site_trash_entries')->updateOrInsert(['site_id' => $site->id], ['delete_item_id' => $item->id, 'deleted_by' => $actorId,
            'deleted_at' => now(), 'restored_at' => null]);
        Access::audit('website.deleted', $actorId, (int) $site->id, ['operation_id' => $item->operation_id,
            'released_hostnames' => array_column($domains, 'host_ascii'), 'pages_preserved' => $receipt['pages_count']]);

        return $receipt;
    }

    private function restoreSite(object $site, array $snapshot, int $actorId): array
    {
        $trash = DB::table('site_trash_entries')->where('site_id', $site->id)->lockForUpdate()->first();
        if (! $trash || (int) $trash->delete_item_id !== (int) $snapshot['delete_item_id']) {
            throw new Conflict('WEBSITE_DELETE_RECEIPT_CHANGED');
        }
        $deletedItem = DB::table('site_delete_items')->find($trash->delete_item_id);
        $receipt = Json::decode($deletedItem->receipt_json);
        $conflicts = $this->domainConflicts($receipt['domains']);
        if ($conflicts !== []) {
            throw new Conflict('WEBSITE_RESTORE_DOMAIN_IN_USE', 'Tên miền đã được sử dụng: '.implode(', ', $conflicts));
        }
        if (DB::table('site_domains')->where('site_id', $site->id)->exists()) {
            throw new Conflict('WEBSITE_RESTORE_DOMAINS_CHANGED');
        }
        foreach ($receipt['domains'] as $domain) {
            unset($domain['id']);
            DB::table('site_domains')->insert($domain);
        }
        foreach ($receipt['source_bindings'] as $binding) {
            $current = DB::table('source_site_bindings')->where(['id' => $binding['id'], 'site_id' => $site->id])->lockForUpdate()->first();
            if (! $current) {
                throw new Conflict('WEBSITE_RESTORE_BINDING_MISSING');
            }
            DB::table('source_site_bindings')->where('id', $binding['id'])->update(['status' => $binding['status'],
                'auto_publish_policy' => Json::encode(array_replace(Json::decode($binding['auto_publish_policy']), ['enabled' => false])),
                'mapping_version' => DB::raw('mapping_version+1'), 'link_generation' => DB::raw('link_generation+1')]);
        }
        foreach ($receipt['keyword_bindings'] as $binding) {
            $current = DB::table('keyword_bindings')->where(['id' => $binding['id'], 'site_id' => $site->id])->lockForUpdate()->first();
            if (! $current) {
                throw new Conflict('WEBSITE_RESTORE_BINDING_MISSING');
            }
            DB::table('keyword_bindings')->where('id', $binding['id'])->update(['status' => $binding['status'], 'row_version' => DB::raw('row_version+1')]);
        }
        DB::table('sites')->where('id', $site->id)->update(['status' => 'paused', 'deleted_at' => null, 'row_version' => DB::raw('row_version+1'),
            'config_epoch' => DB::raw('config_epoch+1'), 'public_content_epoch' => DB::raw('public_content_epoch+1'), 'updated_at' => now()]);
        DB::table('site_trash_entries')->where('site_id', $site->id)->update(['restored_at' => now()]);
        app(PublicHtmlCache::class)->invalidateSite((int) $site->id);
        app(PublicHtmlCache::class)->invalidateAll();
        Access::audit('website.restored', $actorId, (int) $site->id, ['delete_item_id' => $deletedItem->id, 'status' => 'paused', 'auto_publish_enabled' => false]);

        return ['site_id' => (int) $site->id, 'delete_item_id' => $deletedItem->id, 'restored_at' => now()->toIso8601String(),
            'status' => 'paused', 'domains' => $receipt['domains'], 'auto_publish_enabled' => false];
    }

    private function failure(int $itemId, string $code, string $message, bool $conflict): void
    {
        DB::transaction(function () use ($itemId, $code, $message, $conflict): void {
            $item = DB::table('site_delete_items')->where('id', $itemId)->lockForUpdate()->first();
            if (! $item || $item->status !== 'pending') {
                return;
            }
            $attempts = (int) $item->attempts + 1;
            $status = $conflict ? 'conflict' : ($attempts < 3 ? 'pending' : 'failed');
            DB::table('site_delete_items')->where('id', $itemId)->update(['status' => $status, 'attempts' => $attempts, 'error_code' => $code,
                'error_message' => mb_substr($message, 0, 2000), 'completed_at' => $status === 'pending' ? null : now(), 'updated_at' => now()]);
        }, 3);
    }

    public function retry(string $id, int $actorId): void
    {
        $this->authorize($actorId);
        abort_unless(Str::isUuid($id), 404);
        DB::transaction(function () use ($id, $actorId): void {
            $operation = DB::table('site_delete_operations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $operation->actor_id === $actorId, 403);
            if (in_array($operation->status, ['queued', 'running', 'preview'], true)) {
                throw new Conflict('WEBSITE_DELETE_BUSY');
            }
            $count = DB::table('site_delete_items')->where(['operation_id' => $id, 'status' => 'failed'])->update(['status' => 'pending', 'attempts' => 0,
                'error_code' => null, 'error_message' => null, 'completed_at' => null, 'updated_at' => now()]);
            if ($count > 0) {
                DB::table('site_delete_operations')->where('id', $id)->update(['status' => 'queued', 'row_version' => DB::raw('row_version+1'),
                    'completed_at' => null, 'last_dispatched_at' => null, 'updated_at' => now()]);
                DB::afterCommit(fn () => $this->dispatch($id));
            }
        }, 3);
    }

    public function dispatchPending(): int
    {
        if (! Schema::hasTable('site_delete_operations')) {
            return 0;
        }
        $count = 0;
        foreach (DB::table('site_delete_operations')->whereIn('status', ['queued', 'running'])
            ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<', now()->subMinutes(5)))
            ->orderBy('created_at')->limit(100)->pluck('id') as $id) {
            $count += (int) $this->dispatch($id);
        }

        return $count;
    }

    private function dispatch(string $id): bool
    {
        $claimed = DB::table('site_delete_operations')->where('id', $id)->whereIn('status', ['queued', 'running'])
            ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<', now()->subMinutes(5)))
            ->update(['last_dispatched_at' => now()]);
        if (! $claimed) {
            return false;
        }
        try {
            RunWebsiteDeletion::dispatch($id)->onQueue('websites');
        } catch (Throwable $error) {
            DB::table('site_delete_operations')->where('id', $id)->update(['last_dispatched_at' => null]);
            report($error);

            return false;
        }

        return true;
    }

    private function assertState(object $site, string $action): void
    {
        if (($action === 'delete' && $site->deleted_at !== null) || ($action === 'restore' && $site->deleted_at === null)) {
            throw new Conflict($action === 'delete' ? 'WEBSITE_ALREADY_DELETED' : 'WEBSITE_NOT_DELETED');
        }
    }

    private function domainManifest(array $domains): array
    {
        $rows = array_map(function ($domain): array {
            $domain = (array) $domain;

            return [$domain['host_ascii'], (bool) $domain['is_primary'], $domain['status']];
        }, $domains);
        sort($rows);

        return $rows;
    }

    private function domainLabel(array $domains, ?string $baseUrl): string
    {
        foreach ($domains as $domain) {
            $domain = (array) $domain;
            if ($domain['is_primary']) {
                return $domain['host_ascii'];
            }
        }

        return isset($domains[0]) ? ((array) $domains[0])['host_ascii'] : ($baseUrl ?? 'Chưa có domain');
    }

    private function domainConflicts(array $domains): array
    {
        $hosts = array_map(fn ($domain): string => ((array) $domain)['host_ascii'], $domains);

        return $hosts === [] ? [] : DB::table('site_domains')->whereIn('host_ascii', $hosts)->orderBy('host_ascii')->pluck('host_ascii')->all();
    }
}
