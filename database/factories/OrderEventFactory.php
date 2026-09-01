<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderEvent>
 */
class OrderEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'actor_id' => null,
            'from_status' => null,
            'to_status' => 'awaiting_payment',
            'reason' => 'checkout_created',
            'metadata' => null,
            'created_at' => now(),
        ];
    }
}
