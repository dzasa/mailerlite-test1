<?php

namespace App\Jobs;

use App\Contracts\SubscriberEmailRegistry;
use App\Data\SubscriberData;
use App\Models\Subscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateSubscriber implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SubscriberData $subscriber,
    ) {}

    /**
     * Execute the job.
     *
     * insertOrIgnore swallows every constraint violation, not just duplicate
     * email collisions. Revisit this handler if the subscribers schema gains
     * additional unique constraints or required columns.
     */
    public function handle(SubscriberEmailRegistry $registry): void
    {
        $timestamp = now();

        Subscriber::query()->insertOrIgnore([
            ...$this->subscriber->toArray(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $registry->markPersisted($this->subscriber->email);
    }

    /**
     * Release the claim if the job permanently fails.
     */
    public function failed(?Throwable $exception): void
    {
        app(SubscriberEmailRegistry::class)->release($this->subscriber->email);
    }
}
