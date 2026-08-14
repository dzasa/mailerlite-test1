<?php

use App\Exceptions\ClaimStoreUnavailableException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /**
         * Translate an unreachable Redis into a retryable 503 for API clients.
         *
         * Redis is the availability boundary for the subscriber endpoint: the
         * uniqueness claim, the queue push, and the throttle counter all depend
         * on it, and none of them may silently fall back to MySQL.
         */
        $claimStoreUnavailable = fn (Request $request): ?JsonResponse => $request->is('api/*')
            ? response()->json(
                ['message' => 'Subscriber claim store is unavailable. Please retry shortly.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '5'],
            )
            : null;

        $exceptions->render(
            fn (ClaimStoreUnavailableException $exception, Request $request): ?JsonResponse => $claimStoreUnavailable($request),
        );

        /**
         * The throttle middleware reads its counter from the Redis cache store
         * before the controller runs, so a Redis outage surfaces there as a raw
         * client exception that never passes through the claim registry's guard.
         * Without this handler that path returns 500 instead of 503.
         *
         * Predis is matched by name rather than by type hint because only one
         * Redis client is installed at a time; this project ships phpredis.
         */
        $exceptions->render(
            fn (Throwable $exception, Request $request): ?JsonResponse => $exception instanceof RedisException
                || is_a($exception, 'Predis\Connection\ConnectionException')
                    ? $claimStoreUnavailable($request)
                    : null,
        );
    })->create();
