<?php

return [
    'shared_read_cache' => (bool) env('PUBLICATION_SHARED_READ_CACHE_ENABLED', env('APP_ENV') !== 'testing'),
    'shared_keyword_cache' => (bool) env('FIRST_VISIT_KEYWORD_CACHE_ENABLED', env('APP_ENV') !== 'testing'),
    'compact_site_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICATION_COMPACT_SITE_IDS', ''))), fn (int $id): bool => $id > 0)),
];
