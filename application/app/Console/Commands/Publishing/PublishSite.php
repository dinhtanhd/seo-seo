<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Publishing\Planner;
use App\Domain\Publishing\Publisher;
use App\Domain\Support\Access;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishSite extends Command
{
    protected $signature = 'tram:publish-site {site} {--actor= : Existing authorized user ID} {--refresh-aggregates} {--sync : Run real jobs in this process}';

    protected $description = 'Preview then publish a bounded site batch; print durable item counts';

    public function handle(): int
    {
        $site = (int) $this->argument('site');
        $actor = (int) $this->option('actor');
        Access::site($actor, $site, true);
        if ($this->option('sync')) {
            config(['queue.default' => 'sync']);
        }
        if ($this->option('refresh-aggregates')) {
            foreach (DB::table('pages')->where('site_id', $site)->whereIn('page_type', ['home', 'category'])->get() as $aggregate) {
                $query = DB::table('pages')->where('site_id', $site)->whereNotNull('content_item_id')->whereNotIn('status', ['archived', 'gone']);
                if ($aggregate->page_type === 'category') {
                    $query->where('category_id', $aggregate->category_id);
                }app(Planner::class)->refreshAggregate($aggregate->id, $query->pluck('id')->all(), $actor);
            }
        }
        $ids = DB::table('pages')->where('site_id', $site)->whereNotIn('status', ['archived', 'gone'])->orderBy('id')->pluck('id')->all();
        if (count($ids) > 10000) {
            $this->error('Chia thành batch <=10.000 trang qua admin');

            return self::FAILURE;
        }
        $batch = app(Planner::class)->preview($site, $ids, $actor);
        $this->info('Preview batch '.$batch->id.' '.$batch->counts);
        app(Publisher::class)->accept($batch->id, $actor);
        $final = DB::table('publish_batches')->find($batch->id);
        $this->info('Batch '.$final->id.' '.$final->status.' '.$final->counts);

        return in_array($final->status, ['failed', 'partial', 'stale']) ? self::FAILURE : self::SUCCESS;
    }
}
