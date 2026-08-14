<?php

use App\Models\Subscriber;

it('stops on its own when the queue is empty', function (): void {
    // Regression guard: the exit conditions once compared a float against an
    // int-typed max(), so neither could ever fire and the command ran forever.
    // Without a --duration this must still terminate. Note that if that bug
    // returns this test hangs rather than fails — Pest's ->timeout() is not
    // available here — so the elapsed assertion below is the tripwire.
    $startedAt = microtime(true);

    $this->artisan('subscribers:monitor', [
        '--interval' => 0.1,
        '--quiet-ticks' => 1,
    ])->assertSuccessful();

    expect(microtime(true) - $startedAt)->toBeLessThan(5.0);
});

it('stops when the duration elapses', function (): void {
    $this->artisan('subscribers:monitor', [
        '--interval' => 0.1,
        '--duration' => 0.3,
    ])->assertSuccessful();
});

it('reports the rows it observed', function (): void {
    Subscriber::factory()->count(3)->create();

    $this->artisan('subscribers:monitor', [
        '--interval' => 0.1,
        '--quiet-ticks' => 1,
    ])
        ->expectsOutputToContain('Drain summary')
        ->expectsOutputToContain('rows written')
        ->assertSuccessful();
});

it('accepts a sub-second interval without spinning', function (): void {
    $startedAt = microtime(true);

    $this->artisan('subscribers:monitor', [
        '--interval' => 0.1,
        '--quiet-ticks' => 2,
    ])->assertSuccessful();

    // Two quiet ticks at 0.1s cannot complete instantly, which would mean the
    // interval was ignored, nor take seconds, which would mean it was misread.
    expect(microtime(true) - $startedAt)->toBeGreaterThan(0.15)->toBeLessThan(5.0);
});
