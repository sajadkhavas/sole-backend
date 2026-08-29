<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_exposes_only_published_products_with_sellable_variants(): void
    {
        $location = InventoryLocation::factory()->create();

        $published = Product::factory()->published()->create(['slug' => 'available-shoe']);
        $availableVariant = ProductVariant::factory()->for($published)->create([
            'sku' => 'SOLE-AVAILABLE-001',
            'price_minor' => 12500000,
            'currency' => 'IRR',
        ]);
        app(InventoryLedger::class)->adjust($availableVariant, $location, 3, 'API fixture');

        $draft = Product::factory()->create(['slug' => 'draft-shoe']);
        $draftVariant = ProductVariant::factory()->for($draft)->create(['sku' => 'SOLE-DRAFT-001']);
        app(InventoryLedger::class)->adjust($draftVariant, $location, 3, 'API fixture');

        $outOfStock = Product::factory()->published()->create(['slug' => 'sold-out-shoe']);
        ProductVariant::factory()->for($outOfStock)->create(['sku' => 'SOLE-SOLDOUT-001']);

        $response = $this->getJson('/api/v1/catalog/products');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'available-shoe')
            ->assertJsonPath('data.0.variants.0.sku', 'SOLE-AVAILABLE-001')
            ->assertJsonPath('data.0.variants.0.price_minor', 12500000)
            ->assertJsonMissing(['slug' => 'draft-shoe'])
            ->assertJsonMissing(['slug' => 'sold-out-shoe']);

        $this->getJson('/api/v1/catalog/products/available-shoe')->assertOk();
        $this->getJson('/api/v1/catalog/products/draft-shoe')->assertNotFound();
        $this->getJson('/api/v1/catalog/products/sold-out-shoe')->assertNotFound();
    }

    public function test_readiness_endpoint_reports_database_readiness(): void
    {
        $this->getJson('/api/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    }
}
