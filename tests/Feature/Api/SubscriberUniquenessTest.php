<?php

use App\Contracts\SubscriberEmailRegistry;
use App\Jobs\CreateSubscriber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('suppresses a duplicate email and queues the job exactly once', function (): void {
    Queue::fake();

    $payload = ['email' => 'once@example.com', 'status' => 'subscribed'];

    $this->postJson('/api/v1/subscribers', $payload)->assertAccepted();

    $this->postJson('/api/v1/subscribers', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    // The guarantee: a second request must not produce a second job.
    Queue::assertPushed(CreateSubscriber::class, 1);
});

it('rejects a duplicate regardless of the status submitted', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/subscribers', [
        'email' => 'pure@example.com',
        'status' => 'subscribed',
    ])->assertAccepted();

    // A pure create endpoint: a second request is never an update.
    $this->postJson('/api/v1/subscribers', [
        'email' => 'pure@example.com',
        'status' => 'unsubscribed',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertPushed(CreateSubscriber::class, 1);
});

it('rejects an email already marked persisted without reading the database', function (): void {
    Queue::fake();

    app(SubscriberEmailRegistry::class)->markPersisted('known@example.com');

    $this->postJson('/api/v1/subscribers', [
        'email' => 'known@example.com',
        'status' => 'subscribed',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('subscribers', 0);
});

it('treats distinct emails independently', function (): void {
    Queue::fake();

    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
        $this->postJson('/api/v1/subscribers', [
            'email' => $email,
            'status' => 'subscribed',
        ])->assertAccepted();
    }

    Queue::assertPushed(CreateSubscriber::class, 3);
});

it('treats case variants of one address as the same claim', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/subscribers', [
        'email' => 'Case@Example.com',
        'status' => 'subscribed',
    ])->assertAccepted();

    $this->postJson('/api/v1/subscribers', [
        'email' => 'CASE@EXAMPLE.COM',
        'status' => 'subscribed',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertPushed(CreateSubscriber::class, 1);
});

it('keeps claims isolated from a flush of the default cache store', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/subscribers', [
        'email' => 'survives@example.com',
        'status' => 'subscribed',
    ])->assertAccepted();

    // cache:clear is not prefix-scoped; claims must live in their own store.
    Cache::store('array')->flush();
    Cache::flush();

    $this->postJson('/api/v1/subscribers', [
        'email' => 'survives@example.com',
        'status' => 'subscribed',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertPushed(CreateSubscriber::class, 1);
});
