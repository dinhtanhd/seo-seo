<?php

namespace App\Domain\Comments;

use App\Domain\Publishing\SnapshotStorage;
use Illuminate\Support\Facades\Schema;

/** Read-only sample comments for existing movie comment sections, independent of SEO assignments. */
final class LiveMovieComments
{
    public function overlay(array $snapshot): array
    {
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        $pageId = (int) ($snapshot['page']['id'] ?? 0);
        if ($siteId < 1 || $pageId < 1 || ! in_array($snapshot['page']['type'] ?? '', ['movie', 'play'], true)) {
            return $snapshot;
        }
        $hasSection = false;
        foreach (['detail', 'play'] as $view) {
            $name = $snapshot['routing']['views'][$view] ?? $view;
            $fragment = $snapshot['theme']['views'][$name] ?? '';
            if (is_string($fragment) && (str_contains($fragment, 'myui_comment') || str_contains($fragment, 'ewave-comment'))
                && str_contains($fragment, 'comment-box')) {
                $hasSection = true;
                break;
            }
        }
        if (! $hasSection || ! Schema::hasTable('movie_comment_samples')) {
            return $snapshot;
        }
        $locale = is_string($snapshot['site']['locale'] ?? null) ? $snapshot['site']['locale'] : 'zh-CN';
        $snapshot['_live_movie_comments'] = app(MovieCommentCatalog::class)->sample($siteId, $pageId, 6, $locale);
        $snapshot['_live_comments_changed'] = true;

        return $snapshot;
    }
}
