<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Connectors\MacCmsConnector;
use App\Domain\Ingestion\Connectors\ShandianConnector;
use App\Domain\Ingestion\Http\HttpTransport;
use App\Domain\Ingestion\Http\SafeHttpClient;
use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Sites\MovieCategoryPresetService;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Ingestion\BackfillExistingMovies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SourceTypeService
{
    public function __construct(private readonly ?HttpTransport $http = null) {}

    private function source(int $sourceId, int $actorId, bool $write = false): object
    {
        $source = DB::table('source_connectors')->whereNull('deleted_at')->find($sourceId) ?? abort(404);
        $user = DB::table('users')->find($actorId);
        abort_unless($user, 403);
        if (! $user->is_global_admin) {
            $bindings = DB::table('source_site_bindings')->where('connector_id', $sourceId)->whereNull('deleted_at')->whereIn('status', ['active', 'paused'])->pluck('site_id');
            $membership = DB::table('site_memberships')->where('user_id', $actorId)->whereIn('site_id', $bindings);
            if ($write) {
                $membership->where('role', 'owner');
            }
            abort_unless($membership->exists(), 403);
        }

        return $source;
    }

    private function owner(int $actorId, int $siteId): void
    {
        Access::site($actorId, $siteId);
        $global = DB::table('users')->where('id', $actorId)->value('is_global_admin');
        abort_unless($global || DB::table('site_memberships')->where(['user_id' => $actorId, 'site_id' => $siteId, 'role' => 'owner'])->exists(), 403);
    }

    private function supported(object $source): bool
    {
        return in_array($source->kind, ['maccms', 'shandian'], true);
    }

    private function catalogFor(object $source, ?object $catalog): array
    {
        $configHash = Json::hash(Json::decode($source->config_json));
        $supported = $this->supported($source);
        $stale = ! $catalog || ! $catalog->fetched_at || ! $catalog->catalog_hash || $catalog->config_hash !== $configHash;
        if (! $stale) {
            $stale = now()->subDay()->gte(Carbon::parse($catalog->fetched_at));
        }

        return ['supported' => $supported, 'types' => $catalog ? Json::decode($catalog->types_json) : [],
            'fetched_at' => $catalog?->fetched_at, 'hash' => $catalog?->catalog_hash, 'config_hash' => $catalog?->config_hash,
            'stale' => $supported && $stale, 'error' => $catalog?->last_error,
            'current_config_hash' => $configHash];
    }

    public function catalog(int $sourceId, int $actorId): array
    {
        $source = $this->source($sourceId, $actorId);

        return $this->catalogFor($source, DB::table('source_type_catalogs')->where('connector_id', $sourceId)->first());
    }

    public function refresh(int $sourceId, int $actorId): array
    {
        $source = $this->source($sourceId, $actorId, true);
        if (! $this->supported($source)) {
            return $this->catalogFor($source, null);
        }
        $config = Json::decode($source->config_json);
        $configHash = Json::hash($config);
        try {
            if ($source->status !== 'active') {
                throw new \RuntimeException('Nguồn đang tạm dừng; không gọi API catalog.');
            }
            $transport = $this->http ?? app(SafeHttpClient::class);
            $connector = $source->kind === 'shandian' ? new ShandianConnector($transport) : new MacCmsConnector($transport);
            $types = $connector->fetchTypes($config);
            $error = null;
        } catch (\Throwable $e) {
            $types = null;
            $error = mb_substr($e->getMessage(), 0, 2000);
        }
        DB::transaction(function () use ($sourceId, $actorId, $configHash, $types, $error) {
            $source = DB::table('source_connectors')->where('id', $sourceId)->lockForUpdate()->first();
            $this->source($sourceId, $actorId, true);
            if (Json::hash(Json::decode($source->config_json)) !== $configHash) {
                throw new Conflict('SOURCE_CONFIG_CHANGED');
            }
            $catalog = DB::table('source_type_catalogs')->where('connector_id', $sourceId)->lockForUpdate()->first();
            $values = ['updated_at' => now(), 'last_error' => $error, 'last_error_at' => $error === null ? null : now()];
            if ($types !== null) {
                $values += ['types_json' => Json::encode($types), 'config_hash' => $configHash, 'catalog_hash' => Json::hash($types), 'fetched_at' => now()];
            }
            if ($catalog) {
                DB::table('source_type_catalogs')->where('connector_id', $sourceId)->update($values);
            } else {
                DB::table('source_type_catalogs')->insert($values + ['connector_id' => $sourceId, 'created_at' => now()]);
            }
            Access::audit($error === null ? 'source.types.refreshed' : 'source.types.refresh.failed', $actorId, null,
                ['connector_id' => $sourceId, 'types_count' => $types === null ? null : count($types), 'error' => $error]);
        }, 3);

        return $this->catalog($sourceId, $actorId);
    }

    public static function policy(object $binding): array
    {
        $data = Json::decode($binding->mapping_json ?? '{}');
        if (! array_key_exists('mode', $data)) {
            return ['legacy' => true, 'mode' => 'all', 'selected_type_ids' => [], 'category_map' => [], 'catalog_hash' => null, 'catalog_config_hash' => null];
        }
        if (! in_array($data['mode'], ['all', 'selected'], true) || ! is_array($data['selected_type_ids'] ?? null)
            || ! array_is_list($data['selected_type_ids']) || ! is_array($data['category_map'] ?? null)) {
            throw new \RuntimeException('BINDING_SELECTION_INVALID: cấu hình thể loại không hợp lệ.');
        }
        $ids = [];
        foreach ($data['selected_type_ids'] as $id) {
            $id = MacCmsConnector::typeId($id);
            if ($id === null) {
                throw new \RuntimeException('BINDING_SELECTION_INVALID: ID thể loại không hợp lệ.');
            }
            $ids[] = $id;
        }
        $map = [];
        foreach ($data['category_map'] as $type => $category) {
            $type = MacCmsConnector::typeId($type);
            if ($type === null || ($category !== null && (! is_int($category) || $category < 1))) {
                throw new \RuntimeException('BINDING_SELECTION_INVALID: danh mục đích không hợp lệ.');
            }
            $map[$type] = $category;
        }

        return ['legacy' => false, 'mode' => $data['mode'], 'selected_type_ids' => $ids, 'category_map' => $map,
            'catalog_hash' => $data['catalog_hash'] ?? null, 'catalog_config_hash' => $data['catalog_config_hash'] ?? null];
    }

    public function configuration(int $sourceId, int $siteId, int $actorId): array
    {
        Access::site($actorId, $siteId);
        $this->source($sourceId, $actorId);
        $binding = DB::table('source_site_bindings')->where(['connector_id' => $sourceId, 'site_id' => $siteId])->whereNull('deleted_at')->first() ?? abort(404);

        return ['binding' => $binding, 'binding_id' => $binding->id, 'source_id' => $sourceId, 'site_id' => $siteId,
            'mapping_version' => (int) $binding->mapping_version] + self::policy($binding);
    }

    public function saveSelection(int $sourceId, int $siteId, array $data, int $actorId): object
    {
        $this->owner($actorId, $siteId);
        $mode = $data['mode'] ?? null;
        $ids = $data['selected_type_ids'] ?? [];
        $map = $data['category_map'] ?? [];
        if (! in_array($mode, ['all', 'selected'], true) || ! is_array($ids) || ! array_is_list($ids) || count($ids) > 5000 || ! is_array($map) || count($map) > 5000) {
            throw new \InvalidArgumentException('Chọn chế độ all/selected và danh sách ID thể loại hợp lệ.');
        }
        $selected = [];
        foreach ($ids as $id) {
            $id = MacCmsConnector::typeId($id);
            if ($id === null) {
                throw new \InvalidArgumentException('ID thể loại đã chọn không hợp lệ.');
            }
            $selected[$id] = $id;
        }
        $selected = array_values($selected);
        sort($selected, SORT_STRING);
        $categories = [];
        foreach ($map as $type => $category) {
            $type = MacCmsConnector::typeId($type);
            if ($type === null) {
                throw new \InvalidArgumentException('ID trong bảng ghép thể loại không hợp lệ.');
            }
            if ($category === '' || $category === null) {
                $categories[$type] = null;
            } elseif ((is_int($category) || is_string($category)) && ($category = filter_var($category, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) !== false) {
                $categories[$type] = $category;
            } else {
                throw new \InvalidArgumentException('Danh mục đích phải là ID nguyên dương hoặc trống.');
            }
        }

        return DB::transaction(function () use ($sourceId, $siteId, $data, $actorId, $mode, $selected, $categories) {
            // Import already holds source before site/page locks; never acquire system_settings here.
            $source = DB::table('source_connectors')->where('id', $sourceId)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            $binding = DB::table('source_site_bindings')->where(['connector_id' => $sourceId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $this->owner($actorId, $site->id);
            if (! isset($data['mapping_version']) || (int) $data['mapping_version'] !== (int) $binding->mapping_version) {
                throw new Conflict('SOURCE_MAPPING_VERSION_CONFLICT');
            }
            $catalog = $this->catalogFor($source, DB::table('source_type_catalogs')->where('connector_id', $sourceId)->first());
            if (! $catalog['supported'] || $catalog['stale'] || ! is_string($data['catalog_hash'] ?? null) || $catalog['hash'] !== $data['catalog_hash']) {
                throw new Conflict('SOURCE_TYPE_CATALOG_STALE');
            }
            $known = array_fill_keys(array_column($catalog['types'], 'id'), true);
            foreach ([...$selected, ...array_keys($categories)] as $type) {
                if (! isset($known[$type])) {
                    throw new \InvalidArgumentException('Thể loại không có trong catalog hiện tại: '.$type);
                }
            }
            $localIds = array_values(array_unique(array_filter($categories, fn ($id) => $id !== null)));
            if (CatalogAvailability::filter(DB::table('categories')->where('site_id', $siteId)->whereIn('id', $localIds), 'categories')->count() !== count($localIds)) {
                throw new \InvalidArgumentException('Danh mục đích phải thuộc cùng website.');
            }
            $policy = ['schema_version' => 1, 'mode' => $mode, 'selected_type_ids' => $selected, 'category_map' => (object) $categories,
                'catalog_hash' => $catalog['hash'], 'catalog_config_hash' => $catalog['config_hash']];
            DB::table('source_site_bindings')->where('id', $binding->id)->update(['mapping_json' => Json::encode($policy), 'mapping_version' => $binding->mapping_version + 1]);
            DB::table('sites')->where('id', $siteId)->increment('config_epoch');
            Access::audit('source.types.selection.saved', $actorId, $siteId, ['connector_id' => $sourceId, 'binding_id' => $binding->id,
                'mapping_version' => $binding->mapping_version + 1, 'mode' => $mode, 'selected_type_ids' => $selected, 'category_map' => $categories]);
            if ($source->status === 'active' && $binding->status === 'active' && ! empty(Json::decode($binding->auto_publish_policy)['enabled'])
                && app(SiteDeliverySettings::class)->enabled($siteId)) {
                BackfillExistingMovies::record($siteId, $sourceId);
            }

            return DB::table('source_site_bindings')->find($binding->id);
        }, 3);
    }

    public static function recordType(array $record): ?string
    {
        return MacCmsConnector::typeId($record['category_external_id'] ?? $record['_raw']['type_id'] ?? null);
    }

    public static function accepts(array $policy, ?string $typeId): bool
    {
        return $policy['mode'] === 'all' || ($typeId !== null && in_array($typeId, $policy['selected_type_ids'], true));
    }

    /** Include selected API nodes and their ancestors, without enabling any unselected type. */
    public static function acceptedTypeTree(array $types, array $policy): array
    {
        if ($policy['mode'] === 'all') {
            return $types;
        }
        $byId = array_column($types, null, 'id');
        $included = [];
        foreach ($policy['selected_type_ids'] as $id) {
            $seen = [];
            while (isset($byId[$id]) && ! isset($seen[$id])) {
                $seen[$id] = true;
                $included[$id] = true;
                $id = $byId[$id]['parent_id'] ?? '';
            }
        }

        return array_values(array_filter($types, fn (array $type): bool => isset($included[$type['id']])));
    }

    /** Create missing target categories and propose form values; never save a source policy here. */
    public function completeCategories(int $sourceId, int $siteId, array $data, int $actorId): array
    {
        $this->owner($actorId, $siteId);
        $data = Validator::make($data, ['mapping_version' => 'required|integer|min:1', 'catalog_hash' => 'required|string|size:64',
            'mode' => 'required|in:all,selected', 'selected_type_ids' => 'nullable|array|max:5000',
            'selected_type_ids.*' => 'required|string|max:100', 'category_map' => 'nullable|array|max:5000',
            'category_map.*' => 'nullable|integer|min:1'])->validate();

        return DB::transaction(function () use ($sourceId, $siteId, $data, $actorId): array {
            $source = DB::table('source_connectors')->where('id', $sourceId)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            $this->owner($actorId, $siteId);
            $binding = DB::table('source_site_bindings')->where(['connector_id' => $sourceId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            if ((int) $data['mapping_version'] !== (int) $binding->mapping_version) {
                throw new Conflict('SOURCE_MAPPING_VERSION_CONFLICT');
            }
            $catalog = $this->catalogFor($source, DB::table('source_type_catalogs')->where('connector_id', $sourceId)->first());
            if (! $catalog['supported'] || $catalog['stale'] || $catalog['hash'] !== $data['catalog_hash']) {
                throw new Conflict('SOURCE_TYPE_CATALOG_STALE');
            }
            $known = array_fill_keys(array_column($catalog['types'], 'id'), true);
            $selected = array_values(array_unique($data['selected_type_ids'] ?? []));
            $map = $data['category_map'] ?? [];
            foreach ([...$selected, ...array_keys($map)] as $type) {
                if (! isset($known[$type])) {
                    throw new \InvalidArgumentException('Thể loại không có trong catalog hiện tại: '.$type);
                }
            }
            $localIds = array_values(array_unique(array_map('intval', array_filter($map, fn ($id) => $id !== null && $id !== ''))));
            if (CatalogAvailability::filter(DB::table('categories')->where('site_id', $siteId)->whereIn('id', $localIds), 'categories')->count() !== count($localIds)) {
                throw new \InvalidArgumentException('Danh mục đích phải thuộc cùng website.');
            }
            $policy = ['mode' => $data['mode'], 'selected_type_ids' => $selected];
            $completion = app(MovieCategoryPresetService::class)->completeFromSourceTypes($siteId, self::acceptedTypeTree($catalog['types'], $policy), $actorId);
            $filled = 0;
            foreach ($completion['mappings'] as $type => $target) {
                if (self::accepts($policy, (string) $type) && empty($map[$type])) {
                    $map[$type] = (string) $target;
                    $filled++;
                }
            }
            if ($completion['created'] !== []) {
                DB::table('sites')->where('id', $siteId)->increment('config_epoch');
                app(PublicHtmlCache::class)->invalidateSite($siteId);
            }
            Access::audit('source.categories.completed', $actorId, $siteId, ['connector_id' => $sourceId,
                'created' => $completion['created'], 'suggested_count' => $filled, 'policy_saved' => false]);

            return $completion + ['filled' => $filled, 'form' => ['mode' => $data['mode'], 'selected_type_ids' => $selected,
                'category_map' => $map, 'mapping_version' => (int) $binding->mapping_version]];
        }, 3);
    }

    public static function assertCurrentPolicy(array $policy, object $source): void
    {
        // An already chosen policy survives catalog TTL expiry, but not an endpoint/config change.
        if (! $policy['legacy'] && $policy['catalog_config_hash'] !== Json::hash(Json::decode($source->config_json))) {
            throw new Conflict('BINDING_SELECTION_STALE');
        }
    }
}
