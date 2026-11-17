<?php

namespace App\Domain\Sites;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsitePresetService
{
    public function listing(int $actorId): Collection
    {
        $this->authorize($actorId);

        return CatalogAvailability::filter(DB::table('website_presets as presets'), 'website_presets', 'presets')
            ->leftJoin('website_preset_versions as versions', 'versions.id', '=', 'presets.current_version_id')
            ->orderBy('presets.id')->get(['presets.*', 'versions.definition_json'])->map(function (object $row): object {
                $definition = Json::decode($row->definition_json);
                $row->summary = ['theme_id' => $definition['theme_id'] ?? null, 'template_id' => $definition['template_id'] ?? null,
                    'locale' => $definition['locale'] ?? 'zh-CN', 'routes' => $definition['presentation']['routes'] ?? [],
                    'suffixes' => $definition['presentation']['suffixes'] ?? [], 'movie_key' => $definition['presentation']['movie_key'] ?? 'source_id',
                    'category_key' => $definition['presentation']['category_key'] ?? 'id',
                    'pool_count' => count($definition['pool_ids'] ?? []), 'source_count' => count($definition['source_ids'] ?? []),
                    'delivery_mode' => $definition['delivery_mode'] ?? 'on_demand', 'primary_mode' => $definition['primary_mode'] ?? 'weighted',
                    'initial_movie_count' => $definition['initial_movie_count'] ?? 24,
                    'fanmulu_enabled' => (bool) ($definition['fanmulu']['enabled'] ?? false)];
                unset($row->definition_json);

                return $row;
            });
    }

    /** @return array{preset: object, version: object, definition: array} */
    public function get(int $id, int $actorId): array
    {
        $this->authorize($actorId);
        $preset = CatalogAvailability::filter(DB::table('website_presets'), 'website_presets')->find($id) ?? abort(404);
        $version = DB::table('website_preset_versions')->where(['id' => $preset->current_version_id, 'preset_id' => $id])->first() ?? abort(404);

        return ['preset' => $preset, 'version' => $version, 'definition' => Json::decode($version->definition_json)];
    }

    public function save(?int $id, array $data, int $actorId): object
    {
        $this->authorize($actorId);
        $fields = Validator::make($data, ['name' => 'required|string|max:160', 'description' => 'nullable|string|max:1000',
            'row_version' => ($id ? 'required' : 'nullable').'|integer|min:1', 'definition' => 'required|array'])->validate();
        $name = trim($fields['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Cần nhập tên bộ cấu hình.']);
        }

