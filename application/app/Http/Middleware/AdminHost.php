<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class AdminHost
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->attributes->get('admin_host'), 404);

        return $next($request);
    }
}
