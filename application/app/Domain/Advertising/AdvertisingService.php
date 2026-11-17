<?php

namespace App\Domain\Advertising;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\PublicRequestPolicyCache;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AdvertisingService
{
    private array $metadata = [];

    public function __construct(
        private readonly AdvertisingScriptPaths $paths = new AdvertisingScriptPaths,
        private readonly TongjiService $tongji = new TongjiService,
    ) {}

    public function globalSettings(): object
    {
        $settings = DB::table('advertising_settings')->where('id', 1)->first() ?? abort(503);
        $settings->extra_origins = Json::decode($settings->extra_origins_json);
        foreach ($this->paths->settings($this->paths->configuration($settings)) as $field => $value) {
            $settings->$field = $value;
        }

        return $settings;
    }

    public function preferences(int $siteId): object
    {
        return DB::table('site_advertising_settings')->where('site_id', $siteId)->first()
            ?? (object) ['site_id' => $siteId, 'regular_enabled' => false, 'fanmulu_enabled' => false, 'row_version' => 0];
    }

    public function saveGlobal(array $data, int $actorId): object
    {
        $this->authorizeGlobal($actorId);
        $values = Validator::make($data, ['row_version' => 'required|integer|min:1', 'enabled' => 'required|boolean',
            'code' => 'nullable|string|max:65536', 'regular_js_path' => 'sometimes|required|string|max:240',
            'fanmulu_js_path' => 'sometimes|required|string|max:240'])->validate();
        if (array_key_exists('regular_js_path', $data) || array_key_exists('fanmulu_js_path', $data)) {
            abort_unless(AdvertisingScriptPaths::available(), 503, 'Cấu hình đường dẫn JS đang được cập nhật. Hãy tải lại sau.');
        }
        $compiled = AdvertisingCode::compile($values['code'] ?? '', $data['extra_origins'] ?? []);
        if (3 * strlen(implode(' ', $compiled['origins'])) + strlen(implode(' ', $compiled['inline_hashes'])) > 6000) {
            throw ValidationException::withMessages(['code' => 'Mã quảng cáo có quá nhiều nguồn tài nguyên hoặc thẻ script. Giảm số đoạn mã trong cấu hình chung.']);
        }
        if ($values['enabled'] && ! $compiled['has_code']) {
            throw ValidationException::withMessages(['code' => 'Điền mã JS trước khi bật quảng cáo dùng chung.']);
        }

        return DB::transaction(function () use ($values, $compiled, $actorId, $data): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $this->authorizeGlobal($actorId);
            $current = DB::table('advertising_settings')->where('id', 1)->lockForUpdate()->first() ?? abort(503);
            if ((int) $current->row_version !== (int) $values['row_version']) {
                throw new Conflict('ADVERTISING_VERSION_CONFLICT', 'Mã quảng cáo đã thay đổi. Tải lại trước khi lưu.');
            }
            $configuration = $this->paths->configuration($current);
            $previousPaths = $this->paths->settings($configuration);
            $nextConfiguration = $this->paths->merge($configuration, $data);
            $nextPaths = $this->paths->settings($nextConfiguration);
            if ($nextPaths['regular_js_path'] !== $previousPaths['regular_js_path'] || $nextPaths['fanmulu_js_path'] !== $previousPaths['fanmulu_js_path']) {
                app(PublicHtmlCache::class)->invalidateAll();
            }
            $extra = AdvertisingCode::compile('', $data['extra_origins'] ?? [])['origins'];
            app(PublicRequestPolicyCache::class)->invalidate();
            $attributes = ['enabled' => (bool) $values['enabled'],
                'code' => $compiled['code'], 'compiled_js' => $compiled['compiled_js'], 'has_code' => $compiled['has_code'],
                'extra_origins_json' => Json::encode($extra), 'origins_json' => Json::encode($compiled['origins']),
                'inline_hashes_json' => Json::encode($compiled['inline_hashes']), 'row_version' => $current->row_version + 1,
                'updated_by' => $actorId, 'updated_at' => now()];
            if (AdvertisingScriptPaths::available()) {
                $attributes['configuration_json'] = Json::encode($nextConfiguration);
            }
            DB::table('advertising_settings')->where('id', 1)->update($attributes);
            Access::audit('advertising.global.saved', $actorId, null, ['enabled' => (bool) $values['enabled'],
                'code_hash' => hash('sha256', $compiled['code']), 'row_version' => $current->row_version + 1]);
            $this->metadata = [];

            return $this->globalSettings();
        }, 3);
    }

    public function saveSite(int $siteId, array $data, int $actorId): object
    {
        Access::site($actorId, $siteId, true);
        $values = Validator::make($data, ['row_version' => 'required|integer|min:0', 'regular_enabled' => 'required|boolean',
            'fanmulu_enabled' => 'required|boolean'])->validate();

        return DB::transaction(function () use ($siteId, $values, $actorId): object {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? abort(404);
            Access::site($actorId, $siteId, true);
            abort_unless($site->content_type === 'movie', 422, 'Cấu hình này dành cho website phim.');
            $current = DB::table('site_advertising_settings')->where('site_id', $siteId)->lockForUpdate()->first();
            if ((int) ($current->row_version ?? 0) !== (int) $values['row_version']) {
                throw new Conflict('SITE_ADVERTISING_VERSION_CONFLICT', 'Lựa chọn quảng cáo đã thay đổi. Tải lại trước khi lưu.');
            }
            app(PublicHtmlCache::class)->invalidateSite($siteId);
            $attributes = ['regular_enabled' => (bool) $values['regular_enabled'], 'fanmulu_enabled' => (bool) $values['fanmulu_enabled'],
                'row_version' => (int) ($current->row_version ?? 0) + 1, 'updated_by' => $actorId, 'updated_at' => now()];
            if ($current) {
                DB::table('site_advertising_settings')->where('site_id', $siteId)->update($attributes);
            } else {
                DB::table('site_advertising_settings')->insert($attributes + ['site_id' => $siteId, 'created_at' => now()]);
            }
            Access::audit('advertising.site.saved', $actorId, $siteId, $attributes);
            $this->metadata = [];

            return $this->preferences($siteId);
        }, 3);
    }

    public function decorate(string $html, object $site, string $area): string
    {
        $state = $this->state((int) $site->id);
        if ($this->enabled($state, $area) && ! str_contains($html, 'data-tram-advertising="'.$area.'"')) {
            $path = $state['paths'][$area.'_js_path'] ?? AdvertisingScriptPaths::DEFAULTS[$area];
            $tag = '<script data-tram-advertising="'.$area.'" src="'.htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"></script>';
            $position = strripos($html, '</body>');
            $html = $position === false ? $html.$tag : substr_replace($html, $tag, $position, 0);
        }

        return $this->tongji->decorate($html);
    }

    public function hasDecoration(string $html, string $area): bool
    {
        return str_contains($html, 'data-tram-advertising="'.$area.'"')
            || ($this->tongji->enabled() && str_contains($html, $this->tongji->settings()->code));
    }

    public function csp(string $base, object $site, string $area): string
    {
        $state = $this->state((int) $site->id);
        if (! $this->enabled($state, $area) || ! ($state['enabled'] ?? false) || ! ($state['has_code'] ?? false)) {
            return $this->tongji->csp($base);
        }
        $origins = $state['origins'];
        $script = array_values(array_unique(["'self'", ...$origins, ...array_map(fn (string $hash): string => "'".$hash."'", $state['hashes'])]));
        $directives = [];
        foreach (explode(';', $base) as $directive) {
            $parts = preg_split('/\s+/', trim($directive), 2);
            if ($parts[0] !== '') {
                $directives[$parts[0]] = $parts[1] ?? '';
            }
        }
        $directives['script-src'] = implode(' ', $script);
        foreach (['connect-src', 'frame-src'] as $directive) {
            $previous = preg_split('/\s+/', $directives[$directive] ?? "'self'");
            $previous = array_values(array_filter($previous, fn (string $value): bool => $value !== "'none'"));
            $directives[$directive] = implode(' ', array_unique([...$previous, "'self'", ...$origins]));
        }

        return $this->tongji->csp(implode('; ', array_map(fn (string $name, string $value): string => $name.' '.$value, array_keys($directives), $directives)));
    }

    public function javascript(object $site, string $area): string
    {
        abort_unless(in_array($area, ['regular', 'fanmulu'], true), 404);
        $flag = $area.'_enabled';
        $row = DB::table('advertising_settings as a')->leftJoin('site_advertising_settings as s', fn ($join) => $join->where('s.site_id', $site->id))
            ->where('a.id', 1)->first(['a.enabled', 'a.has_code', 'a.compiled_js', 's.regular_enabled', 's.fanmulu_enabled']);
        if (! $row || ! $row->enabled || ! $row->has_code || ! ($row->$flag ?? false)) {
            return '/* Quang cao dang tat. */';
        }

        return $row->compiled_js;
    }

    private function state(int $siteId): array
    {
        if (! array_key_exists($siteId, $this->metadata)) {
            $flags = $this->preferences($siteId);
            $data = ['regular_enabled' => (bool) $flags->regular_enabled, 'fanmulu_enabled' => (bool) $flags->fanmulu_enabled];
            if ($data['regular_enabled'] || $data['fanmulu_enabled']) {
                $columns = ['enabled', 'has_code', 'origins_json', 'inline_hashes_json'];
                if (AdvertisingScriptPaths::available()) {
                    $columns[] = 'configuration_json';
                }
                $global = DB::table('advertising_settings')->where('id', 1)->first($columns);
                $data += ['enabled' => (bool) ($global->enabled ?? false), 'has_code' => (bool) ($global->has_code ?? false),
                    'origins' => $global ? Json::decode($global->origins_json) : [], 'hashes' => $global ? Json::decode($global->inline_hashes_json) : [],
                    'paths' => $this->paths->settings($this->paths->configuration($global))];
            }
            $this->metadata[$siteId] = $data;
        }

        return $this->metadata[$siteId];
    }

    private function enabled(array $state, string $area): bool
    {
        return in_array($area, ['regular', 'fanmulu'], true) && ($state[$area.'_enabled'] ?? false);
    }

    private function authorizeGlobal(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
    }
}
