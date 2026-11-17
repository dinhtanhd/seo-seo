<?php

namespace App\Domain\Sites;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\SitePresentationApply;
use App\Domain\Publishing\SnapshotStorage;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeService;
use Illuminate\Support\Facades\DB;

/** The active layout is independent from immutable film and keyword publications. */
final class SiteAppearance
{
    public function active(int $siteId): ?array
    {
        $value = DB::table('sites')->where('id', $siteId)->value('live_appearance_json');
        if ($value === null) {
            return null;
        }
        $appearance = Json::decode($value);
        if (($appearance['schema_version'] ?? null) !== 1 || ($appearance['site_id'] ?? null) !== $siteId
            || ! array_key_exists('theme', $appearance) || ! is_array($appearance['views'] ?? null)
            || ! is_array($appearance['player'] ?? null)) {
            throw new Conflict('SITE_APPEARANCE_INVALID');
        }

        return $appearance;
    }

    public function overlay(array $snapshot): array
    {
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        $appearance = $siteId > 0 ? $this->active($siteId) : null;
        if ($appearance === null) {
            return $snapshot;
        }
        $snapshot['theme'] = $appearance['theme'];
        $snapshot['routing'] = array_replace($snapshot['routing'] ?? SitePresentationService::defaults(), [
            'views' => $appearance['views'], 'player' => $appearance['player'],
        ]);

        return $snapshot;
    }

    /** The site write lock fences cache writers and earlier per-film appearance jobs. */
    public function activate(int $siteId, int $actorId, ?int $expectedRowVersion = null): object
    {
        Access::site($actorId, $siteId, true);

        return DB::transaction(function () use ($siteId, $actorId, $expectedRowVersion): object {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            Access::site($actorId, $siteId, true);
            abort_unless($site->status === 'active', 409, 'Website phải đang hoạt động để áp dụng giao diện.');
            if ($expectedRowVersion !== null && $expectedRowVersion !== (int) $site->row_version) {
                throw new Conflict('SITE_PRESENTATION_VERSION_CONFLICT');
            }
            $profile = SitePresentationService::profile($site);
            $theme = null;
            if ($site->theme_id !== null) {
                $selected = DB::table('movie_themes')->find($site->theme_id);
                if (! $selected?->current_version_id) {
                    throw new Conflict('THEME_VERSION_MISSING');
                }
                $theme = app(MovieThemeService::class)->snapshot(['id' => (int) $selected->id, 'version_id' => (int) $selected->current_version_id]);
                foreach ($profile['views'] as $role => $view) {
                    $selectedView = $view === 'list' && ! isset($theme['views']['list']) && isset($theme['views']['category']) ? 'category' : $view;
                    if (! isset($theme['views'][$selectedView]) && $role !== 'play') {
                        throw new \InvalidArgumentException('Giao diện đã chọn thiếu file HTML '.$view.'.');
                    }
                }
            }
            $previous = Json::decode($site->live_appearance_json);
            $appearance = ['schema_version' => 1, 'site_id' => $siteId, 'generation' => (int) ($previous['generation'] ?? 0) + 1,
                'theme' => $theme, 'views' => $profile['views'], 'player' => $profile['player'],
                'routing' => $profile,
                'activated_at' => now()->toISOString(), 'activated_by' => $actorId];
            DB::table('sites')->where('id', $siteId)->update(['live_appearance_json' => Json::encode($appearance),
                'row_version' => DB::raw('row_version+1'), 'config_epoch' => DB::raw('config_epoch+1'), 'updated_at' => now()]);
            foreach (DB::table('outbox_events')->where('event_type', SitePresentationApply::EVENT)
                ->where('aggregate_id', (string) $siteId)->whereNull('delivered_at')->lockForUpdate()->get() as $event) {
                $payload = Json::decode($event->payload_json);
                $payload['status'] = 'superseded';
                $payload['updated_at'] = $appearance['activated_at'];
                $payload['superseded_by'] = 'site_appearance';
                DB::table('outbox_events')->where('id', $event->id)->update(['payload_json' => Json::encode($payload), 'delivered_at' => now()]);
            }
            app(PublicHtmlCache::class)->invalidateSite($siteId);
            Access::audit('site.appearance.activated', $actorId, $siteId, ['generation' => $appearance['generation'],
                'theme_id' => $theme['id'] ?? null, 'version_id' => $theme['version_id'] ?? null,
                'views' => $profile['views'], 'cache' => 'cleared_site_wide']);

            return DB::table('sites')->find($siteId);
        }, 3);
    }
}
