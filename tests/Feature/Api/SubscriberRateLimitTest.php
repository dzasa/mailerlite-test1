<?php

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Resolve the named limiter exactly the way the throttle middleware does.
 */
function subscriberLimit(int $perMinute): Limit
{
    config()->set('subscribers.rate_limit_per_minute', $perMinute);

    $callback = app(RateLimiter::class)->limiter('subscribers');

    return $callback(Request::create('/api/v1/subscribers', 'POST'));
}

it('defaults to 120 requests per minute', function (): void {
    expect(config('subscribers.rate_limit_per_minute'))->toBe(120);
});

it('honours a configured limit', function (): void {
    expect(subscriberLimit(500)->maxAttempts)->toBe(500);
});

it('disables throttling when the limit is zero', function (): void {
    // Limit::none() is expressed as PHP_INT_MAX attempts.
    expect(subscriberLimit(0)->maxAttempts)->toBe(PHP_INT_MAX);
});

it('throttles per ip so one client cannot exhaust everyone else', function (): void {
    expect(subscriberLimit(120)->key)->toBe('127.0.0.1');
});

it('actually returns 429 once the limit is exceeded', function (): void {
    config()->set('subscribers.rate_limit_per_minute', 2);

    $post = fn (string $email) => $this->postJson('/api/v1/subscribers', [
        'email' => $email,
        'status' => 'subscribed',
    ]);

    $post('one@example.com')->assertAccepted();
    $post('two@example.com')->assertAccepted();
    $post('three@example.com')->assertStatus(429);
});
