<?php

namespace App\Domain\Keywords;

use App\Domain\Support\Access;
use App\Domain\Support\CatalogUsageGuard;
use App\Domain\Support\Conflict;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PoolCatalogService
{
    public function visiblePools(int $actorId): Collection
    {
        return $this->poolQuery($actorId)->orderBy('name')->orderBy('id')->get();
    }

    public function accessiblePool(int $poolId, int $actorId, bool $write = false, bool $allowDeleted = true): object
    {
        $pool = DB::table('keyword_pools')->find($poolId);
        abort_unless($pool, 404);
        abort_unless($this->poolQuery($actorId)->where('id', $poolId)->exists(), 403);
        abort_if($write && ! $this->canWrite($pool, $actorId), 403);
        abort_if(! $allowDeleted && $pool->deleted_at !== null, 404);

        return $pool;
    }

    public function canWrite(object $pool, int $actorId): bool
    {
        $actor = DB::table('users')->find($actorId);
        if (! $actor) {
            return false;
        }
        if ($actor->is_global_admin) {
            return true;
        }
        if ($pool->scope !== 'site' || ! $pool->owner_site_id) {
            return false;
        }

        return DB::table('site_memberships')->where('user_id', $actorId)->where('site_id', $pool->owner_site_id)->whereIn('role', ['owner', 'operator'])->exists();
    }

    public function create(array $data, int $actorId): object
    {
        $name = $this->name($data['name'] ?? null);
        $locale = $this->locale($data['locale'] ?? 'zh-CN');
        $status = $this->status($data['status'] ?? 'active');

        return DB::transaction(function () use ($name, $locale, $status, $actorId) {
            $this->lockSystem();
            abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
            $this->uniquePoolName($name);
            $id = DB::table('keyword_pools')->insertGetId(['name' => $name, 'locale' => $locale, 'scope' => 'shared', 'owner_site_id' => null,
                'status' => $status, 'row_version' => 1, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->recordChange($id, $actorId, 'pool.created', ['pool_id' => $id], false);

            return DB::table('keyword_pools')->find($id);
        }, 3);
    }

    public function update(int $id, array $data, int $actorId): object
    {
        $version = $this->version($data['row_version'] ?? null);
        $changes = $this->metadata($data, true);

        return $this->mutatePool($id, $version, $actorId, 'pool.updated', function ($pool) use ($changes) {
            if (isset($changes['name'])) {
                $this->uniquePoolName($changes['name'], (int) $pool->id);
            }

            return $changes;
        });
    }

    public function delete(int $id, int $rowVersion, int $actorId): object
    {
        return $this->mutatePool($id, $rowVersion, $actorId, 'pool.deleted', function () use ($id): array {
            app(CatalogUsageGuard::class)->assertUnused('pool', $id);

            return ['status' => 'archived', 'deleted_at' => now()];
        });
    }

    public function restore(int $id, int $rowVersion, int $actorId): object
    {
        return $this->mutatePool($id, $rowVersion, $actorId, 'pool.restored', function ($pool) {
            if ($pool->deleted_at === null) {
                throw new Conflict('POOL_NOT_DELETED');
            }

            return ['status' => 'paused', 'deleted_at' => null];
        }, true);
    }

    public function createGroup(array $data, int $actorId): object
    {
        $poolId = $this->positiveInteger($data['pool_id'] ?? null, 'Pool không hợp lệ.');
        $poolVersion = $this->version($data['pool_row_version'] ?? null);
        $name = $this->name($data['name'] ?? null);
        $status = $this->status($data['status'] ?? 'active');
        $ids = $this->memberIds($data['keyword_ids'] ?? []);

        return DB::transaction(function () use ($poolId, $poolVersion, $name, $status, $ids, $actorId) {
            $this->lockSystem();
            $pool = $this->lockPool($poolId, $actorId);
            $this->checkVersion($pool, $poolVersion, 'POOL_VERSION_CONFLICT');
            $this->requireLiveRecord($pool, 'POOL_DELETED');
            $this->uniqueGroupName($poolId, $name);
            $this->validateMembers($poolId, $ids);
            $id = DB::table('keyword_groups')->insertGetId(['pool_id' => $poolId, 'name' => $name, 'status' => $status, 'row_version' => 1, 'deleted_at' => null]);
            $this->replaceMembers($poolId, $id, $ids);
            $this->recordChange($poolId, $actorId, 'group.created', ['pool_id' => $poolId, 'group_id' => $id, 'keyword_ids' => $ids]);

            return DB::table('keyword_groups')->find($id);
        }, 3);
    }

    public function updateGroup(int $id, array $data, int $actorId): object
    {
        $version = $this->version($data['row_version'] ?? null);
        $changes = $this->metadata($data);
        $ids = array_key_exists('keyword_ids', $data) ? $this->memberIds($data['keyword_ids']) : null;

        return $this->mutateGroup($id, $version, $actorId, 'group.updated', function ($group, $pool) use ($changes, $ids) {
            if (isset($changes['name'])) {
                $this->uniqueGroupName((int) $pool->id, $changes['name'], (int) $group->id);
            }
            if ($ids !== null) {
                $existing = DB::table('keyword_group_members')->where('group_id', $group->id)->pluck('keyword_id')->map(fn ($id) => (int) $id)->all();
                $this->validateMembers((int) $pool->id, $ids, $existing);
                $this->replaceMembers((int) $pool->id, (int) $group->id, $ids);
            }

            return $changes;
        }, false, $ids === null ? [] : ['keyword_ids' => $ids]);
    }

    public function deleteGroup(int $id, int $rowVersion, int $actorId): object
    {
        return $this->mutateGroup($id, $rowVersion, $actorId, 'group.deleted', function () use ($id): array {
            app(CatalogUsageGuard::class)->assertUnused('group', $id);

            return ['status' => 'archived', 'deleted_at' => now()];
        });
    }

    public function restoreGroup(int $id, int $rowVersion, int $actorId): object
    {
        return $this->mutateGroup($id, $rowVersion, $actorId, 'group.restored', function ($group) {
            if ($group->deleted_at === null) {
                throw new Conflict('GROUP_NOT_DELETED');
            }

            return ['status' => 'paused', 'deleted_at' => null];
        }, true);
    }

    private function poolQuery(int $actorId): Builder
    {
        $actor = DB::table('users')->find($actorId);
        abort_unless($actor, 403);
        $query = DB::table('keyword_pools');
        if (! $actor->is_global_admin) {
            $sites = DB::table('site_memberships')->where('user_id', $actorId)->select('site_id');
            $query->where(fn ($q) => $q->whereIn('owner_site_id', clone $sites)->orWhereIn('id', DB::table('keyword_bindings')->whereIn('site_id', clone $sites)->select('pool_id')));
        }

        return $query;
    }

    private function lockSystem(): void
    {
        DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
    }

    private function lockPool(int $id, int $actorId): object
    {
        $pool = DB::table('keyword_pools')->where('id', $id)->lockForUpdate()->first();
        abort_unless($pool, 404);
        abort_unless($this->canWrite($pool, $actorId), 403);

        return $pool;
    }

    private function mutatePool(int $id, int $rowVersion, int $actorId, string $action, callable $mutation, bool $allowDeleted = false): object
    {
        $this->version($rowVersion);

        return DB::transaction(function () use ($id, $rowVersion, $actorId, $action, $mutation, $allowDeleted) {
            $this->lockSystem();
            $pool = $this->lockPool($id, $actorId);
            $this->checkVersion($pool, $rowVersion, 'POOL_VERSION_CONFLICT');
            if (! $allowDeleted) {
                $this->requireLiveRecord($pool, 'POOL_DELETED');
            }
            $changes = $mutation($pool);
            if ($changes) {
                DB::table('keyword_pools')->where('id', $id)->update($changes);
            }
            $this->recordChange($id, $actorId, $action, ['pool_id' => $id, 'changes' => array_keys($changes)]);

            return DB::table('keyword_pools')->find($id);
        }, 3);
    }

    private function mutateGroup(int $id, int $rowVersion, int $actorId, string $action, callable $mutation, bool $allowDeleted = false, array $details = []): object
    {
        $this->version($rowVersion);
        $initial = DB::table('keyword_groups')->find($id);
        abort_unless($initial, 404);

        return DB::transaction(function () use ($id, $rowVersion, $actorId, $action, $mutation, $allowDeleted, $initial, $details) {
            $this->lockSystem();
            $pool = $this->lockPool((int) $initial->pool_id, $actorId);
            $this->requireLiveRecord($pool, 'POOL_DELETED');
            $group = DB::table('keyword_groups')->where('id', $id)->where('pool_id', $pool->id)->lockForUpdate()->first();
            abort_unless($group, 404);
            $this->checkVersion($group, $rowVersion, 'GROUP_VERSION_CONFLICT');
            if (! $allowDeleted) {
                $this->requireLiveRecord($group, 'GROUP_DELETED');
            }
            $changes = $mutation($group, $pool);
            DB::table('keyword_groups')->where('id', $id)->update($changes + ['row_version' => DB::raw('row_version+1')]);
            $this->recordChange((int) $pool->id, $actorId, $action, ['pool_id' => $pool->id, 'group_id' => $id, 'changes' => array_keys($changes)] + $details);

            return DB::table('keyword_groups')->find($id);
        }, 3);
    }

    private function recordChange(int $poolId, int $actorId, string $action, array $details, bool $bumpPool = true): void
    {
        if ($bumpPool) {
            DB::table('keyword_pools')->where('id', $poolId)->update(['row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
        }
        DB::table('system_settings')->where('id', 1)->increment('config_epoch');
        Access::audit($action, $actorId, DB::table('keyword_pools')->where('id', $poolId)->value('owner_site_id'), $details);
    }

    private function checkVersion(object $record, int $expected, string $reason): void
    {
        if ((int) $record->row_version !== $expected) {
            throw new Conflict($reason);
        }
    }

    private function requireLiveRecord(object $record, string $reason): void
    {
        if ($record->deleted_at !== null) {
            throw new Conflict($reason);
        }
    }

    private function uniquePoolName(string $name, ?int $except = null): void
    {
        $query = DB::table('keyword_pools')->where('name', $name);
        if ($except !== null) {
            $query->where('id', '<>', $except);
        }
        if ($query->exists()) {
            throw new Conflict('POOL_NAME_ALREADY_EXISTS');
        }
    }

    private function uniqueGroupName(int $poolId, string $name, ?int $except = null): void
    {
        $query = DB::table('keyword_groups')->where('pool_id', $poolId)->where('name', $name);
        if ($except !== null) {
            $query->where('id', '<>', $except);
        }
        if ($query->exists()) {
            throw new Conflict('GROUP_NAME_ALREADY_EXISTS');
        }
    }

    private function metadata(array $data, bool $pool = false): array
    {
        $changes = [];
        if (array_key_exists('name', $data)) {
            $changes['name'] = $this->name($data['name']);
        }
        if (array_key_exists('status', $data)) {
            $changes['status'] = $this->status($data['status']);
        }
        if ($pool && array_key_exists('locale', $data)) {
            $changes['locale'] = $this->locale($data['locale']);
        }

        return $changes;
    }

    private function name(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Tên kho/nhóm phải là chuỗi.');
        }
        $name = KeywordService::normalize($value);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('Tên kho/nhóm cần từ 1 đến 200 ký tự.');
        }

        return $name;
    }

    private function locale(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 16 || ! preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', $value)) {
            throw new \InvalidArgumentException('Locale không hợp lệ.');
        }

        return $value;
    }

    private function status(mixed $value): string
    {
        if (! in_array($value, ['active', 'paused', 'archived'], true)) {
            throw new \InvalidArgumentException('Status không hợp lệ.');
        }

        return $value;
    }

    private function version(mixed $value): int
    {
        return $this->positiveInteger($value, 'Cần phiên bản hiện tại để lưu thay đổi.');
    }

    private function positiveInteger(mixed $value, string $message): int
    {
        if ((! is_int($value) && ! is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new \InvalidArgumentException($message);
        }

        return (int) $value;
    }

    private function memberIds(mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 10000) {
            throw new \InvalidArgumentException('Danh sách keyword phải là mảng tối đa 10.000 ID.');
        }
        $ids = array_map(fn ($id) => $this->positiveInteger($id, 'ID keyword không hợp lệ.'), $ids);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Danh sách keyword không được trùng ID.');
        }

        return $ids;
    }

    private function validateMembers(int $poolId, array $ids, array $existing = []): void
    {
        $keywords = DB::table('keywords')->where('pool_id', $poolId)->whereIn('id', $ids)->get(['id', 'deleted_at']);
        if ($keywords->count() !== count($ids)) {
            throw new \InvalidArgumentException('Nhóm và keyword phải cùng pool; ID phải tồn tại.');
        }
        foreach ($keywords as $keyword) {
            if ($keyword->deleted_at !== null && ! in_array((int) $keyword->id, $existing, true)) {
                throw new \InvalidArgumentException('Không thể thêm keyword đã xóa vào nhóm.');
            }
        }
    }

    private function replaceMembers(int $poolId, int $groupId, array $ids): void
    {
        DB::table('keyword_group_members')->where('group_id', $groupId)->delete();
        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table('keyword_group_members')->insert(array_map(fn ($id) => ['pool_id' => $poolId, 'group_id' => $groupId, 'keyword_id' => $id], $chunk));
        }
    }
}
