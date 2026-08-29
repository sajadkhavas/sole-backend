<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryLocation> */
class InventoryLocationFactory extends Factory
{
    protected $model = InventoryLocation::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('LOC-###-??')),
            'name' => fake()->unique()->company().' Warehouse',
            'is_active' => true,
        ];
    }
}