        return DB::transaction(function () use ($id, $fields, $name, $actorId): object {
            $this->lock($actorId);
            $current = $id ? DB::table('website_presets')->where('id', $id)->lockForUpdate()->first() ?? abort(404) : null;
            CatalogAvailability::assertWritable($current, 'WEBSITE_PRESET_DELETED');
            if ($current) {
                $this->assertVersion($current, (int) $fields['row_version']);
                if ($current->status !== 'active') {
                    throw new Conflict('WEBSITE_PRESET_ARCHIVED', 'Khôi phục bộ cấu hình trước khi chỉnh sửa.');
                }
            }
            if (DB::table('website_presets')->where('name', $name)->when($id !== null, fn ($query) => $query->where('id', '<>', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Tên bộ cấu hình đã được dùng.']);
            }
            $previous = $current ? Json::decode(DB::table('website_preset_versions')->where('id', $current->current_version_id)->value('definition_json')) : [];
            $definition = $this->normalize($fields['definition'], $previous);
            $presetId = $id ?? DB::table('website_presets')->insertGetId(['name' => $name, 'created_by' => $actorId]);
            $versionNumber = (int) (DB::table('website_preset_versions')->where('preset_id', $presetId)->max('version_no') ?? 0) + 1;
            $versionId = DB::table('website_preset_versions')->insertGetId(['preset_id' => $presetId, 'version_no' => $versionNumber,
                'definition_json' => Json::encode($definition), 'hash' => Json::hash($definition), 'created_by' => $actorId]);
            DB::table('website_presets')->where('id', $presetId)->update(['name' => $name, 'description' => trim($fields['description'] ?? ''),
                'current_version_id' => $versionId, 'row_version' => $current ? (int) $current->row_version + 1 : 1, 'updated_at' => now()]);
            Access::audit('website-preset.saved', $actorId, null, ['preset_id' => $presetId, 'version_id' => $versionId]);

            return DB::table('website_presets')->find($presetId);
        }, 3);
    }

    public function archive(int $id, int $rowVersion, int $actorId): object
    {
        return $this->lifecycle($id, $rowVersion, $actorId, false);
    }

    public function restore(int $id, int $rowVersion, int $actorId): object
    {
        return $this->lifecycle($id, $rowVersion, $actorId, true);
    }

    /** Create ten reusable configurations, never changing an existing configuration or website. */
    public function seedDefaults(int $actorId, ?int $baseSiteId = null): array
    {
        $this->authorize($actorId);

        return DB::transaction(function () use ($actorId, $baseSiteId): array {
            $this->lock($actorId);
            $existing = CatalogAvailability::filter(DB::table('website_presets')->whereIn('default_key', $this->defaultKeys()), 'website_presets')->get()->keyBy('default_key');
            if ($existing->count() === 10) {
                return array_map(fn (string $key): int => (int) $existing[$key]->id, $this->defaultKeys());
            }
            $base = DB::table('sites')->where(['status' => 'active', 'content_type' => 'movie'])
                ->when($baseSiteId !== null, fn ($query) => $query->where('id', $baseSiteId))
                ->orderByRaw('CASE WHEN id = 6 THEN 0 ELSE 1 END')->orderBy('id')->first();
            if (! $base) {
                throw ValidationException::withMessages(['base_site_id' => 'Cần có website phim đang hoạt động để lấy cấu hình nguồn và kho keyword.']);
            }
            $themes = CatalogAvailability::filter(DB::table('movie_themes'), 'movie_themes')->where('status', 'active')->whereNotNull('current_version_id')
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$base->theme_id ?? 0])->orderBy('id')->pluck('id')->all();
            $templates = CatalogAvailability::filter(DB::table('templates as templates'), 'templates', 'templates')->join('template_versions as versions', 'versions.id', '=', 'templates.current_version_id')
                ->where('templates.status', 'active')->orderByRaw('CASE WHEN templates.id = ? THEN 0 ELSE 1 END', [$base->template_id ?? 0])
                ->orderBy('templates.id')->get(['templates.id', 'versions.definition_json']);
            $template = $templates->first(fn (object $candidate): bool => $this->hasMetaKeywords(Json::decode($candidate->definition_json)));
            $poolIds = DB::table('keyword_bindings as bindings')->join('keyword_pools as pools', 'pools.id', '=', 'bindings.pool_id')
                ->where(['bindings.site_id' => $base->id, 'bindings.status' => 'active', 'pools.status' => 'active', 'pools.scope' => 'shared'])
                ->whereNull('pools.deleted_at')->orderBy('pools.id')->distinct()->pluck('pools.id')->map(fn ($id): int => (int) $id)->all();
            $sourceIds = CatalogAvailability::filter(DB::table('source_site_bindings as bindings'), 'source_site_bindings', 'bindings')->join('source_connectors as sources', 'sources.id', '=', 'bindings.connector_id')
                ->where(['bindings.site_id' => $base->id, 'bindings.status' => 'active', 'sources.status' => 'active'])->whereNull('sources.deleted_at')
                ->orderBy('sources.id')->pluck('sources.id')->map(fn ($id): int => (int) $id)->all();
            if ($themes === [] || ! $template || $poolIds === [] || $sourceIds === []) {
                throw ValidationException::withMessages(['base_site_id' => 'Website mẫu cần giao diện, bộ Template SEO có Meta keywords, kho keyword và nguồn phim đang hoạt động.']);
            }
            $profile = SitePresentationService::profile($base);
            $profile['configured'] = true;
            $profile['fanmulu'] = FanmuluSettings::defaults();
            $suffixes = DB::table('url_suffixes')->whereNull('deleted_at')->whereIn('suffix', ['.html', '.asp'])->pluck('suffix')->all();
            $suffixes = array_values(array_unique(['', ...$suffixes]));
            $routes = [
                ['/', '/type', '/movie', '/play', '/search'], ['/', '/category', '/video', '/view', '/search'],
                ['/', '/list', '/vod', '/watch', '/find'], ['/', '/class', '/film', '/player', '/search'],
                ['/', '/channel', '/detail', '/play', '/so'], ['/', '/sort', '/movie', '/watch', '/find'],
                ['/', '/category', '/detail', '/view', '/so'], ['/', '/type', '/video', '/player', '/search'],
                ['/', '/list', '/film', '/play', '/find'], ['/', '/channel', '/vod', '/watch', '/so'],
            ];
            $ids = [];
            foreach ($this->defaultKeys() as $index => $key) {
                if (isset($existing[$key])) {
                    $ids[] = (int) $existing[$key]->id;

                    continue;
                }
                $presentation = $profile;
                $presentation['routes'] = array_combine(SitePresentationService::TYPES, $routes[$index]);
                $presentation['movie_key'] = $index % 3 === 0 ? 'source_id' : 'pinyin_compact_id';
                $presentation['category_key'] = $index % 3 === 0 ? 'id' : 'pinyin_compact_id';
                $presentation['suffixes'] = array_fill_keys(SitePresentationService::TYPES, $suffixes[$index % count($suffixes)]);
                $presentation['suffixes']['home'] = '';
                $definition = ['schema_version' => 1, 'locale' => $base->locale ?: 'zh-CN', 'theme_id' => (int) $themes[$index % count($themes)],
                    'template_id' => (int) $template->id, 'presentation' => $presentation, 'delivery_mode' => 'on_demand', 'pool_ids' => $poolIds,
                    'primary_mode' => ['weighted', 'random-once', 'sequential'][$index % 3], 'secondary_count' => $index % 3,
                    'anchor_count' => 0, 'keyword_cap' => 500000, 'source_ids' => $sourceIds, 'source_reference_site_id' => (int) $base->id,
                    'initial_movie_count' => 24];
                $preset = $this->save(null, ['name' => sprintf('Bộ cấu hình phim %02d', $index + 1),
                    'description' => 'Bộ mẫu sẵn dùng: giao diện, URL, SEO, keyword và nguồn phim. Tạo khi mở link lần đầu.', 'definition' => $definition], $actorId);
                DB::table('website_presets')->where('id', $preset->id)->update(['default_key' => $key]);
                $ids[] = (int) $preset->id;
            }

            return $ids;
        }, 3);
    }

    public function normalize(array $definition, array $previous = []): array
    {
        $keys = ['schema_version', 'locale', 'theme_id', 'template_id', 'presentation', 'delivery_mode', 'pool_ids', 'primary_mode',
            'secondary_count', 'anchor_count', 'keyword_cap', 'source_ids', 'source_reference_site_id', 'initial_movie_count', 'fanmulu'];
        if (array_diff(array_keys($definition), $keys)) {
            throw ValidationException::withMessages(['definition' => 'Bộ cấu hình chứa trường không hỗ trợ.']);
        }
        if (! array_key_exists('fanmulu', $definition) && array_key_exists('fanmulu', $previous)) {
            $definition['fanmulu'] = $previous['fanmulu'];
        }
        $definition += ['schema_version' => 1, 'locale' => 'zh-CN', 'theme_id' => null, 'template_id' => null, 'presentation' => SitePresentationService::defaults(),
            'delivery_mode' => 'on_demand', 'primary_mode' => 'weighted', 'secondary_count' => 0, 'anchor_count' => 0, 'keyword_cap' => null,
            'source_reference_site_id' => null, 'initial_movie_count' => 24];
        $values = Validator::make($definition, ['schema_version' => 'required|integer|in:1', 'locale' => ['required', 'string', 'max:35', 'regex:/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D'],
            'theme_id' => 'nullable|integer|min:1', 'template_id' => 'nullable|integer|min:1', 'presentation' => 'required|array',
            'delivery_mode' => 'required|in:on_demand,prepublish', 'pool_ids' => 'required|array|min:1', 'pool_ids.*' => 'required|integer|min:1|distinct',
            'primary_mode' => 'required|in:weighted,random-once,sequential', 'secondary_count' => 'required|integer|min:0|max:5', 'anchor_count' => 'required|integer|min:0|max:5',
            'keyword_cap' => 'nullable|integer|min:1|max:2147483647', 'source_ids' => 'required|array|min:1', 'source_ids.*' => 'required|integer|min:1|distinct',
            'source_reference_site_id' => 'nullable|integer|min:1', 'initial_movie_count' => 'required|integer|min:0|max:100',
            'fanmulu' => 'sometimes|array:enabled,prefix,prefixes,suffix,links_per_page,max_pages,url_rule,display_name_pool_ids',
            'fanmulu.display_name_pool_ids' => 'sometimes|array|max:1000', 'fanmulu.display_name_pool_ids.*' => 'required|integer|min:1|distinct',
            'fanmulu.prefixes' => 'sometimes|array|max:20', 'fanmulu.prefixes.*' => 'string|max:120'])->validate();
        foreach (['pool_ids', 'source_ids'] as $field) {
            if (! array_is_list($values[$field])) {
                throw ValidationException::withMessages([$field => 'Cần danh sách ID liên tiếp.']);
            }
            $values[$field] = array_map('intval', $values[$field]);
            sort($values[$field]);
        }
        try {
            $values['presentation'] = SitePresentationService::normalize($values['presentation']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['presentation' => $error->getMessage()]);
        }
        foreach ($values['presentation']['suffixes'] ?? [] as $type => $suffix) {
            app(UrlSuffixCatalog::class)->assertSelectable($suffix, $previous['presentation']['suffixes'][$type] ?? '', 'presentation.suffixes.'.$type);
        }
        if (array_key_exists('fanmulu', $values)) {
            $values['fanmulu'] = FanmuluPresetSettings::normalize($values['fanmulu'], $values['presentation'], $previous['fanmulu'] ?? []);
            try {
                FanmuluSettings::assertDisplayNamePools($values['fanmulu']['display_name_pool_ids'] ?? [], null, $values['locale'], true);
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['fanmulu.display_name_pool_ids' => $error->getMessage()]);
            }
        }
        foreach (['theme_id' => 'movie_themes', 'template_id' => 'templates'] as $field => $table) {
            $values[$field] = isset($values[$field]) ? (int) $values[$field] : null;
            if ($values[$field] !== null && ! CatalogAvailability::filter(DB::table($table), $table)->where(['id' => $values[$field], 'status' => 'active'])->whereNotNull('current_version_id')->sharedLock()->first()) {
                throw ValidationException::withMessages([$field => 'Cần chọn mục đang hoạt động và có phiên bản.']);
            }
        }
        if (DB::table('keyword_pools')->whereIn('id', $values['pool_ids'])->where(['status' => 'active', 'scope' => 'shared'])->whereNull('deleted_at')->sharedLock()->get(['id'])->count() !== count($values['pool_ids'])) {
            throw ValidationException::withMessages(['pool_ids' => 'Chỉ dùng kho keyword chung đang hoạt động.']);
        }
        if (! DB::table('keywords')->whereIn('pool_id', $values['pool_ids'])->where(['status' => 'active', 'role' => 'primary', 'locale' => $values['locale']])->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['pool_ids' => 'Kho cần có ít nhất một keyword chính đang hoạt động đúng ngôn ngữ.']);
        }
        if (DB::table('source_connectors')->whereIn('id', $values['source_ids'])->where('status', 'active')->whereNull('deleted_at')->sharedLock()->get(['id'])->count() !== count($values['source_ids'])) {
            throw ValidationException::withMessages(['source_ids' => 'Chỉ dùng nguồn nội dung đang hoạt động.']);
        }
        $values['source_reference_site_id'] = isset($values['source_reference_site_id']) ? (int) $values['source_reference_site_id'] : null;
        if ($values['source_reference_site_id'] !== null) {
            if (! DB::table('sites')->where(['id' => $values['source_reference_site_id'], 'content_type' => 'movie', 'status' => 'active'])->sharedLock()->first()) {
                throw ValidationException::withMessages(['source_reference_site_id' => 'Website tham chiếu cần là website phim đang hoạt động.']);
            }
            if (CatalogAvailability::filter(DB::table('source_site_bindings'), 'source_site_bindings')->where('site_id', $values['source_reference_site_id'])->where('status', 'active')->whereIn('connector_id', $values['source_ids'])->sharedLock()->get(['id'])->count() !== count($values['source_ids'])) {
                throw ValidationException::withMessages(['source_ids' => 'Nguồn chưa được gắn hoạt động vào website tham chiếu.']);
            }
        }
        foreach (['schema_version', 'secondary_count', 'anchor_count', 'initial_movie_count'] as $field) {
            $values[$field] = (int) $values[$field];
        }
        $values['keyword_cap'] = isset($values['keyword_cap']) ? (int) $values['keyword_cap'] : null;

        return $values;
    }

    private function hasMetaKeywords(array $definition): bool
    {
        if (! SeoTemplateBundle::isBundle($definition)) {
            return false;
        }
        foreach (SitePresentationService::TYPES as $type) {
            $slot = $definition['page_templates'][$type]['slots']['meta_keywords'] ?? false;
            $template = is_string($slot) ? $slot : (is_array($slot) && ($slot['enabled'] ?? true) ? ($slot['template'] ?? '') : '');
            if (! is_string($template) || trim($template) === '') {
                return false;
            }
        }

        return true;
    }

    private function defaultKeys(): array
    {
        return array_map(fn (int $number): string => sprintf('movie-default-%02d', $number), range(1, 10));
    }

    private function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Chỉ quản trị toàn hệ thống được quản lý bộ cấu hình website.');
    }

    private function lock(int $actorId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['website-preset-catalog']);
        $this->authorize($actorId);
    }

    private function assertVersion(object $row, int $rowVersion): void
    {
        if ((int) $row->row_version !== $rowVersion) {
            throw new Conflict('WEBSITE_PRESET_VERSION_CONFLICT', 'Bộ cấu hình đã thay đổi. Hãy tải lại trước khi lưu.');
        }
    }

    private function lifecycle(int $id, int $rowVersion, int $actorId, bool $restore): object
    {
        $this->authorize($actorId);

        return DB::transaction(function () use ($id, $rowVersion, $actorId, $restore): object {
            $this->lock($actorId);
            $row = DB::table('website_presets')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            CatalogAvailability::assertWritable($row, 'WEBSITE_PRESET_DELETED');
            $this->assertVersion($row, $rowVersion);
            if (($row->status === 'active') === $restore) {
                throw new Conflict('WEBSITE_PRESET_STATE_CONFLICT');
            }
            if ($restore) {
                $this->normalize(Json::decode(DB::table('website_preset_versions')->where('id', $row->current_version_id)->value('definition_json')));
            }
            DB::table('website_presets')->where('id', $id)->update(['status' => $restore ? 'active' : 'archived', 'row_version' => $rowVersion + 1, 'updated_at' => now()]);
            Access::audit($restore ? 'website-preset.restored' : 'website-preset.archived', $actorId, null, ['preset_id' => $id]);

            return DB::table('website_presets')->find($id);
        }, 3);
    }
}
