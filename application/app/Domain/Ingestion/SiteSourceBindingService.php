<?php

namespace App\Domain\Ingestion;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SiteSourceBindingService
{
    private function owner(int $siteId, int $actorId): void
    {
        Access::site($actorId, $siteId);
        $global = DB::table('users')->where('id', $actorId)->value('is_global_admin');
        abort_unless($global || DB::table('site_memberships')->where(['site_id' => $siteId, 'user_id' => $actorId, 'role' => 'owner'])->exists(), 403, 'Liên kết nguồn cần owner website hoặc quản trị toàn hệ thống.');
    }

    public function create(int $siteId, int $sourceId, int $actorId, bool $autoPublish = false): object
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Chỉ quản trị toàn hệ thống được gắn thêm nguồn dùng chung.');

        return DB::transaction(function () use ($siteId, $sourceId, $actorId, $autoPublish) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $source = DB::table('source_connectors')->where('id', $sourceId)->lockForUpdate()->first() ?? abort(404);
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            $this->owner($site->id, $actorId);
            abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
            $binding = DB::table('source_site_bindings')->where(['connector_id' => $sourceId, 'site_id' => $siteId])->lockForUpdate()->first();
            if ($binding && $binding->deleted_at === null) {
                throw new Conflict('SOURCE_ALREADY_BOUND', 'Nguồn đã được gắn vào website. Cấu hình hiện có được giữ nguyên.');
            }
            if (! in_array($source->status, ['active', 'paused'], true)) {
                throw new Conflict('SOURCE_UNAVAILABLE', 'Nguồn đã lưu trữ; hãy khôi phục nguồn trước khi gắn website.');
            }
            $version = $binding ? (int) $binding->mapping_version + 1 : 1;
            $generation = $binding ? (int) $binding->link_generation + 1 : 1;
            $values = ['mapping_version' => $version, 'link_generation' => $generation, 'mapping_json' => '{}', 'auto_publish_policy' => Json::encode($autoPublish ? ['enabled' => true, 'actor_id' => $actorId] : ['enabled' => false]), 'status' => 'active', 'deleted_at' => null];
            if ($binding) {
                $id = $binding->id;
                DB::table('source_site_bindings')->where('id', $id)->update($values);
            } else {
                $id = DB::table('source_site_bindings')->insertGetId(['site_id' => $siteId, 'connector_id' => $sourceId] + $values);
            }
            DB::table('sites')->where('id', $siteId)->increment('config_epoch');
            if ($binding) {
                DB::table('sites')->where('id', $siteId)->increment('public_content_epoch');
                app(PublicHtmlCache::class)->invalidateSite($siteId);
            }
            Access::audit($binding ? 'source.binding.reattached' : 'source.binding.created', $actorId, $siteId, ['connector_id' => $sourceId, 'binding_id' => $id, 'mapping_version' => $version, 'binding_generation' => $generation, 'auto_publish_enabled' => $autoPublish]);

            return DB::table('source_site_bindings')->find($id);
        }, 3);
    }

    public function update(int $siteId, int $sourceId, array $data, int $actorId): object
    {
        $this->owner($siteId, $actorId);
        if (array_diff(array_keys($data), ['mapping_version', 'status', 'enabled'])) {
            throw ValidationException::withMessages(['binding' => 'Chỉ thay đổi trạng thái và tự xuất bản trong form này.']);
        }
        $data = Validator::make($data, ['mapping_version' => 'required|integer|min:1', 'status' => 'required|in:active,paused,archived', 'enabled' => 'required|boolean'])->validate();
        if (! is_bool($data['enabled'])) {
            throw ValidationException::withMessages(['enabled' => 'Giá trị tự xuất bản phải là boolean.']);
        }

        return DB::transaction(function () use ($siteId, $sourceId, $data, $actorId) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('source_connectors')->where('id', $sourceId)->lockForUpdate()->first() ?? abort(404);
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            $binding = DB::table('source_site_bindings')->where(['connector_id' => $sourceId, 'site_id' => $siteId])->lockForUpdate()->first() ?? abort(404);
            $this->owner($site->id, $actorId);
            if ($binding->deleted_at !== null) {
                throw new Conflict('SOURCE_BINDING_DELETED', 'Nguồn đã được gỡ khỏi website. Gắn nguồn lại nếu muốn tiếp tục sử dụng.');
            }
            if ((int) $binding->mapping_version !== (int) $data['mapping_version']) {
                throw new Conflict('SOURCE_MAPPING_VERSION_CONFLICT', 'Liên kết nguồn đã thay đổi. Hãy tải lại.');
            }
            $policy = Json::decode($binding->auto_publish_policy);
            $policy['enabled'] = $data['enabled'];
            if ($data['enabled']) {
                $policy['actor_id'] = $actorId;
            }
            DB::table('source_site_bindings')->where('id', $binding->id)->update(['status' => $data['status'], 'mapping_version' => $binding->mapping_version + 1, 'auto_publish_policy' => Json::encode($policy)]);
            DB::table('sites')->where('id', $siteId)->increment('config_epoch');
            Access::audit('source.binding.updated', $actorId, $siteId, ['connector_id' => $sourceId, 'binding_id' => $binding->id, 'mapping_version' => $binding->mapping_version + 1, 'status' => $data['status'], 'auto_publish_enabled' => $data['enabled']]);

            return DB::table('source_site_bindings')->find($binding->id);
        }, 3);
    }

    /** Unlink only this domain; the shared source, films and immutable history remain. */
    public function delete(int $siteId, int $sourceId, int $mappingVersion, int $actorId): object
    {
        $this->owner($siteId, $actorId);
        if ($mappingVersion < 1) {
            throw ValidationException::withMessages(['mapping_version' => 'Phiên bản liên kết nguồn không hợp lệ.']);
        }

        return DB::transaction(function () use ($siteId, $sourceId, $mappingVersion, $actorId): object {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('source_connectors')->where('id', $sourceId)->lockForUpdate()->first() ?? abort(404);
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            $binding = DB::table('source_site_bindings')->where(['connector_id' => $sourceId, 'site_id' => $siteId])->lockForUpdate()->first() ?? abort(404);
            $this->owner($site->id, $actorId);
            if ($binding->deleted_at !== null && in_array($mappingVersion, [(int) $binding->mapping_version, (int) $binding->mapping_version - 1], true)) {
                return $binding;
            }
            if ($binding->deleted_at !== null || (int) $binding->mapping_version !== $mappingVersion) {
                throw new Conflict('SOURCE_MAPPING_VERSION_CONFLICT', 'Liên kết nguồn đã thay đổi. Hãy tải lại.');
            }
            $policy = Json::decode($binding->auto_publish_policy);
            $policy['enabled'] = false;
            DB::table('source_site_bindings')->where('id', $binding->id)->update(['status' => 'archived', 'deleted_at' => now(),
                'mapping_version' => $mappingVersion + 1, 'link_generation' => $binding->link_generation + 1, 'auto_publish_policy' => Json::encode($policy)]);
            DB::table('sites')->where('id', $siteId)->update(['config_epoch' => DB::raw('config_epoch+1'), 'public_content_epoch' => DB::raw('public_content_epoch+1')]);
            app(PublicHtmlCache::class)->invalidateSite($siteId);
            Access::audit('source.binding.deleted', $actorId, $siteId, ['connector_id' => $sourceId, 'binding_id' => $binding->id,
                'mapping_version' => $mappingVersion + 1, 'binding_generation' => $binding->link_generation + 1, 'previous_status' => $binding->status, 'pages_preserved' => true]);

            return DB::table('source_site_bindings')->find($binding->id);
        }, 3);
    }
}
