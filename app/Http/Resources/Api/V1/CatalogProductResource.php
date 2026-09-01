<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MediaAttachment;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class CatalogProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, ProductVariant> $variants */
        $variants = $this->relationLoaded('variants') ? $this->variants : collect();
        $availableQuantity = $variants->sum(fn (ProductVariant $variant): int => $this->availableQuantity($variant));
        $availableSizes = $variants
            ->filter(fn (ProductVariant $variant): bool => $this->availableQuantity($variant) > 0 && $variant->size !== null)
            ->pluck('size')
            ->map(fn ($size): string => (string) $size)
            ->unique()
            ->values();
        $prices = $variants->pluck('price_minor')->map(fn ($price): int => (int) $price);
        $currencies = $variants->pluck('currency')->filter()->unique()->values();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand,
            'colorway' => $this->colorway,
            'tags' => $this->tags ?? [],
            'published_at' => $this->published_at?->toAtomString(),
            'merchandising_priority' => (int) ($this->merchandising_priority ?? 0),
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'slug' => $this->category->slug, 'name' => $this->category->name] : null),
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->map(fn ($collection) => ['id' => $collection->id, 'slug' => $collection->slug, 'name' => $collection->name])->values()),
            'media' => $this->whenLoaded('mediaAttachments', fn () => $this->mediaAttachments->filter(fn ($attachment) => $attachment->asset?->status === 'ready')->map(fn ($attachment) => $this->media($attachment))->values()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'title' => $variant->title,
                'size' => $variant->size,
                'color' => $variant->color,
                'price_minor' => (int) $variant->price_minor,
                'compare_at_price_minor' => $variant->compare_at_price_minor === null ? null : (int) $variant->compare_at_price_minor,
                'currency' => $variant->currency,
                'available_quantity' => $this->availableQuantity($variant),
                'availability' => $this->availableQuantity($variant) > 0 ? 'in_stock' : 'out_of_stock',
                'media' => $variant->relationLoaded('mediaAttachments') ? $variant->mediaAttachments->filter(fn ($attachment) => $attachment->asset?->status === 'ready')->map(fn ($attachment) => $this->media($attachment))->values() : [],
            ])->values()),
            'size_guide' => $this->whenLoaded('sizeGuide', function () {
                if (! $this->sizeGuide || $this->sizeGuide->status !== 'published') {
                    return null;
                }

                return [
                    'source_label' => $this->sizeGuide->source_label,
                    'source_url' => $this->sizeGuide->source_url,
                    'measurement_unit' => $this->sizeGuide->measurement_unit,
                    'width_profile' => $this->sizeGuide->width_profile,
                    'verified_at' => $this->sizeGuide->verified_at?->toAtomString(),
                    'entries' => $this->sizeGuide->entries->map(fn ($entry) => [
                        'eu_size' => (string) $entry->eu_size,
                        'foot_length_min_mm' => (int) $entry->foot_length_min_mm,
                        'foot_length_max_mm' => (int) $entry->foot_length_max_mm,
                        'label' => $entry->label,
                    ])->values(),
                ];
            }),
            'decision_support' => [
                'availability' => [
                    'state' => $availableQuantity > 0 ? 'in_stock' : 'out_of_stock',
                    'available_quantity' => $availableQuantity,
                    'available_sizes' => $availableSizes,
                ],
                'pricing' => [
                    'currency' => $currencies->count() === 1 ? $currencies->first() : null,
                    'min_price_minor' => $prices->isEmpty() ? null : $prices->min(),
                    'max_price_minor' => $prices->isEmpty() ? null : $prices->max(),
                ],
                'comparison' => [
                    'brand' => $this->brand,
                    'category' => $this->relationLoaded('category') ? $this->category?->name : null,
                    'colorway' => $this->colorway,
                    'sizes' => $variants->pluck('size')->filter()->map(fn ($size): string => (string) $size)->unique()->values(),
                    'variant_count' => $variants->count(),
                ],
                'social_proof' => [
                    'state' => 'unavailable',
                    'average_rating' => null,
                    'review_count' => 0,
                    'evidence' => null,
                ],
                'delivery' => [
                    'state' => 'unverified',
                    'message' => null,
                ],
                'returns' => [
                    'state' => 'unverified',
                    'message' => null,
                ],
            ],
        ];
    }

    private function availableQuantity(ProductVariant $variant): int
    {
        if (! $variant->relationLoaded('inventoryBalances')) {
            return 0;
        }

        return (int) $variant->inventoryBalances->sum(fn ($balance): int => max(0, (int) $balance->on_hand - (int) $balance->reserved));
    }

    private function media(MediaAttachment $attachment): array
    {
        return [
            'asset_uuid' => $attachment->asset->uuid,
            'role' => $attachment->role,
            'sort_order' => (int) $attachment->sort_order,
            'alt_text' => $attachment->alt_text ?: $attachment->asset->alt_text,
            'sources' => $attachment->asset->variants->sortBy('width')->map(fn ($variant) => [
                'recipe' => $variant->recipe,
                'url' => Storage::disk($variant->disk)->url($variant->path),
                'width' => (int) $variant->width,
                'height' => (int) $variant->height,
                'format' => $variant->format,
                'sha256' => $variant->sha256,
            ])->values(),
        ];
    }
}
