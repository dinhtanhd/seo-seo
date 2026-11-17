<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SharedConfigApply
{
    public function template(int $id, int $actorId): array
    {
        $template = DB::table('templates')->find($id) ?? abort(404);

        return $this->apply($this->pages()->whereRaw('COALESCE(p.template_id, s.template_id) = ?', [$id]), $actorId, 'template:'.$id.':'.$template->current_version_id, ['presentation_only' => true, 'refresh_seo' => true]);
    }

    public function theme(int $id, int $actorId): array
    {
        $theme = DB::table('movie_themes')->find($id) ?? abort(404);

        return $this->apply($this->pages()->whereRaw('COALESCE(p.theme_id, s.theme_id) = ?', [$id]), $actorId, 'theme:'.$id.':'.$theme->current_version_id);
    }

    public function rule(object $rule, int $actorId): array
    {
        $rule = DB::table('rules')->find($rule->id) ?? abort(404);
        $pages = $this->pages();
        if ($rule->scope_type !== 'global') {
            $pages->where('p.site_id', $rule->site_id);
        }
        match ($rule->scope_type) {
            'global', 'site' => null,
            'page_type' => $pages->where('p.page_type', $rule->page_type),
            'page' => $pages->where('p.id', $rule->page_id),
            'category' => $pages->whereIn('p.category_id', $this->descendants((int) $rule->site_id, (int) $rule->category_id)),
            default => throw new \InvalidArgumentException('Phạm vi quy tắc không hợp lệ.'),
        };

        return $this->apply($pages, $actorId, 'rule:'.$rule->id.':'.$rule->current_version_id);
    }

    private function pages(): Builder
    {
        return DB::table('pages as p')->join('sites as s', 's.id', '=', 'p.site_id')
            ->where('p.status', 'live')->whereNotNull('p.current_publication_id')->where('s.status', 'active');
    }

    private function descendants(int $siteId, int $rootId): array
    {
        $rows = DB::table('categories')->where('site_id', $siteId)->limit(10001)->get(['id', 'parent_id']);
        if ($rows->count() > 10000) {
            throw new \InvalidArgumentException('Cây danh mục vượt giới hạn 10.000 mục cho một lần áp dụng.');
        }
        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row->parent_id][] = (int) $row->id;
        }
        $ids = [$rootId => true];
        $queue = [$rootId];
        for ($index = 0; $index < count($queue); $index++) {
            foreach ($children[$queue[$index]] ?? [] as $child) {
                if (! isset($ids[$child])) {
                    $ids[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return array_keys($ids);
    }

    /** Preflight every website before queuing any, then keep each site's real outcome. */
    private function apply(Builder $pages, int $actorId, string $versionKey, array $options = []): array
    {
        $groups = $pages->orderBy('p.site_id')->orderBy('p.id')->get(['p.id', 'p.site_id'])->groupBy('site_id');
        foreach ($groups as $siteId => $group) {
            Access::site($actorId, (int) $siteId, true);
        }
        $result = ['site_count' => $groups->count(), 'page_count' => $groups->sum(fn ($group) => $group->count()), 'batch_ids' => [], 'errors' => []];
        foreach ($groups as $siteId => $group) {
            try {
                $batch = app(QuickApply::class)->apply((int) $siteId, $group->pluck('id')->all(), $actorId, 'shared:'.$versionKey.':site:'.$siteId, $options);
                $result['batch_ids'][(int) $siteId] = (int) $batch->id;
                $errors = DB::table('publish_batch_items')->where(['site_id' => $siteId, 'batch_id' => $batch->id])
                    ->whereIn('status', ['error', 'failed', 'stale', 'locked', 'capacity_conflict']);
                $count = (clone $errors)->count();
                if ($count) {
                    $codes = $errors->whereNotNull('error_code')->distinct()->limit(5)->pluck('error_code')->all();
                    $result['errors'][(int) $siteId] = $count.' trang cần kiểm tra'.($codes ? ': '.implode(', ', $codes) : '.');
                }
            } catch (Conflict|\InvalidArgumentException $error) {
                $result['errors'][(int) $siteId] = $error instanceof Conflict ? $error->reason : $error->getMessage();
            }
        }

        return $result;
    }
}
