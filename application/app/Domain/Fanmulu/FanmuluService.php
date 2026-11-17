<?php

namespace App\Domain\Fanmulu;

use App\Domain\Keywords\Allocator;
use App\Domain\Publishing\FirstVisitPublisher;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FanmuluService
{
    /**
     * Resolve one URL into a durable publication. FANMULU_* conflicts leave any
     * draft and chosen keyword intact; another request may resume publication.
     */
    public function resolve(int $siteId, string $requestPath, ?object &$committedPublication = null): object
    {
        $committedPublication = null;
        $site = DB::table('sites')->find($siteId);
        $intent = app(FanmuluNavigation::class)->intent($siteId, $requestPath);
        $settings = $this->settings($site, $requestPath, $intent);
        $lock = 'tram:fanmulu:'.$siteId.':'.$requestPath;
        DB::select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$lock]);
        try {
            $page = DB::table('pages')->where(['site_id' => $siteId, 'canonical_path' => $requestPath])->first();
            if ($page === null) {
                [$created, $actorId] = $this->createWithSlot($siteId, $requestPath);

                return app(FirstVisitPublisher::class)->resolve($siteId, (int) $created->id, $actorId, $committedPublication);
            }
            $site = DB::table('sites')->find($siteId);
            $intent = app(FanmuluNavigation::class)->intent($siteId, $requestPath);
            $settings = $this->settings($site, $requestPath, $intent);
            if ($page->page_kind !== 'fanmulu') {
                throw new Conflict('FANMULU_PATH_ALREADY_USED');
            }
            if ($page->status === 'live' && $page->current_publication_id) {
                return $page;
            }
            if ($page->status !== 'draft') {
                throw new Conflict('FANMULU_PAGE_UNAVAILABLE');
            }
            $this->assertPublisher((int) $settings['actor_id'], $siteId);

            return app(FirstVisitPublisher::class)->resolve($siteId, (int) $page->id, (int) $settings['actor_id'], $committedPublication);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lock]);
        }
    }

    private function settings(?object $site, string $requestPath, ?array $intent = null): array
    {
        if (! $site || $site->status !== 'active' || $site->content_type !== 'movie') {
            throw new Conflict('FANMULU_SITE_UNAVAILABLE');
        }
        $settings = FanmuluSettings::fromSite($site);
        if (! FanmuluSettings::matches($settings, $requestPath) && ! ($settings['enabled'] && $intent !== null)) {
            throw new Conflict('FANMULU_DISABLED_OR_PATH_INVALID');
        }

        return $settings;
    }

    /** @return array{object,int} Page and publishing actor from the locked configuration. */
    private function createWithSlot(int $siteId, string $path): array
    {
        // A surrounding transaction can create several pages before its lock is released.
        if (DB::transactionLevel() > 0) {
            return $this->createPage($siteId, $path, false);
        }
        try {
            return $this->createPage($siteId, $path, true);
        } catch (Conflict $error) {
            if ($error->reason !== 'FANMULU_SLOT_NEEDS_EXCLUSIVE') {
                throw $error;
            }
        }

        return $this->createPage($siteId, $path, false);
    }

    private function assertCreationSlot(int $siteId, string $key, bool $shared, int $maximum): void
    {
        $inFlight = $shared ? (int) DB::selectOne("SELECT count(*) AS total FROM pg_locks WHERE locktype='advisory' AND mode='ShareLock' AND granted
            AND database=(SELECT oid FROM pg_database WHERE datname=current_database()) AND objsubid=1
            AND ((classid::bigint << 32) | objid::bigint)=hashtextextended(?,0)", [$key])->total : 0;
        // Count committed rows second. A completed admission can count twice, never zero times.
        $created = DB::table('pages')->where(['site_id' => $siteId, 'page_kind' => 'fanmulu'])->count();
        if ($created >= $maximum) {
            throw new Conflict('FANMULU_PAGE_LIMIT');
        }
        if ($created + $inFlight > $maximum) {
            // Roll back the transaction or savepoint to release this shared admission before retry.
            throw new Conflict('FANMULU_SLOT_NEEDS_EXCLUSIVE');
        }
    }

    private function assertPublisher(int $actorId, int $siteId): void
    {
        $actor = DB::table('users')->find($actorId);
        if (! $actor || (! $actor->is_global_admin && ! DB::table('site_memberships')->where(['user_id' => $actorId, 'site_id' => $siteId])->whereIn('role', ['owner', 'operator'])->exists())) {
            throw new Conflict('FANMULU_PUBLISHER_UNAVAILABLE');
        }
    }

    private function sources(int $siteId): Builder
    {
        return app(FanmuluSourceCatalog::class)->liveQuery($siteId);
    }

    private function sourceHint(string $path, array $settings, ?array $intent): ?int
    {
        if ($intent !== null) {
            return $intent['source_page_id'];
        }
        if (in_array($settings['url_rule']['mode'] ?? 'legacy', ['random', 'pattern', 'tokens'], true)) {
            return null;
        }
        $prefix = FanmuluSettings::matchedPrefix($settings, $path);
        if ($prefix === null) {
            return null;
        }
        $key = substr($path, strlen($prefix) + 1);
        if (! preg_match('/^([1-9][0-9]*)-/', $key, $hint)) {
            return null;
        }
        $id = filter_var($hint[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new Conflict('FANMULU_SOURCE_UNAVAILABLE');
        }

        return $id;
    }

    /** Reject a stale frozen label before reserving a page slot. */
    private function assertIntentAvailable(int $siteId, array $intent): void
    {
        $selected = $intent['selected_primary'];
        $keyword = DB::table('keywords')->find($selected['keyword_id']);
        $source = DB::table('pages')->where(['site_id' => $siteId, 'page_kind' => 'regular', 'page_type' => 'movie'])->find($intent['source_page_id']);
        if (! $keyword || $keyword->role !== 'primary' || $keyword->normalized_text !== $selected['text'] || ! $source) {
            throw new Conflict('FANMULU_ASSIGNMENT_UNAVAILABLE');
        }
        if ($source->current_publication_id) {
            $publication = DB::table('page_publications')->where(['site_id' => $siteId, 'page_id' => $source->id, 'id' => $source->current_publication_id])
                ->select('content_revision_id')->selectRaw("jsonb_build_object('content', snapshot_json->'content', '_catalog_card', snapshot_json->'_catalog_card',
                    'category', snapshot_json->'category', 'page', snapshot_json->'page') AS snapshot_json")->first();
            $snapshot = Json::decode($publication?->snapshot_json);
            $frozen = Json::decode(DB::table('content_revisions')->where('id', $publication?->content_revision_id)->value('payload_json'));
            $content = array_replace($frozen, $snapshot['_catalog_card']['content'] ?? [], $snapshot['content'] ?? []);
            $source->category_id = array_key_exists('category_id', $snapshot['page'] ?? []) ? $snapshot['page']['category_id'] : ($snapshot['category']['id'] ?? null);
        } else {
            $content = Json::decode(DB::table('content_revisions')->where('id', $source->working_content_revision_id)->value('payload_json'));
        }
        $displayNamePoolIds = FanmuluSettings::fromSite(DB::table('sites')->find($siteId))['display_name_pool_ids'] ?? [];
        $eligible = $displayNamePoolIds === []
            ? app(Allocator::class)->eligible($keyword, $source, $content, [])
            : app(Allocator::class)->eligibleDisplayName($keyword, $source, $content);
        if (! $eligible) {
            throw new Conflict('FANMULU_ASSIGNMENT_UNAVAILABLE');
        }
    }

    private function createPage(int $siteId, string $path, bool $shared): array
    {
        return DB::transaction(function () use ($siteId, $path, $shared): array {
            $key = 'tram:fanmulu:create:'.$siteId;
            DB::select($shared ? 'SELECT pg_advisory_xact_lock_shared(hashtextextended(?,0))'
                : 'SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$key]);
            $intent = app(FanmuluNavigation::class)->intent($siteId, $path);
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $registry = app(FanmuluUrlRegistry::class);
            $reserved = $intent === null ? null : $registry->find($siteId, $path);
            $query = DB::table('sites')->where('id', $siteId);
            $site = ($reserved === null ? $query->lockForUpdate() : $query->sharedLock())->first();
            $settings = $this->settings($site, $path, $intent);
            $this->assertPublisher((int) $settings['actor_id'], $siteId);
            $this->assertCreationSlot($siteId, $key, $shared, $settings['max_pages']);
            if (isset($intent['selected_primary'])) {
                $this->assertIntentAvailable($siteId, $intent);
            }
            if ($reserved !== null) {
                $current = $registry->find($siteId, $path);
                if (($current['identity_hash'] ?? null) !== $reserved['identity_hash'] || $current['source_page_id'] !== $intent['source_page_id']) {
                    throw new Conflict('FANMULU_NAVIGATION_UNAVAILABLE');
                }
            }
            if (DB::table('page_redirects')->where(['site_id' => $siteId, 'from_path' => $path])->exists()) {
                throw new Conflict('FANMULU_PATH_ALREADY_USED');
            }
            $query = $this->sources($siteId);
            $sourceId = $this->sourceHint($path, $settings, $intent);
            if ($sourceId !== null) {
                $query->where('p.id', $sourceId);
            } else {
                $count = (clone $query)->count();
                if ($count > 0) {
                    $query->orderBy('p.id')->offset(hexdec(substr(hash('sha256', $siteId.':'.$path), 0, 8)) % $count);
                }
            }
            $source = $query->sharedLock()->first(['p.*', 'pp.id as source_publication', 'pp.content_revision_id as source_revision', DB::raw("pp.snapshot_json->'page' as source_snapshot_page")]);
            if (! $source) {
                $draft = app(FanmuluSourceCatalog::class)->draftQuery($siteId);
                if ($sourceId === null) {
                    $candidates = (clone $draft)->orderBy('p.id')->limit(20)->pluck('p.id')->all();
                    $sourceId = $candidates === [] ? null : $candidates[hexdec(substr(hash('sha256', $siteId.':'.$path), 0, 8)) % count($candidates)];
                }
                if ($sourceId !== null) {
                    $source = $draft->where('p.id', $sourceId)->lock('for share of p')->first(['p.*', DB::raw('NULL::bigint as source_publication'),
                        'p.working_content_revision_id as source_revision', DB::raw('NULL::jsonb as source_snapshot_page')]);
                }
            }
            if (! $source) {
                throw new Conflict('FANMULU_SOURCE_UNAVAILABLE');
            }
            $snapshotPage = Json::decode($source->source_snapshot_page);
            $revision = $source->source_revision;
            $publication = $source->source_publication;
            $input = ['source_update_locked' => true, 'fanmulu' => ['source_page_id' => (int) $source->id,
                'source_publication_id' => $publication, 'source_path' => $snapshotPage['path'] ?? $source->canonical_path,
                'source_content_revision_id' => (int) $revision, 'render_detail_once' => true,
                'first_seo_allocation' => 'random-once', 'seed' => bin2hex(random_bytes(16)),
                'publish_request_key' => (string) Str::uuid()]];
            if (isset($intent['selected_primary'])) {
                if ((int) $source->id !== $intent['source_page_id']) {
                    throw new Conflict('FANMULU_NAVIGATION_INVALID');
                }
                $input['fanmulu']['selected_primary'] = $intent['selected_primary'];
                if (isset($intent['discovery_provenance'])) {
                    $input['fanmulu']['discovery_provenance'] = $intent['discovery_provenance'];
                } else {
                    $input['fanmulu']['parent_provenance'] = ['page_id' => $intent['parent_page_id'],
                        'publication_id' => $intent['parent_publication_id'], 'token' => $intent['navigation_token']];
                }
            }
            $id = DB::table('pages')->insertGetId(['site_id' => $siteId, 'page_kind' => 'fanmulu', 'page_type' => 'movie',
                'source_page_id' => $source->id, 'source_publication_id' => $publication,
                'content_item_id' => $source->content_item_id, 'working_content_revision_id' => $revision,
                'serving_content_revision_id' => $publication === null ? $revision : ($source->serving_content_revision_id ?? $revision),
                'canonical_path' => $path, 'category_id' => $source->source_publication === null ? $source->category_id : ($snapshotPage['category_id'] ?? null),
                'template_id' => $source->template_id, 'theme_id' => $source->theme_id,
                'input_snapshot_json' => Json::encode($input), 'input_hash' => Json::hash($input), 'status' => 'draft']);

            return [DB::table('pages')->find($id), (int) $settings['actor_id']];
        }, 3);
    }
}
