<?php

namespace App\Data;

use App\Enums\SubscriberStatus;
use App\Http\Requests\StoreSubscriberRequest;
use Illuminate\Support\Str;

readonly class SubscriberData
{
    /**
     * Create a normalized subscriber data instance.
     */
    public function __construct(
        public string $email,
        public string $status,
    ) {}

    /**
     * Build subscriber data from a validated request.
     */
    public static function fromRequest(StoreSubscriberRequest $request): self
    {
        $validated = $request->validated();
        $status = SubscriberStatus::from((string) $validated['status']);

        return new self(
            email: Str::lower(trim((string) $validated['email'])),
            status: $status->value,
        );
    }

    /**
     * Get the payload used by insertOrIgnore.
     *
     * @return array{email: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'status' => $this->status,
        ];
    }
}
