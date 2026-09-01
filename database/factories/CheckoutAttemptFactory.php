<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CheckoutAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckoutAttempt>
 */
class CheckoutAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'idempotency_key' => fake()->uuid(),
            'user_id' => User::factory(),
            'cart_id' => Cart::factory(),
            'order_id' => null,
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'status' => 'processing',
            'response_payload' => null,
        ];
    }
}
