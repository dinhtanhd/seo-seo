<?php

namespace App\Domain\Rules;

use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RuleLifecycleService
{
    public function authorize(int $id, int $actorId): object
    {
        $rule = DB::table('rules')->find($id) ?? abort(404);
        $this->authorizeRule($rule, $actorId);

        return $rule;
    }

    public function archive(int $id, int $rowVersion, int $actorId): object
    {
        return $this->transition($id, $rowVersion, $actorId, false);
    }

    public function restore(int $id, int $rowVersion, int $actorId): object
    {
        return $this->transition($id, $rowVersion, $actorId, true);
    }

    private function authorizeRule(object $rule, int $actorId): void
    {
        if ($rule->scope_type === 'global') {
            abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Chỉ quản trị toàn hệ thống được thay đổi quy tắc chung.');
        } else {
            Access::site($actorId, (int) $rule->site_id, true);
        }
    }

    private function transition(int $id, int $rowVersion, int $actorId, bool $restore): object
    {
        $initial = $this->authorize($id, $actorId);
        Validator::make(['row_version' => $rowVersion], ['row_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($id, $rowVersion, $actorId, $restore, $initial): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            if ($initial->site_id !== null) {
                DB::table('sites')->where('id', $initial->site_id)->lockForUpdate()->first();
            }
            $rule = DB::table('rules')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->authorizeRule($rule, $actorId);
            CatalogAvailability::assertWritable($rule, 'RULE_DELETED');
            if ((int) $rule->row_version !== $rowVersion) {
                throw new Conflict('RULE_VERSION_CONFLICT', 'Quy tắc đã thay đổi. Hãy tải lại danh sách trước khi thao tác.');
            }
            if (($rule->status === 'archived') !== $restore) {
                throw new Conflict($restore ? 'RULE_NOT_ARCHIVED' : 'RULE_ARCHIVED', 'Trạng thái quy tắc đã thay đổi. Hãy tải lại danh sách.');
            }
            $status = $restore ? 'active' : 'archived';
            DB::table('rules')->where('id', $id)->update(['status' => $status, 'row_version' => $rule->row_version + 1, 'updated_at' => now()]);
            if ($rule->site_id !== null) {
                DB::table('sites')->where('id', $rule->site_id)->increment('config_epoch');
            } else {
                DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            }
            Access::audit($restore ? 'rule.restored' : 'rule.archived', $actorId, $rule->site_id, ['rule_id' => $id, 'current_version_id' => $rule->current_version_id, 'previous_status' => $rule->status, 'status' => $status, 'row_version' => $rule->row_version + 1]);

            return DB::table('rules')->find($id);
        }, 3);
    }
}
