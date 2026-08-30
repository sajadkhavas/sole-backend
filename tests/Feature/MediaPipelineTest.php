<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Services\Media\MediaAttachmentService;
use App\Services\Media\MediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_image_is_verified_reencoded_and_quarantine_is_removed(): void
    {
        Storage::fake('media_quarantine');
        Storage::fake('media_public');
        $asset = $this->assetWithPng();

        $ready = app(MediaProcessor::class)->process($asset);

        $this->assertSame(MediaAsset::STATUS_READY, $ready->status);
        $this->assertSame('image/png', $ready->detected_mime);
        $this->assertSame(3, $ready->variants->count());
        Storage::disk('media_quarantine')->assertMissing($asset->quarantine_path);
        Storage::disk('media_quarantine')->assertExists($ready->source_path);
        foreach ($ready->variants as $variant) {
            Storage::disk('media_public')->assertExists($variant->path);
            $this->assertSame('webp', $variant->format);
        }
    }

    public function test_corrupted_and_over_pixel_budget_images_fail_closed(): void
    {
        Storage::fake('media_quarantine');
        Storage::fake('media_public');
        $bad = MediaAsset::create([
            'uuid' => (string) Str::uuid(), 'status' => MediaAsset::STATUS_PENDING,
            'declared_mime' => 'image/png', 'quarantine_disk' => 'media_quarantine', 'quarantine_path' => 'uploads/bad',
        ]);
        Storage::disk('media_quarantine')->put('uploads/bad', 'not-an-image');

        try {
            app(MediaProcessor::class)->process($bad);
            $this->fail('Corrupted image was accepted.');
        } catch (\Throwable) {
            $this->assertSame(MediaAsset::STATUS_REJECTED, $bad->fresh()->status);
        }

        config(['sole_media.max_pixels' => 1]);
        $large = $this->assetWithPng('uploads/large');
        $this->expectExceptionMessage('MEDIA_DIMENSION_LIMIT');
        app(MediaProcessor::class)->process($large);
    }

    public function test_only_ready_assets_can_attach_to_product_truth(): void
    {
        $product = Product::factory()->create();
        $asset = MediaAsset::create([
            'uuid' => (string) Str::uuid(), 'status' => MediaAsset::STATUS_PENDING,
            'quarantine_disk' => 'media_quarantine', 'quarantine_path' => 'uploads/pending',
        ]);

        $this->expectExceptionMessage('MEDIA_ASSET_NOT_READY');
        app(MediaAttachmentService::class)->attach($asset, 'product', $product->getKey(), 'main');
    }

    private function assetWithPng(string $path = 'uploads/valid'): MediaAsset
    {
        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 160));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('media_quarantine')->put($path, $bytes);

        return MediaAsset::create([
            'uuid' => (string) Str::uuid(), 'status' => MediaAsset::STATUS_PENDING,
            'declared_mime' => 'image/png', 'quarantine_disk' => 'media_quarantine', 'quarantine_path' => $path,
        ]);
    }
}
