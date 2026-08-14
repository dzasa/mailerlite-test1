<?php

namespace App\Support;

use App\Contracts\SubscriberEmailRegistry;
use App\Exceptions\ClaimStoreUnavailableException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CacheSubscriberEmailRegistry implements SubscriberEmailRegistry
{
    private const int PendingTtlSeconds = 600;

    /**
     * Atomically claim an email address for subscriber creation.
     */
    public function claim(string $email): bool
    {
        return $this->guard(fn (): bool => $this->store()->add(
            $this->key($email),
            SubscriberEmailRegistry::Pending,
            self::PendingTtlSeconds,
        ));
    }

    public function state(string $email): ?string
    {
        $state = $this->guard(fn (): mixed => $this->store()->get($this->key($email)));

        return is_string($state) ? $state : null;
    }

    public function markPersisted(string $email): void
    {
        $this->guard(fn (): bool => $this->store()->forever(
            $this->key($email),
            SubscriberEmailRegistry::Persisted,
        ));
    }

    public function release(string $email): void
    {
        $this->guard(fn (): bool => $this->store()->forget($this->key($email)));
    }

    private function store(): Repository
    {
        return Cache::store('subscriber_claims');
    }

    private function key(string $email): string
    {
        return 'subs:'.hash('sha256', $email);
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $operation
     * @return TValue
     *
     * @throws ClaimStoreUnavailableException
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            if (! $this->isRedisConnectionException($exception)) {
                throw $exception;
            }

            throw new ClaimStoreUnavailableException(
                message: 'Subscriber claim store is unavailable.',
                previous: $exception,
            );
        }
    }

    private function isRedisConnectionException(Throwable $exception): bool
    {
        return is_a($exception, 'RedisException')
            || is_a($exception, 'Predis\\Connection\\ConnectionException');
    }
}
