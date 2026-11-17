<?php

namespace App\Domain\Keywords;

use App\Domain\Support\Access;
use App\Domain\Support\CatalogUsageGuard;
use App\Domain\Support\Conflict;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class KeywordCatalogService
{
    public function __construct(
        private readonly PoolCatalogService $pools = new PoolCatalogService,
        private readonly KeywordService $keywords = new KeywordService,
    ) {}

    /** @return array{keyword: object, outcome: string} */
    public function create(int $poolId, array $data, int $actorId): array
    {
        return DB::transaction(function () use ($poolId, $data, $actorId): array {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $pool = DB::table('keyword_pools')->where('id', $poolId)->lockForUpdate()->first();
            $this->pools->accessiblePool($poolId, $actorId, true, false);
            if (! $pool || $pool->row_version !== (int) ($data['pool_row_version'] ?? 0)) {
                throw new Conflict('POOL_VERSION_CONFLICT');
            }
            $group = null;
            if (! empty($data['group_id'])) {
                $group = DB::table('keyword_groups')->where(['id' => $data['group_id'], 'pool_id' => $poolId])->whereNull('deleted_at')->lockForUpdate()->first();
                if (! $group) {
                    throw new InvalidArgumentException('Nhóm phải thuộc kho đã chọn và chưa bị xóa.');
                }
            }
            $row = array_intersect_key($data, array_flip(['original_text', 'role', 'locale', 'weight', 'priority', 'status', 'default_max_active_pages', 'target_url', 'eligibility_json']));
            $row += ['role' => 'primary', 'locale' => $pool->locale, 'weight' => 1, 'priority' => 0, 'status' => 'active'];
            $report = $this->keywords->import($poolId, [$row]);
            $outcome = $report['rows'][0]['status'] ?? 'error';
            if (! in_array($outcome, ['new', 'duplicate', 'restored'], true)) {
                throw new InvalidArgumentException($report['rows'][0]['error'] ?? 'Keyword không được trống.');
            }
            $keyword = DB::table('keywords')->where([
                'pool_id' => $poolId, 'role' => $row['role'], 'locale' => $row['locale'],
                'normalized_text' => KeywordService::normalize($row['original_text']),
            ])->first();
            if (! $keyword) {
                throw new Conflict('KEYWORD_CREATE_RESULT_MISSING');
            }
            if ($group && DB::table('keyword_group_members')->insertOrIgnore(['pool_id' => $poolId, 'group_id' => $group->id, 'keyword_id' => $keyword->id])) {
                DB::table('keyword_groups')->where('id', $group->id)->increment('row_version');
                if ($outcome === 'duplicate') {
                    DB::table('keyword_pools')->where('id', $poolId)->increment('row_version');
                    DB::table('system_settings')->where('id', 1)->increment('config_epoch');
                }
            }
            Access::audit('keyword.catalog.created', $actorId, $pool->owner_site_id, ['keyword_id' => $keyword->id, 'group_id' => $group?->id, 'outcome' => $outcome]);

            return ['keyword' => $keyword, 'outcome' => $outcome];
        }, 3);
    }

    public function update(int $keywordId, array $data, int $actorId): object
    {
        return DB::transaction(function () use ($keywordId, $data, $actorId): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $initial = DB::table('keywords')->find($keywordId);
            abort_unless($initial, 404);
            DB::table('keyword_pools')->where('id', $initial->pool_id)->lockForUpdate()->first();
            $this->pools->accessiblePool($initial->pool_id, $actorId, true, false);
            $keyword = DB::table('keywords')->where('id', $keywordId)->lockForUpdate()->first();
            $this->assertVersion($keyword, (int) ($data['row_version'] ?? 0));
            if ($keyword->deleted_at !== null) {
                throw new Conflict('KEYWORD_DELETED_RESTORE_FIRST');
            }

            return $this->keywords->update($keywordId, $data, $actorId);
        }, 3);
    }

    public function delete(int $keywordId, int $rowVersion, int $actorId): object
    {
        return $this->changeDeletedState($keywordId, $rowVersion, $actorId, true);
    }

    public function restore(int $keywordId, int $rowVersion, int $actorId): object
    {
        return $this->changeDeletedState($keywordId, $rowVersion, $actorId, false);
    }

    private function assertVersion(object $keyword, int $rowVersion): void
    {
        if ($keyword->row_version !== $rowVersion) {
            throw new Conflict('KEYWORD_VERSION_CONFLICT');
        }
    }

    private function changeDeletedState(int $keywordId, int $rowVersion, int $actorId, bool $deleted): object
    {
        return DB::transaction(function () use ($keywordId, $rowVersion, $actorId, $deleted): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $initial = DB::table('keywords')->find($keywordId);
            abort_unless($initial, 404);
            $pool = DB::table('keyword_pools')->where('id', $initial->pool_id)->lockForUpdate()->first();
            $this->pools->accessiblePool($initial->pool_id, $actorId, true, false);
            $keyword = DB::table('keywords')->where('id', $keywordId)->lockForUpdate()->first();
            $this->assertVersion($keyword, $rowVersion);
            if (($keyword->deleted_at !== null) === $deleted) {
                return $keyword;
            }
            if ($deleted) {
                app(CatalogUsageGuard::class)->assertUnused('keyword', $keywordId);
            }
            DB::table('keywords')->where('id', $keywordId)->update([
                'deleted_at' => $deleted ? now() : null, 'status' => $deleted ? 'archived' : 'paused',
                'row_version' => DB::raw('row_version+1'), 'updated_at' => now(),
            ]);
            DB::table('keyword_pools')->where('id', $pool->id)->increment('row_version');
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            Access::audit($deleted ? 'keyword.deleted' : 'keyword.restored', $actorId, $pool->owner_site_id, ['keyword_id' => $keywordId]);

            return DB::table('keywords')->find($keywordId);
        }, 3);
    }
}
