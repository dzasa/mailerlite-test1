<?php

namespace App\Console\Commands;

use App\Contracts\SubscriberEmailRegistry;
use App\Models\Subscriber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('subscribers:warm-registry {--chunk=500 : Number of subscribers to process per database chunk}')]
#[Description('Warm the subscriber email claim registry from persisted subscribers')]
class WarmSubscriberRegistry extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SubscriberEmailRegistry $registry): int
    {
        $warmed = 0;
        $chunkSize = max(1, (int) $this->option('chunk'));

        Subscriber::query()
            ->select(['id', 'email'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $subscribers) use ($registry, &$warmed): void {
                foreach ($subscribers as $subscriber) {
                    if ($registry->state($subscriber->email) !== SubscriberEmailRegistry::Persisted) {
                        $registry->markPersisted($subscriber->email);
                    }

                    $warmed++;
                }
            });

        $this->components->info("Warmed {$warmed} subscriber registry claims.");

        return self::SUCCESS;
    }
}
