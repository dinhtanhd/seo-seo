<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\SchemaAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SiteDeliverySettings
{
    public function enabled(int $siteId): bool
    {
        return (bool) config('public_on_demand.enabled', false) && $this->available()
            && DB::table('site_delivery_settings')->where('site_id', $siteId)->value('mode') === 'on_demand';
    }

    /** Exclude held automatic work before a dispatcher's LIMIT, retaining every durable event unchanged. */
    public function excludeAutomated(Builder $query, string $eventType): Builder
    {
        if (! in_array($eventType, ['ingestion.committed', 'quick-apply.requested', 'catalog-refresh.requested'], true)) {
            throw new \InvalidArgumentException('Loại sự kiện tự áp dụng không được hỗ trợ.');
        }
        if (! config('public_on_demand.enabled', false) || ! $this->available()) {
            return $query;
        }
        if ($eventType === 'ingestion.committed') {
            return $query->whereNotExists(function (Builder $held): void {
                $held->selectRaw('1')->from('site_delivery_settings as delivery_hold')->where('delivery_hold.mode', 'on_demand')
                    ->whereRaw("delivery_hold.site_id::text = outbox_events.payload_json->>'site_id'");
            });
        }

        return $query->whereNotExists(function (Builder $held): void {
            $held->selectRaw('1')->from('publish_batches as delivery_parent')
                ->join('site_delivery_settings as delivery_hold', 'delivery_hold.site_id', '=', 'delivery_parent.site_id')
                ->whereColumn('delivery_parent.id', 'outbox_events.aggregate_id')->where('delivery_hold.mode', 'on_demand')
                ->where('delivery_parent.request_idempotency_key', 'like', 'auto-ingest:%');
        });
    }

    public function pausesAutomatedBatch(?object $batch): bool
    {
        return $batch !== null && str_starts_with((string) $batch->request_idempotency_key, 'auto-ingest:')
            && $this->enabled((int) $batch->site_id);
    }

    /** @return array{mode: string, actor_id: ?int, row_version: int, available: bool, runtime_enabled: bool} */
    public function configuration(int $siteId, int $actorId): array
    {
        Access::site($actorId, $siteId);
        abort_unless(DB::table('sites')->where('id', $siteId)->exists(), 404);

        return $this->data($this->available() ? DB::table('site_delivery_settings')->where('site_id', $siteId)->first() : null);
    }

    /** Change delivery only: publication bytes, assignments and website configuration versions stay untouched. */
    public function save(int $siteId, string $mode, int $rowVersion, int $actorId): array
    {
        Access::site($actorId, $siteId, true);
        Validator::make(['delivery_mode' => $mode, 'delivery_row_version' => $rowVersion], [
            'delivery_mode' => 'required|in:prepublish,on_demand', 'delivery_row_version' => 'required|integer|min:0',
        ])->validate();
        if (! $this->available()) {
            throw ValidationException::withMessages(['delivery_mode' => 'Chưa có cấu hình cách phục vụ trong database. Cần cập nhật hệ thống trước khi lưu.']);
        }

        return DB::transaction(function () use ($siteId, $mode, $rowVersion, $actorId): array {
            $site = DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            abort_unless($site, 404);
            Access::site($actorId, $siteId, true);
            if ($mode === 'on_demand' && ($site->content_type !== 'movie' || $site->status !== 'active')) {
                throw ValidationException::withMessages(['delivery_mode' => 'Chỉ website phim đang hoạt động được tạo trang khi truy cập lần đầu.']);
            }
            $current = DB::table('site_delivery_settings')->where('site_id', $siteId)->lockForUpdate()->first();
            if ((int) ($current->row_version ?? 0) !== $rowVersion) {
                throw new Conflict('SITE_DELIVERY_SETTINGS_STALE');
            }
            $values = ['mode' => $mode, 'actor_id' => $mode === 'on_demand' ? $actorId : null,
                'row_version' => $rowVersion + 1, 'updated_at' => now()];
            if ($current) {
                if (DB::table('site_delivery_settings')->where(['site_id' => $siteId, 'row_version' => $rowVersion])->update($values) !== 1) {
                    throw new Conflict('SITE_DELIVERY_SETTINGS_STALE');
                }
            } else {
                DB::table('site_delivery_settings')->insert(['site_id' => $siteId] + $values);
            }
            if (($current->mode ?? 'prepublish') !== $mode) {
                foreach (DB::table('pages')->where('site_id', $siteId)->whereIn('page_type', ['home', 'category'])->orderBy('id')->get() as $aggregate) {
                    app(PublicHtmlCache::class)->invalidatePage($aggregate);
                }
            }
            Access::audit('site.delivery.saved', $actorId, $siteId, ['mode' => $mode, 'actor_id' => $values['actor_id'], 'row_version' => $values['row_version']]);

            return $this->data((object) $values);
        }, 3);
    }

    private function available(): bool
    {
        return app(SchemaAvailability::class)->hasTable('site_delivery_settings');
    }

    /** @return array{mode: string, actor_id: ?int, row_version: int, available: bool, runtime_enabled: bool} */
    private function data(?object $row): array
    {
        return ['mode' => $row->mode ?? 'prepublish', 'actor_id' => isset($row->actor_id) ? (int) $row->actor_id : null,
            'row_version' => (int) ($row->row_version ?? 0), 'available' => $this->available(),
            'runtime_enabled' => (bool) config('public_on_demand.enabled', false)];
    }
}
