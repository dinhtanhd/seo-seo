<?php

namespace App\Http\Middleware;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\PublicRequestPolicyCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(rtrim($request->getHost(), '.'));
        $domainGate = $request->server('TRAM_DOMAIN_AUTH_REQUEST') === '1';
        $cachePolicy = $request->server('TRAM_PUBLIC_CACHE_POLICY_REQUEST') === '1';
        $adminHosts = array_map('trim', explode(',', (string) config('tram.admin_hosts', env('ADMIN_HOSTS', 'localhost,127.0.0.1,admin.localhost'))));
        if (in_array($host, $adminHosts, true)) {
            if ($cachePolicy) {
                return $this->cachePolicy();
            }
            if ($domainGate) {
                return response('', 403);
            }
            $request->attributes->set('admin_host', true);

            return $next($request);
        }
        $site = DB::table('site_domains')->join('sites', 'sites.id', '=', 'site_domains.site_id')
            ->where('host_ascii', $host)->where('site_domains.status', 'active')->where('sites.status', 'active')
            ->select('sites.*')->first();
        if ($cachePolicy) {
            if (! $site) {
                return response('', 403);
            }
            $path = $request->server('TRAM_PUBLIC_CACHE_POLICY_PATH', '/');
            $policy = app(PublicRequestPolicyCache::class)->warm($host, is_string($path) ? $path : '/');

            return $policy === null ? response('', 403) : response('', 204)->header('Cache-Control', 'no-store')
                ->header('X-Tram-Policy-Cache', 'MISS')
                ->header('X-Tram-Public-Plain-Csp', $policy['plain'])->header('X-Tram-Public-Runtime-Csp', $policy['runtime']);
        }
        if ($domainGate) {
            return response('', $site && app(PublicRequestPolicyCache::class)->warm($host) !== null ? 204 : 403);
        }
        abort_unless($site, 404, 'Website không được cấu hình cho hostname này.');
        $request->attributes->set('public_site', $site);

        return $next($request);
    }

    private function cachePolicy(): Response
    {
        $plain = PublicHtmlCache::PLAIN_CSP;
        $runtime = PublicHtmlCache::RUNTIME_CSP;

        return response('', 204)->header('Cache-Control', 'no-store')
            ->header('X-Tram-Public-Plain-Csp', $plain)->header('X-Tram-Public-Runtime-Csp', $runtime);
    }
}
