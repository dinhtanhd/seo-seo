<?php

namespace App\Domain\Publishing;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Keywords\Allocator;
use App\Domain\Rules\RuleResolver;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeCatalog;
use App\Domain\Themes\MovieThemeService;
use App\Domain\Themes\PublicationReadMemo;
use App\Domain\Themes\ReviewedThemeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Planner
{
    public function fingerprint(object $page, ?array $resolved = null, array $assignments = [], ?array $runtimeSources = null, bool $presentationOnly = false): string
    {
        return Json::hash($this->fingerprintData($page, $resolved, $assignments, $runtimeSources, $presentationOnly));
    }

    public function firstVisitContext(object $page, array $resolved, object $site, int $globalEpoch): array
    {
        return $this->fingerprintData($page, $resolved, [], [], site: $site, globalEpoch: $globalEpoch);
    }

    /** Reuse the configuration and assignment reads while the first-visit transaction holds their fences. */
    public function materializedFirstVisitFingerprint(object $page, array $assignments, string $expected, array $references, ?object $site = null, ?int $globalEpoch = null): string
    {
        $context = $this->fingerprintData($page, null, $assignments, [], site: $site, globalEpoch: $globalEpoch);
        if (Json::hash($context) !== $expected) {
            throw new Conflict('PREVIEW_STALE');
        }
        if (isset($context['runtime_sources'])) {
            $context['runtime_sources'] = app(MovieThemeCatalog::class)->recommendationState($page->site_id, $references);
        }

        return Json::hash($context);
    }

    public function firstVisitFingerprint(array $context, object $page, array $resolved, array $keywords): string
    {
        $poolIds = array_column($context['pools'], 'id');
        foreach ($keywords as $keyword) {
            if (! in_array($keyword->pool_id, $poolIds, true)) {
                throw new Conflict('FIRST_VISIT_POOL_CHANGED');
            }
        }
        $context['page'] = (array) $page;
        $context['resolved'] = $resolved;
        $context['keywords'] = $keywords;

        return Json::hash($context);
    }

    private function fingerprintData(object $page, ?array $resolved, array $assignments, ?array $runtimeSources, bool $presentationOnly = false, ?object $site = null, ?int $globalEpoch = null): array
    {
        $site ??= DB::table('sites')->find($page->site_id);
        $resolved ??= app(RuleResolver::class)->resolve($page, $site);
        $siteFingerprint = (array) $site;
        unset($siteFingerprint['public_content_epoch']);
        $keys = DB::table('keywords')->whereIn('id', array_column($assignments, 'keyword_id'))->orderBy('id')->get()->all();
        $bindings = DB::table('keyword_bindings')->where('site_id', $page->site_id)->orderBy('id')->get()->all();
        $poolIds = array_column($bindings, 'pool_id');
        $poolIds = array_values(array_unique(array_merge($poolIds, FanmuluSettings::fromSite($site)['display_name_pool_ids'] ?? [], array_column($keys, 'pool_id'))));
        $pools = DB::table('keyword_pools')->whereIn('id', $poolIds)->orderBy('id')->get()->all();

        $fingerprint = ['page' => (array) $page, 'site' => $siteFingerprint, 'global_epoch' => $globalEpoch ?? DB::table('system_settings')->where('id', 1)->value('config_epoch'), 'resolved' => $resolved, 'pools' => $pools, 'keywords' => $keys, 'bindings' => $bindings, 'limits' => DB::table('site_keyword_limits')->where('site_id', $page->site_id)->orderBy('keyword_id')->get()->all()];
        if ($presentationOnly) {
            $fingerprint['presentation_only'] = true;
        }
        $routing = SitePresentationService::profile($site);
        if (! empty($routing['configured']) && in_array($routing['category_key'] ?? 'id', ['pinyin_id', 'pinyin_compact_id'], true)) {
            $fingerprint['category_routes'] = SitePresentationService::categoryRouteKeys((int) $site->id, $routing);
        }
        if (! $presentationOnly && in_array($page->page_type, ['home', 'category'], true)) {
            $fingerprint['aggregate_sources'] = app(MovieThemeCatalog::class)->sourceState($page->site_id, MovieThemeCatalog::references(Json::decode($page->input_snapshot_json)));
        }
        if (! $presentationOnly && $page->page_type === 'movie' && ! empty($resolved['theme'])
            && ReviewedThemeRuntime::enabled(app(MovieThemeService::class)->snapshot($resolved['theme']))) {
            $references = $runtimeSources ?? array_map(fn ($source) => ['page_id' => (int) $source->id], app(MovieThemeCatalog::class)->runtimeSourceState($page->site_id, $page->id));
            $fingerprint['runtime_sources'] = app(MovieThemeCatalog::class)->recommendationState($page->site_id, $references);
        }

        return $fingerprint;
    }

    public function preview(int $siteId, array $pageIds, int $actorId, string $action = 'publish', array $options = [], array $priorBatchIds = []): object
    {
        Access::site($actorId, $siteId, true);
        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        sort($pageIds);
        if (! $pageIds || count($pageIds) > 10000) {
            throw new \InvalidArgumentException('Chọn 1–10.000 trang');
        }
        if (! in_array($action, ['publish', 'reallocate', 'rollback'])) {
            throw new \InvalidArgumentException('Action không hợp lệ');
        }
        if (array_key_exists('presentation_only', $options) && (! is_bool($options['presentation_only']) || ($options['presentation_only'] && $action !== 'publish'))) {
            throw new \InvalidArgumentException('Đổi giao diện chỉ dùng với thao tác áp dụng cấu hình.');
        }
        if (array_key_exists('refresh_seo', $options) && (! is_bool($options['refresh_seo'])
            || $action !== 'publish' || ($options['presentation_only'] ?? false) !== true)) {
            throw new \InvalidArgumentException('Cập nhật SEO từ bản đang phục vụ cần chế độ áp dụng giao diện.');
        }
        $requestKey = $options['idempotency_key'] ?? (string) Str::uuid();
        unset($options['_sequential_start']);
        $requestHash = Json::hash($priorBatchIds === [] ? [$siteId, $pageIds, $action, $options] : [$siteId, $pageIds, $action, $options, $priorBatchIds]);

        return DB::transaction(function () use ($siteId, $pageIds, $actorId, $action, $options, $priorBatchIds, $requestKey, $requestHash) {
            return app(PublicationReadMemo::class)->within(function () use ($siteId, $pageIds, $actorId, $action, $options, $priorBatchIds, $requestKey, $requestHash) {
                DB::table('system_settings')->where('id', 1)->sharedLock()->first();
                DB::table('sites')->where('id', $siteId)->sharedLock()->first();
                $existing = DB::table('publish_batches')->where(['requested_by' => $actorId, 'request_idempotency_key' => $requestKey])->first();
                if ($existing) {
                    if ($existing->request_hash !== $requestHash) {
                        throw new Conflict('IDEMPOTENCY_PAYLOAD_CONFLICT');
                    }

                    return $existing;
                }
                $pages = DB::table('pages')->where('site_id', $siteId)->whereIn('id', $pageIds)->orderBy('id')->get();
                if ($pages->count() !== count($pageIds)) {
                    abort(403);
                }
                $seed = $options['seed'] ?? bin2hex(random_bytes(16));
                [$simulation, $options['_sequential_start']] = $this->campaignContext($siteId, $actorId, $priorBatchIds);
                $sequential = [];
                $allocator = ($options['presentation_only'] ?? false) ? null : app(Allocator::class);
                $allocator?->prepare($siteId);
                $batchId = DB::table('publish_batches')->insertGetId(['public_id' => (string) Str::uuid(), 'site_id' => $siteId, 'action' => $action, 'status' => 'previewing', 'requested_by' => $actorId, 'request_idempotency_key' => $requestKey, 'request_hash' => $requestHash, 'options_json' => Json::encode($options + ['seed' => $seed])]);
                foreach ($pages as $page) {
                    $status = 'pending';
                    $error = null;
                    $proposal = [];
                    $fingerprint = null;
                    try {
                        if ($page->publish_locked) {
                            throw new Conflict('PAGE_LOCKED');
                        }
                        if ($page->status === 'archived' || $page->status === 'gone') {
                            throw new Conflict('PAGE_NOT_PUBLISHABLE');
                        }
                        if (! ($options['presentation_only'] ?? false) && in_array($page->page_type, ['home', 'category'], true)) {
                            app(MovieThemeCatalog::class)->sourceState($siteId, MovieThemeCatalog::references(Json::decode($page->input_snapshot_json)));
                        }
                        $resolved = app(RuleResolver::class)->resolve($page);
                        if ($action === 'rollback') {
                            $restoreOptions = $options['restore_map'][$page->id] ?? $options;
                            $restore = DB::table('page_publications')->where(['id' => $restoreOptions['restore_publication_id'] ?? 0, 'page_id' => $page->id])->first();
                            if (! $restore) {
                                throw new Conflict('RESTORE_NOT_FOUND');
                            }
                            if ((int) $page->current_publication_id !== (int) ($restoreOptions['expected_head_id'] ?? 0)) {
                                throw new Conflict('ROLLBACK_HEAD_CONFLICT');
                            }
                            $restored = PublicationRelocation::materialize(app(SnapshotStorage::class)->decode($restore->snapshot_json), $restore->html_body);
                            $snapshot = $restored['snapshot'];
                            SitePresentationService::assertAvailable($page, $snapshot['page']['path'] ?? $page->canonical_path);
                            SitePresentationService::virtualChanges($page, $snapshot);
                            $assignments = $snapshot['assignments'];
                            $html = $restored['html'];
                            $proposal = ['snapshot' => $snapshot, 'html' => $html, 'restored_from_publication_id' => $restore->id];
                        } elseif (($options['presentation_only'] ?? false) === true) {
                            $snapshot = $this->presentationSnapshot($page, $resolved, ($options['refresh_seo'] ?? false) === true);
                            $assignments = $snapshot['assignments'];
                            $html = app(Renderer::class)->html($snapshot);
                            $proposal = ['snapshot' => $snapshot, 'html' => $html];
                        } else {
                            if ($page->content_item_id && ! $page->current_publication_id
                                && DB::table('content_items')->where('id', $page->content_item_id)->value('library_status') === 'archived') {
                                throw new Conflict('MOVIE_ARCHIVED');
                            }
                            $content = $page->working_content_revision_id ? Json::decode(DB::table('content_revisions')->where('id', $page->working_content_revision_id)->value('payload_json')) : [];
                            $assignments = $allocator->allocate($page, $content, $resolved, $seed, $action, $options, $simulation, $sequential);
                            $snapshot = app(Renderer::class)->snapshot($page, $content, $assignments, $resolved);
                            $html = app(Renderer::class)->html($snapshot);
                            $proposal = ['snapshot' => $snapshot, 'html' => $html];
                        }
                        $before = $page->current_publication_id ? DB::table('page_publications')->find($page->current_publication_id) : null;
                        $proposal['before'] = $before ? Json::decode($before->snapshot_json)['slots'] : null;
                        $proposal['after'] = $snapshot['slots'];
                        $proposal['assignments'] = $assignments;
                        $proposal['html_hash'] = hash('sha256', $html);
                        $fingerprint = $this->fingerprint($page, $resolved, $assignments, $snapshot['aggregate']['entries'] ?? [], ($options['presentation_only'] ?? false) === true);
                        $compareCards = app(SnapshotStorage::class)->enabled($siteId) || ! empty($snapshot['_snapshot_cards'])
                            || ($before && isset(Json::decode($before->snapshot_json)['_snapshot_storage']));
                        $publicData = fn (array $data) => ['content_revision_id' => $data['content_revision_id'] ?? null,
                            'content' => $data['content'] ?? [], 'site' => $data['site'] ?? [], 'category' => $data['category'] ?? [],
                            'aggregate_entries' => $compareCards ? app(SnapshotStorage::class)->projectEntries($data) : ($data['aggregate']['entries'] ?? []),
                            'theme_catalog' => $data['theme_catalog'] ?? null,
                            'seo_template_bundle' => $data['seo_template_bundle'] ?? null,
                            'theme' => ! empty($data['theme']) ? ['id' => $data['theme']['id'] ?? null, 'version_id' => $data['theme']['version_id'] ?? null, 'hash' => $data['theme']['hash'] ?? null] : null,
                            'category_id' => $data['page']['category_id'] ?? null,
                            'route_key' => $data['page']['route_key'] ?? SitePresentationService::keyFromPath($data['page']['path'] ?? '/', SitePresentationService::profile($data['routing'] ?? null), 'detail'),
                            'routing' => SitePresentationService::profile($data['routing'] ?? null)];
                        if ($before && $page->status === 'live' && $before->html_hash === $proposal['html_hash'] && $action !== 'rollback'
                            && Json::hash($publicData(app(SnapshotStorage::class)->decode($before->snapshot_json))) === Json::hash($publicData($snapshot))) {
                            $status = 'unchanged';
                        }
                        $proposal['snapshot'] = app(SnapshotStorage::class)->compact($snapshot);
                    } catch (Conflict|\InvalidArgumentException $e) {
                        $error = $e instanceof Conflict ? $e->reason : $e->getMessage();
                        $status = $error === 'PAGE_LOCKED' ? 'locked' : 'error';
                    }
                    DB::table('publish_batch_items')->insert(['batch_id' => $batchId, 'site_id' => $siteId, 'page_id' => $page->id, 'status' => $status, 'preview_json' => Json::encode($proposal), 'fingerprint' => $fingerprint, 'expires_at' => now()->addMinutes(30), 'expected_row_version' => $page->row_version, 'expected_current_publication_id' => $page->current_publication_id, 'error_code' => $error]);
                }
                DB::table('publish_batches')->where('id', $batchId)->update(['status' => 'ready', 'sequential_json' => Json::encode($sequential), 'counts' => Json::encode(DB::table('publish_batch_items')->where('batch_id', $batchId)->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status')->all())]);
                Access::audit('batch.preview', $actorId, $siteId, ['page_ids' => $pageIds, 'action' => $action], null, $batchId);

                return DB::table('publish_batches')->find($batchId);
            });
        }, 3);
    }

    /** Change appearance from the live artifact, never from an unpublished content edit. */
    private function presentationSnapshot(object $page, array $resolved, bool $refreshSeo = false): array
    {
        $publication = $page->status === 'live' && $page->current_publication_id
            ? DB::table('page_publications')->where(['id' => $page->current_publication_id, 'site_id' => $page->site_id, 'page_id' => $page->id])->first() : null;
        if (! $publication) {
            throw new Conflict('PRESENTATION_REQUIRES_LIVE_PAGE');
        }
        $snapshot = FanmuluDetailArtifact::forRefresh(PublicationRelocation::materialize(app(SnapshotStorage::class)->decode($publication->snapshot_json), $publication->html_body)['snapshot']);
        if (($snapshot['site']['id'] ?? null) !== $page->site_id || ($snapshot['page']['id'] ?? null) !== $page->id
            || ($snapshot['page']['type'] ?? null) !== $page->page_type || $publication->content_item_id !== $page->content_item_id
            || (isset($snapshot['fanmulu']) ? 'fanmulu' : 'regular') !== ($page->page_kind ?? 'regular')
            || ! is_array($snapshot['content'] ?? null) || ! is_array($snapshot['assignments'] ?? null)) {
            throw new Conflict('PRESENTATION_SOURCE_INVALID');
        }
        if ($refreshSeo) {
            return app(Renderer::class)->refreshSeo($snapshot, $resolved, $page);
        }
        $site = DB::table('sites')->find($page->site_id);
        $routing = SitePresentationService::profile($site);
        $categoryKeys = SitePresentationService::categoryRouteKeys($page->site_id, $routing);
        $frozenPage = clone $page;
        $frozenPage->category_id = $snapshot['page']['category_id'] ?? $snapshot['category']['id'] ?? null;
        $path = SitePresentationService::pathForPage($frozenPage, $routing, $snapshot['content'], $categoryKeys);
        SitePresentationService::assertAvailable($page, $path);
        SitePresentationService::virtualChanges($page, ['routing' => $routing, 'page' => ['path' => $path]]);
        $snapshot['routing'] = $routing;
        $snapshot['page']['path'] = $path;
        if (($page->page_kind ?? 'regular') !== 'fanmulu') {
            $snapshot['page']['route_key'] = SitePresentationService::routeKey($frozenPage, $snapshot['content'], $routing, $categoryKeys);
        }
        $snapshot['canonical_url'] = rtrim($snapshot['site']['base_url'], '/').$path;
        $snapshot['content_revision_id'] = $publication->content_revision_id;
        $snapshot['theme'] = empty($resolved['theme']) ? null : app(MovieThemeService::class)->snapshot($resolved['theme']);
        $snapshot['resolved']['theme'] = $resolved['theme'] ?? null;
        if (is_array($snapshot['theme_catalog'] ?? null)) {
            foreach ($snapshot['theme_catalog']['categories'] ?? [] as $index => $category) {
                $snapshot['theme_catalog']['categories'][$index]['path'] = SitePresentationService::categoryPath($category, $routing, $categoryKeys);
            }
        } elseif (MovieThemeCatalog::supports($snapshot['theme'])) {
            $snapshot['theme_catalog'] = app(MovieThemeCatalog::class)->freeze($page->site_id, $snapshot['aggregate']['entries'] ?? [], $routing, 10000, $categoryKeys);
        }
        if (isset($snapshot['category']['id'])) {
            $snapshot['category']['path'] = SitePresentationService::categoryPath($snapshot['category'], $routing, $categoryKeys);
        }
        unset($snapshot['_catalog_card']);

        return $snapshot;
    }

    /** Rebuild simulation from durable, ordered previews; this never reserves quota. */
    private function campaignContext(int $siteId, int $actorId, array $priorBatchIds): array
    {
        if (count($priorBatchIds) > 128 || count(array_unique($priorBatchIds)) !== count($priorBatchIds)) {
            throw new \InvalidArgumentException('Danh sách preview trước không hợp lệ.');
        }
        $simulation = [];
        $starts = [];
        $lastId = 0;
        foreach ($priorBatchIds as $id) {
            $batch = DB::table('publish_batches')->find($id);
            if (! $batch || (int) $batch->site_id !== $siteId || (int) $batch->requested_by !== $actorId
                || $batch->status !== 'ready' || $batch->accepted_at !== null || $batch->id <= $lastId) {
                throw new Conflict('CAMPAIGN_PREDECESSOR_CONFLICT');
            }
            $lastId = $batch->id;
            foreach (Json::decode($batch->sequential_json) as $policy => $range) {
                if (isset($starts[$policy]) && ($range['start'] !== $starts[$policy]['end'] || $range['cursor_revision'] !== $starts[$policy]['cursor_revision'])) {
                    throw new Conflict('CAMPAIGN_PREDECESSOR_CONFLICT');
                }
                $starts[$policy] = ['state_id' => $range['state_id'], 'start' => $range['end'], 'end' => $range['end'], 'cursor_revision' => $range['cursor_revision'] + 1];
            }
            foreach (DB::table('publish_batch_items')->where('batch_id', $id)->where('status', 'pending')->get() as $item) {
                foreach (Json::decode($item->preview_json)['assignments'] ?? [] as $assignment) {
                    $simulation[$siteId.':'.$assignment['keyword_id']][] = $item->page_id;
                }
            }
        }

        return [$simulation, $starts];
    }

    public function refreshAggregate(int $pageId, array $pageIds, int $actorId): void
    {
        $page = DB::table('pages')->find($pageId);
        if (! $page) {
            abort(404);
        }Access::site($actorId, $page->site_id, true);
        if (! in_array($page->page_type, ['home', 'category'])) {
            throw new \InvalidArgumentException('Chỉ home/category dùng aggregate');
        }
        $selectedPageIds = array_values(array_unique(array_map('intval', $pageIds)));
        $entries = app(MovieThemeCatalog::class)->publishedEntries($page->site_id, array_map(fn ($id) => ['page_id' => $id], $selectedPageIds));
        $input = ['entries' => $entries, 'selected_page_ids' => $selectedPageIds];
        DB::transaction(function () use ($pageId, $entries, $input, $actorId, $page) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('sites')->where('id', $page->site_id)->sharedLock()->first();
            DB::table('pages')->where('id', $pageId)->lockForUpdate()->first();
            DB::table('pages')->where('id', $pageId)->update(['input_snapshot_json' => Json::encode($input), 'input_hash' => Json::hash($input), 'row_version' => DB::raw('row_version+1')]);
            Access::audit('page.aggregate.draft', $actorId, $page->site_id, ['entries' => count($entries)], $pageId);
        });
    }
}
