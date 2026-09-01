<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SizeGuide;
use App\Models\User;
use App\Services\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SizeFitIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_only_published_source_backed_size_guide(): void
    {
        [$product] = $this->sellableProduct();
        $guide = $this->guide($product, verified: true);

        $this->getJson('/api/v1/catalog/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.size_guide.source_label', 'Official brand chart')
            ->assertJsonPath('data.size_guide.entries.0.eu_size', '41.0');

        $guide->update(['status' => 'draft']);
        $this->getJson('/api/v1/catalog/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.size_guide', null);
    }

    public function test_recommendation_is_confidence_aware_idempotent_and_does_not_store_measurement(): void
    {
        [$product] = $this->sellableProduct();
        $this->guide($product, verified: true);
        $requestId = (string) Str::uuid();

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/catalog/products/'.$product->slug.'/fit/recommendation', [
                'foot_length_mm' => 257,
                'request_id' => $requestId,
            ])->assertOk()
                ->assertJsonPath('data.recommended_eu_size', '42.0')
                ->assertJsonPath('data.confidence', 'high')
                ->assertJsonStructure(['data' => ['reason', 'disclaimer']]);
        }

        $this->assertDatabaseCount('fit_events', 1);
        $event = (array) $this->getConnection()->table('fit_events')->first();
        $this->assertArrayNotHasKey('foot_length_mm', $event);
    }

    public function test_out_of_range_measurement_never_returns_false_certainty(): void
    {
        [$product] = $this->sellableProduct();
        $this->guide($product, verified: true);

        $this->postJson('/api/v1/catalog/products/'.$product->slug.'/fit/recommendation', ['foot_length_mm' => 310])
            ->assertOk()
            ->assertJsonPath('data.recommended_eu_size', null)
            ->assertJsonPath('data.confidence', 'low')
            ->assertJsonPath('data.reason', 'measurement_outside_supported_range');
    }

    public function test_fit_feedback_requires_authentication_and_variant_ownership(): void
    {
        [$product, $variant] = $this->sellableProduct();
        [$otherProduct, $otherVariant] = $this->sellableProduct('other-shoe');
        $payload = ['product_variant_id' => $variant->id, 'purchased_size' => '42', 'overall_fit' => 'true_to_size', 'width_fit' => 'standard'];

        $this->putJson('/api/v1/catalog/products/'.$product->slug.'/fit/feedback', $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/v1/catalog/products/'.$product->slug.'/fit/feedback', [
            ...$payload,
            'product_variant_id' => $otherVariant->id,
        ])->assertUnprocessable();

        $this->actingAs($user)->putJson('/api/v1/catalog/products/'.$product->slug.'/fit/feedback', $payload)
            ->assertCreated()
            ->assertJsonPath('data.overall_fit', 'true_to_size');
    }

    private function sellableProduct(?string $slug = 'fit-shoe'): array
    {
        $location = InventoryLocation::factory()->create();
        $product = Product::factory()->published()->create(['slug' => $slug, 'brand' => 'SOLE Test']);
        $variant = ProductVariant::factory()->for($product)->create(['sku' => strtoupper($slug).'-42', 'size' => '42']);
        app(InventoryLedger::class)->adjust($variant, $location, 5, 'P04 fixture');
        return [$product, $variant];
    }

    private function guide(Product $product, bool $verified): SizeGuide
    {
        $guide = SizeGuide::query()->create([
            'product_id' => $product->id,
            'status' => 'published',
            'source_label' => 'Official brand chart',
            'source_url' => 'https://example.test/official-size-chart',
            'measurement_unit' => 'mm',
            'width_profile' => 'standard',
            'verified_at' => $verified ? now() : null,
        ]);
        $guide->entries()->createMany([
            ['eu_size' => 41, 'foot_length_min_mm' => 248, 'foot_length_max_mm' => 252],
            ['eu_size' => 42, 'foot_length_min_mm' => 253, 'foot_length_max_mm' => 258],
            ['eu_size' => 43, 'foot_length_min_mm' => 259, 'foot_length_max_mm' => 264],
        ]);
        return $guide;
    }
}
