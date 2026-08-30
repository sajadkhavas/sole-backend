<?php

namespace Tests\Feature;

use App\Models\CatalogImportRun;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_never_mutates_and_apply_is_idempotent(): void
    {
        $asset = MediaAsset::create([
            'uuid' => (string) Str::uuid(), 'status' => MediaAsset::STATUS_READY,
            'quarantine_disk' => 'media_quarantine', 'quarantine_path' => 'done',
            'source_disk' => 'media_quarantine', 'source_path' => 'originals/a.png',
            'sha256' => str_repeat('a', 64), 'detected_mime' => 'image/png', 'bytes' => 100, 'width' => 20, 'height' => 20,
        ]);
        $path = storage_path('framework/testing/catalog-manifest.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode($this->manifest($asset->uuid), JSON_THROW_ON_ERROR));

        $service = app(CatalogImportService::class);
        $dry = $service->fromFile($path, false);
        $this->assertSame('dry_run', $dry['status']);
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Product::count());

        $applied = $service->fromFile($path, true);
        $this->assertSame('applied', $applied['status']);
        $this->assertSame(1, Category::count());
        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductVariant::count());
        $this->assertSame(1, CatalogImportRun::count());

        $again = $service->fromFile($path, true);
        $this->assertSame('already_applied', $again['status']);
        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductVariant::count());
        $this->assertSame(1, CatalogImportRun::count());
    }

    public function test_duplicate_sku_fails_preflight_before_mutation(): void
    {
        $manifest = $this->manifest((string) Str::uuid());
        $manifest['media'] = [];
        $manifest['variants'][] = $manifest['variants'][0];
        $path = storage_path('framework/testing/catalog-manifest-duplicate.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('CATALOG_SKU_DUPLICATE');
        app(CatalogImportService::class)->fromFile($path, false);
    }

    private function manifest(string $mediaUuid): array
    {
        return [
            'schema_version' => 1,
            'source' => 'test-fixture',
            'categories' => [['slug' => 'running', 'name' => 'Running', 'status' => 'published']],
            'collections' => [['slug' => 'launch', 'name' => 'Launch', 'status' => 'published']],
            'products' => [[
                'slug' => 'sole-runner', 'name' => 'SOLE Runner', 'category_slug' => 'running',
                'collection_slugs' => ['launch'], 'brand' => 'SOLE', 'colorway' => 'Black', 'tags' => ['launch'],
                'status' => 'published', 'published_at' => now()->subMinute()->toAtomString(),
            ]],
            'variants' => [[
                'product_slug' => 'sole-runner', 'sku' => 'SOLE-RUN-42', 'title' => '42 Black',
                'size' => '42', 'color' => 'Black', 'price_minor' => 100000, 'currency' => 'IRR', 'is_active' => true,
            ]],
            'media' => [[
                'media_uuid' => $mediaUuid, 'subject_type' => 'product', 'subject_key' => 'sole-runner',
                'role' => 'main', 'sort_order' => 0, 'alt_text' => 'SOLE Runner Black',
            ]],
        ];
    }
}
