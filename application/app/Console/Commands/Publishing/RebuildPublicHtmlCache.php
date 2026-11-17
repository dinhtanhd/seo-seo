<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Publishing\PublicHtmlCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildPublicHtmlCache extends Command
{
    protected $signature = 'tram:public-cache {--site= : Chỉ dựng lại một website} {--purge : Gỡ toàn bộ cache trước khi dựng lại} {--dispatch : Phục hồi các event cache đang chờ}';

    protected $description = 'Dựng lại HTML public từ publication đang live; không đổi nội dung hoặc assignment.';

    public function handle(PublicHtmlCache $cache): int
    {
        if ($this->option('purge')) {
            $cache->purge();
        }
        if ($this->option('dispatch')) {
            $counts = $cache->deliverPending();
            $this->info('Cache events: '.json_encode($counts));

            return $counts['failed'] ? self::FAILURE : self::SUCCESS;
        }
        if (! config('public_html_cache.enabled')) {
            $this->info('Cache public đang tắt; đã giữ đường đọc Laravel.');

            return self::SUCCESS;
        }
        $siteId = $this->option('site');
        if ($siteId !== null && (! ctype_digit((string) $siteId) || (int) $siteId < 1)) {
            $this->error('ID website không hợp lệ.');

            return self::FAILURE;
        }
        $sites = DB::table('sites')->where('content_type', 'movie');
        if ($siteId !== null) {
            $sites->where('id', (int) $siteId);
        }
        $count = 0;
        foreach ($sites->orderBy('id')->pluck('id') as $id) {
            $count += $cache->rebuildSite($id);
        }
        $this->info('Đã dựng '.$count.' file HTML từ publication live.');

        return self::SUCCESS;
    }
}
