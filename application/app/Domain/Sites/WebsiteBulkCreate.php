<?php

namespace App\Domain\Sites;

use App\Domain\Rules\MovieSeoTemplateLibrary;
use App\Domain\Rules\SeoTemplateBundle;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Jobs\Sites\RunWebsiteCreation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class WebsiteBulkCreate
{
    /** Only the installed movie library is eligible; archived or invalid bundles are never chosen. */
    public function eligibleSeoTemplates(): Collection
    {
        if (! Schema::hasTable('movie_seo_template_catalog')) {
            return collect();
        }

        return DB::table('movie_seo_template_catalog as catalog')
            ->join('templates as t', 't.id', '=', 'catalog.template_id')
            ->join('template_versions as v', function ($join): void {
                $join->on('v.id', '=', 't.current_version_id')->on('v.template_id', '=', 't.id');
            })
            ->whereIn('catalog.stable_key', array_keys(app(MovieSeoTemplateLibrary::class)->catalog()))
            ->where('t.status', 'active')->where('v.schema_version', 2)->orderBy('t.id')
            ->get(['t.id', 't.name', 't.current_version_id', 'v.version_no', 'v.hash', 'v.definition_json', 'catalog.stable_key'])
            ->filter(function ($template): bool {
                try {
                    $definition = Json::decode($template->definition_json);
                    if (! SeoTemplateBundle::isBundle($definition)) {
                        return false;
                    }
                    SeoTemplateBundle::validate($definition);

                    return true;
                } catch (\InvalidArgumentException|\JsonException|\TypeError) {
                    return false;
                }
            })->map(function ($template): object {
                unset($template->definition_json);

                return $template;
            })->values();
    }

    public function parseInput(string $text, string $defaultScheme = 'https'): array
    {
        if (! in_array($defaultScheme, ['http', 'https'], true) || strlen($text) > 2097152 || ! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['domains' => 'Danh sách cần là UTF-8, tối đa 2 MB, dùng HTTP hoặc HTTPS.']);
        }
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        $entries = $seen = $errors = [];
        $adminHosts = array_map('trim', explode(',', (string) config('tram.admin_hosts', env('ADMIN_HOSTS', 'localhost,127.0.0.1,admin.localhost'))));
        foreach (preg_split('/\R/u', $text) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv(trim($line), ',', '"', '');
            $url = trim($cells[0] ?? '');
            $url = str_contains($url, '://') ? $url : $defaultScheme.'://'.$url;
            try {
                $parts = parse_url($url);
            } catch (\ValueError) {
                $parts = false;
            }
            $host = $parts ? (idn_to_ascii(strtolower(rtrim($parts['host'] ?? '', '.')), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '') : '';
            $name = trim($cells[1] ?? '') ?: $host;
            if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || count($cells) > 2 || strlen($host) > 253
                || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)
                || filter_var($host, FILTER_VALIDATE_IP) || in_array($host, $adminHosts, true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ! in_array($parts['path'] ?? '', ['', '/'], true) || mb_strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
                $errors[] = 'Dòng '.($index + 1).': domain hoặc URL không hợp lệ; chỉ nhập URL gốc, có thể thêm tên sau dấu phẩy.';

                continue;
            }
            if (isset($seen[$host])) {
                $errors[] = 'Dòng '.($index + 1).': domain trùng dòng '.$seen[$host].'.';

                continue;
            }
            $seen[$host] = $index + 1;
            $entries[] = ['domain' => $host, 'base_url' => $parts['scheme'].'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : ''), 'name' => $name];
            if (count($entries) > 10000) {
                throw ValidationException::withMessages(['domains' => 'Mỗi lượt nhận tối đa 10.000 domain. Có thể gửi nhiều lượt tiếp theo.']);
            }
        }
        if ($errors !== [] || $entries === []) {
            throw ValidationException::withMessages(['domains' => $errors === [] ? 'Nhập ít nhất một domain.' : implode(' ', array_slice($errors, 0, 20))]);
        }

        return $entries;
    }

    public function submit(array $lines, array $presetIds, int $actorId, string $requestKey, array $options = []): object
    {
        $this->authorize($actorId);
        if (! array_is_list($presetIds) || collect($presetIds)->contains(fn ($id) => ! is_int($id) || $id < 1)) {
            throw ValidationException::withMessages(['preset_ids' => 'Cần danh sách ID bộ cấu hình hợp lệ.']);
        }
        if (trim($requestKey) === '' || strlen($requestKey) > 160 || ! array_is_list($lines) || count($lines) > 10000 || $lines === []
            || $presetIds === [] || count($presetIds) > 100 || count(array_unique($presetIds)) !== count($presetIds)) {
            throw ValidationException::withMessages(['domains' => 'Danh sách domain, bộ cấu hình hoặc mã yêu cầu không hợp lệ.']);
        }
        $normalized = [];
        foreach ($lines as $line) {
            if (! is_array($line) || array_diff(array_keys($line), ['domain', 'base_url', 'name']) || ! isset($line['base_url'],$line['domain'])) {
                throw ValidationException::withMessages(['domains' => 'Dòng domain không đúng cấu trúc.']);
            }
            if (! is_string($line['domain']) || ! is_string($line['base_url']) || (isset($line['name']) && ! is_string($line['name']))) {
                throw ValidationException::withMessages(['domains' => 'Domain, URL và tên hiển thị cần là chuỗi văn bản.']);
            }
            $csv = '"'.str_replace('"', '""', $line['base_url']).'","'.str_replace('"', '""', $line['name'] ?? '').'"';
            $entry = $this->parseInput($csv)[0];
            if ($entry['domain'] !== $line['domain']) {
                throw ValidationException::withMessages(['domains' => 'Domain phải trùng hostname trong URL.']);
            }
            $normalized[] = $entry;
        }
        if (count(array_unique(array_column($normalized, 'domain'))) !== count($normalized)) {
            throw ValidationException::withMessages(['domains' => 'Danh sách có domain trùng nhau.']);
        }
        // Existing callers and operations keep the preset's SEO; the admin form explicitly enables random SEO.
        $options = array_replace(['distribution' => 'random', 'random_theme' => true, 'random_seo' => false, 'fanmulu_mode' => 'preset'], $options);
        if (array_diff(array_keys($options), ['distribution', 'random_theme', 'random_seo', 'fanmulu_mode', 'random_attributes']) || ! in_array($options['distribution'], ['random', 'round_robin'], true)
            || ! is_bool($options['random_theme']) || ! is_bool($options['random_seo']) || ! in_array($options['fanmulu_mode'], ['preset', 'enabled', 'disabled'], true)) {
            throw ValidationException::withMessages(['distribution' => 'Cách chọn cấu hình hoặc giao diện không hợp lệ.']);
        }
        $randomAttributes = WebsiteRandomAttributes::normalize($options['random_attributes'] ?? []);
        if ($randomAttributes === []) {
            unset($options['random_attributes']);
        } else {
            $options['random_attributes'] = $randomAttributes;
        }
        $presetIds = array_map('intval', $presetIds);
        sort($presetIds);
        // Keep the legacy request hash when SEO is inherited, including re-submissions from an older form.
        $hashOptions = $options['random_seo'] ? $options : array_diff_key($options, ['random_seo' => true]);
        if ($options['fanmulu_mode'] === 'preset') {
            unset($hashOptions['fanmulu_mode']);
        }
        $hash = Json::hash([$normalized, $presetIds, $hashOptions]);
        $operation = DB::transaction(function () use ($normalized, $presetIds, $actorId, $requestKey, $options, $hash, $randomAttributes): object {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['website-create-request:'.$actorId.':'.$requestKey]);
            $this->authorize($actorId);
            $existing = DB::table('website_create_operations')->where(['created_by' => $actorId, 'request_key' => $requestKey])->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new Conflict('WEBSITE_CREATE_REQUEST_CHANGED', 'Mã yêu cầu đã dùng cho danh sách khác. Tải lại biểu mẫu để tạo lượt mới.');
                }

                return $existing;
            }
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $manifest = [];
            $sourceCandidates = [];
            $sourceManifest = [];
            foreach ($presetIds as $presetId) {
                $saved = app(WebsitePresetService::class)->get($presetId, $actorId);
                if ($saved['preset']->status !== 'active') {
                    throw new Conflict('WEBSITE_PRESET_ARCHIVED');
                }
                if ($randomAttributes !== []) {
                    WebsiteRandomAttributes::assertCompatible($randomAttributes, $saved['definition']);
                }
                $candidates = $this->sourceCandidates($saved['definition']);
                if ($candidates->isEmpty()) {
                    throw ValidationException::withMessages(['preset_ids' => 'Bộ cấu hình '.$saved['preset']->name.' không còn nguồn phim đang hoạt động và liên kết hợp lệ với website tham chiếu.']);
                }
                $sourceCandidates[$presetId] = $candidates->pluck('id')->map(fn ($sourceId): int => (int) $sourceId)->all();
                foreach ($candidates as $candidate) {
                    $sourceManifest[$candidate->id] = ['id' => (int) $candidate->id, 'name' => $candidate->name, 'kind' => $candidate->kind];
                }
                $manifest[] = ['id' => $presetId, 'version_id' => (int) $saved['version']->id, 'name' => $saved['preset']->name, 'definition' => $saved['definition']];
            }
            $themes = DB::table('movie_themes')->where('status', 'active')->whereNotNull('current_version_id')->orderBy('id')->get(['id', 'name', 'current_version_id']);
            if ($themes->isEmpty()) {
                throw ValidationException::withMessages(['preset_ids' => 'Chưa có giao diện phim đang hoạt động.']);
            }
            $seoTemplates = $options['random_seo'] ? $this->eligibleSeoTemplates() : collect();
            if ($options['random_seo'] && $seoTemplates->isEmpty()) {
                throw ValidationException::withMessages(['random_seo' => 'Chưa có bộ SEO phim mới hợp lệ đang hoạt động. Mở Template SEO để thêm hoặc khôi phục bộ mẫu trước khi tạo website.']);
            }
            $templateIds = $options['random_seo'] ? $seoTemplates->pluck('id')->all() : array_column(array_column($manifest, 'definition'), 'template_id');
            $templateManifest = DB::table('templates as t')->join('template_versions as v', function ($join): void {
                $join->on('v.id', '=', 't.current_version_id')->on('v.template_id', '=', 't.id');
            })->whereIn('t.id', $templateIds)->orderBy('t.id')
                ->get(['t.id', 't.name', 't.current_version_id', 'v.version_no', 'v.hash'])->keyBy('id');
            $offset = 0;
            if ($options['distribution'] === 'round_robin') {
                $rotation = Json::hash($presetIds);
                DB::table('website_preset_rotations')->insertOrIgnore(['key' => $rotation]);
                $offset = (int) DB::table('website_preset_rotations')->where('key', $rotation)->lockForUpdate()->value('next_position');
                DB::table('website_preset_rotations')->where('key', $rotation)->update(['next_position' => $offset + count($normalized)]);
            }
            $id = (string) Str::uuid();
            $frozenOptions = $options + ['source_selection' => 'random_once', 'source_manifest' => $sourceManifest,
                'theme_versions' => $themes->pluck('current_version_id', 'id')->all(),
                'template_versions' => $templateManifest->pluck('current_version_id', 'id')->all(),
                'seo_templates' => $templateManifest->map(fn ($template): array => ['id' => (int) $template->id, 'name' => $template->name,
                    'version_id' => (int) $template->current_version_id, 'version_no' => (int) $template->version_no, 'hash' => $template->hash])->all()];
            DB::table('website_create_operations')->insert(['id' => $id, 'created_by' => $actorId, 'request_key' => $requestKey, 'request_hash' => $hash,
                'total' => count($normalized), 'preset_manifest_json' => Json::encode($manifest), 'options_json' => Json::encode($frozenOptions)]);
            $items = [];
            foreach ($normalized as $position => $entry) {
                $preset = $manifest[$options['distribution'] === 'random' ? random_int(0, count($manifest) - 1) : ($offset + $position) % count($manifest)];
                $definition = $preset['definition'];
                $candidates = $sourceCandidates[$preset['id']];
                // Freeze one source now; workers and retries consume this exact saved choice.
                $definition['source_ids'] = [$candidates[random_int(0, count($candidates) - 1)]];
                $theme = $options['random_theme'] ? $themes[random_int(0, $themes->count() - 1)] : $themes->firstWhere('id', $definition['theme_id']);
                if (! $theme) {
                    throw new Conflict('PRESET_THEME_UNAVAILABLE');
                }
                $definition['theme_id'] = (int) $theme->id;
                if ($options['random_seo']) {
                    $definition['template_id'] = (int) $seoTemplates[random_int(0, $seoTemplates->count() - 1)]->id;
                }
                if ($options['fanmulu_mode'] !== 'preset') {
                    $definition['fanmulu'] = array_replace(FanmuluPresetSettings::defaults(), $definition['fanmulu'] ?? [],
                        ['enabled' => $options['fanmulu_mode'] === 'enabled']);
                }
                if ($randomAttributes !== []) {
                    $resolved = WebsiteRandomAttributes::apply($entry, $definition, $randomAttributes);
                    $entry = $resolved['entry'];
                    $definition = $resolved['definition'];
                }
                if ($options['fanmulu_mode'] !== 'preset') {
                    $definition['fanmulu'] = FanmuluPresetSettings::normalize($definition['fanmulu'], $definition['presentation'], $definition['fanmulu']);
                }
                $items[] = ['operation_id' => $id, 'position' => $position + 1, ...$entry, 'preset_id' => $preset['id'], 'preset_version_id' => $preset['version_id'],
                    'preset_name' => $preset['name'], 'theme_id' => $theme->id, 'theme_name' => $theme->name, 'definition_json' => Json::encode($definition)];
                if (count($items) === 250) {
                    DB::table('website_create_items')->insert($items);
                    $items = [];
                }
            }
            if ($items !== []) {
                DB::table('website_create_items')->insert($items);
            }

            return DB::table('website_create_operations')->find($id);
        }, 3);
        DB::afterCommit(fn () => $this->dispatch($operation->id));

        return $operation;
    }

    /** One durable transaction per domain, including the first live home page. */
    public function process(string $id): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $lock = 'website-create-operation:'.$id;
        if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$lock])->acquired) {
            return false;
        }
        try {
            return DB::transaction(function () use ($id): bool {
                DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
                $operation = DB::table('website_create_operations')->where('id', $id)->lockForUpdate()->first();
                if (! $operation) {
                    return false;
                }
                $item = DB::table('website_create_items')->where(['operation_id' => $id, 'status' => 'queued'])->orderBy('position')->lockForUpdate()->first();
                if (! $item) {
                    return false;
                }
                try {
                    $site = DB::transaction(function () use ($item, $operation, $id): object {
                        DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
                        $definition = Json::decode($item->definition_json);
                        $frozen = Json::decode($operation->options_json);
                        foreach (['theme_id' => ['movie_themes', 'theme_versions'], 'template_id' => ['templates', 'template_versions']] as $field => [$table, $key]) {
                            $resource = DB::table($table)->where('id', $definition[$field])->sharedLock()->first();
                            if (! $resource || $resource->status !== 'active' || (int) $resource->current_version_id !== (int) ($frozen[$key][$definition[$field]] ?? 0)) {
                                throw new Conflict('WEBSITE_RESOURCE_CHANGED', 'Giao diện hoặc Template SEO đã thay đổi sau khi nhận danh sách. Tạo lượt mới để dùng cấu hình mới.');
                            }
                        }

                        return app(WebsiteProvisioner::class)->create(['domain' => $item->domain, 'base_url' => $item->base_url, 'name' => $item->name],
                            $definition, (int) $operation->created_by, 'bulk:'.$id.':'.$item->id);
                    });
                    $result = ['status' => 'ready', 'site_id' => $site->id, 'error_code' => null, 'error_message' => null];
                } catch (Throwable $exception) {
                    if ($exception instanceof Conflict) {
                        $code = $exception->reason;
                        $message = $exception->getMessage();
                    } elseif ($exception instanceof ValidationException) {
                        $code = 'CONFIGURATION_INVALID';
                        $message = implode(' ', array_merge(...array_values($exception->errors())));
                    } elseif ($exception instanceof HttpExceptionInterface) {
                        $code = 'ACCESS_DENIED';
                        $message = 'Không còn quyền tạo website hoặc tài nguyên cấu hình không còn khả dụng.';
                    } else {
                        $code = 'CREATION_FAILED';
                        $message = 'Chưa tạo được website. Dữ liệu của domain này đã được hoàn tác; có thể thử lại.';
                        report($exception);
                    }
                    $result = ['status' => 'error', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 2000)];
                }
                DB::table('website_create_items')->where('id', $item->id)->update($result + ['attempts' => $item->attempts + 1, 'updated_at' => now()]);
                $more = DB::table('website_create_items')->where(['operation_id' => $id, 'status' => 'queued'])->exists();
                $errors = DB::table('website_create_items')->where(['operation_id' => $id, 'status' => 'error'])->exists();
                DB::table('website_create_operations')->where('id', $id)->update(['status' => $more ? 'running' : ($errors ? 'partial' : 'completed'), 'last_dispatched_at' => null, 'updated_at' => now()]);

                return $more;
            }, 3);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lock]);
        }
    }

    public function report(string $id, int $actorId): array
    {
        $this->authorize($actorId);
        abort_unless(Str::isUuid($id), 404);
        $operation = DB::table('website_create_operations')->find($id) ?? abort(404);
        $options = Json::decode($operation->options_json);
        $items = DB::table('website_create_items')->where('operation_id', $id)->orderBy('position')->get([
            'id', 'operation_id', 'position', 'domain', 'base_url', 'name', 'preset_id', 'preset_version_id', 'preset_name',
            'theme_id', 'theme_name', 'status', 'site_id', 'attempts', 'error_code', 'error_message', 'created_at', 'updated_at',
            DB::raw("(definition_json->>'template_id')::bigint AS seo_template_id"),
            DB::raw("definition_json->'fanmulu' AS fanmulu_settings_json"),
            DB::raw("definition_json->'presentation' AS presentation_settings_json"),
            DB::raw("definition_json->'source_ids' AS source_ids_json"),
        ]);
        $counts = ['queued' => 0, 'running' => 0, 'ready' => 0, 'error' => 0, 'total' => $items->count()];
        foreach ($items as $item) {
            $counts[$item->status]++;
            $seo = $options['seo_templates'][$item->seo_template_id] ?? [];
            $item->seo_template_name = $seo['name'] ?? null;
            $item->seo_template_version_id = $seo['version_id'] ?? $options['template_versions'][$item->seo_template_id] ?? null;
            $item->seo_template_version_no = $seo['version_no'] ?? null;
            $item->fanmulu_settings = array_replace(FanmuluPresetSettings::defaults(), Json::decode($item->fanmulu_settings_json));
            $item->presentation_settings = Json::decode($item->presentation_settings_json);
            $item->source_choices = array_map(fn ($sourceId): array => ['id' => (int) $sourceId,
                'name' => $options['source_manifest'][$sourceId]['name'] ?? null], Json::decode($item->source_ids_json));
            unset($item->fanmulu_settings_json, $item->presentation_settings_json, $item->source_ids_json);
        }

        return ['operation' => $operation, 'items' => $items, 'counts' => $counts, 'options' => $options, 'distribution' => $items->countBy('preset_name')->all()];
    }

    public function retry(string $id, int $actorId): void
    {
        $this->authorize($actorId);
        abort_unless(Str::isUuid($id), 404);
        DB::transaction(function () use ($id): void {
            DB::table('website_create_operations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if (DB::table('website_create_items')->where(['operation_id' => $id, 'status' => 'error'])->update(['status' => 'queued', 'error_code' => null, 'error_message' => null, 'updated_at' => now()])) {
                DB::table('website_create_operations')->where('id', $id)->update(['status' => 'queued', 'last_dispatched_at' => null, 'updated_at' => now()]);
            }
        });
        DB::afterCommit(fn () => $this->dispatch($id));
    }

    public function dispatchPending(): int
    {
        $count = 0;
        foreach (DB::table('website_create_operations')->whereIn('status', ['queued', 'running'])
            ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<', now()->subMinutes(5)))
            ->orderBy('created_at')->limit(100)->pluck('id') as $id) {
            $count += (int) $this->dispatch($id);
        }

        return $count;
    }

    private function dispatch(string $id): bool
    {
        $claimed = DB::table('website_create_operations')->where('id', $id)->whereIn('status', ['queued', 'running'])
            ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<', now()->subMinutes(5)))->update(['last_dispatched_at' => now()]);
        if (! $claimed) {
            return false;
        }
        try {
            RunWebsiteCreation::dispatch($id);
        } catch (Throwable $exception) {
            DB::table('website_create_operations')->where('id', $id)->update(['last_dispatched_at' => null]);
            report($exception);
        }

        return true;
    }

    private function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
    }

    /** Preset sources are candidates; every newly submitted domain receives one currently usable source. */
    private function sourceCandidates(array $definition): Collection
    {
        $referenceId = $definition['source_reference_site_id'] ?? null;

        return CatalogAvailability::filter(DB::table('source_connectors as source'), 'source_connectors', 'source')
            ->whereIn('source.id', $definition['source_ids'] ?? [])->where('source.status', 'active')
            ->when($referenceId !== null, function ($query) use ($referenceId): void {
                $query->whereExists(function ($binding) use ($referenceId): void {
                    CatalogAvailability::filter($binding->selectRaw('1')->from('source_site_bindings as binding'), 'source_site_bindings', 'binding')
                        ->whereColumn('binding.connector_id', 'source.id')
                        ->where('binding.site_id', $referenceId)->where('binding.status', 'active');
                });
            })->orderBy('source.id')->sharedLock()->get(['source.id', 'source.name', 'source.kind']);
    }
}
