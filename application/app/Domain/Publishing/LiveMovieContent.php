<?php

namespace App\Domain\Publishing;

use App\Domain\Fanmulu\FanmuluNavigation;
use App\Domain\Fanmulu\FanmuluPresentation;
use App\Domain\Keywords\AssignmentPurpose;
use App\Domain\Rules\Html;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Rules\TemplateCompiler;
use App\Domain\Rules\TemplateVariableService;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A public-only data projection; publication bytes, SEO policy and keyword pins stay immutable. */
final class LiveMovieContent
{
    private ?bool $available = null;

    private function available(): bool
    {
        return $this->available ??= Schema::hasColumn('pages', 'serving_content_revision_id');
    }

    public function overlay(array $snapshot): array
    {
        if (! $this->available()) {
            return $snapshot;
        }
        $snapshot = app(SnapshotStorage::class)->hydrate($snapshot);
        $siteId = (int) ($snapshot['site']['id'] ?? 0);
        $pageId = (int) ($snapshot['page']['id'] ?? 0);
        if ($siteId < 1) {
            return $snapshot;
        }
        if ($pageId > 0 && is_array($snapshot['content'] ?? null) && $snapshot['content'] !== []) {
            $row = $this->pages($siteId)->where('p.id', $pageId)->first(['p.id', 'p.content_item_id',
                'r.id as revision_id', 'r.payload_json', 'pp.content_revision_id as frozen_revision_id']);
            $baseRevision = (int) ($snapshot['content_revision_id'] ?? 0);
            if ($row && (int) $row->revision_id !== $baseRevision
                && ($baseRevision === 0 || $baseRevision === (int) $row->frozen_revision_id)) {
                $snapshot = $this->applyRevision($snapshot, (int) $row->revision_id, $row->payload_json);
            }
        }
        if (is_array($snapshot['aggregate']['entries'] ?? null) && $snapshot['aggregate']['entries'] !== []) {
            $snapshot['aggregate']['entries'] = $this->overlayEntries($siteId, $snapshot['aggregate']['entries']);
            if (array_filter($snapshot['aggregate']['entries'], fn ($entry): bool => is_array($entry) && ! empty($entry['_live_content_changed']))) {
                $snapshot['_live_content_changed'] = true;
                unset($snapshot['_snapshot_aggregate_hash']);
            }
        }

        return $snapshot;
    }

    /** Project the serving data before a first publication has a live head. */
    public function forFirstPublication(array $snapshot, object $page): array
    {
        $revision = (int) ($page->serving_content_revision_id ?? 0);
        if ($revision > 0 && $revision !== (int) $snapshot['content_revision_id']) {
            $payload = DB::table('content_revisions')->where(['id' => $revision, 'content_item_id' => $page->content_item_id])->value('payload_json');
            if ($payload !== null) {
                return $this->applyRevision($snapshot, $revision, $payload);
            }
        }

        return $snapshot;
    }

    private function applyRevision(array $snapshot, int $revision, mixed $payload): array
    {
        $snapshot['content'] = Json::decode($payload);
        $snapshot['content_revision_id'] = $revision;
        if (FanmuluPresentation::enabled($snapshot)) {
            $snapshot['fanmulu']['original_title'] = (string) ($snapshot['content']['title'] ?? '');
        }
        $snapshot = $this->presentation($snapshot);
        unset($snapshot['_catalog_card']);
        $snapshot['_live_content_changed'] = true;

        return $snapshot;
    }

