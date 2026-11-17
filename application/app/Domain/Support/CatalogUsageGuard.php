<?php

namespace App\Domain\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Bounded inbound-reference checks; callers own authorization and mutation locks. */
final class CatalogUsageGuard
{
    private const TABLES = ['movie' => 'content_items', 'template' => 'templates', 'theme' => 'movie_themes',
        'preset' => 'website_presets', 'category' => 'categories', 'rule' => 'rules', 'keyword' => 'keywords',
        'pool' => 'keyword_pools', 'group' => 'keyword_groups', 'source' => 'source_connectors', 'suffix' => 'url_suffixes'];

    /** Shared across freshly resolved guard instances; always unwound by finally. */
    private static array $operations = [];

    public function withinOperation(?string $id, callable $callback): mixed
    {
        self::$operations[] = $id;
        try {
            return $callback();
        } finally {
            array_pop(self::$operations);
        }
    }

    public function lock(string $kind, int $id): object
    {
        return DB::table($this->table($kind))->where('id', $id)->when($kind === 'movie', fn (Builder $q) => $q->where('kind', 'movie'))
            ->lockForUpdate()->first() ?? abort(404);
    }

    /** count is a lower bound, capped at seven; samples contain at most six rows. */
    public function report(string $kind, int $id, ?string $ignoreOperationId = null): array
    {
        $entity = DB::table($this->table($kind))->where('id', $id)->when($kind === 'movie', fn (Builder $q) => $q->where('kind', 'movie'))
            ->first($kind === 'suffix' ? ['id', 'suffix'] : ['id']);
        abort_unless($entity, 404);
        $ignoreOperationId ??= self::$operations === [] ? null : end(self::$operations);
        $reasons = [];
        $this->direct($kind, $id, $reasons, $ignoreOperationId);
        $this->configuration($kind, $id, $reasons);
        $this->history($kind, $id, $reasons);
        if ($kind === 'suffix') {
            $this->suffix($entity->suffix, $reasons);
        }
        if (Schema::hasTable('catalog_delete_items')) {
            $pending = DB::table('catalog_delete_items as r')->where(['r.kind' => $kind, 'r.item_id' => $id])->whereIn('r.status', ['pending', 'running']);
            if ($ignoreOperationId !== null) {
                $pending->where('r.operation_id', '<>', $ignoreOperationId);
            }
            $this->add($reasons, 'Thao tác hàng loạt khác đang chờ hoặc đang chạy', $pending, ['r.id as ref_id', 'r.operation_id'],
                fn ($row) => ['label' => 'Thao tác '.$row->operation_id.' · mục #'.$row->ref_id]);
        }

        return ['can_delete' => $reasons === [], 'reasons' => $reasons];
    }

    public function assertUnused(string $kind, int $id, ?string $ignoreOperationId = null): void
    {
        $usage = $this->report($kind, $id, $ignoreOperationId);
        if (! $usage['can_delete']) {
            throw new CatalogItemInUse($kind, $id, $usage);
        }
    }

