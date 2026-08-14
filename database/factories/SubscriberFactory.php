<?php

namespace Database\Factories;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'status' => SubscriberStatus::Subscribed,
        ];
    }

    /**
     * Indicate that the subscriber is subscribed.
     */
    public function subscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriberStatus::Subscribed,
        ]);
    }

    /**
     * Indicate that the subscriber is unsubscribed.
     */
    public function unsubscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriberStatus::Unsubscribed,
        ]);
    }
}
