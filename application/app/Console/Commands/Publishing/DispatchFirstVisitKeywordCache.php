<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\SchemaAvailability;
use App\Jobs\Publishing\WarmFirstVisitKeywordCache;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DispatchFirstVisitKeywordCache extends Command
{
    protected $signature = 'tram:keyword-cache {--site= : ID website cần nạp trước}';

    protected $description = 'Xếp job nền nạp trước kho keyword cho các trang mở lần đầu';

    public function handle(): int
    {
        if (! config('publication_storage.shared_keyword_cache')) {
            return self::SUCCESS;
        }
        $option = $this->option('site');
        $siteId = $option === null ? null : filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($siteId === false) {
            $this->error('Cần ID website hợp lệ.');

            return self::INVALID;
        }
        $onDemand = config('public_on_demand.enabled') && app(SchemaAvailability::class)->hasTable('site_delivery_settings');
        CatalogAvailability::filter(DB::table('sites'), 'sites')
            ->where(['status' => 'active', 'content_type' => 'movie'])
            ->when($siteId !== null, fn (Builder $query) => $query->where('id', $siteId))
            ->where(function (Builder $query) use ($onDemand): void {
                $query->whereRaw("lower(presentation_json->'fanmulu'->>'enabled') IN ('true','1','yes','on')");
                if ($onDemand) {
                    $query->orWhereExists(fn (Builder $delivery) => $delivery->selectRaw('1')->from('site_delivery_settings')
                        ->whereColumn('site_id', 'sites.id')->where('mode', 'on_demand'));
                }
            })
            ->select('id')->chunkById(100, function ($sites): void {
                foreach ($sites as $site) {
                    WarmFirstVisitKeywordCache::dispatch((int) $site->id)->onQueue('default');
                }
            });

        return self::SUCCESS;
    }
}
