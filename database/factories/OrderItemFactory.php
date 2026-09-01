<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
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
            'product_variant_id' => ProductVariant::factory(),
            'sku' => strtoupper(fake()->bothify('SOLE-#####-??')),
            'product_name' => fake()->words(3, true),
            'variant_title' => fake()->words(2, true),
            'size' => '42',
            'quantity' => 1,
            'unit_price_minor' => 1000000,
            'line_total_minor' => 1000000,
        ];
    }
}
