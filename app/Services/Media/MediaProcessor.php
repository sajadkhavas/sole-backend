<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use DomainException;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MediaProcessor
{
    public function process(MediaAsset $asset): MediaAsset
    {
        if ($asset->status === MediaAsset::STATUS_READY) {
            return $asset->load('variants');
        }

        if ($asset->status !== MediaAsset::STATUS_PENDING) {
            throw new DomainException('MEDIA_NOT_PROCESSABLE');
        }

        $disk = Storage::disk($asset->quarantine_disk);

        try {
            if (! $disk->exists($asset->quarantine_path)) {
                throw new DomainException('MEDIA_UPLOAD_MISSING');
            }

            $bytesCount = $disk->size($asset->quarantine_path);
            if ($bytesCount < 1 || $bytesCount > (int) config('sole_media.max_bytes')) {
                throw new DomainException('MEDIA_SIZE_LIMIT');
            }

            $bytes = $disk->get($asset->quarantine_path);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
            if (! in_array($mime, config('sole_media.allowed_mimes'), true)) {
                throw new DomainException('MEDIA_MIME_REJECTED');
            }

            if ($mime === 'image/webp' && $this->isAnimatedWebp($bytes)) {
                throw new DomainException('MEDIA_ANIMATION_REJECTED');
            }

            $info = @getimagesizefromstring($bytes);
            if (! is_array($info) || ! isset($info[0], $info[1], $info['mime']) || $info['mime'] !== $mime) {
                throw new DomainException('MEDIA_DECODE_METADATA_INVALID');
            }

            $width = (int) $info[0];
            $height = (int) $info[1];
            if ($width < 1 || $height < 1
                || $width > (int) config('sole_media.max_width')
                || $height > (int) config('sole_media.max_height')
                || ($width * $height) > (int) config('sole_media.max_pixels')) {
                throw new DomainException('MEDIA_DIMENSION_LIMIT');
            }

            $image = @imagecreatefromstring($bytes);
            if (! $image instanceof GdImage) {
                throw new DomainException('MEDIA_DECODE_FAILED');
            }

            $sha = hash('sha256', $bytes);
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw new DomainException('MEDIA_EXTENSION_UNKNOWN'),
            };
            $sourcePath = 'originals/'.$sha.'.'.$extension;
            $sourceDisk = $asset->quarantine_disk;
            $deliveryDisk = (string) config('sole_media.delivery_disk');
            $recipeVersion = (int) config('sole_media.recipe_version');

            DB::transaction(function () use ($asset, $bytes, $bytesCount, $mime, $width, $height, $sha, $sourcePath, $sourceDisk, $deliveryDisk, $recipeVersion, $image): void {
                Storage::disk($sourceDisk)->put($sourcePath, $bytes);

                foreach ((array) config('sole_media.recipes') as $name => $recipe) {
                    [$variantBytes, $variantWidth, $variantHeight] = $this->render(
                        $image,
                        (int) $recipe['width'],
                        (int) $recipe['height'],
                        (string) $recipe['fit'],
                        (int) $recipe['quality'],
                        (float) $asset->focal_x,
                        (float) $asset->focal_y,
                    );
                    $path = $sha.'/v'.$recipeVersion.'/'.$name.'.webp';
                    Storage::disk($deliveryDisk)->put($path, $variantBytes, ['visibility' => 'public']);

                    MediaVariant::updateOrCreate(
                        ['media_asset_id' => $asset->getKey(), 'recipe' => $name, 'recipe_version' => $recipeVersion],
                        [
                            'format' => 'webp',
                            'width' => $variantWidth,
                            'height' => $variantHeight,
                            'bytes' => strlen($variantBytes),
                            'sha256' => hash('sha256', $variantBytes),
                            'disk' => $deliveryDisk,
                            'path' => $path,
                        ],
                    );
                }

                $asset->forceFill([
                    'status' => MediaAsset::STATUS_READY,
                    'detected_mime' => $mime,
                    'bytes' => $bytesCount,
                    'width' => $width,
                    'height' => $height,
                    'frame_count' => 1,
                    'sha256' => $sha,
                    'source_disk' => $sourceDisk,
                    'source_path' => $sourcePath,
                    'rejection_code' => null,
                    'ingested_at' => now(),
                ])->save();
            });

            imagedestroy($image);
            $disk->delete($asset->quarantine_path);

            return $asset->fresh()->load('variants');
        } catch (Throwable $exception) {
            $asset->forceFill([
                'status' => MediaAsset::STATUS_REJECTED,
                'rejection_code' => substr($exception->getMessage(), 0, 120),
            ])->save();
            throw $exception;
        }
    }

    private function isAnimatedWebp(string $bytes): bool
    {
        return str_contains(substr($bytes, 0, 64), 'ANIM') || str_contains($bytes, 'ANMF');
    }

    private function render(GdImage $source, int $targetWidth, int $targetHeight, string $fit, int $quality, float $focalX, float $focalY): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

        if ($fit === 'cover') {
            $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
            $cropWidth = $targetWidth / $scale;
            $cropHeight = $targetHeight / $scale;
            $maxX = max(0, $sourceWidth - $cropWidth);
            $maxY = max(0, $sourceHeight - $cropHeight);
            $srcX = (int) round(max(0, min($maxX, ($focalX * $sourceWidth) - ($cropWidth / 2))));
            $srcY = (int) round(max(0, min($maxY, ($focalY * $sourceHeight) - ($cropHeight / 2))));
            imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, (int) round($cropWidth), (int) round($cropHeight));
        } else {
            $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight, 1);
            $drawWidth = max(1, (int) round($sourceWidth * $scale));
            $drawHeight = max(1, (int) round($sourceHeight * $scale));
            $dstX = (int) floor(($targetWidth - $drawWidth) / 2);
            $dstY = (int) floor(($targetHeight - $drawHeight) / 2);
            imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
        }

        ob_start();
        $ok = imagewebp($canvas, null, max(0, min(100, $quality)));
        $encoded = ob_get_clean();
        imagedestroy($canvas);

        if (! $ok || ! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('MEDIA_WEBP_ENCODE_FAILED');
        }

        return [$encoded, $targetWidth, $targetHeight];
    }
}
