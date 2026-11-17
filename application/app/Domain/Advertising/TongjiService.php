<?php

namespace App\Domain\Advertising;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TongjiService
{
    private ?object $memo = null;

    public function settings(): object
    {
        if ($this->memo === null) {
            $data = app(AdvertisingScriptPaths::class)->configuration()['tongji'] ?? [];
            $this->memo = (object) array_replace(['row_version' => 1, 'enabled' => false, 'code' => '', 'placement' => 'body'], $data);
        }

        return $this->memo;
    }

    public function enabled(): bool
    {
        $settings = $this->settings();

        return (bool) $settings->enabled && trim($settings->code) !== '';
    }

    public function save(array $data, int $actorId): object
    {
        $this->authorize($actorId);
        abort_unless(AdvertisingScriptPaths::available(), 503, 'Cấu hình thống kê đang được cập nhật. Hãy tải lại sau.');
        $values = Validator::make($data, ['tongji_row_version' => 'required|integer|min:1', 'tongji_enabled' => 'required|boolean',
            'tongji_code' => 'nullable|string|max:65536', 'tongji_placement' => 'required|in:head,body'])->validate();
        $code = $values['tongji_code'] ?? '';
        if (strlen($code) > 65536 || ! mb_check_encoding($code, 'UTF-8') || str_contains($code, "\0")) {
            throw ValidationException::withMessages(['tongji_code' => 'Mã thống kê cần là UTF-8, tối đa 64 KiB và không chứa NUL.']);
        }
        if ($values['tongji_enabled'] && trim($code) === '') {
            throw ValidationException::withMessages(['tongji_code' => 'Dán mã HTML thống kê trước khi bật.']);
        }

        return DB::transaction(function () use ($values, $code, $actorId): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $this->authorize($actorId);
            $row = DB::table('advertising_settings')->where('id', 1)->lockForUpdate()->first() ?? abort(503);
            $configuration = app(AdvertisingScriptPaths::class)->configuration($row);
            $current = $configuration['tongji'] ?? [];
            if ((int) ($current['row_version'] ?? 1) !== (int) $values['tongji_row_version']) {
                throw new Conflict('TONGJI_VERSION_CONFLICT', 'Mã thống kê đã thay đổi. Tải lại trước khi lưu.');
            }
            $next = ['row_version' => (int) ($current['row_version'] ?? 1) + 1, 'enabled' => (bool) $values['tongji_enabled'],
                'code' => $code, 'placement' => $values['tongji_placement']];
            // The settings write lock also fences all HTML cache writers.
            app(PublicHtmlCache::class)->invalidateAll();
            $configuration['tongji'] = $next;
            DB::table('advertising_settings')->where('id', 1)->update(['configuration_json' => Json::encode($configuration),
                'updated_by' => $actorId, 'updated_at' => now()]);
            Access::audit('tongji.global.saved', $actorId, null, ['enabled' => $next['enabled'], 'placement' => $next['placement'],
                'row_version' => $next['row_version'], 'code_hash' => hash('sha256', $code)]);
            $this->memo = null;

            return $this->settings();
        }, 3);
    }

    /** Inject bytes verbatim, without a wrapper, serialization or provider-specific rewriting. */
    public function decorate(string $html): string
    {
        if (! $this->enabled()) {
            return $html;
        }
        $settings = $this->settings();
        if (str_contains($html, $settings->code)) {
            return $html;
        }
        $position = strripos($html, $settings->placement === 'head' ? '</head>' : '</body>');
        if ($position === false && $settings->placement === 'head') {
            $position = strripos($html, '</body>');
        }

        return $position === false ? $html.$settings->code : substr_replace($html, $settings->code, $position, 0);
    }

    public function csp(string $base): string
    {
        if (! $this->enabled()) {
            return $base;
        }
        $directives = [];
        foreach (explode(';', $base) as $directive) {
            $parts = preg_split('/\s+/', trim($directive), 2);
            if ($parts[0] !== '') {
                $directives[$parts[0]] = $parts[1] ?? '';
            }
        }
        // Authorized raw providers may write further inline scripts or load scripts dynamically.
        // Hash-only policies cannot authorize those unknown future bytes.
        $directives['script-src'] = "'self' https: http: 'unsafe-inline' 'unsafe-eval'";
        foreach (['connect-src', 'frame-src'] as $name) {
            $directives[$name] = "'self' https: http:";
        }
        $directives['img-src'] = "'self' https: http: data:";
        $directives['style-src'] = "'self' https: http: 'unsafe-inline'";

        return implode('; ', array_map(fn ($name, $value) => $name.' '.$value, array_keys($directives), $directives));
    }

    private function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
    }
}
