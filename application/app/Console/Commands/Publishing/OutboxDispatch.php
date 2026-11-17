<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Delivery\BaiduService;
use App\Domain\Support\Json;
use App\Jobs\Publishing\PublishItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OutboxDispatch extends Command
{
    protected $signature = 'tram:outbox {--limit=200}';

    protected $description = 'Recover durable publication queue events; consumers are idempotent';

    public function handle(): int
    {
        $count = 0;
        foreach (DB::table('outbox_events')->whereIn('event_type', ['publish.requested', 'publication.activated'])->whereNull('delivered_at')->orderBy('created_at')->limit((int) $this->option('limit'))->get() as $event) {
            try {
                $payload = Json::decode($event->payload_json);
                if ($event->event_type === 'publish.requested') {
                    PublishItem::dispatch((int) $payload['item_id'])->onQueue('publish');
                } elseif ($event->event_type === 'publication.activated' && class_exists(BaiduService::class)) {
                    $config = DB::table('baidu_configs')->where(['site_id' => $payload['site_id'], 'status' => 'active'])->first();
                    if ($config && $config->schema_verified) {
                        app(BaiduService::class)->enqueue($payload['site_id'], [$payload['publication_id']]);
                    }
                }
                DB::transaction(function () use ($event) {
                    DB::table('processed_events')->insertOrIgnore(['consumer' => 'outbox-dispatch', 'event_id' => $event->id]);
                    DB::table('outbox_events')->where('id', $event->id)->update(['delivered_at' => now(), 'attempts' => DB::raw('attempts+1')]);
                });
                $count++;
            } catch (\Throwable $e) {
                DB::table('outbox_events')->where('id', $event->id)->increment('attempts');
                report($e);
            }
        }
        $this->info("Delivered: $count");

        return self::SUCCESS;
    }
}