    /** Reuse identities and frozen links while fetching only card-sized data in one batch. */
    public function overlayEntries(int $siteId, array $entries): array
    {
        if ($siteId < 1 || $entries === [] || ! $this->available()) {
            return $entries;
        }
        $ids = [];
        $paths = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['page_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            } elseif (is_string($entry['path'] ?? null) && strlen($entry['path']) <= 2000) {
                $paths[] = $entry['path'];
            }
        }
        if ($ids === [] && $paths === []) {
            return $entries;
        }
        $rows = $this->pages($siteId)->where(function (Builder $query) use ($ids, $paths): void {
            $query->whereIn('p.id', array_unique($ids))->orWhereIn('p.canonical_path', array_unique($paths));
        })->select('p.id', 'p.canonical_path', 'p.content_item_id', 'pp.id as publication_id', 'r.id as revision_id', 'pp.content_revision_id as frozen_revision_id')
            ->selectRaw($this->cardProjection().' AS content_json')
            ->get();
        $byId = $rows->keyBy('id');
        $byPath = $rows->keyBy('canonical_path');
        $routingIds = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! empty($entry['play_path'])) {
                continue;
            }
            $id = (int) ($entry['page_id'] ?? 0);
            $row = $id > 0 ? $byId->get($id) : $byPath->get($entry['path'] ?? '');
            if (! $row || (isset($entry['content_item_id']) && (int) $entry['content_item_id'] !== (int) $row->content_item_id)
                || (int) $row->revision_id === (int) ($entry['content_revision_id'] ?? $row->frozen_revision_id)
                || empty(Json::decode($row->content_json)['episodes'])) {
                continue;
            }
            $routingIds[$row->publication_id] = $row->publication_id;
        }
        $routing = $routingIds === [] ? collect() : DB::table('page_publications as pp')->where('pp.site_id', $siteId)->whereIn('pp.id', array_values($routingIds))
            ->select('pp.id')->selectRaw("pp.snapshot_json->'routing' AS routing_json, pp.snapshot_json->'page'->>'route_key' AS route_key")->get()->keyBy('id');
        foreach ($entries as &$entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['page_id'] ?? 0);
            $row = $id > 0 ? $byId->get($id) : $byPath->get($entry['path'] ?? '');
            if (! $row || (isset($entry['content_item_id']) && (int) $entry['content_item_id'] !== (int) $row->content_item_id)
                || (int) $row->revision_id === (int) ($entry['content_revision_id'] ?? $row->frozen_revision_id)) {
                continue;
            }
            $entry['content'] = Json::decode($row->content_json);
            $entry['content_revision_id'] = (int) $row->revision_id;
            if (FanmuluPresentation::enabled($entry)) {
                $entry['fanmulu']['original_title'] = (string) ($entry['content']['title'] ?? '');
            }
            if (empty($entry['content']['episodes'])) {
                $entry['play_path'] = '';
            } elseif (empty($entry['play_path'])) {
                $frozenRouting = $routing->get($row->publication_id) ?? throw new \LogicException('Live card publication routing is missing.');
                $profile = SitePresentationService::profile(Json::decode($frozenRouting->routing_json));
                $key = $entry['route_key'] ?? $frozenRouting->route_key
                    ?? SitePresentationService::keyFromPath($entry['path'] ?? $row->canonical_path, $profile, 'detail');
                $entry['play_path'] = FanmuluNavigation::enabled($entry)
                    ? FanmuluNavigation::scopePath($entry, 'play', '1') : SitePresentationService::playPath($profile, $key, 1);
            }
            unset($entry['_card'], $entry['_catalog_card'], $entry['_catalog_card_frozen'], $entry['managed_body']);
            $entry['_live_content_changed'] = true;
        }
        unset($entry);

        return $entries;
    }

    /** The pointer can only resolve a revision belonging to this page's content item and live head. */
    private function pages(int $siteId): Builder
    {
        return DB::table('pages as p')->join('page_publications as pp', function ($join): void {
            $join->on('pp.id', '=', 'p.current_publication_id')->on('pp.page_id', '=', 'p.id')
                ->on('pp.site_id', '=', 'p.site_id')->on('pp.content_item_id', '=', 'p.content_item_id');
        })->join('content_revisions as r', function ($join): void {
            $join->on('r.id', '=', 'p.serving_content_revision_id')->on('r.content_item_id', '=', 'p.content_item_id');
        })->where('p.site_id', $siteId)->where('p.status', 'live')->where('p.page_type', 'movie');
    }

    /** Compile only data-dependent output using the already frozen expressions and assignments. */
    private function presentation(array $snapshot): array
    {
        $assignments = $snapshot['assignments'] ?? [];
        $display = $snapshot;
        unset($display['managed_body']);
        $content = FanmuluPresentation::content($display);
        $seoAssignments = AssignmentPurpose::seo($assignments);
        $primary = collect($seoAssignments)->firstWhere('role', 'primary');
        $secondary = FanmuluPresentation::enabled($snapshot) && ! FanmuluPresentation::separateSeo($snapshot)
            ? $snapshot['fanmulu']['display_title']
            : collect($seoAssignments)->where('role', 'secondary')->pluck('text')->implode('、');
        $values = ['content.title' => $content['title'] ?? null, 'content.genre' => $content['genre'] ?? null,
            'content.author' => $content['author'] ?? null, 'kw.primary' => $primary['text'] ?? null,
            'kw.secondary' => $secondary, 'site.name' => $snapshot['site']['name'] ?? '',
            'category.name' => $snapshot['category']['name'] ?? '影片目录'] + SeoTemplateBundle::movieMetadataValues($content)
            + (empty($snapshot['custom_variables']) ? [] : app(TemplateVariableService::class)->values($snapshot['custom_variables']));
        $resolved = $snapshot['resolved'] ?? [];
        $slots = $snapshot['slots'] ?? [];
        foreach ($resolved['slots'] ?? [] as $slot => $config) {
            if (str_starts_with($slot, 'allocation.') || in_array($slot, ['managed_body', 'related_links'], true)) {
                continue;
            }
            if ($config === false || (is_array($config) && ($config['enabled'] ?? true) === false)) {
                unset($slots[$slot]);

                continue;
            }
            $fallbacks = is_array($config) ? ($config['fallbacks'] ?? []) : [];
            foreach (['content.genre', 'content.author', 'content.year', 'content.area', 'content.director', 'content.actors', 'content.language'] as $token) {
                if (! array_key_exists($token, $fallbacks)) {
                    $fallbacks[$token] = '';
                }
            }
            $expression = is_string($config) ? $config : (is_array($config) ? ($config['template'] ?? '') : '');
            $value = app(TemplateCompiler::class)->compile($expression, $values, $fallbacks, $slot === 'intro');
            $slots[$slot] = $slot === 'intro' ? Html::sanitize($value) : $value;
        }
        $snapshot['slots'] = FanmuluPresentation::slots($snapshot, $slots);
        $body = Html::sanitize($content['body_html'] ?? '');
        $zone = is_string($resolved['managed_body_zone'] ?? null) ? $resolved['managed_body_zone'] : 'main-content';
        $body = '<div data-seo-zone="'.htmlspecialchars($zone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$body.'</div>';
        $policy = $resolved['slots']['managed_body'] ?? false;
        if ($policy !== false && (! is_array($policy) || ($policy['enabled'] ?? true) !== false)) {
            $body = Html::anchors($body, array_values(array_filter($assignments, fn ($assignment): bool => ($assignment['role'] ?? null) === 'anchor')),
                $snapshot['canonical_url'] ?? '', (int) ($policy['max_anchors'] ?? 3), (int) ($policy['per_keyword'] ?? 1));
        }
        $snapshot['managed_body'] = $body;

        return $snapshot;
    }

    private function cardProjection(): string
    {
        $fields = [];
        foreach (['title', 'poster_url', 'year', 'genre', 'area', 'language', 'actors', 'director', 'directors', 'remarks',
            'score', 'hits', 'hits_week', 'hits_month', 'source_updated_at'] as $field) {
            $fields[] = "'".$field."', r.payload_json->'".$field."'";
        }
        $metadata = [];
        foreach (['poster_url', 'score', 'vod_score', 'hits', 'vod_hits', 'hits_week', 'vod_hits_week', 'hits_month', 'vod_hits_month'] as $field) {
            $metadata[] = "'".$field."', r.payload_json->'metadata'->'".$field."'";
        }
        $fields[] = "'metadata', jsonb_build_object(".implode(', ', $metadata).')';
        $fields[] = "'episodes', CASE WHEN jsonb_typeof(r.payload_json->'episodes') = 'array' AND r.payload_json->'episodes' <> '[]'::jsonb THEN '[{}]'::jsonb ELSE '[]'::jsonb END";
        $fields[] = "'body_html', left(r.payload_json->>'body_html', 4096)";

        return 'jsonb_build_object('.implode(', ', $fields).')';
    }
}
