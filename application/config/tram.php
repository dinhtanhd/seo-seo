<?php

return [
    'admin_host' => env('ADMIN_HOST', 'localhost'),
    'admin_hosts' => env('ADMIN_HOSTS', env('ADMIN_HOST', 'localhost,127.0.0.1,admin.localhost')),
    'public_wildcard_domains' => env('PUBLIC_WILDCARD_DOMAINS', ''),
    'release' => env('RELEASE_TAG', 'local'),
    'preview_ttl_minutes' => (int) env('PREVIEW_TTL_MINUTES', 30),
    'content_locale' => env('CONTENT_LOCALE', 'zh-CN'),
];
