<?php

use App\Contracts\SubscriberEmailRegistry;
use App\Exceptions\ClaimStoreUnavailableException;
use App\Jobs\CreateSubscriber;
use Illuminate\Support\Facades\Queue;

it('accepts a valid subscriber without touching the database', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/subscribers', [
        'email' => 'taylor@example.com',
        'status' => 'subscribed',
    ])
        ->assertAccepted()
        ->assertJsonPath('data.email', 'taylor@example.com')
        ->assertJsonPath('data.status', 'subscribed');

    Queue::assertPushed(CreateSubscriber::class, 1);

    // The request path must defer every write; the worker owns persistence.
    $this->assertDatabaseCount('subscribers', 0);
});

it('normalizes the email before claiming and queueing', function (string $input): void {
    Queue::fake();

    $this->postJson('/api/v1/subscribers', [
        'email' => $input,
        'status' => 'subscribed',
    ])
        ->assertAccepted()
        ->assertJsonPath('data.email', 'normalize@example.com');

    Queue::assertPushed(
        CreateSubscriber::class,
        fn (CreateSubscriber $job): bool => $job->subscriber->email === 'normalize@example.com',
    );
})->with([
    'uppercase' => ['NORMALIZE@EXAMPLE.COM'],
    'mixed case' => ['Normalize@Example.Com'],
    'surrounding whitespace' => ['  normalize@example.com  '],
    'both' => ["\tNormalize@EXAMPLE.com  "],
]);

it('rejects invalid input and queues nothing', function (array $payload, string $invalidField): void {
    Queue::fake();

    $this->postJson('/api/v1/subscribers', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidField);

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('subscribers', 0);
})->with([
    'missing email' => [['status' => 'subscribed'], 'email'],
    'malformed email' => [['email' => 'not-an-email', 'status' => 'subscribed'], 'email'],
    'empty email' => [['email' => '', 'status' => 'subscribed'], 'email'],
    'missing status' => [['email' => 'valid@example.com'], 'status'],
    'unknown status' => [['email' => 'valid@example.com', 'status' => 'pending'], 'status'],
    'status casing is strict' => [['email' => 'valid@example.com', 'status' => 'Subscribed'], 'status'],
]);

it('rejects an email longer than the column allows', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/subscribers', [
        'email' => str_repeat('a', 250).'@example.com',
        'status' => 'subscribed',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertNothingPushed();
});

it('fails fast with 503 and Retry-After when the claim store is unavailable', function (): void {
    Queue::fake();

    $this->app->bind(SubscriberEmailRegistry::class, fn (): SubscriberEmailRegistry => new class implements SubscriberEmailRegistry
    {
        public function claim(string $email): bool
        {
            throw new ClaimStoreUnavailableException('Claim store is down.');
        }

        public function state(string $email): ?string
        {
            return null;
        }

        public function markPersisted(string $email): void {}

        public function release(string $email): void {}
    });

    $response = $this->postJson('/api/v1/subscribers', [
        'email' => 'outage@example.com',
        'status' => 'subscribed',
    ]);

    $response->assertStatus(503)->assertHeader('Retry-After', '5');

    // No silent fallback to MySQL, and nothing queued behind an unclaimed email.
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('subscribers', 0);

    expect($response->json('message'))->not->toContain('Redis');
});
