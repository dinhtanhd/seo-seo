<?php

namespace App\Domain\Publishing;

use App\Domain\Comments\LiveMovieComments;
use App\Domain\Sites\SiteAppearance;
use Illuminate\Support\Facades\DB;

/** The first publication's HTML is reusable until its public inputs change. */
final class FanmuluDetailArtifact
{
    public function prepare(array $snapshot, object $page): array
    {
        $site = DB::table('sites')->find($snapshot['site']['id']);
        $epoch = (int) DB::table('system_settings')->where('id', 1)->value('config_epoch');
        $snapshot = app(LiveMovieContent::class)->overlay($snapshot);
        $snapshot = app(LiveMovieContent::class)->forFirstPublication($snapshot, $page);
        $snapshot = app(SiteAppearance::class)->overlay($snapshot);
        $snapshot = app(LiveMovieRecommendations::class)->overlay($snapshot);
        $snapshot = app(LiveMovieComments::class)->overlay($snapshot);
        $snapshot['fanmulu']['detail_artifact'] = self::epochs($site, $epoch) + ['serving_revision' => (int) $snapshot['content_revision_id']];

        return $snapshot;
    }

    public static function exists(array $snapshot): bool
    {
        return is_array($snapshot['fanmulu']['detail_artifact'] ?? null);
    }

    public static function current(array $snapshot, object $page, object $site, ?int $epoch = null): bool
    {
        if (! self::exists($snapshot)) {
            return false;
        }
        $epoch ??= (int) DB::table('system_settings')->where('id', 1)->value('config_epoch');

        // JSONB can reorder object keys.
        return $snapshot['fanmulu']['detail_artifact'] == self::epochs($site, $epoch) + [
            'serving_revision' => (int) ($page->serving_content_revision_id ?? $page->working_content_revision_id),
        ];
    }

    public static function forRefresh(array $snapshot): array
    {
        if (! self::exists($snapshot)) {
            return $snapshot;
        }
        if (! empty($snapshot['_live_recommendations_changed'])) {
            $snapshot['aggregate']['entries'] = [];
        }
        unset($snapshot['fanmulu']['detail_artifact'], $snapshot['_live_recommendation_tabs'], $snapshot['_live_movie_comments'],
            $snapshot['_live_content_changed'], $snapshot['_live_recommendations_changed'], $snapshot['_live_comments_changed']);

        return $snapshot;
    }

    /** Keep frozen content separate from the initial HTML's serving projection. */
    public function frozenSnapshot(array $projection, array $frozen): array
    {
        foreach (['content', 'content_revision_id', 'slots', 'managed_body'] as $key) {
            $projection[$key] = $frozen[$key];
        }
        $projection['fanmulu']['original_title'] = $frozen['fanmulu']['original_title'];

        return $projection;
    }

    private static function epochs(object $site, int $epoch): array
    {
        return ['version' => 1, 'site_config' => (int) $site->config_epoch, 'catalog' => (int) $site->public_content_epoch, 'global' => $epoch];
    }
}
