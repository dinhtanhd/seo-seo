<?php

namespace App\Domain\Publishing;

use App\Domain\Advertising\AdvertisingService;
use App\Domain\Sites\PublicSiteResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PublicRequestPolicyCache
{
    public function __construct(private readonly PublicSiteResolver $sites) {}

    /** Recheck under the same global/site locks used by configuration writers. */
    public function warm(string $host, ?string $path = null): ?array
    {
        $committed = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($host, $path, $committed): ?array {
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            $resolved = $this->sites->resolve($host, lock: true);
            if ($resolved === null) {
                return null;
            }
            $site = $resolved['site'];
            $common = ['schema' => 1, 'database' => config('database.connections.pgsql.database'),
                'host' => $host, 'site_id' => (int) $site->id];
            $response = [];
            if ($path !== null) {
                $advertising = app(AdvertisingService::class);
                $preferences = $advertising->preferences((int) $site->id);
                $area = $preferences->regular_enabled !== $preferences->fanmulu_enabled && PublicHtmlCache::safePath($path)
                    && DB::table('pages')->where('site_id', $site->id)->where('canonical_path', $path)->value('page_kind') === 'fanmulu'
                        ? 'fanmulu' : 'regular';
                $response = ['plain' => $advertising->csp(PublicHtmlCache::PLAIN_CSP, $site, $area),
                    'runtime' => $advertising->csp(PublicHtmlCache::RUNTIME_CSP, $site, $area)];
            }
            if ($committed && ! $resolved['wildcard']) {
                try {
                    $this->write($host, null, $common + ['kind' => 'domain']);
                    if ($path !== null) {
                        $this->write($host, $path, $common + ['kind' => 'csp', 'path_hash' => hash('sha256', $path)] + $response);
                    }
                } catch (Throwable $error) {
                    // The current response is still based on locked, authoritative DB reads.
                    report($error);
                }
            }

            return $response;
        }, 3);
    }

    public function invalidate(?string $host = null, ?string $path = null): void
    {
        if (PublicRequestPolicyReader::file((string) config('public_html_cache.root'), (string) config('database.connections.pgsql.database'), $host ?? 'validation.localhost') === null) {
            throw new RuntimeException('Namespace policy cache không hợp lệ.');
        }
        $root = rtrim((string) config('public_html_cache.root'), '/').'/'.config('database.connections.pgsql.database');
        if ($host !== null && $path !== null) {
            $file = PublicRequestPolicyReader::file((string) config('public_html_cache.root'), (string) config('database.connections.pgsql.database'), $host, $path);
            if ($file === null) {
                return;
            }
            if (! file_exists($file) && ! is_link($file)) {
                return;
            }
            if (! PublicRequestPolicyReader::safe($file)) {
                throw new RuntimeException('Đường dẫn policy cache không an toàn.');
            }
            if (is_file($file) && ! unlink($file)) {
                throw new RuntimeException('Không thể vô hiệu policy cache.');
            }

            return;
        }
        $directory = $root.'/.request-policies'.($host !== null ? '/'.$host : '');
        if (! is_dir($directory) && ! is_link($directory)) {
            return;
        }
        if (! PublicRequestPolicyReader::safe($directory.'/.guard')) {
            throw new RuntimeException('Thư mục policy cache không an toàn.');
        }
        $retired = $directory.'-retired-'.Str::uuid();
        if (! rename($directory, $retired)) {
            throw new RuntimeException('Không thể vô hiệu policy cache.');
        }
        $cleanup = static function () use ($retired): void {
            (new Filesystem)->deleteDirectory($retired);
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($cleanup);
            DB::afterRollBack($cleanup);
        } else {
            $cleanup();
        }
    }

    private function write(string $host, ?string $path, array $data): void
    {
        $file = PublicRequestPolicyReader::file((string) config('public_html_cache.root'), (string) config('database.connections.pgsql.database'), $host, $path);
        if ($file === null) {
            return;
        }
        if (! PublicRequestPolicyReader::safe($file)) {
            throw new RuntimeException('Đường dẫn policy cache không an toàn.');
        }
        $previous = umask(0002);
        $temporary = null;
        try {
            (new Filesystem)->ensureDirectoryExists(dirname($file), 02775);
            if (! PublicRequestPolicyReader::safe($file)) {
                throw new RuntimeException('Đường dẫn policy cache đã thay đổi.');
            }
            $temporary = tempnam(dirname($file), '.policy-');
            if ($temporary === false || file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR)) === false
                || ! chmod($temporary, 0644) || ! rename($temporary, $file)) {
                throw new RuntimeException('Không thể ghi policy cache.');
            }
        } finally {
            umask($previous);
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
