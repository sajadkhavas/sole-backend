<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryReservation>
 */
class InventoryReservationFactory extends Factory
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
            'inventory_location_id' => InventoryLocation::factory(),
            'quantity' => 1,
            'status' => 'active',
            'expires_at' => now()->addMinutes(15),
            'released_at' => null,
        ];
    }
}
