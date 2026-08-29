<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_sku_uniqueness_is_owned_by_the_database(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->create(['sku' => 'SOLE-UNIQUE-001']);

        $this->expectException(QueryException::class);
        ProductVariant::factory()->for($product)->create(['sku' => 'SOLE-UNIQUE-001']);
    }

    public function test_business_setting_value_changes_increment_version(): void
    {
        $setting = \App\Models\BusinessSetting::query()->create([
            'key' => 'commerce.currency',
            'value' => ['code' => 'IRR'],
        ]);

        $this->assertSame(1, (int) $setting->version);

        $setting->update(['value' => ['code' => 'IRR', 'minor_unit' => 0]]);

        $this->assertSame(2, (int) $setting->fresh()->version);
    }
}
