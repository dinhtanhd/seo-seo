<?php

namespace App\Domain\Sites;

use App\Domain\Advertising\AdvertisingScriptPaths;
use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Ingestion\SourceTypeService;
use App\Domain\Publishing\Planner;
use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\Publisher;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteProvisioner
{
    /** A bulk item's durable transaction owns request idempotency; all new website state commits together. */
    public function create(array $entry, array $definition, int $actorId, string $idempotencyKey): object
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
        [$entry, $definition] = $this->validate($entry, $definition, $idempotencyKey);

        return DB::transaction(function () use ($entry, $definition, $actorId, $idempotencyKey): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['website-provision:'.$entry['domain']]);
            abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
            if (DB::table('site_domains')->where('host_ascii', $entry['domain'])->exists()) {
                throw new Conflict('DOMAIN_ALREADY_EXISTS', 'Tên miền đã được dùng; website hiện có được giữ nguyên.');
            }
            $sources = DB::table('source_connectors')->whereIn('id', $definition['source_ids'])->orderBy('id')->sharedLock()->get();
            if ($sources->count() !== count($definition['source_ids']) || $sources->contains(fn ($source) => $source->status !== 'active' || $source->deleted_at !== null)) {
                throw new Conflict('PRESET_SOURCE_UNAVAILABLE');
            }
            foreach (['theme_id' => 'movie_themes', 'template_id' => 'templates'] as $field => $table) {
                if (! CatalogAvailability::filter(DB::table($table), $table)->where(['id' => $definition[$field], 'status' => 'active'])->whereNotNull('current_version_id')->exists()) {
                    throw new Conflict('PRESET_'.strtoupper($field).'_UNAVAILABLE');
                }
            }
            $pools = DB::table('keyword_pools')->whereIn('id', $definition['pool_ids'])->orderBy('id')->sharedLock()->get();
            if ($pools->count() !== count($definition['pool_ids']) || $pools->contains(fn ($pool) => $pool->scope !== 'shared' || $pool->status !== 'active' || $pool->deleted_at !== null)) {
                throw new Conflict('PRESET_POOL_UNAVAILABLE');
            }
            FanmuluSettings::assertDisplayNamePools($definition['fanmulu']['display_name_pool_ids'] ?? [], null, $entry['locale'], true);
            $this->assertAnchors($entry['domain'], $definition);
            $profile = array_replace($definition['presentation'], ['configured' => true, 'fanmulu' => ['enabled' => false]]);
            if (array_key_exists('fanmulu', $definition)) {
                $profile['fanmulu'] = FanmuluPresetSettings::normalize($definition['fanmulu'], $profile) + ['actor_id' => $actorId];
            }
            $profile = SitePresentationService::normalize($profile);
            app(AdvertisingScriptPaths::class)->assertRoutesAvailable($profile);
            $siteId = DB::table('sites')->insertGetId(['name' => $entry['name'], 'locale' => $entry['locale'],
                'content_type' => 'movie', 'base_url' => $entry['base_url'], 'theme_id' => $definition['theme_id'],
                'template_id' => $definition['template_id'], 'presentation_json' => Json::encode($profile)]);
            DB::table('site_memberships')->insert(['site_id' => $siteId, 'user_id' => $actorId, 'role' => 'owner']);
            DB::table('site_domains')->insert(['site_id' => $siteId, 'host_ascii' => $entry['domain'], 'is_primary' => true,
                'status' => 'active', 'verified_at' => null]);
            $categoryMap = $this->categories($siteId, $definition['source_reference_site_id'], $actorId);
            $policies = $this->sources($siteId, $sources->all(), $categoryMap, $definition['source_reference_site_id'], $actorId);
            $this->keywords($siteId, $definition, $actorId);
            $delivery = app(SiteDeliverySettings::class)->configuration($siteId, $actorId);
            if ($definition['delivery_mode'] === 'on_demand' && (! $delivery['available'] || ! $delivery['runtime_enabled'])) {
                throw new Conflict('ON_DEMAND_UNAVAILABLE', 'Chế độ tạo trang khi mở link chưa được bật trên hệ thống.');
            }
            if ($delivery['available']) {
                app(SiteDeliverySettings::class)->save($siteId, $definition['delivery_mode'], 0, $actorId);
            }
            $movies = $this->movies($siteId, $definition, $policies, $profile);
            if ($definition['delivery_mode'] === 'prepublish' && $movies !== []) {
                $this->apply($siteId, $movies, $actorId, $idempotencyKey.':movies');
            }
            $input = ['selected_page_ids' => $movies];
            $home = DB::table('pages')->insertGetId(['site_id' => $siteId, 'page_type' => 'home',
                'canonical_path' => SitePresentationService::endpointPath($profile, 'home'),
                'input_snapshot_json' => Json::encode($input), 'input_hash' => Json::hash($input)]);
            $this->apply($siteId, [$home], $actorId, $idempotencyKey.':home');
            Access::audit('site.provisioned', $actorId, $siteId, ['domain' => $entry['domain'], 'delivery_mode' => $definition['delivery_mode'],
                'initial_movie_count' => count($movies), 'source_ids' => $definition['source_ids'], 'pool_ids' => $definition['pool_ids']]);
            app(PublicHtmlCache::class)->invalidateAll();

            return DB::table('sites')->find($siteId);
        }, 3);
    }

    private function validate(array $entry, array $definition, string $idempotencyKey): array
    {
        if (array_diff(array_keys($entry), ['domain', 'base_url', 'name', 'locale']) || array_diff(array_keys($definition), [
            'schema_version', 'locale', 'theme_id', 'template_id', 'presentation', 'delivery_mode', 'pool_ids', 'primary_mode',
            'secondary_count', 'anchor_count', 'keyword_cap', 'source_ids', 'source_reference_site_id', 'initial_movie_count', 'fanmulu',
        ])) {
            throw ValidationException::withMessages(['preset' => 'Cấu hình có trường không được hỗ trợ.']);
        }
        Validator::make($entry, ['domain' => 'required|string|max:253', 'base_url' => 'required|url:http,https|max:500',
            'name' => 'sometimes|nullable|string|max:200', 'locale' => 'sometimes|nullable|string|max:16'])->validate();
        $host = idn_to_ascii(strtolower(rtrim(trim($entry['domain']), '.')), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        $parts = parse_url($entry['base_url']);
        $adminHosts = array_map('trim', explode(',', (string) config('tram.admin_hosts', env('ADMIN_HOSTS', 'localhost,127.0.0.1,admin.localhost'))));
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP) || in_array($host, $adminHosts, true) || strtolower($parts['host'] ?? '') !== $host
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 200) {
            throw ValidationException::withMessages(['domain' => 'Tên miền và URL gốc phải hợp lệ, trùng hostname, không có đường dẫn hoặc thông tin đăng nhập.']);
        }
        $definition = array_replace(['schema_version' => 1, 'locale' => 'zh-CN', 'delivery_mode' => 'on_demand', 'primary_mode' => 'weighted',
            'secondary_count' => 0, 'anchor_count' => 0, 'keyword_cap' => null, 'source_reference_site_id' => null, 'initial_movie_count' => 24], $definition);
        Validator::make($definition, ['schema_version' => 'required|integer|in:1', 'locale' => 'required|string|max:16',
            'theme_id' => 'required|integer|min:1', 'template_id' => 'required|integer|min:1', 'presentation' => 'required|array',
            'delivery_mode' => 'required|in:on_demand,prepublish', 'pool_ids' => 'required|array|min:1|max:20', 'pool_ids.*' => 'required|integer|min:1|distinct',
            'source_ids' => 'required|array|min:1|max:20', 'source_ids.*' => 'required|integer|min:1|distinct',
            'source_reference_site_id' => 'nullable|integer|min:1', 'primary_mode' => 'required|in:weighted,random-once,sequential',
            'secondary_count' => 'required|integer|min:0|max:5', 'anchor_count' => 'required|integer|min:0|max:5',
            'keyword_cap' => 'nullable|integer|min:1|max:2147483647', 'initial_movie_count' => 'required|integer|min:0|max:100',
            'fanmulu' => 'sometimes|array:enabled,prefix,prefixes,suffix,links_per_page,max_pages,url_rule,display_name_pool_ids',
            'fanmulu.display_name_pool_ids' => 'sometimes|array|max:1000', 'fanmulu.display_name_pool_ids.*' => 'required|integer|min:1|distinct'])->validate();
        foreach (['pool_ids', 'source_ids'] as $field) {
            if (! array_is_list($definition[$field])) {
                throw ValidationException::withMessages([$field => 'Cần danh sách ID.']);
            }
            $definition[$field] = array_map('intval', $definition[$field]);
            sort($definition[$field]);
        }
        foreach (['schema_version', 'theme_id', 'template_id', 'secondary_count', 'anchor_count', 'initial_movie_count'] as $field) {
            $definition[$field] = (int) $definition[$field];
        }
        $definition['source_reference_site_id'] = $definition['source_reference_site_id'] === null ? null : (int) $definition['source_reference_site_id'];
        $definition['keyword_cap'] = $definition['keyword_cap'] === null ? null : (int) $definition['keyword_cap'];
        $entry['domain'] = $host;
        $entry['base_url'] = rtrim($entry['base_url'], '/');
        $entry['name'] = trim($entry['name'] ?? '') ?: $host;
        $entry['locale'] = $entry['locale'] ?? $definition['locale'];

        return [$entry, $definition];
    }

    private function assertAnchors(string $host, array $definition): void
    {
        if ($definition['anchor_count'] === 0) {
            return;
        }
        foreach (DB::table('keywords')->whereIn('pool_id', $definition['pool_ids'])->where('role', 'anchor')->where('status', 'active')->whereNull('deleted_at')->cursor() as $anchor) {
            $target = parse_url($anchor->target_url ?? '');
            if (! in_array(strtolower($target['scheme'] ?? ''), ['http', 'https'], true) || strtolower($target['host'] ?? '') !== $host || isset($target['user']) || isset($target['pass'])) {
                throw new Conflict('PRESET_CROSS_DOMAIN_ANCHOR', 'Kho có anchor trỏ domain khác. Chọn số anchor bằng 0 hoặc kho phù hợp.');
            }
        }
    }

    /** @return array<int,int> */
    private function categories(int $siteId, ?int $referenceSite, int $actorId): array
    {
        if ($referenceSite === null) {
            app(MovieCategoryPresetService::class)->initialize($siteId, $actorId);

            return [];
        }
        if (! DB::table('sites')->where(['id' => $referenceSite, 'content_type' => 'movie', 'status' => 'active'])->exists()) {
            throw new Conflict('PRESET_REFERENCE_SITE_UNAVAILABLE');
        }
        $remaining = CatalogAvailability::filter(DB::table('categories')->where('site_id', $referenceSite), 'categories')->orderBy('id')->get()->keyBy('id');
        $map = [];
        while ($remaining->isNotEmpty()) {
            $before = $remaining->count();
            foreach ($remaining as $category) {
                if ($category->parent_id !== null && ! isset($map[$category->parent_id])) {
                    continue;
                }
                $map[$category->id] = DB::table('categories')->insertGetId(['site_id' => $siteId, 'parent_id' => $category->parent_id === null ? null : $map[$category->parent_id],
                    'name' => $category->name, 'external_key' => $category->external_key, 'genre' => $category->genre]);
                $remaining->forget($category->id);
            }
            if ($remaining->count() === $before) {
                throw new Conflict('PRESET_CATEGORY_TREE_INVALID');
            }
        }

        return $map;
    }

    private function sources(int $siteId, array $sources, array $categoryMap, ?int $referenceSite, int $actorId): array
    {
        $policies = [];
        foreach ($sources as $source) {
            if ($referenceSite !== null) {
                $reference = CatalogAvailability::filter(DB::table('source_site_bindings'), 'source_site_bindings')
                    ->where(['site_id' => $referenceSite, 'connector_id' => $source->id, 'status' => 'active'])->sharedLock()->first();
                if (! $reference) {
                    throw new Conflict('PRESET_SOURCE_BINDING_UNAVAILABLE');
                }
                $policy = SourceTypeService::policy($reference);
                SourceTypeService::assertCurrentPolicy($policy, $source);
                foreach ($policy['category_map'] as &$category) {
                    if ($category !== null) {
                        $category = $categoryMap[$category] ?? throw new Conflict('PRESET_CATEGORY_MAPPING_INVALID');
                    }
                }
                unset($category);
                $mapping = $policy['legacy'] ? [] : array_diff_key($policy, ['legacy' => true]);
                $catalog = DB::table('source_type_catalogs')->where('connector_id', $source->id)->first();
                if (! $policy['legacy'] && $catalog && $catalog->config_hash === Json::hash(Json::decode($source->config_json))) {
                    $completionTypes = MovieCategoryPresetService::completionTypeTree(
                        SourceTypeService::acceptedTypeTree(Json::decode($catalog->types_json), $policy), $mapping['category_map'],
                        DB::table('categories')->where('site_id', $siteId)->orderBy('id')->get());
                    $completion = app(MovieCategoryPresetService::class)->completeFromSourceTypes($siteId,
                        $completionTypes, $actorId);
                    foreach ($completion['mappings'] as $type => $target) {
                        if (SourceTypeService::accepts($policy, (string) $type) && ! array_key_exists($type, $mapping['category_map'])) {
                            $mapping['category_map'][$type] = $target;
                        }
                    }
                    $policy = SourceTypeService::policy((object) ['mapping_json' => Json::encode($mapping)]);
                }
            } else {
                $catalog = DB::table('source_type_catalogs')->where('connector_id', $source->id)->first();
                if (in_array($source->kind, ['maccms', 'shandian'], true) && (! $catalog || $catalog->config_hash !== Json::hash(Json::decode($source->config_json)))) {
                    throw new Conflict('PRESET_SOURCE_CATALOG_UNAVAILABLE');
                }
                $completion = $catalog ? app(MovieCategoryPresetService::class)->completeFromSourceTypes($siteId, Json::decode($catalog->types_json), $actorId) : null;
                $mapping = $catalog ? ['schema_version' => 1, 'mode' => 'all', 'selected_type_ids' => [],
                    'category_map' => $completion['mappings'],
                    'catalog_hash' => $catalog->catalog_hash, 'catalog_config_hash' => $catalog->config_hash] : [];
                $policy = SourceTypeService::policy((object) ['mapping_json' => Json::encode($mapping)]);
            }
            DB::table('source_site_bindings')->insert(['site_id' => $siteId, 'connector_id' => $source->id, 'mapping_version' => 1,
                'mapping_json' => Json::encode($mapping), 'auto_publish_policy' => Json::encode(['enabled' => true, 'actor_id' => $actorId]), 'status' => 'active']);
            $policies[$source->id] = $policy;
        }

        return $policies;
    }

    private function keywords(int $siteId, array $definition, int $actorId): void
    {
        foreach ($definition['pool_ids'] as $pool) {
            DB::table('keyword_bindings')->insert(['site_id' => $siteId, 'pool_id' => $pool, 'status' => 'active']);
        }
        DB::table('site_keyword_limits')->insertUsing(['site_id', 'keyword_id', 'max_active_pages'], DB::table('keywords')->whereIn('pool_id', $definition['pool_ids'])->whereNull('deleted_at')
            ->selectRaw('? AS site_id, id AS keyword_id, ?::integer AS max_active_pages', [$siteId, $definition['keyword_cap']]));
        foreach (['movie', 'home', 'category'] as $type) {
            foreach (['primary' => 1, 'secondary' => $definition['secondary_count'], 'anchor' => $definition['anchor_count']] as $role => $count) {
                $config = ['enabled' => $type === 'movie' && $count > 0, 'pool_ids' => $definition['pool_ids'],
                    'mode' => $role === 'primary' ? $definition['primary_mode'] : 'weighted', 'count' => $type === 'movie' ? $count : 0,
                    'required' => $type === 'movie' && $role === 'primary'];
                $this->rule($siteId, $type, 'allocation.'.$role, $config, $actorId);
            }
        }
        $versionId = DB::table('templates')->where('id', $definition['template_id'])->value('current_version_id');
        $template = Json::decode(DB::table('template_versions')->where('id', $versionId)->value('definition_json'));
        if (! SeoTemplateBundle::isBundle($template)) {
            $this->rule($siteId, null, 'meta_keywords', ['enabled' => true, 'template' => '{{content.title}},{{kw.primary}},{{site.name}}', 'fallbacks' => ['content.title' => '', 'kw.primary' => '']], $actorId);
        }
    }

    private function rule(int $siteId, ?string $type, string $slot, array $config, int $actorId): void
    {
        $id = DB::table('rules')->insertGetId(['site_id' => $siteId, 'scope_type' => $type === null ? 'site' : 'page_type', 'page_type' => $type, 'slot' => $slot]);
        $version = DB::table('rule_versions')->insertGetId(['rule_id' => $id, 'version_no' => 1, 'config_json' => Json::encode($config), 'hash' => Json::hash($config), 'created_by' => $actorId]);
        DB::table('rules')->where('id', $id)->update(['current_version_id' => $version]);
    }

    private function movies(int $siteId, array $definition, array $policies, array $profile): array
    {
        $limit = $definition['initial_movie_count'];
        if ($limit === 0) {
            return [];
        }
        $candidates = [];
        foreach ($policies as $source => $policy) {
            $query = DB::table('content_items as c')->join('content_revisions as r', function ($join): void {
                $join->on('r.id', '=', 'c.current_revision_id')->on('r.content_item_id', '=', 'c.id');
            })->where('c.source_connector_id', $source)->where('c.kind', 'movie')->where('c.library_status', 'active');
            CatalogAvailability::filter($query, 'content_items', 'c');
            if ($policy['mode'] === 'selected') {
                $query->whereIn(DB::raw("COALESCE(r.payload_json->>'category_external_id', r.payload_json#>>'{_raw,type_id}')"), $policy['selected_type_ids']);
            }
            $candidates[$source] = $query->orderByDesc('c.id')->limit($limit)->get(['c.id', 'c.external_id', 'c.current_revision_id', 'r.payload_json'])->all();
        }
        $movies = [];
        while (count($movies) < $limit && array_filter($candidates)) {
            foreach ($candidates as $source => &$items) {
                $item = array_shift($items);
                if (! $item || count($movies) === $limit) {
                    continue;
                }
                $content = Json::decode($item->payload_json);
                $type = SourceTypeService::recordType($content);
                if (! SourceTypeService::accepts($policies[$source], $type)) {
                    continue;
                }
                $category = $type === null ? null : ($policies[$source]['category_map'][$type] ?? null);
                $key = SitePresentationService::movieKey((int) $source, $item->external_id, $content, $profile);
                $movies[] = DB::table('pages')->insertGetId(['site_id' => $siteId, 'content_item_id' => $item->id,
                    'working_content_revision_id' => $item->current_revision_id, 'category_id' => $category, 'page_type' => 'movie',
                    'canonical_path' => SitePresentationService::detailPath($profile, $key), 'status' => 'draft']);
            }
            unset($items);
        }

        return $movies;
    }

    private function apply(int $siteId, array $pageIds, int $actorId, string $idempotencyKey): void
    {
        $batch = app(Planner::class)->preview($siteId, $pageIds, $actorId, options: ['idempotency_key' => $idempotencyKey]);
        $items = DB::table('publish_batch_items')->where('batch_id', $batch->id)->orderBy('page_id')->get();
        if ($items->contains(fn ($item) => $item->status !== 'pending')) {
            $error = $items->first(fn ($item) => $item->status !== 'pending')->error_code;
            throw new Conflict('WEBSITE_CONFIGURATION_INCOMPLETE', $error ?? 'Không thể tạo trang phục vụ.');
        }
        app(Publisher::class)->accept($batch->id, $actorId);
        foreach ($items as $item) {
            if (! app(Publisher::class)->publishItem($item->id)) {
                throw new Conflict('WEBSITE_APPLY_FAILED', DB::table('publish_batch_items')->where('id', $item->id)->value('error_code') ?? 'UNKNOWN');
            }
        }
    }
}
