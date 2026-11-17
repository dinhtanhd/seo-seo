<?php

namespace App\Domain\Sites;

use App\Domain\Advertising\AdvertisingService;
use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Sites\RunWebsiteBulkEdit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Domain configuration jobs never republish films or reassign keywords. */
final class WebsiteBulkEdit
{
    public const CHUNK = 10;

    public const MAX_SITES = 10000;

    public function create(array $siteIds, array $changes, int $actorId, string $idempotencyKey): object
    {
        if (! array_is_list($siteIds) || $siteIds === [] || count($siteIds) > self::MAX_SITES
            || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 160) {
            throw ValidationException::withMessages(['site_ids' => 'Chọn 1–10.000 domain và dùng mã yêu cầu hợp lệ. Có thể gửi nhiều lượt.']);
        }
        foreach ($siteIds as &$siteId) {
            if ((! is_int($siteId) && ! is_string($siteId)) || filter_var($siteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages(['site_ids' => 'Danh sách cần chứa ID website hợp lệ.']);
            }
            $siteId = (int) $siteId;
        }
        unset($siteId);
        $siteIds = array_values(array_unique($siteIds));
        sort($siteIds);
        $changes = $this->normalize($changes);
        $hash = Json::hash([$siteIds, $changes]);

        return DB::transaction(function () use ($siteIds, $changes, $actorId, $idempotencyKey, $hash): object {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['website-bulk-edit-request:'.$actorId.':'.$idempotencyKey]);
            $this->authorize($actorId, $siteIds, array_key_exists('theme_id', $changes));
            $existing = DB::table('website_bulk_edit_operations')->where(['actor_id' => $actorId, 'request_key' => $idempotencyKey])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new Conflict('WEBSITE_BULK_EDIT_REQUEST_CHANGED', 'Mã yêu cầu đã dùng cho nội dung khác. Tải lại biểu mẫu để tạo lượt mới.');
                }

                return $existing;
            }
            $sites = DB::table('sites')->whereIn('id', $siteIds)->orderBy('id')->get(['id', 'status', 'content_type', 'row_version']);
            if ($sites->count() !== count($siteIds)) {
                abort(403);
            }
            if ($sites->contains(fn ($site): bool => $site->status !== 'active' || $site->content_type !== 'movie')) {
                throw ValidationException::withMessages(['site_ids' => 'Chỉ chọn các website phim đang hoạt động.']);
            }
            $themeVersion = null;
            if (isset($changes['theme_id'])) {
                $theme = DB::table('movie_themes')->find($changes['theme_id']);
                if (! $theme || $theme->status !== 'active' || ! $theme->current_version_id) {
                    throw ValidationException::withMessages(['theme_id' => 'Chọn giao diện đang hoạt động và có phiên bản hợp lệ.']);
                }
                $themeVersion = (int) $theme->current_version_id;
            }
            $preferences = DB::table('site_advertising_settings')->whereIn('site_id', $siteIds)->pluck('row_version', 'site_id');
            $id = (string) Str::uuid();
            DB::table('website_bulk_edit_operations')->insert(['id' => $id, 'actor_id' => $actorId, 'request_key' => $idempotencyKey,
                'request_hash' => $hash, 'changes_json' => Json::encode($changes), 'theme_version_id' => $themeVersion,
                'status' => 'queued', 'total' => count($siteIds), 'created_at' => now(), 'updated_at' => now()]);
            $items = $sites->map(fn ($site): array => ['operation_id' => $id, 'site_id' => $site->id,
                'expected_site_version' => $site->row_version, 'expected_advertising_version' => (int) ($preferences[$site->id] ?? 0),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()])->all();
            foreach (array_chunk($items, 500) as $chunk) {
                DB::table('website_bulk_edit_items')->insert($chunk);
            }
            Access::audit('websites.bulk.edit.requested', $actorId, null, ['operation_id' => $id, 'total' => count($siteIds), 'changes' => $changes]);
            DB::afterCommit(fn () => RunWebsiteBulkEdit::dispatch($id));

            return DB::table('website_bulk_edit_operations')->find($id);
        }, 3);
    }

    public function report(string $id, int $actorId): array
    {
        $operation = DB::table('website_bulk_edit_operations')->find($id) ?? abort(404);
        abort_unless((int) $operation->actor_id === $actorId, 403);
        $siteIds = DB::table('website_bulk_edit_items')->where('operation_id', $id)->pluck('site_id')->all();
        $changes = Json::decode($operation->changes_json);
        $this->authorize($actorId, $siteIds, array_key_exists('theme_id', $changes));
        $counts = array_fill_keys(['pending', 'running', 'completed', 'conflict', 'failed'], 0);
        foreach (DB::table('website_bulk_edit_items')->where('operation_id', $id)->selectRaw('status,count(*) total')->groupBy('status')->get() as $count) {
            $counts[$count->status] = (int) $count->total;
        }

        return ['operation' => $operation, 'id' => $id, 'status' => $operation->status, 'total' => (int) $operation->total,
            'counts' => $counts, 'changes' => $changes,
            'items' => DB::table('website_bulk_edit_items')->where('operation_id', $id)->orderBy('id')->limit(100)->get(),
            'has_more' => $operation->total > 100, 'created_at' => $operation->created_at, 'updated_at' => $operation->updated_at];
    }

    /** Return true while durable pending items remain; duplicate jobs serialize by operation. */
    public function process(string $id): bool
    {
        $key = 'website-bulk-edit:'.$id;
        if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) acquired', [$key])->acquired) {
            return false;
        }
        try {
            $operation = DB::table('website_bulk_edit_operations')->find($id);
            if (! $operation || ! in_array($operation->status, ['queued', 'processing'], true)) {
                return false;
            }
            DB::table('website_bulk_edit_operations')->where('id', $id)->update(['status' => 'processing', 'updated_at' => now()]);
            $items = DB::table('website_bulk_edit_items')->where('operation_id', $id)->whereIn('status', ['pending', 'running'])->orderBy('id')->limit(self::CHUNK)->pluck('id');
            foreach ($items as $itemId) {
                $this->item($operation, (int) $itemId);
            }
            if (DB::table('website_bulk_edit_items')->where('operation_id', $id)->whereIn('status', ['pending', 'running'])->exists()) {
                return true;
            }
            $failures = DB::table('website_bulk_edit_items')->where('operation_id', $id)->whereIn('status', ['conflict', 'failed'])->count();
            $success = DB::table('website_bulk_edit_items')->where('operation_id', $id)->where('status', 'completed')->exists();
            DB::table('website_bulk_edit_operations')->where('id', $id)->update(['status' => $failures === 0 ? 'completed' : ($success ? 'partial' : 'failed'),
                'completed_at' => now(), 'updated_at' => now()]);

            return false;
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
        }
    }

    private function item(object $operation, int $itemId): void
    {
        try {
            DB::transaction(function () use ($operation, $itemId): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                $initial = DB::table('website_bulk_edit_items')->find($itemId);
                if (! $initial || $initial->operation_id !== $operation->id) {
                    return;
                }
                $site = DB::table('sites')->where('id', $initial->site_id)->lockForUpdate()->first();
                $item = DB::table('website_bulk_edit_items')->where('id', $itemId)->lockForUpdate()->first();
                if (! in_array($item->status, ['pending', 'running'], true)) {
                    return;
                }
                $changes = Json::decode($operation->changes_json);
                $this->authorize((int) $operation->actor_id, [(int) $item->site_id], array_key_exists('theme_id', $changes));
                if (! $site || $site->status !== 'active' || $site->content_type !== 'movie') {
                    throw new Conflict('WEBSITE_BULK_EDIT_SITE_UNAVAILABLE');
                }
                if ((int) $site->row_version !== (int) $item->expected_site_version) {
                    throw new Conflict('WEBSITE_BULK_EDIT_SITE_CHANGED');
                }
                if (array_key_exists('theme_id', $changes)) {
                    if ($changes['theme_id'] !== null) {
                        $theme = DB::table('movie_themes')->find($changes['theme_id']);
                        if (! $theme || $theme->status !== 'active' || (int) $theme->current_version_id !== (int) $operation->theme_version_id) {
                            throw new Conflict('WEBSITE_BULK_EDIT_THEME_CHANGED');
                        }
                    }
                    $site = app(SitePresentationService::class)->save((int) $site->id, SitePresentationService::profile($site) + [
                        'theme_id' => $changes['theme_id'], 'row_version' => (int) $site->row_version], (int) $operation->actor_id);
                    $site = app(SiteAppearance::class)->activate((int) $site->id, (int) $operation->actor_id, (int) $site->row_version);
                }
                if (array_key_exists('advertising_regular', $changes) || array_key_exists('advertising_fanmulu', $changes)) {
                    $advertising = app(AdvertisingService::class);
                    $current = $advertising->preferences((int) $site->id);
                    if ((int) $current->row_version !== (int) $item->expected_advertising_version) {
                        throw new Conflict('WEBSITE_BULK_EDIT_ADVERTISING_CHANGED');
                    }
                    $advertising->saveSite((int) $site->id, ['row_version' => (int) $current->row_version,
                        'regular_enabled' => $changes['advertising_regular'] ?? (bool) $current->regular_enabled,
                        'fanmulu_enabled' => $changes['advertising_fanmulu'] ?? (bool) $current->fanmulu_enabled], (int) $operation->actor_id);
                } elseif (! array_key_exists('theme_id', $changes) && ($changes['clear_cache'] ?? false)) {
                    app(PublicHtmlCache::class)->invalidateSite((int) $site->id);
                }
                DB::table('website_bulk_edit_items')->where('id', $itemId)->update(['status' => 'completed', 'error_code' => null,
                    'attempts' => DB::raw('attempts+1'), 'completed_at' => now(), 'updated_at' => now()]);
                Access::audit('website.bulk.edit.applied', (int) $operation->actor_id, (int) $site->id,
                    ['operation_id' => $operation->id, 'changes' => $changes]);
            }, 3);
        } catch (Conflict $error) {
            $this->failure($itemId, $error->reason, true);
        } catch (HttpExceptionInterface $error) {
            $this->failure($itemId, $error->getStatusCode() === 403 ? 'WEBSITE_BULK_EDIT_PERMISSION_CHANGED' : 'WEBSITE_BULK_EDIT_SITE_UNAVAILABLE', true);
        } catch (ValidationException|\InvalidArgumentException $error) {
            $this->failure($itemId, 'WEBSITE_BULK_EDIT_CONFIGURATION_INVALID', true);
        } catch (Throwable $error) {
            report($error);
            $this->failure($itemId, 'WEBSITE_BULK_EDIT_PROCESSING_FAILED', false);
        }
    }

    private function failure(int $itemId, string $reason, bool $conflict): void
    {
        DB::transaction(function () use ($itemId, $reason, $conflict): void {
            $item = DB::table('website_bulk_edit_items')->where('id', $itemId)->lockForUpdate()->first();
            if (! $item || ! in_array($item->status, ['pending', 'running'], true)) {
                return;
            }
            $attempts = (int) $item->attempts + 1;
            $status = $conflict ? 'conflict' : ($attempts < 3 ? 'pending' : 'failed');
            DB::table('website_bulk_edit_items')->where('id', $itemId)->update(['status' => $status, 'error_code' => $reason,
                'attempts' => $attempts, 'completed_at' => $status === 'pending' ? null : now(), 'updated_at' => now()]);
        }, 3);
    }

    /** Failed technical attempts retain their original CAS; conflicts require a new explicit request. */
    public function retry(string $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId): void {
            if (! DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0)) acquired', ['website-bulk-edit:'.$id])->acquired) {
                throw new Conflict('WEBSITE_BULK_EDIT_BUSY');
            }
            $operation = DB::table('website_bulk_edit_operations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $operation->actor_id === $actorId, 403);
            $siteIds = DB::table('website_bulk_edit_items')->where('operation_id', $id)->pluck('site_id')->all();
            $this->authorize($actorId, $siteIds, array_key_exists('theme_id', Json::decode($operation->changes_json)));
            if (in_array($operation->status, ['queued', 'processing'], true)) {
                throw new Conflict('WEBSITE_BULK_EDIT_BUSY');
            }
            $count = DB::table('website_bulk_edit_items')->where('operation_id', $id)->where('status', 'failed')->update([
                'status' => 'pending', 'attempts' => 0, 'error_code' => null, 'completed_at' => null, 'updated_at' => now()]);
            if ($count === 0) {
                return;
            }
            DB::table('website_bulk_edit_operations')->where('id', $id)->update(['status' => 'queued', 'completed_at' => null, 'updated_at' => now()]);
            Access::audit('websites.bulk.edit.retried', $actorId, null, ['operation_id' => $id, 'total' => $count]);
            DB::afterCommit(fn () => RunWebsiteBulkEdit::dispatch($id));
        }, 3);
    }

    public function dispatchPending(): int
    {
        $ids = DB::table('website_bulk_edit_operations')->whereIn('status', ['queued', 'processing'])->orderBy('created_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            RunWebsiteBulkEdit::dispatch($id);
        }

        return $ids->count();
    }

    private function authorize(int $actorId, array $siteIds, bool $owner): void
    {
        $user = DB::table('users')->find($actorId);
        abort_unless($user, 403);
        if (! $user->is_global_admin) {
            $count = DB::table('site_memberships')->where('user_id', $actorId)->whereIn('role', $owner ? ['owner'] : ['owner', 'operator'])->whereIn('site_id', $siteIds)->count();
            abort_unless($count === count($siteIds), 403);
        }
    }

    private function normalize(array $changes): array
    {
        if (array_diff(array_keys($changes), ['theme_id', 'advertising_regular', 'advertising_fanmulu', 'clear_cache'])) {
            throw ValidationException::withMessages(['changes' => 'Trường sửa hàng loạt chưa được hỗ trợ.']);
        }
        if (array_key_exists('theme_id', $changes) && $changes['theme_id'] !== null) {
            $themeId = $changes['theme_id'];
            if ((! is_int($themeId) && ! is_string($themeId)) || filter_var($themeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages(['theme_id' => 'ID giao diện không hợp lệ.']);
            }
            $changes['theme_id'] = (int) $themeId;
        }
        foreach (['advertising_regular', 'advertising_fanmulu', 'clear_cache'] as $field) {
            if (array_key_exists($field, $changes) && ! is_bool($changes[$field])) {
                throw ValidationException::withMessages([$field => 'Lựa chọn cần là bật, tắt hoặc giữ nguyên.']);
            }
        }
        if (! ($changes['clear_cache'] ?? false)) {
            unset($changes['clear_cache']);
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'Chọn ít nhất một cấu hình cần thay đổi hoặc xóa cache.']);
        }
        ksort($changes);

        return $changes;
    }
}
