<?php

namespace App\Domain\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Access
{
    public static function site(int $actorId, int $siteId, bool $write = false, ?object $lockedSite = null): void
    {
        $user = DB::table('users')->find($actorId);
        if (! $user) {
            abort(403);
        }
        if (! $user->is_global_admin) {
            $membership = DB::table('site_memberships')->where('user_id', $actorId)->where('site_id', $siteId)->first();
            if (! $membership || ($write && $membership->role === 'viewer')) {
                abort(403);
            }
        }
        $site = ($lockedSite->id ?? null) === $siteId ? $lockedSite : DB::table('sites')->find($siteId);
        abort_if(($site->deleted_at ?? null) !== null, 410, 'Website đã được xóa. Khôi phục website trước khi thao tác.');
    }

    public static function audit(string $action, ?int $actor, ?int $site, array $after, ?int $page = null, ?int $batch = null): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => $action, 'site_id' => $site, 'page_id' => $page, 'batch_id' => $batch, 'after_json' => Json::encode($after), 'correlation_id' => (string) Str::uuid()]);
    }
}