    private function direct(string $kind, int $id, array &$reasons, ?string $ignoreOperationId): void
    {
        if ($kind === 'movie') {
            $this->pages($reasons, 'Trang website còn giữ phim chính hoặc fanmulu ở mọi trạng thái', DB::table('pages as r')->where('r.content_item_id', $id));
            $this->publications($reasons, 'Lịch sử website còn giữ phim', DB::table('page_publications as r')->where('r.content_item_id', $id));
            $this->bindings($reasons, DB::table('keyword_bindings as r')->where('r.content_item_id', $id));
            $query = DB::table('movie_library_items as r')->where('r.content_item_id', $id)->whereIn('r.status', ['pending', 'running', 'failed']);
            if ($ignoreOperationId !== null) {
                $query->where('r.operation_id', '<>', $ignoreOperationId);
            }
            $this->add($reasons, 'Công việc Kho phim còn chờ xử lý hoặc thử lại', $query, ['r.id as ref_id', 'r.operation_id'],
                fn ($r) => ['label' => 'Công việc '.$r->operation_id, 'url' => '/admin/movies/operations/'.$r->operation_id]);
        } elseif (in_array($kind, ['template', 'theme'], true)) {
            $field = $kind === 'template' ? 'template_id' : 'theme_id';
            $this->sites($reasons, 'Website còn chọn mục này, kể cả website đã dừng hoặc lưu trữ', DB::table('sites as r')->where('r.'.$field, $id));
            $this->pages($reasons, 'Trang riêng còn chọn mục này ở mọi trạng thái', DB::table('pages as r')->where('r.'.$field, $id));
            if ($kind === 'theme') {
                $this->sites($reasons, 'Giao diện đang phục vụ được website lưu riêng', $this->json(DB::table('sites as r'), 'r.live_appearance_json', ['$.theme.id'], $id));
                $this->add($reasons, 'Lượt sửa website còn giữ giao diện', $this->json(DB::table('website_bulk_edit_operations as r'), 'r.changes_json', ['$.theme_id'], $id));
            }
        } elseif ($kind === 'preset') {
            $this->add($reasons, 'Lượt tạo website hoặc website đã tạo còn giữ bộ cấu hình', DB::table('website_create_items as r')->where('r.preset_id', $id), ['r.id as ref_id', 'r.domain as ref_label', 'r.site_id']);
        } elseif ($kind === 'category') {
            $this->add($reasons, 'Danh mục con còn thuộc danh mục này', DB::table('categories as r')->where('r.parent_id', $id), ['r.id as ref_id', 'r.site_id', 'r.name as ref_label']);
            $this->pages($reasons, 'Trang website còn gắn danh mục ở mọi trạng thái', DB::table('pages as r')->where('r.category_id', $id));
            $this->add($reasons, 'Quy tắc còn gắn danh mục', DB::table('rules as r')->where('r.category_id', $id), ['r.id as ref_id', 'r.site_id', 'r.slot as ref_label']);
            $this->bindings($reasons, DB::table('keyword_bindings as r')->where('r.category_id', $id));
            $this->add($reasons, 'Nguồn còn ghép vào danh mục, kể cả liên kết đã dừng', $this->json(CatalogAvailability::filter(DB::table('source_site_bindings as r'), 'source_site_bindings', 'r'), 'r.mapping_json', ['$.category_map.*'], $id), ['r.id as ref_id', 'r.site_id']);
        } elseif (in_array($kind, ['keyword', 'pool', 'group'], true)) {
            if ($kind === 'keyword') {
                $this->add($reasons, 'Keyword còn thuộc nhóm', DB::table('keyword_group_members as r')->where('r.keyword_id', $id), ['r.group_id as ref_id']);
                $this->add($reasons, 'Website còn đặt quota riêng cho keyword', DB::table('site_keyword_limits as r')->where('r.keyword_id', $id), ['r.keyword_id as ref_id', 'r.site_id']);
            } else {
                $this->bindings($reasons, DB::table('keyword_bindings as r')->where($kind === 'group' ? 'r.group_id' : 'r.pool_id', $id));
            }
            if ($kind !== 'group') {
                $keys = DB::table('keywords')->where($kind === 'pool' ? 'pool_id' : 'id', $id)->select('id');
                $this->add($reasons, 'Lịch sử website còn giữ keyword đã gán', DB::table('page_keyword_assignments as r')->whereIn('r.keyword_id', clone $keys), ['r.id as ref_id', 'r.page_id', 'r.publication_id'],
                    fn ($r) => ['label' => 'Trang #'.$r->page_id.' · bản #'.$r->publication_id, 'url' => '/admin/pages/'.$r->page_id]);
                $this->add($reasons, 'Claim keyword còn được giữ hoặc lưu trong lịch sử', DB::table('keyword_capacity_claims as r')->whereIn('r.keyword_id', clone $keys), ['r.id as ref_id', 'r.site_id']);
                $this->add($reasons, 'URL fanmulu đã cấp còn giữ keyword', DB::table('fanmulu_url_registry as r')->whereIn('r.keyword_id', clone $keys), ['r.id as ref_id', 'r.site_id', 'r.path as ref_label']);
                if ($kind === 'pool') {
                    $this->add($reasons, 'Đợt nhập keyword còn chờ xử lý hoặc thử lại', DB::table('keyword_import_runs as r')->where('r.pool_id', $id)->whereNotIn('r.status', ['succeeded', 'partial']));
                }
            }
        } elseif ($kind === 'source') {
            $this->add($reasons, 'Website còn gắn nguồn, kể cả liên kết đã dừng hoặc lưu trữ', CatalogAvailability::filter(DB::table('source_site_bindings as r'), 'source_site_bindings', 'r')->where('r.connector_id', $id), ['r.id as ref_id', 'r.site_id']);
            $contentReason = count($reasons);
            $this->add($reasons, 'Kho nội dung còn giữ dữ liệu và định danh của nguồn', DB::table('content_items as r')
                ->leftJoin('content_revisions as source_revision', function ($join): void {
                    $join->on('source_revision.id', '=', 'r.current_revision_id')->on('source_revision.content_item_id', '=', 'r.id');
                })->where('r.source_connector_id', $id)
                ->when(Schema::hasColumn('content_items', 'deleted_at'), fn (Builder $q) => $q->whereNull('r.deleted_at')),
                ['r.id as ref_id', 'r.kind', DB::raw("source_revision.payload_json->>'title' AS ref_label")],
                fn ($row): array => ['label' => (trim((string) ($row->ref_label ?? '')) !== '' ? $row->ref_label : 'Nội dung').' · #'.$row->ref_id]
                    + ($row->kind === 'movie' ? ['url' => '/admin/deletions/movie/'.$row->ref_id] : []));
            if (isset($reasons[$contentReason])) {
                $reasons[$contentReason]['url'] = '/admin/movies?source_id='.$id.'&library_status=all';
                $reasons[$contentReason]['link_label'] = 'Mở kho phim của nguồn';
            }
            $this->add($reasons, 'Đợt lấy dữ liệu còn chờ, đang chạy hoặc có thể tiếp tục', DB::table('ingest_runs as r')->where('r.connector_id', $id)->whereNotIn('r.status', ['succeeded', 'partial', 'cancelled']), ['r.id as ref_id', 'r.status as ref_label']);
        } elseif ($kind === 'rule') {
            $this->add($reasons, 'Quy tắc đang bật và còn áp dụng cho website hoặc toàn hệ thống', DB::table('rules as r')->join('rule_versions as v', 'v.id', '=', 'r.current_version_id')
                ->where('r.id', $id)->where('r.status', 'active')->whereNotNull('v.config_json')->whereRaw("v.config_json <> 'null'::jsonb"), ['r.id as ref_id', 'r.site_id', 'r.scope_type as ref_label']);
        }
    }

