<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, bounded selections. Draft changes never mutate a publication or assignment. */
final class PageBulkService
{
    public const LIMIT = 10000;

    private const FIELDS = ['theme_id', 'template_id', 'category_id', 'assignment_locked', 'publish_locked', 'noindex'];

    private const TYPES = ['home', 'category', 'movie', 'novel', 'episode', 'chapter', 'editorial'];

    public function inspect(array $selection, int $actorId, string $intent = 'edit', ?string $operationId = null, array $changes = []): array
    {
        $this->intent($intent);
        $selection = $this->normalizeSelection($selection);
        $changes = $this->normalizeChanges($changes);
        if ($intent === 'publish' && $changes) {
            throw new \InvalidArgumentException('Preview xuất bản không sửa metadata nháp.');
        }
        $operationId ??= (string) Str::uuid();
        $this->uuid($operationId);
        $requestHash = Json::hash([$selection, $intent, $changes]);

        return DB::transaction(function () use ($selection, $actorId, $intent, $operationId, $changes, $requestHash) {
            $this->systemLock();
            abort_unless(DB::table('users')->where('id', $actorId)->exists(), 403);
            // A stable operation UUID is also the request idempotency key.
            DB::table('page_bulk_operations')->insertOrIgnore(['id' => $operationId, 'actor_id' => $actorId, 'intent' => $intent,
                'selection_json' => Json::encode($selection), 'selected_json' => '{}', 'fingerprint' => str_repeat('0', 64),
                'payload_hash' => $requestHash, 'changes_json' => Json::encode($changes), 'result_json' => '{}',
                'status' => 'inspected', 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
            $operation = $this->operation($operationId, $actorId, true);
            if ($operation->payload_hash !== $requestHash) {
                throw new Conflict('IDEMPOTENCY_PAYLOAD_CONFLICT');
            }
            if ($operation->fingerprint !== str_repeat('0', 64)) {
                $this->authorizeSites($actorId, Json::decode($operation->selected_json)['scope_site_ids'], true);

                return $this->present($operation);
            }
            $selected = $this->selectLocked($selection, $actorId, $intent);
            $this->validateTargets($changes, $selected);
            $fingerprint = $this->fingerprint($selection, $intent, $changes, $selected);
            DB::table('page_bulk_operations')->where('id', $operationId)->update(['selected_json' => Json::encode($selected), 'fingerprint' => $fingerprint]);

            return $this->present($this->operation($operationId, $actorId));
        }, 3);
    }

    public function report(string $operationId, int $actorId): array
    {
        $operation = $this->operation($operationId, $actorId);
        $selected = Json::decode($operation->selected_json);
        $this->authorizeSites($actorId, $selected['scope_site_ids'] ?? [], false);

        return $this->present($operation);
    }

    public function apply(string $operationId, string $fingerprint, array $changes, int $actorId): array
    {
        $changes = $this->normalizeChanges($changes);

        return DB::transaction(function () use ($operationId, $fingerprint, $changes, $actorId) {
            $this->systemLock();
            $operation = $this->operation($operationId, $actorId, true);
            if ($operation->intent !== 'edit') {
                throw new Conflict('BULK_INTENT_CONFLICT');
            }
            if (! hash_equals($operation->fingerprint, $fingerprint)) {
                throw new Conflict('BULK_FINGERPRINT_CONFLICT');
            }
            if (Json::hash(Json::decode($operation->changes_json)) !== Json::hash($changes)) {
                throw new Conflict('BULK_CHANGES_CONFLICT');
            }
            $frozen = Json::decode($operation->selected_json);
            $this->authorizeSites($actorId, $frozen['scope_site_ids'], true);
            if ($operation->status === 'applied') {
                return Json::decode($operation->result_json);
            }
            $selected = $this->checkSelection($operation, $actorId);
            $this->validateTargets($changes, $selected);
            $updated = 0;
            $siteCounts = [];
            foreach ($selected['rows'] as $page) {
                $update = [];
                foreach ($changes as $field => $value) {
                    if ($page[$field] !== $value) {
                        $update[$field] = $value;
                    }
                }
                if ($update) {
                    $affected = DB::table('pages')->where('id', $page['id'])->where('row_version', $page['row_version'])
                        ->update($update + ['row_version' => $page['row_version'] + 1, 'updated_at' => now()]);
                    if ($affected !== 1) {
                        throw new Conflict('PAGE_VERSION_CONFLICT');
                    }
                    $updated++;
                    $siteCounts[$page['site_id']] = ($siteCounts[$page['site_id']] ?? 0) + 1;
                }
            }
            $result = ['id' => $operationId, 'status' => 'applied', 'total' => count($selected['rows']), 'updated' => $updated,
                'unchanged' => count($selected['rows']) - $updated, 'site_ids' => $this->pageSiteIds($selected),
                'groups' => array_map(fn ($site) => ['site_id' => $site, 'updated' => $siteCounts[$site] ?? 0], $this->pageSiteIds($selected)),
                'applied_at' => now()->toIso8601String()];
            DB::table('page_bulk_operations')->where('id', $operationId)->update(['status' => 'applied', 'result_json' => Json::encode($result), 'updated_at' => now()]);
            Access::audit('pages.bulk.draft.applied', $actorId, null, ['operation_id' => $operationId, 'changes' => $changes, 'result' => $result]);

            return Json::decode(DB::table('page_bulk_operations')->where('id', $operationId)->value('result_json'));
        }, 3);
    }

    /** Use inside the campaign's transaction to retain locks until its selection is persisted. */
    public function resolve(array $selection, int $actorId, string $intent = 'publish'): array
    {
        $this->intent($intent);
        $selection = $this->normalizeSelection($selection);

        return DB::transaction(function () use ($selection, $actorId, $intent) {
            $this->systemLock();
            $selected = $this->selectLocked($selection, $actorId, $intent);

            return $this->resolved($selected, $this->fingerprint($selection, $intent, [], $selected));
        }, 3);
    }

    public function resolveOperation(string $operationId, string $fingerprint, int $actorId): array
    {
        return DB::transaction(function () use ($operationId, $fingerprint, $actorId) {
            $this->systemLock();
            $operation = $this->operation($operationId, $actorId, true);
            if ($operation->intent !== 'publish' || ! hash_equals($operation->fingerprint, $fingerprint)) {
                throw new Conflict('BULK_FINGERPRINT_CONFLICT');
            }

            return $this->resolved($this->checkSelection($operation, $actorId), $fingerprint);
        }, 3);
    }

    private function checkSelection(object $operation, int $actorId): array
    {
        if (CarbonImmutable::parse($operation->expires_at)->isPast()) {
            throw new Conflict('BULK_PREVIEW_EXPIRED');
        }
        $selection = Json::decode($operation->selection_json);
        $changes = Json::decode($operation->changes_json);
        try {
            $selected = $this->selectLocked($selection, $actorId, $operation->intent);
        } catch (\InvalidArgumentException $e) {
            throw new Conflict('BULK_SELECTION_STALE', $e->getMessage());
        }
        if (! hash_equals($operation->fingerprint, $this->fingerprint($selection, $operation->intent, $changes, $selected))) {
            throw new Conflict('BULK_SELECTION_STALE');
        }

        return $selected;
    }

    private function selectLocked(array $selection, int $actorId, string $intent): array
    {
        $user = DB::table('users')->find($actorId);
        abort_unless($user, 403);
        if ($selection['mode'] === 'ids') {
            $sites = DB::table('pages')->whereIn('id', $selection['page_ids'])->distinct()->orderBy('site_id')->pluck('site_id')->map(fn ($id) => (int) $id)->all();
        } elseif (($selection['scope'] ?? null) === 'all_eligible') {
            $sites = DB::table('sites')->when(! $user->is_global_admin, fn ($q) => $q->whereIn('id', DB::table('site_memberships')->where('user_id', $actorId)->whereIn('role', ['owner', 'operator'])->select('site_id')))
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $sites = $selection['site_ids'];
        }
        $this->authorizeSites($actorId, $sites, true);
        // Exclusive site locks stop importer/page-editor phantoms for all-matching CAS.
        $siteRows = DB::table('sites')->whereIn('id', $sites)->orderBy('id')->lockForUpdate()->get();
        abort_unless($siteRows->count() === count($sites), 403);
        $this->authorizeSites($actorId, $sites, true);
        $query = DB::table('pages')->whereIn('site_id', $sites);
        if ($selection['mode'] === 'ids') {
            $query->whereIn('id', $selection['page_ids']);
        } else {
            $this->filter($query, $selection['filters']);
        }
        $pages = $query->orderBy('id')->limit(self::LIMIT + 1)->lockForUpdate()->get();
        if ($pages->count() > self::LIMIT) {
            throw new \InvalidArgumentException('Tối đa 10.000 trang. Hãy thu hẹp bộ lọc.');
        }
        if ($selection['mode'] === 'ids') {
            abort_unless($pages->count() === count($selection['page_ids']), 403);
        }
        if ($pages->isEmpty()) {
            throw new \InvalidArgumentException('Không có trang phù hợp; cần chọn ít nhất một trang.');
        }
        $rows = [];
        foreach ($pages as $page) {
            $row = ['id' => (int) $page->id, 'site_id' => (int) $page->site_id, 'row_version' => (int) $page->row_version,
                'path' => $page->canonical_path, 'page_type' => $page->page_type, 'status' => $page->status,
                'current_publication_id' => $page->current_publication_id === null ? null : (int) $page->current_publication_id,
                'content_item_id' => $page->content_item_id === null ? null : (int) $page->content_item_id,
                'working_content_revision_id' => $page->working_content_revision_id === null ? null : (int) $page->working_content_revision_id,
                'row_hash' => Json::hash((array) $page)];
            foreach (self::FIELDS as $field) {
                $row[$field] = str_ends_with($field, '_id') ? ($page->$field === null ? null : (int) $page->$field) : (bool) $page->$field;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'scope_site_ids' => $sites,
            'site_rows' => $siteRows->map(fn ($site) => ['id' => (int) $site->id, 'content_type' => $site->content_type, 'row_version' => (int) $site->row_version,
                'config_epoch' => (int) $site->config_epoch, 'row_hash' => Json::hash((array) $site)])->all(),
            'global_epoch' => (int) DB::table('system_settings')->where('id', 1)->value('config_epoch')];
    }

    private function normalizeSelection(array $selection): array
    {
        $mode = $selection['mode'] ?? '';
        if (! in_array($mode, ['ids', 'matching'], true)) {
            throw new \InvalidArgumentException('Chọn mode ids hoặc matching một cách rõ ràng.');
        }
        if ($mode === 'ids') {
            if (array_diff(array_keys($selection), ['mode', 'page_ids'])) {
                throw new \InvalidArgumentException('Selection IDs không nhận bộ lọc hoặc phạm vi ngầm.');
            }

            return ['mode' => 'ids', 'page_ids' => $this->ids($selection['page_ids'] ?? null)];
        }
        if (array_diff(array_keys($selection), ['mode', 'site_ids', 'scope', 'filters'])) {
            throw new \InvalidArgumentException('Selection filter có trường không được hỗ trợ.');
        }
        $result = ['mode' => 'matching'];
        if (($selection['scope'] ?? null) === 'all_eligible') {
            if (! empty($selection['site_ids'])) {
                throw new \InvalidArgumentException('Chọn site_ids hoặc all_eligible, không chọn cả hai.');
            }
            $result['scope'] = 'all_eligible';
        } else {
            if (array_key_exists('scope', $selection)) {
                throw new \InvalidArgumentException('Scope không hợp lệ.');
            }
            $result['site_ids'] = $this->ids($selection['site_ids'] ?? null);
        }
        $filters = $selection['filters'] ?? [];
        if (! is_array($filters) || array_diff(array_keys($filters), ['q', 'page_type', 'status', 'category_id', 'theme_id', 'template_id'])) {
            throw new \InvalidArgumentException('Bộ lọc không hợp lệ.');
        }
        $normalized = [];
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($field === 'q') {
                if (! is_string($value) || mb_strlen($value) > 200 || preg_match('/[\x00-\x1f]/u', $value)) {
                    throw new \InvalidArgumentException('Từ tìm kiếm phải là chuỗi tối đa 200 ký tự.');
                }
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            } elseif ($field === 'page_type') {
                if (! in_array($value, self::TYPES, true)) {
                    throw new \InvalidArgumentException('Loại trang không hợp lệ.');
                }
            } elseif ($field === 'status') {
                if (! in_array($value, ['draft', 'live', 'archived', 'gone'], true)) {
                    throw new \InvalidArgumentException('Trạng thái trang không hợp lệ.');
                }
            } else {
                $value = $this->positiveId($value);
            }
            $normalized[$field] = $value;
        }
        ksort($normalized);
        $result['filters'] = $normalized;

        return $result;
    }

    private function normalizeChanges(array $changes): array
    {
        if (array_diff(array_keys($changes), self::FIELDS)) {
            throw new \InvalidArgumentException('Chỉ được sửa theme/template/category và ba cờ nháp.');
        }
        $result = [];
        foreach ($changes as $field => $change) {
            if (! is_array($change) || array_diff(array_keys($change), ['mode', 'value']) || ! in_array($change['mode'] ?? null, ['keep', 'set'], true)) {
                throw new \InvalidArgumentException('Mỗi thay đổi phải có mode keep hoặc set.');
            }
            if ($change['mode'] === 'keep') {
                continue;
            }
            if (! array_key_exists('value', $change)) {
                throw new \InvalidArgumentException('Set cần có value rõ ràng.');
            }
            $value = $change['value'];
            if (str_ends_with($field, '_id')) {
                $value = $value === null ? null : $this->positiveId($value);
            } elseif (! is_bool($value)) {
                throw new \InvalidArgumentException('Cờ nháp cần true hoặc false; null không có nghĩa inherit.');
            }
            $result[$field] = $value;
        }
        ksort($result);

        return $result;
    }

    private function validateTargets(array $changes, array $selected): void
    {
        $siteIds = $this->pageSiteIds($selected);
        if (isset($changes['category_id'])) {
            if (count($siteIds) !== 1 || ! DB::table('categories')->where('id', $changes['category_id'])->where('site_id', $siteIds[0])->exists()) {
                throw new \InvalidArgumentException('Category phải thuộc một website duy nhất trong tập trang đã chọn.');
            }
        }
        if (isset($changes['template_id']) && ! DB::table('templates')->where('id', $changes['template_id'])->where('status', 'active')->exists()) {
            throw new \InvalidArgumentException('Template phải đang active.');
        }
        if (isset($changes['theme_id'])) {
            $theme = DB::table('movie_themes')->find($changes['theme_id']);
            if (! $theme || collect($selected['site_rows'])->whereIn('id', $siteIds)->contains(fn ($site) => $site['content_type'] !== 'movie')) {
                throw new \InvalidArgumentException('Giao diện phim cần theme hợp lệ và website phim.');
            }
            if ($theme->status !== 'active' && collect($selected['rows'])->contains(fn ($page) => $page['theme_id'] !== $changes['theme_id'])) {
                throw new \InvalidArgumentException('Theme đã archive chỉ được giữ khi là theme hiện tại của mọi trang.');
            }
        }
    }

    private function filter(Builder $query, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if ($field === 'q') {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
                $query->where(fn ($q) => $q->where('canonical_path', 'ilike', $pattern)->orWhereExists(fn ($revision) => $revision->selectRaw('1')->from('content_revisions')
                    ->whereColumn('content_revisions.id', 'pages.working_content_revision_id')->whereRaw("payload_json->>'title' ILIKE ?", [$pattern])));
            } else {
                $query->where($field, $value);
            }
        }
    }

    private function fingerprint(array $selection, string $intent, array $changes, array $selected): string
    {
        return Json::hash([$selection, $intent, $changes, $selected]);
    }

    private function resolved(array $selected, string $fingerprint): array
    {
        $groups = [];
        foreach ($selected['rows'] as $row) {
            $groups[$row['site_id']][] = $row['id'];
        }
        ksort($groups, SORT_NUMERIC);

        return ['page_ids' => array_column($selected['rows'], 'id'), 'site_ids' => $this->pageSiteIds($selected), 'groups' => $groups,
            'fingerprint' => $fingerprint, 'rows' => $selected['rows']];
    }

    private function present(object $operation): array
    {
        $selected = Json::decode($operation->selected_json);
        $changes = Json::decode($operation->changes_json);
        $groups = [];
        foreach ($selected['rows'] ?? [] as $row) {
            $groups[$row['site_id']] = ($groups[$row['site_id']] ?? 0) + 1;
        }
        ksort($groups, SORT_NUMERIC);
        $sample = array_map(function ($row) use ($changes) {
            $before = array_intersect_key($row, array_flip(self::FIELDS));

            return ['id' => $row['id'], 'site_id' => $row['site_id'], 'path' => $row['path'], 'row_version' => $row['row_version'], 'before' => $before, 'after' => array_replace($before, $changes)];
        }, array_slice($selected['rows'] ?? [], 0, 50));

        return ['id' => $operation->id, 'fingerprint' => $operation->fingerprint, 'intent' => $operation->intent, 'status' => $operation->status,
            'selection' => Json::decode($operation->selection_json), 'page_ids' => array_column($selected['rows'] ?? [], 'id'), 'changes' => $changes, 'total' => count($selected['rows'] ?? []),
            'site_ids' => array_keys($groups), 'groups' => array_map(fn ($site, $count) => ['site_id' => $site, 'count' => $count], array_keys($groups), array_values($groups)),
            'sample' => $sample, 'expires_at' => CarbonImmutable::parse($operation->expires_at)->toIso8601String(), 'result' => Json::decode($operation->result_json)];
    }

    private function operation(string $id, int $actorId, bool $lock = false): object
    {
        $this->uuid($id);
        $query = DB::table('page_bulk_operations')->where('id', $id);
        $operation = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($operation, 404);
        abort_unless((int) $operation->actor_id === $actorId && DB::table('users')->where('id', $actorId)->exists(), 403);

        return $operation;
    }

    private function authorizeSites(int $actorId, array $siteIds, bool $write): void
    {
        foreach ($siteIds as $siteId) {
            Access::site($actorId, $siteId, $write);
        }
    }

    private function pageSiteIds(array $selected): array
    {
        $ids = array_values(array_unique(array_column($selected['rows'], 'site_id')));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function ids(mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || ! $ids || count($ids) > self::LIMIT) {
            throw new \InvalidArgumentException('Cần danh sách 1–10.000 ID rõ ràng.');
        }
        $ids = array_values(array_unique(array_map(fn ($id) => $this->positiveId($id), $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function positiveId(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value))) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('ID phải là số nguyên dương.');
        }

        return (int) $value;
    }

    private function uuid(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new \InvalidArgumentException('Operation ID phải là UUID.');
        }
    }

    private function intent(string $intent): void
    {
        if (! in_array($intent, ['edit', 'publish'], true)) {
            throw new \InvalidArgumentException('Intent phải là edit hoặc publish.');
        }
    }

    private function systemLock(): void
    {
        DB::table('system_settings')->where('id', 1)->sharedLock()->first();
    }
}
