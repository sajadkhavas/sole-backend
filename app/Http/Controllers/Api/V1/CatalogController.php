<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CatalogProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min(100, $request->integer('per_page', 24)));

        $products = Product::query()
            ->published()
            ->whereHas('variants', fn ($query) => $query->sellable())
            ->with([
                'category:id,name,slug',
                'collections:id,name,slug',
                'mediaAttachments.asset.variants',
                'variants' => fn ($query) => $query->sellable()->with(['inventoryBalances', 'mediaAttachments.asset.variants']),
            ])
            ->orderByDesc('published_at')
            ->paginate($perPage);

        return CatalogProductResource::collection($products);
    }

    public function show(Product $product): CatalogProductResource
    {
        abort_unless(Product::query()->published()->whereKey($product->getKey())->whereHas('variants', fn ($query) => $query->sellable())->exists(), 404);

        $product->load([
            'category:id,name,slug',
            'collections:id,name,slug',
            'mediaAttachments.asset.variants',
            'variants' => fn ($query) => $query->sellable()->with(['inventoryBalances', 'mediaAttachments.asset.variants']),
        ]);

        return new CatalogProductResource($product);
    }
}
