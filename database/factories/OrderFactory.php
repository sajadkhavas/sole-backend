<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'user_id' => User::factory(),
            'customer_address_id' => null,
            'status' => 'awaiting_payment',
            'currency' => 'IRR',
            'subtotal_minor' => 1000000,
            'discount_minor' => 0,
            'shipping_minor' => 100000,
            'total_minor' => 1100000,
            'shipping_address_snapshot' => ['country_code' => 'IR'],
            'reservation_expires_at' => now()->addMinutes(15),
        ];
    }
}
