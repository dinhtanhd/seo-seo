<?php

namespace App\Jobs\Ingestion;

use App\Domain\Ingestion\ExistingMovieCatalogBackfill;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BackfillExistingMovies implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const EVENT = 'catalog-backfill.requested';

    public int $timeout = 90;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('websites');
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    /** Record alongside the site configuration so Redis can be unavailable at commit. */
    public static function record(int $siteId, int $sourceId): void
    {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => self::EVENT,
            'aggregate_id' => $siteId,
            'payload_json' => Json::encode(['source_id' => $sourceId, 'cursor' => 0]),
        ]);
    }

    public static function dispatchPending(): void
    {
        foreach (DB::table('outbox_events')->where('event_type', self::EVENT)
            ->whereNull('delivered_at')->orderBy('created_at')->limit(100)->pluck('id') as $id) {
            self::dispatch($id);
        }
    }

    public function handle(ExistingMovieCatalogBackfill $catalog): void
    {
        $more = DB::transaction(function () use ($catalog): bool {
            $event = DB::table('outbox_events')->where(['id' => $this->eventId, 'event_type' => self::EVENT])
                ->whereNull('delivered_at')->lockForUpdate()->first();
            if ($event === null) {
                return false;
            }
            $payload = Json::decode($event->payload_json);
            try {
                $chunk = $catalog->linkChunk((int) $event->aggregate_id, (int) $payload['source_id'], (int) $payload['cursor']);
            } catch (Conflict $exception) {
                if (! in_array($exception->reason, ['BINDING_SELECTION_STALE', 'BINDING_SELECTION_INVALID'], true)) {
                    throw $exception;
                }
                // Saving a valid mapping records a new request. A stale one cannot advance.
                report($exception);
                $payload['last_error'] = $exception->reason;
                $chunk = ['cursor' => $payload['cursor'], 'more' => false];
            }
            $payload['cursor'] = $chunk['cursor'];
            DB::table('outbox_events')->where('id', $this->eventId)->update([
                'payload_json' => Json::encode($payload),
                'delivered_at' => $chunk['more'] ? null : now(),
            ]);

            return $chunk['more'];
        }, 3);

        if ($more) {
            self::dispatch($this->eventId);
        }
    }
}
