<?php

use App\Contracts\SubscriberEmailRegistry;
use App\Data\SubscriberData;
use App\Enums\SubscriberStatus;
use App\Jobs\CreateSubscriber;
use App\Models\Subscriber;
use RuntimeException;

function subscriberData(string $email = 'worker@example.com', string $status = 'subscribed'): SubscriberData
{
    return new SubscriberData(email: $email, status: $status);
}

it('persists the subscriber and promotes the claim to persisted', function (): void {
    $registry = app(SubscriberEmailRegistry::class);
    $registry->claim('worker@example.com');

    expect($registry->state('worker@example.com'))->toBe(SubscriberEmailRegistry::Pending);

    dispatch_sync(new CreateSubscriber(subscriberData()));

    $this->assertDatabaseHas('subscribers', [
        'email' => 'worker@example.com',
        'status' => 'subscribed',
    ]);

    expect($registry->state('worker@example.com'))->toBe(SubscriberEmailRegistry::Persisted);
});

it('casts the persisted status back to the enum', function (): void {
    dispatch_sync(new CreateSubscriber(subscriberData(status: 'unsubscribed')));

    expect(Subscriber::query()->sole()->status)->toBe(SubscriberStatus::Unsubscribed);
});

it('is idempotent when the same email is processed twice', function (): void {
    dispatch_sync(new CreateSubscriber(subscriberData()));
    dispatch_sync(new CreateSubscriber(subscriberData()));

    $this->assertDatabaseCount('subscribers', 1);
});

it('does not overwrite the stored status on a repeat run', function (): void {
    dispatch_sync(new CreateSubscriber(subscriberData(status: 'subscribed')));
    dispatch_sync(new CreateSubscriber(subscriberData(status: 'unsubscribed')));

    // insertOrIgnore, not upsert: the first write wins.
    $this->assertDatabaseCount('subscribers', 1);
    expect(Subscriber::query()->sole()->status)->toBe(SubscriberStatus::Subscribed);
});

it('releases the claim when the job permanently fails', function (): void {
    $registry = app(SubscriberEmailRegistry::class);
    $registry->claim('worker@example.com');

    (new CreateSubscriber(subscriberData()))->failed(new RuntimeException('exhausted'));

    // A dead job must not leave the address blocked until the TTL expires.
    expect($registry->state('worker@example.com'))->toBeNull();
});

it('lets a released email be claimed again', function (): void {
    $registry = app(SubscriberEmailRegistry::class);

    expect($registry->claim('retry@example.com'))->toBeTrue()
        ->and($registry->claim('retry@example.com'))->toBeFalse();

    $registry->release('retry@example.com');

    expect($registry->claim('retry@example.com'))->toBeTrue();
});
