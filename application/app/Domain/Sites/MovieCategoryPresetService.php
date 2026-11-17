<?php

namespace App\Domain\Sites;

use App\Domain\Ingestion\Connectors\MacCmsConnector;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MovieCategoryPresetService
{
    public const VERSION = 'movie-tree-v1';

    public const EXISTING_EXTRA_ADOPTIONS = ['悬疑电影' => '电影'];

    /** @return array<string, array<string, string>> */
    public static function tree(): array
    {
        return [
            '电影' => ['action' => '动作片', 'comedy' => '喜剧片', 'romance' => '爱情片', 'scifi' => '科幻片', 'horror' => '恐怖片', 'theory' => '理论', 'japan-korea' => '日韩', 'drama' => '剧情片'],
            '电视剧' => ['hongkong' => '香港剧', 'western' => '欧美剧', 'korean' => '韩国剧', 'taiwan' => '台湾剧', 'japanese' => '日本剧', 'short' => '短剧', 'mainland' => '国产剧', 'overseas' => '海外剧'],
            '综艺' => ['mainland-variety' => '大陆综艺', 'hk-tw-variety' => '港台综艺', 'jk-variety' => '日韩综艺', 'western-variety' => '欧美综艺'],
            '动漫' => ['mainland-anime' => '国产动漫', 'western-anime' => '欧美动漫', 'japanese-anime' => '日本动漫', 'adult-anime' => '里番动漫'],
        ];
    }

    public function state(int $siteId, ?Collection $categories = null): array
    {
        $site = DB::table('sites')->find($siteId) ?? abort(404);

        return ['config_epoch' => (int) $site->config_epoch, 'category_fingerprint' => self::fingerprint($categories ?? DB::table('categories')->where('site_id', $siteId)->orderBy('id')->get())];
    }

    private static function fingerprint(Collection $categories): string
    {
        return Json::hash($categories->sortBy('id')->values()->map(fn ($category) => (array) $category)->all());
    }

    public function initialize(int $siteId, int $actorId): array
    {
        $state = $this->state($siteId);

        return $this->apply($siteId, $actorId, $state['config_epoch'], $state['category_fingerprint']);
    }

    public function apply(int $siteId, int $actorId, int $configEpoch, string $fingerprint): array
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Chỉ quản trị toàn hệ thống được tạo cây danh mục phim.');

        return DB::transaction(function () use ($siteId, $actorId, $configEpoch, $fingerprint) {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            abort_unless($site->content_type === 'movie', 422, 'Cây danh mục phim chỉ áp dụng cho website phim.');
            abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
            $categories = DB::table('categories')->where('site_id', $siteId)->orderBy('id')->lockForUpdate()->get();
            if ((int) $site->config_epoch !== $configEpoch || ! hash_equals(self::fingerprint($categories), $fingerprint)) {
                throw new Conflict('MOVIE_CATEGORY_PRESET_STALE', 'Danh mục hoặc cấu hình website đã đổi. Hãy tải lại trước khi tạo cây.');
            }
            $result = ['preset' => self::VERSION, 'site_id' => $siteId, 'created' => [], 'reused' => [], 'reparented' => [], 'existing_extra_adoptions' => [], 'conflicts' => []];
            foreach (self::tree() as $rootName => $children) {
                $root = $this->ensure($categories, $siteId, $rootName, null, 'root-'.array_search($rootName, array_keys(self::tree()), true), $result);
                foreach ($children as $key => $name) {
                    if ($root === null) {
                        $result['conflicts'][] = ['name' => $name, 'reason' => 'PARENT_UNAVAILABLE', 'parent_name' => $rootName];
                    } else {
                        $this->ensure($categories, $siteId, $name, $root, $key, $result);
                    }
                }
                foreach (self::EXISTING_EXTRA_ADOPTIONS as $extraName => $extraParent) {
                    if ($rootName === $extraParent && $root !== null && $categories->where('name', $extraName)->isNotEmpty()) {
                        if ($this->ensure($categories, $siteId, $extraName, $root, 'existing-suspense', $result) !== null) {
                            $result['existing_extra_adoptions'][$extraName] = $extraParent;
                        }
                    }
                }
            }
            if ($result['created'] !== [] || $result['reparented'] !== []) {
                DB::table('sites')->where('id', $siteId)->increment('config_epoch');
                Access::audit('category.movie_preset.applied', $actorId, $siteId, $result);
            }

            return $result + $this->state($siteId);
        }, 3);
    }

    private function ensure(Collection $categories, int $siteId, string $name, ?int $parentId, string $key, array &$result): ?int
    {
        $externalKey = self::VERSION.':'.$key;
        $byKey = $categories->firstWhere('external_key', $externalKey);
        $matches = $categories->where('name', $name);
        CatalogAvailability::assertWritable($byKey, 'CATEGORY_DELETED');
        foreach ($matches as $matched) {
            CatalogAvailability::assertWritable($matched, 'CATEGORY_DELETED');
        }
        if ($byKey && ($byKey->name !== $name || ($byKey->parent_id !== null && (int) $byKey->parent_id !== $parentId))) {
            $result['conflicts'][] = ['name' => $name, 'category_id' => (int) $byKey->id, 'reason' => 'PRESET_KEY_CHANGED'];

            return null;
        }
        if ($matches->count() > 1) {
            $result['conflicts'][] = ['name' => $name, 'category_ids' => $matches->pluck('id')->all(), 'reason' => 'AMBIGUOUS_NAME'];

            return null;
        }
        $existing = $matches->first();
        if ($existing) {
            if ($existing->parent_id !== null && (int) $existing->parent_id !== $parentId) {
                $result['conflicts'][] = ['name' => $name, 'category_id' => (int) $existing->id, 'reason' => 'OTHER_PARENT'];

                return null;
            }
            if ($parentId !== null && $existing->parent_id === null) {
                DB::table('categories')->where('id', $existing->id)->update(['parent_id' => $parentId, 'row_version' => DB::raw('row_version+1')]);
                $existing->parent_id = $parentId;
                $existing->row_version++;
                $result['reparented'][] = ['id' => (int) $existing->id, 'name' => $name, 'parent_id' => $parentId];
            } else {
                $result['reused'][] = ['id' => (int) $existing->id, 'name' => $name, 'parent_id' => $parentId];
            }

            return (int) $existing->id;
        }
        $id = DB::table('categories')->insertGetId(['site_id' => $siteId, 'name' => $name, 'parent_id' => $parentId, 'external_key' => $externalKey]);
        $categories->push((object) ['id' => $id, 'site_id' => $siteId, 'name' => $name, 'parent_id' => $parentId, 'external_key' => $externalKey, 'genre' => null, 'row_version' => 1]);
        $result['created'][] = ['id' => $id, 'name' => $name, 'parent_id' => $parentId];

        return $id;
    }

    /** @return array<string, string> */
    public static function sourceAliases(): array
    {
        return ['动作' => '动作片', '喜剧' => '喜剧片', '爱情' => '爱情片', '科幻' => '科幻片', '恐怖' => '恐怖片', '剧情' => '剧情片', '伦理片' => '理论', '理论片' => '理论', '伦理' => '理论', '港剧' => '香港剧', '美剧' => '欧美剧', '韩剧' => '韩国剧', '台剧' => '台湾剧', '日剧' => '日本剧', '短剧大全' => '短剧', '内地剧' => '国产剧', '大陆剧' => '国产剧', '国产综艺' => '大陆综艺', '内地综艺' => '大陆综艺',
            '记录片' => '纪录片', '纪录' => '纪录片', '记录' => '纪录片', '战争' => '战争片', '悬疑' => '悬疑片', '悬疑电影' => '悬疑片',
            '国产动画' => '国产动漫', '日本动画' => '日本动漫', '欧美动画' => '欧美动漫'];
    }

    public static function suggestedMappings(array $types, Collection $categories): array
    {
        $suggestions = [];
        $byName = $categories->filter(fn ($category): bool => ($category->deleted_at ?? null) === null)
            ->groupBy(fn ($category): string => self::canonicalName($category->name, ($category->parent_id ?? null) === null));
        foreach (self::orderedSourceTypes($types) as $type) {
            $parent = $type['parent_id'];
            if ($parent !== null && ! isset($suggestions[$parent])) {
                continue;
            }
            $name = self::canonicalName($type['name'], $parent === null);
            $matches = $byName->get($name, collect());
            if ($parent !== null) {
                $matches = $matches->filter(fn ($category): bool => (int) ($category->parent_id ?? 0) === (int) $suggestions[$parent]);
            } elseif (array_key_exists($name, self::tree())) {
                $matches = $matches->filter(fn ($category): bool => ($category->parent_id ?? null) === null);
            }
            if ($matches->count() === 1) {
                $suggestions[(string) $type['id']] = (string) $matches->first()->id;
            }
        }

        return $suggestions;
    }

    /** Keep absent mappings and their safe ancestors, without changing an explicit/manual branch. */
    public static function completionTypeTree(array $types, array $existingMap, Collection $categories): array
    {
        $ordered = self::orderedSourceTypes($types);
        $suggestions = self::suggestedMappings($types, $categories);
        $blocked = [];
        $needed = [];
        foreach ($ordered as $type) {
            $id = $type['id'];
            if ($type['parent_id'] !== null && isset($blocked[$type['parent_id']])) {
                $blocked[$id] = true;

                continue;
            }
            if (array_key_exists($id, $existingMap)) {
                $target = $existingMap[$id];
                if ((! is_int($target) && ! is_string($target)) || ! isset($suggestions[$id])
                    || (string) $target !== (string) $suggestions[$id]) {
                    $blocked[$id] = true;
                }
            } else {
                $needed[$id] = true;
            }
        }
        foreach (array_reverse($ordered) as $type) {
            if (isset($needed[$type['id']]) && $type['parent_id'] !== null) {
                $needed[$type['parent_id']] = true;
            }
        }

        return array_values(array_filter($ordered, fn ($type): bool => isset($needed[$type['id']])));
    }

    /**
     * Explicitly complete API descendants under an existing, unambiguous movie branch.
     * The caller owns source/policy locks and decides when to advance category epochs or audit.
     *
     * @return array{created:array<int,int>,mappings:array<string,int>,unmatched:array<int,string>}
     */
    public function completeFromSourceTypes(int $siteId, array $types, int $actorId): array
    {
        $ordered = self::orderedSourceTypes($types);

        return DB::transaction(function () use ($siteId, $types, $ordered, $actorId): array {
            Access::site($actorId, $siteId, true);
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            abort_unless($site->content_type === 'movie', 422, 'Chỉ website phim được bổ sung thể loại nguồn.');
            abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin')
                || DB::table('site_memberships')->where(['site_id' => $siteId, 'user_id' => $actorId, 'role' => 'owner'])->exists(), 403);
            Access::site($actorId, $siteId, true);
            $categories = DB::table('categories')->where('site_id', $siteId)->orderBy('id')->lockForUpdate()->get();
            $mappings = array_map('intval', self::suggestedMappings($types, $categories));
            $byId = $categories->keyBy('id')->all();
            $siblingsByName = [];
            $externalKeys = [];
            foreach ($categories as $category) {
                $siblingsByName[(int) ($category->parent_id ?? 0).':'.self::canonicalName($category->name, false)][] = $category;
                if ($category->external_key !== null) {
                    $externalKeys[$category->external_key] = true;
                }
            }
            $created = [];
            foreach ($ordered as $type) {
                $id = $type['id'];
                $parent = $type['parent_id'];
                if (isset($mappings[$id]) || $parent === null || ! isset($mappings[$parent])) {
                    continue;
                }
                $parentId = $mappings[$parent];
                if (! self::supportedBranch($byId, $parentId)) {
                    continue;
                }
                $name = self::canonicalName($type['name'], false);
                $siblingKey = $parentId.':'.$name;
                $siblings = $siblingsByName[$siblingKey] ?? [];
                if (count($siblings) > 1 || array_filter($siblings, fn ($category): bool => ($category->deleted_at ?? null) !== null)) {
                    continue;
                }
                if (count($siblings) === 1) {
                    $mappings[$id] = (int) $siblings[0]->id;

                    continue;
                }
                $externalKey = 'movie-source-tree-v1:'.hash('sha256', $parentId.':'.$name);
                if (isset($externalKeys[$externalKey])) {
                    continue;
                }
                $displayName = self::sourceAliases()[self::normalizedName($type['name'])] ?? $type['name'];
                $newId = DB::table('categories')->insertGetId(['site_id' => $siteId, 'parent_id' => $parentId, 'name' => $displayName, 'external_key' => $externalKey]);
                $category = (object) ['id' => $newId, 'site_id' => $siteId, 'parent_id' => $parentId,
                    'name' => $displayName, 'external_key' => $externalKey, 'genre' => null, 'row_version' => 1, 'deleted_at' => null];
                $byId[$newId] = $category;
                $siblingsByName[$siblingKey][] = $category;
                $externalKeys[$externalKey] = true;
                $mappings[$id] = $newId;
                $created[] = $newId;
            }
            $ids = array_values(array_unique(array_filter(array_map(fn ($type): ?string => is_array($type)
                ? MacCmsConnector::typeId($type['id'] ?? null) : null, $types))));
            usort($ids, fn ($a, $b): int => strnatcmp((string) $a, (string) $b));
            uksort($mappings, fn ($a, $b): int => strnatcmp((string) $a, (string) $b));

            return ['created' => $created, 'mappings' => $mappings, 'unmatched' => array_values(array_diff($ids, array_keys($mappings)))];
        }, 3);
    }

    private static function canonicalName(string $name, bool $root): string
    {
        $name = self::normalizedName($name);
        if ($root) {
            $name = ['电影片' => '电影', '连续剧' => '电视剧', '电视连续剧' => '电视剧', '剧集' => '电视剧',
                '综艺片' => '综艺', '动漫片' => '动漫', '动画片' => '动漫', '动画' => '动漫'][$name] ?? $name;
        }

        return self::sourceAliases()[$name] ?? $name;
    }

    /** Orphans, duplicate IDs, cycles and their descendants are intentionally omitted. */
    private static function orderedSourceTypes(array $types): array
    {
        if (count($types) > 5000) {
            throw new \InvalidArgumentException('Danh sách thể loại nguồn tối đa 5.000 mục.');
        }
        $indexed = [];
        $seen = [];
        foreach ($types as $type) {
            $id = is_array($type) ? MacCmsConnector::typeId($type['id'] ?? null) : null;
            if ($id === null) {
                continue;
            }
            if (isset($seen[$id])) {
                unset($indexed[$id]);

                continue;
            }
            $seen[$id] = true;
            $name = $type['name'] ?? null;
            if (! is_string($name) || trim($name) === '' || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 200 || str_contains($name, "\0")) {
                continue;
            }
            $parent = in_array($type['parent_id'] ?? null, [null, '', 0, '0'], true) ? null : MacCmsConnector::typeId($type['parent_id']);
            if (($type['parent_id'] ?? null) !== null && ! in_array($type['parent_id'], ['', 0, '0'], true) && $parent === null) {
                continue;
            }
            $indexed[$id] = ['id' => $id, 'name' => trim($name), 'parent_id' => $parent];
        }
        uksort($indexed, fn ($a, $b): int => strnatcmp((string) $a, (string) $b));
        $children = [];
        $queue = [];
        foreach ($indexed as $id => $type) {
            if ($type['parent_id'] === null) {
                $queue[] = $id;
            } elseif (isset($indexed[$type['parent_id']])) {
                $children[$type['parent_id']][] = $id;
            }
        }
        $ordered = [];
        for ($offset = 0; $offset < count($queue); $offset++) {
            $id = $queue[$offset];
            $ordered[] = $indexed[$id];
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $ordered;
    }

    private static function supportedBranch(array $categories, int $id): bool
    {
        $seen = [];
        while ($category = $categories[$id] ?? null) {
            if (isset($seen[$id]) || ($category->deleted_at ?? null) !== null) {
                return false;
            }
            $seen[$id] = true;
            if ($category->parent_id === null) {
                return array_key_exists(self::canonicalName($category->name, true), self::tree());
            }
            $id = (int) $category->parent_id;
        }

        return false;
    }

    private static function normalizedName(string $name): string
    {
        return mb_strtolower(\Normalizer::normalize(trim($name), \Normalizer::FORM_C), 'UTF-8');
    }
}
