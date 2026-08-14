<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed per IP address on POST /api/v1/subscribers.
    | The endpoint is public, so this is the only thing standing between a
    | single client and unbounded writes. Set it to 0 to disable throttling,
    | which is what a load test needs — otherwise it measures this limiter
    | rather than the endpoint.
    |
    */

    'rate_limit_per_minute' => (int) env('SUBSCRIBERS_RATE_LIMIT_PER_MINUTE', 120),

];
