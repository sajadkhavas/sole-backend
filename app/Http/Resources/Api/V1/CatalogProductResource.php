<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'published_at' => $this->published_at?->toAtomString(),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ] : null),
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->map(fn ($collection) => [
                'id' => $collection->id,
                'slug' => $collection->slug,
                'name' => $collection->name,
            ])->values()),
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
            ])->values()),
        ];
    }
}
