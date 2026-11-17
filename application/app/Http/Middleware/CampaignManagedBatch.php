<?php

namespace App\Http\Middleware;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CampaignManagedBatch
{
    public function handle(Request $request, Closure $next)
    {
        $child = DB::table('publication_campaign_chunks')->where('batch_id', (int) $request->route('id'))->first();
        if ($child) {
            Access::site($request->user()->id, (int) $child->site_id, true);
            throw new Conflict('CAMPAIGN_MANAGED_BATCH', 'Thao tác tại chiến dịch #'.$child->campaign_id.' để giữ thứ tự và lịch sử nhiều website.');
        }

        return $next($request);
    }
}
