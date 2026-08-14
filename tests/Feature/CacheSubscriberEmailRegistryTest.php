<?php

use App\Contracts\SubscriberEmailRegistry;
use App\Support\CacheSubscriberEmailRegistry;

beforeEach(function (): void {
    $this->registry = new CacheSubscriberEmailRegistry;
});

it('grants a claim only once', function (): void {
    expect($this->registry->claim('first@example.com'))->toBeTrue()
        ->and($this->registry->claim('first@example.com'))->toBeFalse()
        ->and($this->registry->claim('first@example.com'))->toBeFalse();
});

it('reports no state for an unseen email', function (): void {
    expect($this->registry->state('unseen@example.com'))->toBeNull();
});

it('reports pending after a claim and persisted after promotion', function (): void {
    $this->registry->claim('lifecycle@example.com');
    expect($this->registry->state('lifecycle@example.com'))->toBe(SubscriberEmailRegistry::Pending);

    $this->registry->markPersisted('lifecycle@example.com');
    expect($this->registry->state('lifecycle@example.com'))->toBe(SubscriberEmailRegistry::Persisted);
});

it('still refuses a claim once the email is persisted', function (): void {
    $this->registry->markPersisted('done@example.com');

    expect($this->registry->claim('done@example.com'))->toBeFalse();
});

it('restores claimability after release', function (): void {
    $this->registry->claim('released@example.com');
    $this->registry->release('released@example.com');

    expect($this->registry->state('released@example.com'))->toBeNull()
        ->and($this->registry->claim('released@example.com'))->toBeTrue();
});

it('scopes keys per email so addresses cannot collide', function (): void {
    $this->registry->claim('one@example.com');

    expect($this->registry->claim('two@example.com'))->toBeTrue()
        ->and($this->registry->state('one@example.com'))->toBe(SubscriberEmailRegistry::Pending)
        ->and($this->registry->state('two@example.com'))->toBe(SubscriberEmailRegistry::Pending);
});

it('treats the raw string as given, leaving normalization to the caller', function (): void {
    // Normalization is the DTO's single responsibility; the registry must not
    // silently lower-case, or the claim key and the persisted row could drift.
    $this->registry->claim('Raw@Example.com');

    expect($this->registry->state('raw@example.com'))->toBeNull()
        ->and($this->registry->state('Raw@Example.com'))->toBe(SubscriberEmailRegistry::Pending);
});
