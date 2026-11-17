<?php

return [
    'enabled' => (bool) env('PUBLIC_HTML_CACHE_ENABLED', env('APP_ENV') !== 'testing'),
    'root' => storage_path('app/public-html'),
    // An absent site remains unlimited; zero explicitly disables new cache writes for that site.
    'site_max_bytes' => in_array(env('PUBLIC_HTML_CACHE_SITE6_MAX_BYTES'), [null, ''], true) ? [] : [6 => env('PUBLIC_HTML_CACHE_SITE6_MAX_BYTES')],
];
