<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SubscriberEmailRegistry;
use App\Data\SubscriberData;
use App\Exceptions\ClaimStoreUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriberRequest;
use App\Jobs\CreateSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SubscriberController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @throws ClaimStoreUnavailableException
     * @throws ValidationException
     */
    public function __invoke(
        StoreSubscriberRequest $request,
        SubscriberEmailRegistry $registry,
    ): JsonResponse {
        $subscriber = SubscriberData::fromRequest($request);

        if (! $registry->claim($subscriber->email)) {
            throw ValidationException::withMessages([
                'email' => __('The email has already been taken.'),
            ]);
        }

        try {
            Bus::dispatch(new CreateSubscriber($subscriber));
        } catch (Throwable $exception) {
            $registry->release($subscriber->email);

            Log::warning('subscriber_create_dispatch_failed_claim_released', [
                'counter' => 'subscriber.create.dispatch_failed_claim_released',
                'email_hash' => hash('sha256', $subscriber->email),
                'exception' => $exception::class,
            ]);

            throw new ClaimStoreUnavailableException(
                message: 'Subscriber creation could not be queued.',
                previous: $exception,
            );
        }

        return response()->json([
            'message' => 'Subscriber creation accepted.',
            'data' => $subscriber->toArray(),
        ], Response::HTTP_ACCEPTED);
    }
}
