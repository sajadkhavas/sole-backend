<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SeoRedirect;
use App\Models\SeoRoutePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SeoContentController extends Controller
{
    public function content(ContentPage $page): JsonResponse
    {
        $page = ContentPage::query()->published()->whereKey($page->getKey())->firstOrFail();

        return response()->json(['data' => $this->contentPayload($page)]);
    }

    public function manifest(): JsonResponse
    {
        return response()->json(['data' => [
            'routes' => SeoRoutePolicy::query()->where('is_active', true)->orderBy('route_key')->get()->map(fn (SeoRoutePolicy $policy) => [
                'route_key' => $policy->route_key,
                'path_pattern' => $policy->path_pattern,
                'canonical_policy' => $policy->canonical_policy,
                'robots_policy' => $policy->robots_policy,
                'schema_type' => $policy->schema_type,
                'sitemap_segment' => $policy->sitemap_segment,
                'facets_indexable' => $policy->facets_indexable,
            ])->values(),
            'content' => ContentPage::query()->published()->orderBy('canonical_path')->get()->map(fn (ContentPage $page) => $this->contentPayload($page))->values(),
            'redirects' => SeoRedirect::query()->where('is_active', true)->orderBy('source_path')->get()->filter(fn (SeoRedirect $redirect): bool => $this->safeRedirect($redirect))->map(fn (SeoRedirect $redirect) => [
                'source_path' => $redirect->source_path,
                'destination_path' => $redirect->destination_path,
                'status_code' => $redirect->status_code,
            ])->values(),
        ]]);
    }

    public function sitemap(): JsonResponse
    {
        $products = Product::query()->published()
            ->whereHas('variants', fn (Builder $query) => $query->active())
            ->orderBy('slug')->get(['slug', 'updated_at']);

        return response()->json(['data' => ['segments' => [
            'core' => collect(['/', '/products', '/brands'])->map(fn (string $path) => ['path' => $path, 'last_modified' => null]),
            'content' => ContentPage::query()->published()->orderBy('canonical_path')->get()->map(fn (ContentPage $page) => [
                'path' => $page->canonical_path,
                'last_modified' => $page->updated_at?->toAtomString(),
            ])->values(),
            'products' => $products->map(fn (Product $product) => [
                'path' => '/product/'.$product->slug,
                'last_modified' => $product->updated_at?->toAtomString(),
            ])->values(),
        ]]]);
    }

    public function merchantProducts(): JsonResponse
    {
        $siteUrl = $this->publicSiteUrl();
        $products = Product::query()->published()
            ->whereHas('variants', fn (Builder $query) => $query->active())
            ->with([
                'mediaAttachments.asset.variants',
                'variants' => fn ($query) => $query->active()->with('inventoryBalances'),
            ])->orderBy('id')->get();

        $items = $products->map(function (Product $product) use ($siteUrl): ?array {
            $variants = $product->variants;
            $currencies = $variants->pluck('currency')->filter()->unique();
            $price = $variants->min('price_minor');
            $image = $product->mediaAttachments
                ->filter(fn ($attachment) => $attachment->asset?->status === 'ready')
                ->flatMap(fn ($attachment) => $attachment->asset->variants)
                ->sortByDesc('width')->first();
            if ($price === null || $currencies->count() !== 1 || $image === null || trim((string) $product->description) === '') {
                return null;
            }

            $available = $variants->contains(fn (ProductVariant $variant): bool => $variant->inventoryBalances->sum(
                fn ($balance): int => max(0, (int) $balance->on_hand - (int) $balance->reserved)
            ) > 0);

            return [
                'id' => 'sole-product-'.$product->getKey(),
                'title' => $product->name,
                'description' => $product->description,
                'link' => $siteUrl.'/product/'.$product->slug,
                'image_link' => $this->absoluteUrl(Storage::disk($image->disk)->url($image->path), $siteUrl),
                'availability' => $available ? 'in_stock' : 'out_of_stock',
                'price_minor' => (int) $price,
                'currency' => $currencies->first(),
                'brand' => $product->brand,
                'condition' => 'new',
                'updated_at' => $product->updated_at?->toAtomString(),
            ];
        })->filter()->values();

        return response()->json(['data' => $items, 'meta' => [
            'generated_from' => 'published_catalog',
            'submission_state' => 'not_submitted',
            'item_count' => $items->count(),
        ]]);
    }

    private function contentPayload(ContentPage $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'summary' => $page->summary,
            'blocks' => $page->blocks,
            'version' => $page->version,
            'published_at' => $page->published_at?->toAtomString(),
            'seo' => [
                'title' => $page->seo_title,
                'description' => $page->seo_description,
                'canonical_path' => $page->canonical_path,
                'robots' => $page->robots,
                'schema_type' => $page->schema_type,
                'sitemap_segment' => $page->sitemap_segment,
            ],
        ];
    }

    private function publicSiteUrl(): string
    {
        $value = rtrim((string) config('sole.public_site_url'), '/');
        $parts = parse_url($value);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new HttpException(503, 'MERCHANT_PUBLIC_SITE_URL_UNAVAILABLE');
        }

        return $value;
    }

    private function absoluteUrl(string $value, string $siteUrl): string
    {
        if (str_starts_with($value, 'https://')) {
            return $value;
        }

        return $siteUrl.'/'.ltrim($value, '/');
    }

    private function safeRedirect(SeoRedirect $redirect): bool
    {
        return str_starts_with($redirect->source_path, '/')
            && str_starts_with($redirect->destination_path, '/')
            && ! str_starts_with($redirect->source_path, '//')
            && ! str_starts_with($redirect->destination_path, '//')
            && $redirect->source_path !== $redirect->destination_path
            && in_array($redirect->status_code, [301, 302, 307, 308], true);
    }
}
