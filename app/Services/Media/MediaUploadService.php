<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaUploadService
{
    public function createIntent(User $user, string $filename, string $declaredMime, int $declaredBytes): array
    {
        if (! $user->hasPermission('catalog.update')) {
            abort(403);
        }

        if (! in_array($declaredMime, config('sole_media.allowed_mimes'), true)) {
            throw new InvalidArgumentException('MEDIA_DECLARED_MIME_NOT_ALLOWED');
        }

        if ($declaredBytes < 1 || $declaredBytes > (int) config('sole_media.max_bytes')) {
            throw new InvalidArgumentException('MEDIA_DECLARED_SIZE_INVALID');
        }

        $uuid = (string) Str::uuid();
        $disk = (string) config('sole_media.quarantine_disk');
        $path = 'uploads/'.$uuid;

        $asset = MediaAsset::create([
            'uuid' => $uuid,
            'created_by' => $user->getKey(),
            'status' => MediaAsset::STATUS_PENDING,
            'original_filename' => basename($filename),
            'declared_mime' => $declaredMime,
            'bytes' => $declaredBytes,
            'quarantine_disk' => $disk,
            'quarantine_path' => $path,
        ]);

        $expiresAt = now()->addMinutes(max(1, (int) config('sole_media.upload_ttl_minutes')));
        $upload = Storage::disk($disk)->temporaryUploadUrl($path, $expiresAt, ['Content-Type' => $declaredMime]);

        return [
            'asset_uuid' => $asset->uuid,
            'upload_url' => $upload['url'],
            'headers' => $upload['headers'],
            'expires_at' => $expiresAt->toAtomString(),
        ];
    }
}