    private function configuration(string $kind, int $id, array &$reasons): void
    {
        $definitions = match ($kind) {
            'template' => ['$.template_id'], 'theme' => ['$.theme_id'], 'source' => ['$.source_ids[*]'],
            'pool' => ['$.pool_ids[*]', '$.fanmulu.display_name_pool_ids[*]', '$.presentation.fanmulu.display_name_pool_ids[*]'], default => [],
        };
        if ($definitions !== []) {
            $this->add($reasons, 'Phiên bản bộ cấu hình khác còn tham chiếu mục này', $this->json(DB::table('website_preset_versions as r'), 'r.definition_json', $definitions, $id), ['r.id as ref_id', 'r.preset_id'],
                fn ($r) => ['label' => 'Bộ cấu hình #'.$r->preset_id.' · phiên bản #'.$r->ref_id, 'url' => '/admin/site-presets/'.$r->preset_id.'/edit']);
            $this->add($reasons, 'Cấu hình đã lưu trong lượt tạo website', $this->json(DB::table('website_create_items as r'), 'r.definition_json', $definitions, $id), ['r.id as ref_id', 'r.domain as ref_label', 'r.site_id']);
            $this->add($reasons, 'Danh sách bộ cấu hình đã chọn trong lượt tạo website', $this->json(DB::table('website_create_operations as r'), 'r.preset_manifest_json',
                array_map(fn ($path) => '$[*].definition'.substr($path, 1), $definitions), $id));
        } elseif ($kind === 'preset') {
            $this->add($reasons, 'Danh sách bộ cấu hình đã chọn trong lượt tạo website', $this->json(DB::table('website_create_operations as r'), 'r.preset_manifest_json', ['$[*].id'], $id));
        }
        $allocations = match ($kind) {
            'pool' => ['$.pool_ids[*]', '$.fallback_pool_ids[*]'], 'keyword' => ['$.keyword_ids[*]', '$.manual_ids[*]'], default => []
        };
        if ($allocations !== []) {
            $this->add($reasons, 'Phiên bản quy tắc còn tham chiếu mục này', $this->json(DB::table('rule_versions as r'), 'r.config_json', $allocations, $id), ['r.id as ref_id', 'r.rule_id'],
                fn ($r) => ['label' => 'Quy tắc #'.$r->rule_id.' · phiên bản #'.$r->ref_id, 'url' => '/admin/rules?rule_id='.$r->rule_id]);
        }
        if ($kind === 'pool') {
            $this->sites($reasons, 'Website còn chọn kho tên fanmulu riêng', $this->json(DB::table('sites as r'), 'r.presentation_json', ['$.fanmulu.display_name_pool_ids[*]'], $id));
            $this->sites($reasons, 'Cấu hình đang phục vụ còn chọn kho tên fanmulu riêng', $this->json(DB::table('sites as r'), 'r.live_appearance_json', ['$.routing.fanmulu.display_name_pool_ids[*]'], $id));
        } elseif ($kind === 'keyword') {
            $this->pages($reasons, 'Trang fanmulu còn giữ keyword được chọn', $this->json(DB::table('pages as r'), 'r.input_snapshot_json', ['$.fanmulu.selected_primary.keyword_id', '$.fanmulu.selected_keyword_id'], $id));
        }
        $bulk = match ($kind) {
            'template' => ['$.template_id'], 'theme' => ['$.theme_id'], 'category' => ['$.category_id'],
            'keyword' => ['$.keyword_ids[*]', '$.manual_ids[*]', '$.allocation.*.keyword_ids[*]', '$.allocation.*.manual_ids[*]'],
            'pool' => ['$.pool_ids[*]', '$.fallback_pool_ids[*]', '$.fanmulu.display_name_pool_ids[*]'], default => [],
        };
        if ($bulk !== []) {
            foreach (['site_bulk_operations', 'page_bulk_operations'] as $table) {
                $this->add($reasons, 'Thao tác sửa hàng loạt còn lưu cấu hình tham chiếu', $this->json(DB::table($table.' as r'), 'r.changes_json', $bulk, $id));
            }
        }
        $selected = match ($kind) {
            'movie' => 'content_item_id', 'theme' => 'theme_id', 'template' => 'template_id', 'category' => 'category_id', default => null
        };
        if ($selected !== null) {
            $this->add($reasons, 'Danh sách trang đã chọn trong thao tác hàng loạt', $this->json(DB::table('page_bulk_operations as r'), 'r.selected_json', ['$.rows[*].'.$selected], $id));
        }
        if ($kind === 'theme') {
            $this->add($reasons, 'Bản đối chiếu cấu hình website trong thao tác hàng loạt', $this->json(DB::table('site_bulk_operations as r'), 'r.manifest_json', ['$.sites[*].before.theme_id', '$.sites[*].after.theme_id'], $id));
        }
    }

