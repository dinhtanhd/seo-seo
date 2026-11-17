<?php

namespace App\Domain\Themes;

use App\Domain\Fanmulu\FanmuluRuntime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Bounded, stable links to other active websites in this installation. */
final class SystemFriendLinks
{
    public const LIMIT = 10;

    /** @return array<int,array{site_id:int,text:string,url:string}> */
    public function links(int $siteId): array
    {
        $query = $this->eligible()->where('s.id', '<>', $siteId);
        $selected = (clone $query)->where('s.id', '>', $siteId)->orderBy('s.id')->limit(self::LIMIT)->get();
        if ($selected->count() < self::LIMIT) {
            $selected = $selected->concat((clone $query)->where('s.id', '<', $siteId)->orderBy('s.id')->limit(self::LIMIT - $selected->count())->get());
        }

        return $selected->map(fn ($site): array => ['site_id' => (int) $site->id,
            'text' => trim((string) $site->name) !== '' ? $site->name : $site->host_ascii,
            'url' => rtrim($site->base_url, '/').'/'])->values()->all();
    }

    public function decorate(string $html, object $site, bool $fanmulu = false): string
    {
        return ($site->content_type ?? null) === 'movie'
            ? app(FanmuluRuntime::class)->friendLinksOverlay(app(FriendLinksMarkup::class)->decorate($html, $this->links((int) $site->id)), $fanmulu)
            : $html;
    }

    private function eligible(): Builder
    {
        $adminHosts = array_values(array_filter(array_map(fn ($host): string => strtolower(rtrim(trim($host), '.')),
            explode(',', (string) config('tram.admin_hosts', 'localhost,127.0.0.1,admin.localhost')))));
        $root = '(?i)^https?://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.?)(?::[0-9]{1,5})?/?$';

        return DB::table('sites as s')->join('site_domains as d', 'd.site_id', '=', 's.id')
            ->where('s.status', 'active')->where('d.status', 'active')->where('d.is_primary', true)
            ->whereNotIn('d.host_ascii', $adminHosts)->whereRaw('char_length(d.host_ascii) <= 253')
            ->whereRaw("d.host_ascii ~ '^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$'")
            ->whereRaw("d.host_ascii !~ '^[0-9.]+$'")
            ->whereRaw("s.base_url !~ '[[:space:][:cntrl:]]'")
            ->whereRaw('s.base_url ~ ?', [$root])
            ->whereRaw("rtrim(lower(substring(s.base_url from ?)), '.') = d.host_ascii", [$root])
            ->whereRaw('COALESCE((substring(s.base_url from ?))::integer, 1) BETWEEN 1 AND 65535', ['(?i)^https?://[a-z0-9.-]+:([0-9]{1,5})/?$'])
            ->select(['s.id', 's.name', 's.base_url', 'd.host_ascii']);
    }
}
