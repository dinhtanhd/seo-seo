<?php

namespace App\Domain\Sites;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SiteBulkService
{
    public function inspect(array $siteIds, array $changes, int $actorId, ?string $id = null): array
    {
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        sort($siteIds);
        if (! $siteIds || count($siteIds) > 1000 || min($siteIds) < 1) {
            throw new \InvalidArgumentException('Chọn 1–1.000 website.');
        }
        $changes = $this->normalizeChanges($changes);
        $id ??= (string) Str::uuid();
        if (! Str::isUuid($id)) {
            throw new \InvalidArgumentException('Mã thao tác không hợp lệ.');
        }
        $requestHash = Json::hash([$actorId, $siteIds, $changes]);

        return DB::transaction(function () use ($siteIds, $changes, $actorId, $id, $requestHash) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('site_bulk_operations')->insertOrIgnore(['id' => $id, 'actor_id' => $actorId, 'site_ids_json' => Json::encode($siteIds),
                'changes_json' => Json::encode($changes), 'manifest_json' => '{}', 'request_hash' => $requestHash,
                'fingerprint' => str_repeat('0', 64), 'expires_at' => now()->addMinutes(30)]);
            $existing = DB::table('site_bulk_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless((int) $existing->actor_id === $actorId, 403);
            if ($existing->request_hash !== $requestHash) {
                throw new Conflict('IDEMPOTENCY_PAYLOAD_CONFLICT');
            }
            if ($existing->fingerprint !== str_repeat('0', 64)) {
                return $this->report($id, $actorId);
            }
            $manifest = $this->manifest($siteIds, $changes, $actorId);
            DB::table('site_bulk_operations')->where('id', $id)->update(['manifest_json' => Json::encode($manifest), 'fingerprint' => Json::hash($manifest)]);

            return $this->report($id, $actorId);
        }, 3);
    }

    public function report(string $id, int $actorId): array
    {
        $operation = DB::table('site_bulk_operations')->where('id', $id)->first() ?? abort(404);
        abort_unless((int) $operation->actor_id === $actorId, 403);
        foreach (Json::decode($operation->site_ids_json) as $siteId) {
            $this->owner($actorId, $siteId);
        }

        return ['id' => $operation->id, 'status' => $operation->status, 'fingerprint' => $operation->fingerprint,
            'total' => count(Json::decode($operation->site_ids_json)), 'sites' => Json::decode($operation->manifest_json)['sites'],
            'changes' => Json::decode($operation->changes_json), 'expires_at' => $operation->expires_at,
            'result' => Json::decode($operation->result_json)];
    }

    public function apply(string $id, string $fingerprint, int $actorId): array
    {
        return DB::transaction(function () use ($id, $fingerprint, $actorId) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $operation = DB::table('site_bulk_operations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $operation->actor_id === $actorId, 403);
            $siteIds = Json::decode($operation->site_ids_json);
            foreach ($siteIds as $siteId) {
                $this->owner($actorId, $siteId);
            }
            if (! hash_equals($operation->fingerprint, $fingerprint)) {
                throw new Conflict('SITE_BULK_PREVIEW_STALE');
            }
            if ($operation->status === 'applied') {
                return $this->report($id, $actorId);
            }
            if (now()->greaterThan($operation->expires_at)) {
                throw new Conflict('SITE_BULK_PREVIEW_EXPIRED');
            }
            $manifest = $this->manifest($siteIds, Json::decode($operation->changes_json), $actorId, true);
            if (! hash_equals($operation->fingerprint, Json::hash($manifest))) {
                throw new Conflict('SITE_BULK_PREVIEW_STALE');
            }
            $results = [];
            foreach ($manifest['sites'] as $site) {
                $changed = Json::hash($site['before']) !== Json::hash($site['after']);
                if ($changed) {
                    app(SitePresentationService::class)->save($site['id'], $site['after']['presentation'] + [
                        'theme_id' => $site['after']['theme_id'], 'row_version' => $site['row_version']], $actorId);
                }
                $results[] = ['site_id' => $site['id'], 'status' => $changed ? 'changed' : 'unchanged'];
            }
            $result = ['sites' => $results, 'changed' => count(array_filter($results, fn ($r) => $r['status'] === 'changed')),
                'unchanged' => count(array_filter($results, fn ($r) => $r['status'] === 'unchanged'))];
            DB::table('site_bulk_operations')->where('id', $id)->update(['status' => 'applied', 'result_json' => Json::encode($result), 'updated_at' => now()]);
            Access::audit('sites.bulk.draft', $actorId, null, ['operation_id' => $id, 'result' => $result]);

            return $this->report($id, $actorId);
        }, 3);
    }