    private function history(string $kind, int $id, array &$reasons): void
    {
        $paths = match ($kind) {
            'theme' => ['$.theme.id', '$.resolved.theme.id'],
            'template' => ['$.seo_template_bundle.template_id', '$.resolved.seo_template_bundle.template_id'],
            'rule' => ['$.resolved.sources.*.rule_id', '$.assignments[*].selection.rule_source.rule_id'],
            'keyword' => ['$.assignments[*].keyword_id', '$.fanmulu.selected_primary.keyword_id'],
            'pool' => ['$.assignments[*].selection.pool_id', '$.resolved.slots.*.pool_ids[*]', '$.resolved.slots.*.fallback_pool_ids[*]', '$.routing.fanmulu.display_name_pool_ids[*]'],
            'category' => ['$.page.category_id', '$.category.id', '$.category.ancestor_ids[*]', '$.theme_catalog.categories[*].id', '$.theme_catalog.categories[*].ancestor_ids[*]'], default => [],
        };
        if ($paths !== []) {
            if ($kind === 'theme' && $this->indexedThemeHistoryReady()) {
                $query = $this->themeReferences(DB::table('page_publications as r'), 'r.snapshot_json', $id);
                $preview = $this->themeReferences(DB::table('publish_batch_items as r'), "r.preview_json->'snapshot'", $id);
            } else {
                $query = $this->json(DB::table('page_publications as r'), 'r.snapshot_json', $paths, $id);
                $preview = $this->json(DB::table('publish_batch_items as r'), 'r.preview_json', array_map(fn ($p) => '$.snapshot'.substr($p, 1), $paths), $id);
            }
            if ($kind === 'template') {
                $versions = DB::table('template_versions')->where('template_id', $id)->selectRaw('to_jsonb(id)');
                $query->orWhereIn(DB::raw("r.snapshot_json #> '{resolved,template_version_id}'"), clone $versions);
                $preview->orWhereIn(DB::raw("r.preview_json #> '{snapshot,resolved,template_version_id}'"), clone $versions);
            }
            $this->publications($reasons, 'Bản lưu website bất biến còn tham chiếu mục này', $query);
            $this->add($reasons, 'Kế hoạch hoặc lịch sử hàng đợi website còn tham chiếu', $preview, ['r.id as ref_id', 'r.site_id', 'r.batch_id'],
                fn ($r) => ['label' => 'Lượt #'.$r->batch_id.' · mục #'.$r->ref_id, 'site_id' => (int) $r->site_id, 'url' => '/admin/batches/'.$r->batch_id]);
        }
        if ($kind === 'category') {
            $query = DB::table('publication_category_index as r')->where(fn (Builder $q) => $q->where('r.category_id', (string) $id)->orWhere('r.legacy_category_id', (string) $id));
            $this->add($reasons, 'Chỉ mục danh mục của bản lưu website còn tham chiếu', $query->orWhere(fn (Builder $q) => $this->json($q, 'r.ancestor_ids', ['$[*]'], $id)), ['r.publication_id as ref_id', 'r.site_id']);
        } elseif ($kind === 'rule') {
            $this->add($reasons, 'Lịch sử gán keyword còn ghi nguồn quy tắc', $this->json(DB::table('page_keyword_assignments as r'), 'r.selection_json', ['$.rule_source.rule_id'], $id), ['r.id as ref_id', 'r.page_id'],
                fn ($r) => ['label' => 'Trang #'.$r->page_id, 'url' => '/admin/pages/'.$r->page_id]);
        }
    }

