<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Publishing\FanmuluRecommendationIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RebuildFanmuluRecommendations extends Command
{
    protected $signature = 'tram:fanmulu-recommendations {--site= : Website cần dựng}
        {--limit=1 : Số website tối đa mỗi lượt}';

    protected $description = 'Tính sẵn danh sách ứng viên phim cho fanmulu theo website';

    public function handle(FanmuluRecommendationIndex $index): int
    {
        if (! config('public_on_demand.enabled')) {
            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $site = $this->option('site');
        if ($limit === false || ($site !== null && filter_var($site, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('Cần ID website hợp lệ và giới hạn từ 1 đến 100.');

            return self::INVALID;
        }

        $sites = DB::table('sites as s')->join('site_delivery_settings as d', 'd.site_id', '=', 's.id')
            ->leftJoin('fanmulu_recommendation_states as w', 'w.site_id', '=', 's.id')
            ->where(['s.status' => 'active', 's.content_type' => 'movie', 'd.mode' => 'on_demand'])
            ->whereRaw("s.presentation_json->'fanmulu'->>'enabled' = 'true'")
            ->whereRaw('w.built_generation IS DISTINCT FROM COALESCE(w.generation,0)')
            ->when($site !== null, fn ($query) => $query->where('s.id', (int) $site))
            ->orderByRaw('w.rebuilt_at NULLS FIRST')->orderBy('s.id')->limit($limit)->pluck('s.id');

        $ready = 0;
        foreach ($sites as $id) {
            if ($index->rebuild($id)) {
                $this->info('Đã dựng gợi ý fanmulu cho website #'.$id);
                $ready++;
            }
        }

        return $ready === $sites->count() ? self::SUCCESS : self::FAILURE;
    }
}
