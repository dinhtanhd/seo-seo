<?php

use App\Domain\Publishing\PublicRequestPolicyReader;

require_once __DIR__.'/../app/Domain/Publishing/PublicRequestPolicyReader.php';
$policy = (new PublicRequestPolicyReader)->read($_SERVER);
if ($policy !== null) {
    http_response_code(204);
    header('Cache-Control: no-store');
    header('X-Tram-Policy-Cache: HIT');
    if (isset($policy['plain'], $policy['runtime'])) {
        header('X-Tram-Public-Plain-Csp: '.$policy['plain']);
        header('X-Tram-Public-Runtime-Csp: '.$policy['runtime']);
    }
    exit;
}