    private function indexedThemeHistoryReady(): bool
    {
        return (bool) DB::selectOne(<<<'SQL'
SELECT to_regprocedure('tram_catalog_theme_references(jsonb)') IS NOT NULL
 AND (SELECT count(*) FROM pg_index WHERE indisvalid AND indisready AND indexrelid IN (
  to_regclass('catalog_pub_theme_refs'),to_regclass('catalog_preview_theme_refs')
 )) = 2 AS ready
SQL)->ready;
    }

    /** Index only theme reference keys, preserving lax JSON arrays and numeric/string matching. */
    private function themeReferences(Builder $query, string $document, int $id): Builder
    {
        return $query->whereRaw("(tram_catalog_theme_references($document) @> ?::jsonb OR tram_catalog_theme_references($document) @> ?::jsonb)",
            [Json::encode([$id]), Json::encode([(string) $id])]);
    }

    private function suffix(string $suffix, array &$reasons): void
    {
        $this->sites($reasons, 'Website còn cấu hình đuôi URL', $this->json(DB::table('sites as r'), 'r.presentation_json', ['$.suffixes.*', '$.fanmulu.suffix'], $suffix));
        $this->sites($reasons, 'Cấu hình đang phục vụ còn giữ đuôi URL', $this->json(DB::table('sites as r'), 'r.live_appearance_json', ['$.routing.suffixes.*', '$.routing.fanmulu.suffix'], $suffix));
        foreach (['website_preset_versions', 'website_create_items'] as $table) {
            $this->add($reasons, 'Cấu hình tạo website còn giữ đuôi URL', $this->json(DB::table($table.' as r'), 'r.definition_json', ['$.presentation.suffixes.*', '$.fanmulu.suffix', '$.presentation.fanmulu.suffix'], $suffix));
        }
        $this->add($reasons, 'Lựa chọn ngẫu nhiên trong lượt tạo website còn giữ đuôi URL', $this->json(DB::table('website_create_operations as r'), 'r.options_json', ['$.random_attributes.suffix[*]', '$.random_attributes.fanmulu_suffix[*]'], $suffix));
        $this->add($reasons, 'URL fanmulu đã cấp còn dùng đuôi URL', DB::table('fanmulu_url_registry as r')->where('r.suffix', $suffix), ['r.id as ref_id', 'r.site_id', 'r.path as ref_label']);
        $this->pages($reasons, 'URL trang đã lưu còn dùng đuôi URL', DB::table('pages as r')->whereRaw('right(r.canonical_path, ?) = ?', [strlen($suffix), $suffix]));
        $this->publications($reasons, 'Bản lưu website còn giữ đuôi URL', $this->json(DB::table('page_publications as r'), 'r.snapshot_json', ['$.routing.suffixes.*', '$.routing.fanmulu.suffix'], $suffix));
        $this->add($reasons, 'Kế hoạch website còn giữ đuôi URL', $this->json(DB::table('publish_batch_items as r'), 'r.preview_json', ['$.snapshot.routing.suffixes.*', '$.snapshot.routing.fanmulu.suffix'], $suffix), ['r.id as ref_id', 'r.site_id']);
    }

