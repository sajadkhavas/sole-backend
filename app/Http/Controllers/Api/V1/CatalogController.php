<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CatalogProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'size' => ['nullable', 'string', 'max:32'],
            'availability' => ['nullable', Rule::in(['all', 'in_stock', 'out_of_stock'])],
            'price_max_minor' => ['nullable', 'integer', 'min:0'],
            'quick' => ['nullable', Rule::in(['all', 'new', 'sale', 'limited'])],
            'sort' => ['nullable', Rule::in(['relevance', 'merchandising', 'newest', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        $perPage = (int) ($filters['per_page'] ?? 24);
        $products = $query->paginate($perPage)->withQueryString();
        $recovery = $this->recovery($filters, (int) $products->total());

        return CatalogProductResource::collection($products)->additional([
            'facets' => $this->facets(),
            'recovery' => $recovery,
        ]);
    }

    public function show(Product $product): CatalogProductResource
    {
        $product = Product::query()
            ->published()
            ->whereKey($product->getKey())
            ->whereHas('variants', fn (Builder $query) => $query->active())
            ->with($this->relations())
            ->firstOrFail();

        return new CatalogProductResource($product);
    }

    public function related(Product $product): AnonymousResourceCollection
    {
        $product = Product::query()->published()->with('category')->findOrFail($product->getKey());

        $related = $this->baseQuery()
            ->whereKeyNot($product->getKey())
            ->where(function (Builder $query) use ($product): void {
                if ($product->brand) {
                    $query->where('brand', $product->brand);
                }
                if ($product->category_id) {
                    $product->brand
                        ? $query->orWhere('category_id', $product->category_id)
                        : $query->where('category_id', $product->category_id);
                }
            })
            ->orderByDesc('merchandising_priority')
            ->orderByDesc('published_at')
            ->limit(8)
            ->get();

        return CatalogProductResource::collection($related);
    }

    private function baseQuery(): Builder
    {
        return Product::query()
            ->published()
            ->whereHas('variants', fn (Builder $query) => $query->active())
            ->with($this->relations());
    }

    private function relations(): array
    {
        return [
            'category',
            'collections',
            'mediaAttachments.asset.variants',
            'sizeGuide.entries',
            'variants' => fn ($query) => $query->active()->with(['inventoryBalances', 'mediaAttachments.asset.variants']),
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(brand, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw('LOWER(COALESCE(colorway, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                    ->orWhereHas('variants', fn (Builder $variant) => $variant->active()->whereRaw('LOWER(sku) LIKE ?', [$like]));
            });
        }

        if (! empty($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }
        if (! empty($filters['category'])) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category']));
        }
        if (! empty($filters['size'])) {
            $query->whereHas('variants', fn (Builder $variant) => $variant->active()->where('size', $filters['size']));
        }
        if (isset($filters['price_max_minor'])) {
            $query->whereHas('variants', fn (Builder $variant) => $variant->active()->where('price_minor', '<=', $filters['price_max_minor']));
        }

        $availability = $filters['availability'] ?? 'all';
        if ($availability === 'in_stock') {
            $query->whereHas('variants', fn (Builder $variant) => $variant->sellable());
        } elseif ($availability === 'out_of_stock') {
            $query->whereDoesntHave('variants', fn (Builder $variant) => $variant->sellable());
        }

        match ($filters['quick'] ?? 'all') {
            'new' => $query->whereJsonContains('tags', 'new'),
            'limited' => $query->whereJsonContains('tags', 'limited'),
            'sale' => $query->whereHas('variants', fn (Builder $variant) => $variant->active()->whereColumn('compare_at_price_minor', '>', 'price_minor')),
            default => null,
        };
    }

    private function applySort(Builder $query, array $filters): void
    {
        $price = ProductVariant::query()
            ->select('price_minor')
            ->whereColumn('product_id', 'products.id')
            ->where('is_active', true)
            ->orderBy('price_minor')
            ->limit(1);

        match ($filters['sort'] ?? 'relevance') {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'price_asc' => $query->orderBy($price)->orderByDesc('merchandising_priority')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc($price)->orderByDesc('merchandising_priority')->orderByDesc('id'),
            'merchandising' => $query->orderByDesc('merchandising_priority')->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('merchandising_priority')->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    private function facets(): array
    {
        $published = Product::query()->published()->whereHas('variants', fn (Builder $query) => $query->active());

        $brands = (clone $published)
            ->whereNotNull('brand')
            ->selectRaw('brand, COUNT(*) as total')
            ->groupBy('brand')
            ->orderBy('brand')
            ->get()
            ->map(fn (Product $product) => ['value' => $product->brand, 'count' => (int) $product->total])
            ->values();

        $categories = Category::query()
            ->whereHas('products', fn (Builder $query) => $query->published()->whereHas('variants', fn (Builder $variant) => $variant->active()))
            ->withCount(['products' => fn (Builder $query) => $query->published()->whereHas('variants', fn (Builder $variant) => $variant->active())])
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => ['value' => $category->slug, 'label' => $category->name, 'count' => (int) $category->products_count])
            ->values();

        $sizes = ProductVariant::query()
            ->active()
            ->whereNotNull('size')
            ->whereHas('product', fn (Builder $query) => $query->published())
            ->selectRaw('size, COUNT(DISTINCT product_id) as total')
            ->groupBy('size')
            ->orderBy('size')
            ->get()
            ->map(fn (ProductVariant $variant) => ['value' => (string) $variant->size, 'count' => (int) $variant->total])
            ->values();

        return [
            'brands' => $brands,
            'categories' => $categories,
            'sizes' => $sizes,
            'availability' => ['in_stock', 'out_of_stock'],
        ];
    }

    private function recovery(array $filters, int $total): ?array
    {
        $original = trim((string) ($filters['q'] ?? ''));
        if ($original === '' || $total > 0) {
            return null;
        }

        $needle = mb_strtolower($original);
        $candidates = Product::query()
            ->published()
            ->whereHas('variants', fn (Builder $query) => $query->active())
            ->limit(200)
            ->get(['name', 'brand'])
            ->flatMap(fn (Product $product) => [$product->name, $product->brand])
            ->filter()
            ->unique()
            ->values();

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $distance = levenshtein($needle, mb_strtolower((string) $candidate));
            if ($distance < $bestDistance) {
                $best = (string) $candidate;
                $bestDistance = $distance;
            }
        }

        $threshold = max(2, (int) floor(mb_strlen($needle) * 0.34));
        if ($best === null || $bestDistance > $threshold) {
            return ['original_query' => $original, 'suggested_query' => null];
        }

        return ['original_query' => $original, 'suggested_query' => $best];
    }
}
