<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductPublicationRevision;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductPublicationService;
use App\Services\Media\MediaAttachmentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPublicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_requires_review_accessible_media_and_supports_safe_publication_rollback(): void
    {
        $product = Product::factory()->create(['status' => 'draft', 'published_at' => null]);
        ProductVariant::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);
        $asset = MediaAsset::create([
            'uuid' => (string) Str::uuid(),
            'status' => MediaAsset::STATUS_READY,
            'quarantine_disk' => 'media_quarantine',
            'quarantine_path' => 'uploads/ready',
            'alt_text' => 'SOLE shoe profile',
        ]);
        app(MediaAttachmentService::class)->attach($asset, 'product', $product->getKey(), 'main');

        $review = app(ProductPublicationService::class)->requestReview($product);
        $this->assertSame('review', $review->status);

        $published = app(ProductPublicationService::class)->publish($review);
        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertSame(2, ProductPublicationRevision::query()->count());

        $rolledBack = app(ProductPublicationService::class)->rollbackLatestPublication($published);
        $this->assertSame('review', $rolledBack->status);
        $this->assertNull($rolledBack->published_at);
        $this->assertSame('rollback', ProductPublicationRevision::query()->latest('id')->value('action'));
    }

    public function test_publish_without_accessible_ready_media_fails_closed(): void
    {
        $product = Product::factory()->create(['status' => 'review', 'published_at' => null]);
        ProductVariant::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);

        $this->expectExceptionMessage('CATALOG_PUBLISH_ACCESSIBLE_MEDIA_REQUIRED');
        app(ProductPublicationService::class)->publish($product);
    }

    public function test_rollback_refuses_to_overwrite_a_newer_catalog_state(): void
    {
        $product = Product::factory()->create(['status' => 'draft', 'published_at' => null]);
        ProductVariant::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);
        $asset = MediaAsset::create([
            'uuid' => (string) Str::uuid(),
            'status' => MediaAsset::STATUS_READY,
            'quarantine_disk' => 'media_quarantine',
            'quarantine_path' => 'uploads/ready',
            'alt_text' => 'SOLE shoe profile',
        ]);
        app(MediaAttachmentService::class)->attach($asset, 'product', $product->getKey(), 'main');
        $review = app(ProductPublicationService::class)->requestReview($product);
        $published = app(ProductPublicationService::class)->publish($review);
        $published->forceFill(['status' => 'archived'])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CATALOG_PUBLICATION_ROLLBACK_STALE');
        app(ProductPublicationService::class)->rollbackLatestPublication($published);
    }

    public function test_publication_revision_is_append_only(): void
    {
        $product = Product::factory()->create();
        $revision = ProductPublicationRevision::create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->getKey(),
            'action' => 'review',
            'before' => ['status' => 'draft', 'published_at' => null],
            'after' => ['status' => 'review', 'published_at' => null],
            'created_at' => now(),
        ]);

        $this->expectExceptionMessage('Publication revisions are append-only.');
        $revision->delete();
    }
}
