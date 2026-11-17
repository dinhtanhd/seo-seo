<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/readyz', function () {
    abort_unless(request()->getHost() === config('tram.admin_host'), 404);
    try {
        DB::select('SELECT 1');
        Redis::connection()->ping();
        if (! Schema::hasTable('page_publications')) {
            return response()->json(['status' => 'schema_pending'], 503);
        }

        return response()->json(['status' => 'ready', 'database' => 'ready', 'redis' => 'ready', 'release' => config('tram.release')]);
    } catch (Throwable $e) {
        return response()->json(['status' => 'unavailable'], 503);
    }
});
