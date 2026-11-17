<?php

use App\Domain\Publishing\HtmlCacheReader;

// Read cached bytes without bootstrapping Composer or Laravel.
require_once __DIR__.'/../app/Domain/Publishing/HtmlCacheReader.php';

$cached = (new HtmlCacheReader)->open($_SERVER);
if ($cached === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Content-Length: '.$cached['size']);
header('X-Tram-Cache-Delivery: php');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        fpassthru($cached['stream']);
    }
} finally {
    fclose($cached['stream']);
}
