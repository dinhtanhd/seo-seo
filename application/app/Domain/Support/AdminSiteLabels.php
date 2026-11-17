<?php

namespace App\Domain\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Administrative identity only; public brand names and frozen data are never changed. */
final class AdminSiteLabels
{
    private ?Request $requestScope = null;

    private ?int $actorScope = null;

    private array $cache = [];

    public function label(object|int|null $site): string
    {
        $id = $this->id($site);
        if ($id === null) {
            return 'Chưa chọn website';
        }

        return $this->labels([$id])->get($id, 'Website #'.$id);
    }

    /** Sort readable choices by their domain label, with a stable ID tie break. */
    public function sortSites(iterable $sites): Collection
    {
        $sites = collect($sites);
        $labels = $this->labels($sites);

        return $sites->filter(fn ($site) => $labels->has($this->id($site)))
            ->sort(fn ($left, $right) => strcasecmp($labels->get($this->id($left)), $labels->get($this->id($right)))
                ?: $this->id($left) <=> $this->id($right))->values();
    }

    /** @return Collection<int, string> Only sites readable by the current actor are returned. */
    public function labels(iterable $sites): Collection
    {
        $actor = Auth::user();
        $request = app('request');
        $actorId = $actor === null ? null : (int) $actor->getAuthIdentifier();
        if ($this->requestScope !== $request || $this->actorScope !== $actorId) {
            $this->requestScope = $request;
            $this->actorScope = $actorId;
            $this->cache = [];
        }
        $ids = [];
        foreach ($sites as $site) {
            $id = $this->id($site);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        $missing = array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, $this->cache)));
        if ($missing !== [] && $actor !== null) {
            $rows = DB::table('sites')->whereIn('id', $missing)
                ->when(! $actor->is_global_admin, fn ($query) => $query->whereIn('id',
                    DB::table('site_memberships')->where('user_id', $actorId)->select('site_id')))
                ->get(['id', 'base_url']);
            $domains = $rows->isEmpty() ? collect() : DB::table('site_domains')->whereIn('site_id', $rows->pluck('id'))
                ->orderBy('host_ascii')->orderBy('id')->get(['id', 'site_id', 'host_ascii', 'is_primary', 'status'])->groupBy('site_id');
            foreach ($rows as $row) {
                $this->cache[(int) $row->id] = $this->identity($row, $domains->get($row->id, collect()));
            }
        }
        foreach ($missing as $id) {
            $this->cache[$id] ??= null;
        }

        return collect($ids)->filter(fn ($id) => $this->cache[$id] !== null)
            ->mapWithKeys(fn ($id) => [$id => $this->cache[$id]]);
    }

    /** Batch known site collections and related rows before Blade renders individual labels. */
    public function primeView(array $data): void
    {
        $ids = [];
        foreach (['site', 'sites', 'websites', 'ruleSites'] as $key) {
            $values = $data[$key] ?? [];
            if ($key === 'site') {
                $values = [$values];
            }
            if (is_iterable($values)) {
                foreach ($values as $value) {
                    if (($id = $this->id($value)) !== null) {
                        $ids[$id] = $id;
                    }
                }
            }
        }
        $report = $data['report'] ?? [];
        $reportSites = is_array($report) ? ($report['sites'] ?? []) : ($report instanceof \stdClass ? ($report->sites ?? []) : []);
        if (is_iterable($reportSites)) {
            foreach ($reportSites as $site) {
                if (($id = $this->id($site)) !== null) {
                    $ids[$id] = $id;
                }
            }
        }
        $this->relatedIds($data, $ids);
        $this->labels($ids);
    }

    private function id(mixed $value): ?int
    {
        $value = is_object($value) ? ($value->id ?? null) : (is_array($value) ? ($value['id'] ?? null) : $value);
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    private function relatedIds(mixed $value, array &$ids, int $depth = 0): void
    {
        if ($depth > 12) {
            return;
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value) && ! $value instanceof \Traversable) {
            return;
        }
        if (is_array($value) && isset($value['site_id']) && ($id = $this->id($value['site_id'])) !== null) {
            $ids[$id] = $id;
        }
        foreach ($value as $entry) {
            // In particular, snapshot_json/preview_json/report strings stay opaque.
            if (is_array($entry) || $entry instanceof \stdClass || $entry instanceof \Traversable) {
                $this->relatedIds($entry, $ids, $depth + 1);
            }
        }
    }

    private function identity(object $site, Collection $domains): string
    {
        $primary = $domains->where('is_primary', true)->sortBy(fn ($domain) => ($domain->status === 'active' ? '0' : '1').'|'.$domain->host_ascii.'|'.str_pad((string) $domain->id, 20, '0', STR_PAD_LEFT))->first();
        if ($primary !== null) {
            return $primary->host_ascii;
        }
        $base = is_string($site->base_url) ? parse_url($site->base_url) : false;
        if (is_array($base) && in_array(strtolower($base['scheme'] ?? ''), ['http', 'https'], true)
            && is_string($base['host'] ?? null) && $base['host'] !== '') {
            return strtolower($base['host']);
        }
        $alias = $domains->sortBy(fn ($domain) => ($domain->status === 'active' ? '0' : '1').'|'.$domain->host_ascii.'|'.str_pad((string) $domain->id, 20, '0', STR_PAD_LEFT))->first();

        return $alias?->host_ascii ?? 'Website #'.$site->id.' · chưa có tên miền';
    }
}
