<?php

namespace App\Domain\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Recoverable list management, independent of immutable audit rows and queue recovery data. */
final class ActivityRecordService
{
    public function filters(array $input): array
    {
        return Validator::make($input, [
            'site_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:200',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['from']) ? ['after_or_equal:from'] : [])],
            'trash' => 'nullable|boolean',
        ])->validate();
    }

    public function query(string $kind, int $actorId, array $filters = [], ?bool $trash = false): Builder
    {
        $table = $this->table($kind);
        $actor = DB::table('users')->find($actorId);
        abort_unless($actor, 403);
        $query = DB::table($table);
        if ($kind === 'audit') {
            $sites = CatalogAvailability::filter(DB::table('sites'), 'sites');
            if (! $actor->is_global_admin) {
                $sites->whereIn('id', DB::table('site_memberships')->where('user_id', $actorId)->select('site_id'));
            }
            if (! empty($filters['site_id'])) {
                $siteId = (int) $filters['site_id'];
                abort_unless(DB::table('sites')->where('id', $siteId)->exists(), 404);
                Access::site($actorId, $siteId);
                $query->where('site_id', $siteId);
            } else {
                $query->where(function (Builder $scope) use ($sites, $actorId): void {
                    $scope->whereIn('site_id', $sites->select('id'))
                        ->orWhere(fn (Builder $global) => $global->whereNull('site_id')->where('actor_id', $actorId));
                });
            }
        } elseif (! $actor->is_global_admin) {
            $query->whereRaw('false');
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where($kind === 'audit' ? 'action' : 'queue', 'ilike', '%'.$escaped.'%');
        }
        $time = $kind === 'audit' ? 'created_at' : 'failed_at';
        if (! empty($filters['from'])) {
            $query->where($time, '>=', CarbonImmutable::parse($filters['from'], 'UTC')->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where($time, '<', CarbonImmutable::parse($filters['to'], 'UTC')->startOfDay()->addDay());
        }
        if ($trash !== null) {
            $query->whereExists(function (Builder $state) use ($kind, $table): void {
                $state->selectRaw('1')->from('activity_record_states')
                    ->where('kind', $kind)->whereColumn('record_id', $table.'.id');
            }, not: ! $trash);
        }

        return $query;
    }

    public function mutate(array $input, int $actorId): int
    {
        abort_unless(DB::table('users')->where(['id' => $actorId, 'is_global_admin' => true])->exists(), 403);
        $data = Validator::make($input, [
            'kind' => 'required|in:audit,failed_job', 'action' => 'required|in:delete,restore',
            'scope' => 'sometimes|in:selected,filtered',
            'ids' => 'required_unless:scope,filtered|array|min:1|max:10000',
            'ids.*' => 'required|integer|min:1|distinct',
            'cutoff_id' => 'required_if:scope,filtered|integer|min:0',
        ])->validate();
        $filters = $this->filters($input);

        return DB::transaction(function () use ($data, $filters, $actorId): int {
            $actor = DB::table('users')->where('id', $actorId)->sharedLock()->first();
            abort_unless($actor?->is_global_admin, 403);
            $kind = $data['kind'];
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [746103, $kind === 'audit' ? 1 : 2]);
            $targets = $this->query($kind, $actorId, $filters, trash: null);
            $scope = $data['scope'] ?? 'selected';
            if ($scope === 'selected') {
                $ids = array_map('intval', $data['ids']);
                abort_unless(DB::table($this->table($kind))->whereIn('id', $ids)->count() === count($ids), 404, 'Một số bản ghi không còn tồn tại. Tải lại danh sách.');
                $targets->whereIn('id', $ids);
                abort_unless((clone $targets)->count() === count($ids), 403, 'Có bản ghi ngoài phạm vi được phép quản lý.');
            } else {
                $targets->where('id', '<=', (int) $data['cutoff_id']);
            }
            $table = $this->table($kind);
            $targets->whereExists(function (Builder $state) use ($kind, $table): void {
                $state->selectRaw('1')->from('activity_record_states')->where('kind', $kind)->whereColumn('record_id', $table.'.id');
            }, not: $data['action'] === 'delete');
            if ($data['action'] === 'delete') {
                $count = DB::table('activity_record_states')->insertUsing(['kind', 'record_id', 'deleted_by', 'deleted_at'],
                    $targets->selectRaw('? AS kind, '.$table.'.id AS record_id, ? AS deleted_by, CURRENT_TIMESTAMP AS deleted_at', [$kind, $actorId]));
            } else {
                $count = DB::table('activity_record_states')->where('kind', $kind)
                    ->whereIn('record_id', $targets->select($table.'.id'))->delete();
            }
            if ($count > 0) {
                Access::audit('activity.records.'.($data['action'] === 'delete' ? 'trashed' : 'restored'), $actorId, null,
                    ['kind' => $kind, 'count' => $count, 'scope' => $scope, 'filters' => $filters,
                        'cutoff_id' => $data['cutoff_id'] ?? null,
                        'selection_hash' => isset($data['ids']) ? Json::hash($data['ids']) : null]);
            }

            return $count;
        }, 3);
    }

    private function table(string $kind): string
    {
        abort_unless(in_array($kind, ['audit', 'failed_job'], true), 422);

        return $kind === 'audit' ? 'audit_events' : 'failed_jobs';
    }
}
