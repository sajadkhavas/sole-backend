<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MediaAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CatalogProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand,
            'colorway' => $this->colorway,
            'tags' => $this->tags ?? [],
            'published_at' => $this->published_at?->toAtomString(),
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'slug' => $this->category->slug, 'name' => $this->category->name] : null),
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->map(fn ($collection) => ['id' => $collection->id, 'slug' => $collection->slug, 'name' => $collection->name])->values()),
            'media' => $this->whenLoaded('mediaAttachments', fn () => $this->mediaAttachments->filter(fn ($attachment) => $attachment->asset?->status === 'ready')->map(fn ($attachment) => $this->media($attachment))->values()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'title' => $variant->title,
                'size' => $variant->size,
                'color' => $variant->color,
                'price_minor' => (int) $variant->price_minor,
                'compare_at_price_minor' => $variant->compare_at_price_minor === null ? null : (int) $variant->compare_at_price_minor,
                'currency' => $variant->currency,
                'available_quantity' => (int) $variant->inventoryBalances->sum(fn ($balance) => max(0, (int) $balance->on_hand - (int) $balance->reserved)),
                'media' => $variant->relationLoaded('mediaAttachments') ? $variant->mediaAttachments->filter(fn ($attachment) => $attachment->asset?->status === 'ready')->map(fn ($attachment) => $this->media($attachment))->values() : [],
            ])->values()),
        ];
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