    private function owner(int $actorId, int $siteId): void
    {
        Access::site($actorId, $siteId, true);
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin')
            || DB::table('site_memberships')->where(['site_id' => $siteId, 'user_id' => $actorId, 'role' => 'owner'])->exists(), 403);
    }

    private function manifest(array $siteIds, array $changes, int $actorId, bool $exclusive = false): array
    {
        $query = DB::table('sites')->whereIn('id', $siteIds)->orderBy('id');
        $sites = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->get();
        if ($sites->count() !== count($siteIds)) {
            abort(403);
        }
        $rows = [];
        foreach ($sites as $site) {
            $this->owner($actorId, $site->id);
            if ($site->content_type !== 'movie') {
                throw new \InvalidArgumentException('Cấu hình giao diện phim chỉ áp dụng cho website phim.');
            }
            $before = ['theme_id' => $site->theme_id, 'presentation' => SitePresentationService::profile($site)];
            $after = $before;
            foreach (['theme_id', 'movie_key', 'category_key'] as $field) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }
                if ($field === 'theme_id') {
                    $after['theme_id'] = $changes[$field];
                } else {
                    $after['presentation'][$field] = $changes[$field];
                }
            }
            foreach ($changes['routes'] ?? [] as $type => $path) {
                $after['presentation']['routes'][$type] = $path;
            }
            foreach ($changes['suffixes'] ?? [] as $type => $suffix) {
                app(UrlSuffixCatalog::class)->assertSelectable($suffix, $before['presentation']['suffixes'][$type] ?? '', 'suffixes.'.$type);
                $after['presentation']['suffixes'] ??= array_fill_keys(SitePresentationService::TYPES, '');
                $after['presentation']['suffixes'][$type] = $suffix;
            }
            $after['presentation']['configured'] = true;
            $after['presentation'] = SitePresentationService::normalize($after['presentation']);
            if ($after['theme_id'] !== null) {
                $theme = DB::table('movie_themes')->find($after['theme_id']);
                if (! $theme || ($theme->status !== 'active' && (int) $site->theme_id !== $theme->id)) {
                    throw new \InvalidArgumentException('Giao diện không hoạt động: '.$after['theme_id']);
                }
            }
            $rows[] = ['id' => $site->id, 'name' => $site->name, 'row_version' => $site->row_version, 'before' => $before, 'after' => $after];
        }

        return ['sites' => $rows, 'global_epoch' => DB::table('system_settings')->where('id', 1)->value('config_epoch')];
    }

    private function normalizeChanges(array $input): array
    {
        if (array_diff(array_keys($input), ['theme_id', 'movie_key', 'category_key', 'routes', 'suffixes'])) {
            throw new \InvalidArgumentException('Trường sửa website không hỗ trợ.');
        }
        $changes = [];
        $value = function ($spec) {
            if (! is_array($spec) || ! in_array($spec['mode'] ?? null, ['keep', 'set'], true) || array_diff(array_keys($spec), ['mode', 'value'])) {
                throw new \InvalidArgumentException('Mỗi trường cần giữ nguyên hoặc đặt giá trị cụ thể.');
            }
            if ($spec['mode'] === 'keep') {
                return [false, null];
            }
            if (! array_key_exists('value', $spec)) {
                throw new \InvalidArgumentException('Thiếu giá trị cần đặt.');
            }

            return [true, $spec['value']];
        };
        foreach (['theme_id', 'movie_key', 'category_key'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            [$set, $v] = $value($input[$field]);
            if (! $set) {
                continue;
            }
            if ($field === 'theme_id' && $v !== null) {
                $v = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($v === false) {
                    throw new \InvalidArgumentException('ID giao diện không hợp lệ.');
                }
            }
            if ($field === 'movie_key' && ! in_array($v, ['source_id', 'pinyin_id', 'pinyin_compact_id'], true)) {
                throw new \InvalidArgumentException('Kiểu URL phim không hợp lệ.');
            }
            if ($field === 'category_key' && ! in_array($v, ['id', 'pinyin_id', 'pinyin_compact_id'], true)) {
                throw new \InvalidArgumentException('Kiểu URL danh mục không hợp lệ.');
            }
            $changes[$field] = $v;
        }
        if (isset($input['routes'])) {
            if (! is_array($input['routes']) || array_diff(array_keys($input['routes']), SitePresentationService::TYPES)) {
                throw new \InvalidArgumentException('Loại đường dẫn không hỗ trợ.');
            }
            foreach ($input['routes'] as $type => $spec) {
                [$set, $v] = $value($spec);
                if ($set) {
                    if (! is_string($v)) {
                        throw new \InvalidArgumentException('Đường dẫn phải là chuỗi.');
                    }
                    $changes['routes'][$type] = $v;
                }
            }
        }
        if (isset($input['suffixes'])) {
            if (! is_array($input['suffixes']) || array_diff(array_keys($input['suffixes']), SitePresentationService::TYPES)) {
                throw new \InvalidArgumentException('Loại đuôi URL không hỗ trợ.');
            }
            foreach ($input['suffixes'] as $type => $spec) {
                [$set, $suffix] = $value($spec);
                if ($set) {
                    $changes['suffixes'][$type] = UrlSuffixCatalog::normalizeSuffix($suffix);
                }
            }
        }
        if ($changes === []) {
            throw new \InvalidArgumentException('Chọn ít nhất một trường cần sửa.');
        }

        return $changes;
    }
}