    private function sites(array &$reasons, string $label, Builder $query): void
    {
        $this->add($reasons, $label, $query, ['r.id as ref_id'], fn ($r) => ['label' => 'Website #'.$r->ref_id, 'site_id' => (int) $r->ref_id, 'url' => '/admin/sites/'.$r->ref_id.'/presentation']);
    }

    private function pages(array &$reasons, string $label, Builder $query): void
    {
        $this->add($reasons, $label, $query, ['r.id as ref_id', 'r.site_id', 'r.canonical_path as ref_label'], fn ($r) => ['label' => 'Website #'.$r->site_id.' · '.$r->ref_label, 'site_id' => (int) $r->site_id, 'url' => '/admin/pages/'.$r->ref_id]);
    }

    private function publications(array &$reasons, string $label, Builder $query): void
    {
        $this->add($reasons, $label, $query, ['r.id as ref_id', 'r.site_id', 'r.page_id'], fn ($r) => ['label' => 'Website #'.$r->site_id.' · trang #'.$r->page_id.' · bản #'.$r->ref_id, 'site_id' => (int) $r->site_id, 'url' => '/admin/pages/'.$r->page_id]);
    }

    private function bindings(array &$reasons, Builder $query): void
    {
        $this->add($reasons, 'Website còn lưu liên kết kho/nhóm, kể cả liên kết đã dừng', $query, ['r.id as ref_id', 'r.site_id']);
    }

    private function add(array &$reasons, string $label, Builder $query, array $columns = ['r.id as ref_id'], ?callable $sample = null): void
    {
        $rows = (clone $query)->limit(7)->get($columns);
        if ($rows->isEmpty()) {
            return;
        }
        $reasons[] = ['label' => $label, 'count' => $rows->count(), 'has_more' => $rows->count() > 6,
            'samples' => $rows->take(6)->map(function ($row) use ($sample): array {
                if ($sample !== null) {
                    return $sample($row);
                }
                $result = ['label' => (isset($row->site_id) ? 'Website #'.$row->site_id.' · ' : '').($row->ref_label ?? '#'.$row->ref_id)];
                if (isset($row->site_id)) {
                    $result += ['site_id' => (int) $row->site_id, 'url' => '/admin/sites/'.$row->site_id.'/presentation'];
                }

                return $result;
            })->values()->all()];
    }

    private function json(Builder $query, string $column, array $paths, int|string $value): Builder
    {
        return $query->where(static function (Builder $part) use ($column, $paths, $value): void {
            foreach ($paths as $path) {
                $part->orWhereRaw('jsonb_path_exists('.$column.', ?::jsonpath, ?::jsonb)', [$path.' ? (@ == $v || @ == $s)', json_encode(['v' => $value, 's' => (string) $value], JSON_THROW_ON_ERROR)]);
            }
        });
    }

    private function table(string $kind): string
    {
        return self::TABLES[$kind] ?? throw new InvalidArgumentException('Loại danh mục không được hỗ trợ.');
    }
}
