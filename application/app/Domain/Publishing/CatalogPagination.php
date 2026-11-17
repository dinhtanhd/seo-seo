<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/** Exact, briefly cached counts and bounded adjacent navigation for admin and published galleries. */
final class CatalogPagination
{
    public function paginate(Builder $query, int $perPage, int $siteId, string $id, ?string $time = null): CatalogPaginator
    {
        $page = max(1, Paginator::resolveCurrentPage());
        $scope = Json::hash(['database' => config('database.connections.pgsql.database'),
            'content_epoch' => (int) DB::table('sites')->where('id', $siteId)->value('public_content_epoch'),
            'sql' => $query->toSql(), 'bindings' => $query->getBindings(), 'per_page' => $perPage, 'order' => [$id, $time]]);
        $total = app(PublicCatalogCache::class)->count($siteId, null, fn () => (clone $query)->count(), $scope);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $items = collect();
        if ($page <= $lastPage && $total > 0) {
            $token = request()->query('catalog_cursor');
            $boundary = CatalogPaginator::boundary(is_string($token) ? $token : null, $scope, $page);
            if ($time !== null && ! is_string($boundary['time'] ?? null)) {
                $boundary = null;
            }
            $reverse = $boundary !== null ? $boundary['direction'] === 'before' : $page > $lastPage / 2;
            if ($boundary !== null) {
                $operator = $reverse ? '>' : '<';
                if ($time === null) {
                    $query->where($id, $operator, $boundary['id']);
                } else {
                    $query->whereRaw('('.$time.','.$id.') '.$operator.' (?,?)', [$boundary['time'], $boundary['id']]);
                }
            }
            $direction = $reverse ? 'asc' : 'desc';
            if ($time !== null) {
                $query->orderBy($time, $direction);
            }
            $offset = $boundary !== null ? 0 : ($reverse ? max(0, $total - $page * $perPage) : ($page - 1) * $perPage);
            $limit = $boundary !== null ? $perPage : min($perPage, $total - ($page - 1) * $perPage);
            $items = $query->orderBy($id, $direction)->offset($offset)->limit($limit)->get();
            if ($reverse) {
                $items = $items->reverse()->values();
            }
        }

        return new CatalogPaginator($items, $total, $perPage, $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => Paginator::resolveQueryString()], $scope, $time !== null);
    }
}
