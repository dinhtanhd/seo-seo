<?php

namespace App\Domain\Keywords;

use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

final class SitePoolBindingService
{
    public function authorize(int $actorId, int $siteId): void
    {
        abort_unless(DB::table('sites')->where('id', $siteId)->exists(), 404);
        Access::site($actorId, $siteId);
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin')
            || DB::table('site_memberships')->where(['user_id' => $actorId, 'site_id' => $siteId, 'role' => 'owner'])->exists(), 403);
    }

    public function save(int $siteId, ?int $bindingId, array $data, int $actorId): object
    {
        $this->authorize($actorId, $siteId);

        return DB::transaction(function () use ($siteId, $bindingId, $data, $actorId): object {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            $this->authorize($actorId, $siteId);
            $binding = $bindingId ? DB::table('keyword_bindings')->where(['id' => $bindingId, 'site_id' => $siteId])->lockForUpdate()->first() : null;
            if ($bindingId) {
                abort_unless($binding, 404);
                if ((int) $binding->row_version !== (int) $data['row_version']) {
                    throw new Conflict('BINDING_VERSION_CONFLICT');
                }
            }
            $poolId = $binding ? $binding->pool_id : (int) $data['pool_id'];
            $pool = DB::table('keyword_pools')->where('id', $poolId)->sharedLock()->first();
            abort_unless($pool, 422, 'Kho keyword không tồn tại.');
            app(PoolCatalogService::class)->accessiblePool($poolId, $actorId);
            abort_unless($pool->scope === 'shared' || (int) $pool->owner_site_id === $siteId, 422, 'Kho riêng chỉ được gắn vào website sở hữu.');
            abort_if($pool->deleted_at && ($binding === null || $data['status'] === 'active'), 422, 'Kho đã xóa. Khôi phục kho trước khi bật liên kết.');
            $values = array_intersect_key($data, array_flip(['group_id', 'page_type', 'category_id', 'priority', 'status']));
            foreach (['group_id', 'page_type', 'category_id'] as $field) {
                if (! array_key_exists($field, $values)) {
                    $values[$field] = $binding?->{$field};
                }
            }
            if (! empty($values['group_id'])) {
                $group = DB::table('keyword_groups')->where(['id' => $values['group_id'], 'pool_id' => $poolId])->first();
                abort_unless($group, 422, 'Nhóm phải thuộc kho keyword đã chọn.');
                abort_if($group->deleted_at && $data['status'] === 'active', 422, 'Nhóm đã xóa. Khôi phục nhóm trước khi bật liên kết.');
            }
            if (! empty($values['category_id'])) {
                abort_unless(CatalogAvailability::filter(DB::table('categories'), 'categories')->where(['id' => $values['category_id'], 'site_id' => $siteId])->exists(), 422, 'Danh mục phải thuộc website này và chưa bị xóa.');
            }
            $filters = $data['filters'] ?? Json::decode($binding?->filters_json ?? '{}');
            KeywordService::validateFilters($filters, Json::decode($binding?->filters_json ?? '{}'));
            $values['filters_json'] = Json::encode($filters);
            if ($binding) {
                $id = $binding->id;
                DB::table('keyword_bindings')->where('id', $id)->update($values + ['row_version' => $binding->row_version + 1]);
            } else {
                $duplicate = DB::table('keyword_bindings')->where(['site_id' => $siteId, 'pool_id' => $poolId])->whereNull('content_item_id')->whereRaw('filters_json = ?::jsonb', [$values['filters_json']]);
                foreach (['group_id', 'page_type', 'category_id'] as $field) {
                    $duplicate->where($field, $values[$field]);
                }
                if ($duplicate->exists()) {
                    throw new Conflict('BINDING_ALREADY_EXISTS');
                }
                $id = DB::table('keyword_bindings')->insertGetId($values + ['site_id' => $siteId, 'pool_id' => $poolId]);
            }
            DB::table('sites')->where('id', $siteId)->increment('config_epoch');
            Access::audit($binding ? 'binding.updated' : 'binding.created', $actorId, $siteId, ['binding_id' => $id]);

            return DB::table('keyword_bindings')->find($id);
        }, 3);
    }

    public function archive(int $siteId, int $bindingId, int $version, int $actorId): void
    {
        $this->authorize($actorId, $siteId);
        DB::transaction(function () use ($siteId, $bindingId, $version, $actorId): void {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            $this->authorize($actorId, $siteId);
            $binding = DB::table('keyword_bindings')->where(['id' => $bindingId, 'site_id' => $siteId])->lockForUpdate()->first() ?? abort(404);
            if ((int) $binding->row_version !== $version) {
                throw new Conflict('BINDING_VERSION_CONFLICT');
            }
            DB::table('keyword_bindings')->where('id', $bindingId)->update(['status' => 'archived', 'row_version' => $version + 1]);
            DB::table('sites')->where('id', $siteId)->increment('config_epoch');
            Access::audit('binding.archived', $actorId, $siteId, ['binding_id' => $bindingId]);
        }, 3);
    }

    public function limit(int $siteId, int $keywordId, array $data, int $actorId): void
    {
        $this->authorize($actorId, $siteId);
        DB::transaction(function () use ($siteId, $keywordId, $data, $actorId): void {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            $this->authorize($actorId, $siteId);
            if ((int) $site->config_epoch !== (int) $data['site_config_epoch']) {
                throw new Conflict('SITE_CONFIG_CHANGED');
            }
            $keyword = DB::table('keywords')->find($keywordId) ?? abort(404);
            abort_unless(DB::table('keyword_bindings')->where(['site_id' => $siteId, 'pool_id' => $keyword->pool_id])->exists(), 422, 'Keyword phải nằm trong kho đã gắn vào website.');
            if ($data['inherit']) {
                DB::table('site_keyword_limits')->where(['site_id' => $siteId, 'keyword_id' => $keywordId])->delete();
            } else {
                DB::table('site_keyword_limits')->updateOrInsert(['site_id' => $siteId, 'keyword_id' => $keywordId], ['max_active_pages' => $data['max_active_pages'] ?? null]);
            }
            DB::table('sites')->where('id', $siteId)->increment('config_epoch');
            Access::audit('keyword.site_limit.updated', $actorId, $siteId, ['keyword_id' => $keywordId, 'inherit' => $data['inherit']]);
        }, 3);
    }
}
