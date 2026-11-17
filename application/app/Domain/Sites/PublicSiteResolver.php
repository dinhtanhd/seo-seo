<?php

namespace App\Domain\Sites;

use Illuminate\Support\Facades\DB;

final class PublicSiteResolver
{
    public function isAdminHost(string $host): bool
    {
        $adminHosts = explode(',', (string) config('tram.admin_hosts', 'localhost,127.0.0.1,admin.localhost'));

        return in_array($host, array_map(fn (string $name): string => strtolower(trim($name)), $adminHosts), true);
    }

    /** @return array{site:object,wildcard:bool}|null */
    public function resolve(string $host, bool $lock = false): ?array
    {
        $parent = $this->wildcardParent($host);
        $query = DB::table('site_domains as domain')->join('sites', 'sites.id', '=', 'domain.site_id');
        if ($parent === null) {
            $query->where('domain.host_ascii', $host);
        } else {
            // Keep a registered exact hostname ahead of its parent even if paused.
            $query->whereIn('domain.host_ascii', [$host, $parent])->orderByRaw('domain.host_ascii = ? DESC', [$host]);
        }
        $site = $query->first(['sites.*', 'domain.host_ascii as request_domain', 'domain.status as request_domain_status']);
        if (! $site || $site->status !== 'active' || ($site->deleted_at ?? null) !== null || $site->request_domain_status !== 'active') {
            return null;
        }
        $domain = $site->request_domain;
        $wildcard = $domain !== $host;
        unset($site->request_domain, $site->request_domain_status);

        if ($lock) {
            // Configuration writers take the site lock before editing domain rows.
            $site = DB::table('sites')->where('id', $site->id)->where('status', 'active')->sharedLock()->first();
            if (! $site || ($site->deleted_at ?? null) !== null
                || ! DB::table('site_domains')->where(['host_ascii' => $domain, 'site_id' => $site->id, 'status' => 'active'])->sharedLock()->first()
                || ($wildcard && DB::table('site_domains')->where('host_ascii', $host)->exists())) {
                return null;
            }
        }

        return compact('site', 'wildcard');
    }

    private function wildcardParent(string $host): ?string
    {
        $allowlist = (string) config('tram.public_wildcard_domains', '');
        if ($allowlist === '' || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || $this->isAdminHost($host)) {
            return null;
        }
        // Limit the wildcard to one DNS label, matching wildcard TLS coverage.
        $apex = explode('.', $host, 2)[1] ?? '';
        if (! str_contains($apex, '.') || filter_var($apex, FILTER_VALIDATE_IP) || $this->isAdminHost($apex)) {
            return null;
        }
        $allowed = array_map(fn (string $name): string => strtolower(rtrim(trim($name), '.')), explode(',', $allowlist));

        return in_array($apex, $allowed, true) ? $apex : null;
    }
}
