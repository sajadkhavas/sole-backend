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

    public function test_public_catalog_exposes_published_products_with_active_variants_and_honest_availability(): void
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
        ProductVariant::factory()->for($draft)->create(['sku' => 'SOLE-DRAFT-001']);

        $outOfStock = Product::factory()->published()->create(['slug' => 'sold-out-shoe']);
        ProductVariant::factory()->for($outOfStock)->create(['sku' => 'SOLE-SOLDOUT-001']);

        $response = $this->getJson('/api/v1/catalog/products?sort=newest');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['slug' => 'draft-shoe']);

        $available = collect($response->json('data'))->firstWhere('slug', 'available-shoe');
        $soldOut = collect($response->json('data'))->firstWhere('slug', 'sold-out-shoe');
        $this->assertSame(12500000, $available['variants'][0]['price_minor']);
        $this->assertSame('in_stock', $available['decision_support']['availability']['state']);
        $this->assertSame('out_of_stock', $soldOut['decision_support']['availability']['state']);

        $this->getJson('/api/v1/catalog/products/available-shoe')->assertOk();
        $this->getJson('/api/v1/catalog/products/draft-shoe')->assertNotFound();
        $this->getJson('/api/v1/catalog/products/sold-out-shoe')
            ->assertOk()
            ->assertJsonPath('data.decision_support.availability.state', 'out_of_stock');
    }

    public function test_readiness_endpoint_reports_database_readiness(): void
    {
        $this->getJson('/api/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    }
}
