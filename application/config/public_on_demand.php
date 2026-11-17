<?php

return [
    // Opt in per domain after deploying the database migration.
    'enabled' => (bool) env('PUBLIC_ON_DEMAND_ENABLED', false),
    'catalog_ttl' => 60,
    'search_index_enabled' => (bool) env('PUBLIC_CATALOG_SEARCH_INDEX_ENABLED', true),
    // Detail/play render at most 20 recommendations per visible section.
    'recommendation_limit' => 120,
];
