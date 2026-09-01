<?php

namespace Tests\Feature;

use App\Models\BackInStockIntent;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryPdpConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_is_backend_authoritative_and_keeps_published_sold_out_products_visible(): void
    {
        $running = Category::factory()->create(['name' => 'Running', 'slug' => 'running', 'status' => 'published']);
        $location = InventoryLocation::factory()->create();

        $priority = Product::factory()->published()->for($running)->create([
            'name' => 'Alpha Runner',
            'brand' => 'SOLE Lab',
            'colorway' => 'Black',
            'tags' => ['new'],
            'merchandising_priority' => 50,
        ]);
        $priorityVariant = ProductVariant::factory()->for($priority)->create(['sku' => 'ALPHA-42', 'size' => '42', 'price_minor' => 1200000]);
        app(InventoryLedger::class)->adjust($priorityVariant, $location, 4, 'P05 fixture');

        $soldOut = Product::factory()->published()->for($running)->create([
            'name' => 'Beta Runner',
            'brand' => 'SOLE Lab',
            'colorway' => 'White',
            'merchandising_priority' => 10,
        ]);
        ProductVariant::factory()->for($soldOut)->create(['sku' => 'BETA-43', 'size' => '43', 'price_minor' => 900000]);

        Product::factory()->create(['name' => 'Draft Runner', 'brand' => 'SOLE Lab']);

        $response = $this->getJson('/api/v1/catalog/products?category=running&brand=SOLE%20Lab&sort=merchandising');
        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Runner')
            ->assertJsonPath('data.0.decision_support.availability.state', 'in_stock')
            ->assertJsonPath('data.1.name', 'Beta Runner')
            ->assertJsonPath('data.1.decision_support.availability.state', 'out_of_stock')
            ->assertJsonPath('data.1.decision_support.social_proof.state', 'unavailable')
            ->assertJsonPath('data.1.decision_support.delivery.state', 'unverified')
            ->assertJsonPath('data.1.decision_support.returns.state', 'unverified')
            ->assertJsonPath('facets.categories.0.value', 'running');

        $this->getJson('/api/v1/catalog/products?availability=in_stock')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/catalog/products?availability=out_of_stock')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/catalog/products?size=42')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/catalog/products?price_max_minor=1000000')->assertJsonPath('data.0.name', 'Beta Runner');
    }

    public function test_discovery_exposes_no_result_recovery_without_fabricating_results(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Velocity One', 'brand' => 'SOLE']);
        ProductVariant::factory()->for($product)->create();

        $this->getJson('/api/v1/catalog/products?q=Velociti%20One')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('recovery.original_query', 'Velociti One')
            ->assertJsonPath('recovery.suggested_query', 'Velocity One');
    }

    public function test_related_products_are_ranked_from_published_authoritative_inventory(): void
    {
        $category = Category::factory()->create(['name' => 'Running', 'slug' => 'running']);
        $subject = Product::factory()->published()->for($category)->create(['brand' => 'SOLE']);
        ProductVariant::factory()->for($subject)->create();

        $high = Product::factory()->published()->for($category)->create(['brand' => 'Other', 'merchandising_priority' => 20]);
        ProductVariant::factory()->for($high)->create();
        $low = Product::factory()->published()->for($category)->create(['brand' => 'SOLE', 'merchandising_priority' => 5]);
        ProductVariant::factory()->for($low)->create();

        $this->getJson("/api/v1/catalog/products/{$subject->slug}/related")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $high->id)
            ->assertJsonPath('data.1.id', $low->id);
    }

    public function test_back_in_stock_capture_requires_consent_is_idempotent_and_does_not_return_contact_data(): void
    {
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $payload = [
            'variant_id' => $variant->id,
            'email' => 'Person@Example.com',
            'consent' => true,
            'consent_version' => 'p05-v1',
        ];

        $this->postJson("/api/v1/catalog/products/{$product->slug}/back-in-stock", $payload)
            ->assertCreated()
            ->assertJsonMissing(['email' => 'Person@Example.com'])
            ->assertJsonPath('notification_delivery', 'deferred_to_p09');

        $this->postJson("/api/v1/catalog/products/{$product->slug}/back-in-stock", $payload)->assertOk();
        $this->assertDatabaseCount('back_in_stock_intents', 1);

        $intent = BackInStockIntent::query()->firstOrFail();
        $this->assertSame('person@example.com', $intent->contact_email);
        $this->assertSame(hash('sha256', 'person@example.com'), $intent->email_hash);
        $this->assertNotSame('person@example.com', $intent->getRawOriginal('contact_email'));

        $this->postJson("/api/v1/catalog/products/{$product->slug}/back-in-stock", [
            'variant_id' => $variant->id,
            'email' => 'person@example.com',
            'consent' => false,
            'consent_version' => 'p05-v1',
        ])->assertUnprocessable();
    }

    public function test_back_in_stock_rejects_foreign_or_available_variants(): void
    {
        $location = InventoryLocation::factory()->create();
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        app(InventoryLedger::class)->adjust($variant, $location, 1, 'P05 fixture');

        $other = Product::factory()->published()->create();
        $otherVariant = ProductVariant::factory()->for($other)->create();

        $payload = ['email' => 'person@example.com', 'consent' => true, 'consent_version' => 'p05-v1'];

        $this->postJson("/api/v1/catalog/products/{$product->slug}/back-in-stock", $payload + ['variant_id' => $variant->id])
            ->assertConflict()
            ->assertJsonPath('error', 'variant_already_available');

        $this->postJson("/api/v1/catalog/products/{$product->slug}/back-in-stock", $payload + ['variant_id' => $otherVariant->id])
            ->assertNotFound();
    }
}
