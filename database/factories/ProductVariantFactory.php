<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SOLE-#####-??')),
            'title' => fake()->words(2, true),
            'size' => fake()->randomElement(['40', '41', '42', '43']),
            'color' => fake()->safeColorName(),
            'price_minor' => fake()->numberBetween(1000000, 50000000),
            'compare_at_price_minor' => null,
            'currency' => 'IRR',
            'is_active' => true,
        ];
    }
}
