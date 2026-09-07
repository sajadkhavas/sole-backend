<?php

namespace App\Services\Media;

use App\Contracts\MediaMalwareScanner;
use DomainException;

class TrustedAdminImageScanner implements MediaMalwareScanner
{
    public function assertClean(string $bytes): void
    {
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
    }

    private function isAnimatedWebp(string $bytes): bool
    {
        return str_contains(substr($bytes, 0, 64), 'ANIM') || str_contains($bytes, 'ANMF');
    }
}
