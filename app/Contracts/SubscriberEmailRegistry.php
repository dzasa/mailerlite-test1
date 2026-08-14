<?php

namespace App\Contracts;

interface SubscriberEmailRegistry
{
    public const string Pending = 'pending';

    public const string Persisted = 'persisted';

    public function claim(string $email): bool;

    public function state(string $email): ?string;

    public function markPersisted(string $email): void;

    public function release(string $email): void;
}
