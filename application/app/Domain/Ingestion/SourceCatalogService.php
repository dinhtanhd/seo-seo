<?php

namespace App\Domain\Ingestion;

use App\Domain\Support\Access;
use App\Domain\Support\CatalogUsageGuard;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SourceCatalogService
{
    public function canManage(int $actorId): bool
    {
        return (bool) DB::table('users')->where('id', $actorId)->value('is_global_admin');
    }

    public function authorizeManagement(int $actorId): void
    {
        abort_unless($this->canManage($actorId), 403, 'Cấu hình nguồn dùng chung cần quản trị toàn hệ thống.');
    }

    public function catalog(int $actorId, bool $includeDeleted = false): Collection
    {
        abort_unless(DB::table('users')->where('id', $actorId)->exists(), 403);
        if ($includeDeleted) {
            $this->authorizeManagement($actorId);
        }
        $columns = ['id', 'name', 'kind', 'status', 'row_version', 'latest_max_pages', 'deleted_at'];
        if ($this->canManage($actorId)) {
            $columns[] = 'config_json';
            $columns[] = 'schedule_json';
        }

        return DB::table('source_connectors')->when(! $includeDeleted, fn ($query) => $query->whereNull('deleted_at'))->orderBy('name')->orderBy('id')->get($columns);
    }

    public function sourceForManagement(int $id, int $actorId): object
    {
        $this->authorizeManagement($actorId);

        return DB::table('source_connectors')->whereNull('deleted_at')->find($id) ?? abort(404);
    }

    public function create(array $data, array $configuration, int $actorId): object
    {
        $this->authorizeManagement($actorId);
        $scheduleData = $data;
        $data = Validator::make($data, ['name' => 'required|string|max:200', 'kind' => 'required|in:maccms,shandian,json,csv,html,fixture', 'latest_max_pages' => 'sometimes|required|integer|in:3,4,5'])->validate();
        if (isset($data['latest_max_pages'])) {
            $data['latest_max_pages'] = (int) $data['latest_max_pages'];
        }

        return DB::transaction(function () use ($data, $configuration, $actorId, $scheduleData) {
            $this->authorizeManagement($actorId);
            $schedule = $this->scheduleOverride($configuration, $scheduleData, $data['kind']);
            $id = DB::table('source_connectors')->insertGetId($data + ['config_json' => Json::encode($configuration), 'schedule_json' => $schedule, 'status' => 'active', 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit('source.created', $actorId, null, ['connector_id' => $id, 'kind' => $data['kind'], 'latest_max_pages' => $data['latest_max_pages'] ?? 3, 'schedule' => $schedule === null ? null : Json::decode($schedule), 'global_catalog' => true]);

            return DB::table('source_connectors')->find($id);
        }, 3);
    }

    public function update(int $id, array $data, array $configuration, int $actorId): object
    {
        $this->authorizeManagement($actorId);
        $scheduleData = $data;
        $data = Validator::make($data, ['name' => 'required|string|max:200', 'status' => 'required|in:active,paused,archived', 'row_version' => 'required|integer|min:1', 'latest_max_pages' => 'sometimes|required|integer|in:3,4,5'])->validate();

        return DB::transaction(function () use ($id, $data, $configuration, $actorId, $scheduleData) {
            $this->authorizeManagement($actorId);
            $source = DB::table('source_connectors')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            if ((int) $source->row_version !== (int) $data['row_version']) {
                throw new Conflict('SOURCE_VERSION_CONFLICT', 'Cấu hình nguồn đã thay đổi. Hãy tải lại.');
            }
            $schedule = $this->scheduleOverride($configuration, $scheduleData, $source->kind, $source);
            DB::table('source_connectors')->where('id', $id)->update(['name' => $data['name'], 'status' => $data['status'], 'config_json' => Json::encode($configuration), 'schedule_json' => $schedule, 'latest_max_pages' => isset($data['latest_max_pages']) ? (int) $data['latest_max_pages'] : $source->latest_max_pages, 'row_version' => $source->row_version + 1, 'updated_at' => now()]);
            Access::audit('source.updated', $actorId, null, ['connector_id' => $id, 'status' => $data['status'], 'latest_max_pages' => isset($data['latest_max_pages']) ? (int) $data['latest_max_pages'] : $source->latest_max_pages, 'schedule' => $schedule === null ? null : Json::decode($schedule), 'global_catalog' => true]);

            return DB::table('source_connectors')->find($id);
        }, 3);
    }

    private function scheduleOverride(array $configuration, array $data, string $kind, ?object $source = null): ?string
    {
        $fields = array_flip(['schedule_mode', 'schedule_daily_time', 'schedule_timezone', 'schedule_interval_minutes']);
        $input = array_intersect_key($data, $fields);
        if ($input === []) {
            return $source?->schedule_json;
        }
        $previous = $source ? SourceSchedule::forSource($source) : [];
        $effective = SourceSchedule::apply($configuration, $input, $kind, $previous);
        if ($source && ! array_key_exists('schedule_mode', $input)
            && array_intersect_key($effective, $fields) === array_intersect_key($previous, $fields)) {
            return $source->schedule_json;
        }

        return Json::encode(array_intersect_key($effective, $fields));
    }

    public function delete(int $id, int $rowVersion, int $actorId): object
    {
        return $this->changeLifecycle($id, $rowVersion, $actorId, false);
    }

    public function restore(int $id, int $rowVersion, int $actorId): object
    {
        return $this->changeLifecycle($id, $rowVersion, $actorId, true);
    }

    private function changeLifecycle(int $id, int $rowVersion, int $actorId, bool $restore): object
    {
        $this->authorizeManagement($actorId);
        Validator::make(['row_version' => $rowVersion], ['row_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($id, $rowVersion, $actorId, $restore) {
            $this->authorizeManagement($actorId);
            $source = DB::table('source_connectors')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if ((int) $source->row_version !== $rowVersion) {
                throw new Conflict('SOURCE_VERSION_CONFLICT', 'Nguồn đã thay đổi. Hãy tải lại trước khi xóa hoặc khôi phục.');
            }
            if ($restore && $source->deleted_at === null) {
                throw new Conflict('SOURCE_NOT_DELETED', 'Nguồn chưa ở trong thùng rác.');
            }
            if (! $restore && $source->deleted_at !== null) {
                throw new Conflict('SOURCE_DELETED', 'Nguồn đã ở trong thùng rác.');
            }
            if (! $restore) {
                app(CatalogUsageGuard::class)->assertUnused('source', $id);
            }
            DB::table('source_connectors')->where('id', $id)->update([
                'status' => $restore ? 'paused' : 'archived',
                'deleted_at' => $restore ? null : now(),
                'row_version' => $source->row_version + 1,
                'updated_at' => now(),
            ]);
            Access::audit($restore ? 'source.restored' : 'source.deleted', $actorId, null, [
                'connector_id' => $id,
                'previous_status' => $source->status,
                'status' => $restore ? 'paused' : 'archived',
                'global_catalog' => true,
            ]);

            return DB::table('source_connectors')->find($id);
        }, 3);
    }
}
