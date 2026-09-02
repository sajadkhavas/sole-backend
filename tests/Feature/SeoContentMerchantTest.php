<?php

namespace Tests\Feature;

use App\Models\ContentPage;
use App\Models\ContentPageRevision;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SeoRedirect;
use App\Services\Content\ContentPublicationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoContentMerchantTest extends TestCase
{
    use RefreshDatabase;

    public function test_governed_content_requires_review_and_supports_append_only_rollback(): void
    {
        $page = $this->page();
        $review = app(ContentPublicationService::class)->requestReview($page);
        $published = app(ContentPublicationService::class)->publish($review);

        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertSame(2, $published->version);
        $this->assertSame(2, ContentPageRevision::query()->count());

        $this->getJson('/api/v1/content/pages/shipping-guide')
            ->assertOk()
            ->assertJsonPath('data.seo.canonical_path', '/guides/shipping');

        $rolledBack = app(ContentPublicationService::class)->rollbackLatestPublication($published);
        $this->assertSame('review', $rolledBack->status);
        $this->getJson('/api/v1/content/pages/shipping-guide')->assertNotFound();

        $this->expectExceptionMessage('Content revisions are append-only.');
        ContentPageRevision::query()->firstOrFail()->delete();
    }

    public function test_unreviewed_content_cannot_publish(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CONTENT_PUBLISH_REQUIRES_REVIEW');
        app(ContentPublicationService::class)->publish($this->page());
    }

    public function test_manifest_exposes_seeded_policy_and_filters_unsafe_redirects(): void
    {
        SeoRedirect::create(['source_path' => '/old', 'destination_path' => '/new', 'status_code' => 308]);
        SeoRedirect::create(['source_path' => '/escape', 'destination_path' => '//evil.example', 'status_code' => 302]);

        $response = $this->getJson('/api/v1/seo/manifest')->assertOk();
        $this->assertContains('home', collect($response->json('data.routes'))->pluck('route_key'));
        $this->assertSame([[
            'source_path' => '/old',
            'destination_path' => '/new',
            'status_code' => 308,
        ]], $response->json('data.redirects'));
    }

    public function test_sitemap_contains_only_published_products_and_content(): void
    {
        $published = Product::factory()->create(['status' => 'published', 'published_at' => now(), 'slug' => 'published-shoe']);
        ProductVariant::factory()->create(['product_id' => $published->getKey(), 'is_active' => true]);
        $draft = Product::factory()->create(['status' => 'draft', 'published_at' => null, 'slug' => 'draft-shoe']);
        ProductVariant::factory()->create(['product_id' => $draft->getKey(), 'is_active' => true]);

        $paths = collect($this->getJson('/api/v1/seo/sitemap')->assertOk()->json('data.segments.products'))->pluck('path');
        $this->assertTrue($paths->contains('/product/published-shoe'));
        $this->assertFalse($paths->contains('/product/draft-shoe'));
    }

    public function test_merchant_feed_fails_closed_without_https_site_and_excludes_incomplete_product(): void
    {
        config()->set('sole.public_site_url', null);
        $this->getJson('/api/v1/merchant/products')->assertServiceUnavailable();

        config()->set('sole.public_site_url', 'https://sole.example');
        $product = Product::factory()->create(['status' => 'published', 'published_at' => now(), 'description' => 'Verified description']);
        ProductVariant::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);
        $this->getJson('/api/v1/merchant/products')
            ->assertOk()
            ->assertJsonPath('meta.generated_from', 'published_catalog')
            ->assertJsonPath('meta.submission_state', 'not_submitted')
            ->assertJsonCount(0, 'data');
    }

    private function page(): ContentPage
    {
        return ContentPage::create([
            'slug' => 'shipping-guide',
            'title' => 'Shipping guide',
            'summary' => 'How SOLE shipping works.',
            'blocks' => [['type' => 'prose', 'heading' => 'Shipping', 'body' => 'Verified guidance.']],
            'status' => 'draft',
            'seo_title' => 'SOLE shipping guide',
            'seo_description' => 'Verified shipping guidance for SOLE customers.',
            'canonical_path' => '/guides/shipping',
            'robots' => 'index,follow',
            'schema_type' => 'WebPage',
            'sitemap_segment' => 'content',
        ]);
    }
}
