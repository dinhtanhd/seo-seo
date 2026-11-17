<?php

namespace App\Http\Middleware;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Publishing\PublicRequestPolicyCache;
use App\Domain\Sites\PublicSiteResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSite
{
    public function __construct(private readonly PublicSiteResolver $sites) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(rtrim($request->getHost(), '.'));
        $domainGate = $request->server('TRAM_DOMAIN_AUTH_REQUEST') === '1';
        $cachePolicy = $request->server('TRAM_PUBLIC_CACHE_POLICY_REQUEST') === '1';
        if ($this->sites->isAdminHost($host)) {
            if ($cachePolicy) {
                return $this->cachePolicy();
            }
            if ($domainGate) {
                return response('', 403);
            }
            $request->attributes->set('admin_host', true);

            return $next($request);
        }
        if ($cachePolicy) {
            $path = $request->server('TRAM_PUBLIC_CACHE_POLICY_PATH', '/');
            $policy = app(PublicRequestPolicyCache::class)->warm($host, is_string($path) ? $path : '/');

            return $policy === null ? response('', 403) : response('', 204)->header('Cache-Control', 'no-store')
                ->header('X-Tram-Policy-Cache', 'MISS')
                ->header('X-Tram-Public-Plain-Csp', $policy['plain'])->header('X-Tram-Public-Runtime-Csp', $policy['runtime']);
        }
        if ($domainGate) {
            return response('', app(PublicRequestPolicyCache::class)->warm($host) !== null ? 204 : 403);
        }
        $resolved = $this->sites->resolve($host);
        abort_unless($resolved, 404, 'Website không được cấu hình cho hostname này.');
        $request->attributes->set('public_site', $resolved['site']);
        if ($resolved['wildcard']) {
            $request->server->set('TRAM_PUBLIC_CACHE_ELIGIBLE', '0');
        }

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
