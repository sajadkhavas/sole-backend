<?php

namespace App\Services\Media;

use App\Models\Category;
use App\Models\Collection;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\ProductVariant;
use DomainException;
use Illuminate\Database\Eloquent\Model;

class MediaAttachmentService
{
    public const SUBJECTS = [
        'product' => Product::class,
        'variant' => ProductVariant::class,
        'category' => Category::class,
        'collection' => Collection::class,
    ];

    public function attach(MediaAsset $asset, string $subjectType, int $subjectId, string $role, int $sortOrder = 0, ?string $altText = null): MediaAttachment
    {
        if ($asset->status !== MediaAsset::STATUS_READY) {
            throw new DomainException('MEDIA_ASSET_NOT_READY');
        }

        $modelClass = self::SUBJECTS[$subjectType] ?? null;
        if ($modelClass === null || ! $modelClass::query()->whereKey($subjectId)->exists()) {
            throw new DomainException('MEDIA_ATTACHMENT_SUBJECT_INVALID');
        }

        $resolvedAlt = trim((string) ($altText ?: $asset->alt_text));
        if ($role !== 'decorative' && $resolvedAlt === '') {
            throw new DomainException('MEDIA_ALT_TEXT_REQUIRED');
        }

        return MediaAttachment::updateOrCreate(
            [
                'media_asset_id' => $asset->getKey(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'role' => $role,
                'sort_order' => max(0, $sortOrder),
            ],
            ['alt_text' => $role === 'decorative' ? null : $resolvedAlt],
        );
    }

    public function subject(string $type, string $key): Model
    {
        return match ($type) {
            'product' => Product::query()->where('slug', $key)->firstOrFail(),
            'variant' => ProductVariant::query()->where('sku', $key)->firstOrFail(),
            'category' => Category::query()->where('slug', $key)->firstOrFail(),
            'collection' => Collection::query()->where('slug', $key)->firstOrFail(),
            default => throw new DomainException('MEDIA_ATTACHMENT_SUBJECT_TYPE_INVALID'),
        };
    }
}
