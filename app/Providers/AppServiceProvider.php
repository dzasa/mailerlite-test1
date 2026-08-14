<?php

namespace App\Providers;

use App\Contracts\SubscriberEmailRegistry;
use App\Support\CacheSubscriberEmailRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SubscriberEmailRegistry::class, CacheSubscriberEmailRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure API rate limiters.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('subscribers', function (Request $request): Limit {
            /**
             * Read through config rather than env() so the value survives
             * config caching, and so tests can vary it without touching .env.
             */
            $perMinute = (int) config('subscribers.rate_limit_per_minute');

            return $perMinute > 0
                ? Limit::perMinute($perMinute)->by($request->ip() ?: 'anonymous')
                : Limit::none();
        });
    }
}
