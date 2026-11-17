<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

final class QuickApply
{
    /**
     * Accept one explicit page selection using the normal durable publishing
     * pipeline. Errors stay visible in the batch; no failed proposal is replaced.
     * At most one homepage or single-page item runs inline after commits.
     */
    public function apply(int $siteId, array $pageIds, int $actorId, string $idempotencyKey, array $options = []): object
    {
        Access::site($actorId, $siteId, true);
        abort_unless(DB::table('sites')->where('id', $siteId)->exists(), 404);
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('Cần mã thao tác hợp lệ.');
        }
        if (count($pageIds) > 10000) {
            throw new \InvalidArgumentException('Mỗi lượt chọn thủ công tối đa 10.000 trang. Đổi giao diện website được xử lý riêng theo từng phần.');
        }
        $ids = [];
        foreach ($pageIds as $pageId) {
            if (! is_int($pageId) && ! is_string($pageId)) {
                throw new \InvalidArgumentException('ID trang phải là số nguyên dương.');
            }
            $id = filter_var($pageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new \InvalidArgumentException('ID trang phải là số nguyên dương.');
            }
            $ids[$id] = $id;
        }
        $pages = DB::table('pages')->where('site_id', $siteId)->whereIn('id', array_values($ids))->orderBy('id')->get(['id', 'status']);
        abort_unless($pages->count() === count($ids), 403);
        $eligibleIds = $pages->whereNotIn('status', ['archived', 'gone'])->pluck('id')->all();
        if ($eligibleIds === []) {
            throw new \InvalidArgumentException('Không có trang hợp lệ để áp dụng. Trang lưu trữ hoặc đã gỡ không được tự khôi phục.');
        }

        $batch = DB::transaction(function () use ($siteId, $eligibleIds, $actorId, $idempotencyKey, $options): object {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['tram:quick-apply:'.$actorId.':'.$idempotencyKey]);

            return app(Planner::class)->preview($siteId, $eligibleIds, $actorId, 'publish', array_replace($options, ['idempotency_key' => $idempotencyKey]));
        });
        $items = DB::table('publish_batch_items')->where('batch_id', $batch->id)->get(['id', 'status']);
        if (! $batch->accepted_at && $items->where('status', 'pending')->isEmpty()
            && ($items->where('status', 'unchanged')->isEmpty() || $items->whereNotIn('status', ['pending', 'unchanged'])->isNotEmpty())) {
            return $batch;
        }
        if (! $batch->accepted_at && $batch->status !== 'ready') {
            return $batch;
        }

        $workflow = Json::decode($batch->options_json)['origin'] ?? null;
        if ($items->count() > 1 && (! $batch->accepted_at || $workflow === 'quick_apply')) {
            $eventId = app(QuickApplyWorkflow::class)->accept($batch, $actorId);
            if (! (Json::decode($batch->options_json)['presentation_only'] ?? false)) {
                app(CatalogRefresh::class)->scheduleForBatch($siteId, (int) $batch->id, $actorId);
            }
            if (DB::transactionLevel() === 0) {
                $home = DB::table('publish_batch_items as item')->join('pages as page', 'page.id', '=', 'item.page_id')
                    ->where('item.batch_id', $batch->id)->where('item.status', 'pending')->where('page.page_type', 'home')->value('item.id');
                if ($home !== null) {
                    app(QuickApplyWorkflow::class)->process($eventId, (int) $home);
                }
            }

            return DB::table('publish_batches')->find($batch->id);
        }

        app(Publisher::class)->accept($batch->id, $actorId);
        if (! (Json::decode($batch->options_json)['presentation_only'] ?? false)) {
            app(CatalogRefresh::class)->scheduleForBatch($siteId, (int) $batch->id, $actorId);
        }
        if (DB::transactionLevel() === 0) {
            $pending = DB::table('publish_batch_items as item')->join('pages as page', 'page.id', '=', 'item.page_id')
                ->where('item.batch_id', $batch->id)->where('item.accepted_for_publish', true)->where('item.status', 'pending');
            $inline = (clone $pending)->where('page.page_type', 'home')->orderBy('item.page_id')->value('item.id');
            if ($inline === null && $items->count() === 1) {
                $inline = $pending->value('item.id');
            }
            if ($inline !== null) {
                try {
                    app(Publisher::class)->publishItem((int) $inline);
                } catch (Conflict $exception) {
                    if ($exception->reason !== 'LEASE_BUSY') {
                        throw $exception;
                    }
                }
            }
        }

        return DB::table('publish_batches')->find($batch->id);
    }
}
