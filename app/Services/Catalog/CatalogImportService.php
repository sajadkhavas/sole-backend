<?php

namespace App\Services\Catalog;

use App\Models\CatalogImportRun;
use App\Models\Category;
use App\Models\Collection;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Media\MediaAttachmentService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class CatalogImportService
{
    public function __construct(private readonly MediaAttachmentService $attachments) {}

    public function fromFile(string $path, bool $apply = false): array
    {
        if (is_file($path) === false || is_readable($path) === false) {
            throw new DomainException('CATALOG_MANIFEST_UNREADABLE');
        }

        $raw = file_get_contents($path);
        if (is_string($raw) === false) {
            throw new DomainException('CATALOG_MANIFEST_UNREADABLE');
        }

        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('CATALOG_MANIFEST_JSON_INVALID');
        }

        if (is_array($manifest) === false) {
            throw new DomainException('CATALOG_MANIFEST_ROOT_INVALID');
        }

        $report = $this->preflight($manifest);
        $sha = hash('sha256', $raw);

        if ($apply === false) {
            return ['status' => 'dry_run', 'manifest_sha256' => $sha, 'report' => $report];
        }

        if ($existing = CatalogImportRun::query()->where('manifest_sha256', $sha)->first()) {
            return ['status' => 'already_applied', 'manifest_sha256' => $sha, 'run_uuid' => $existing->uuid, 'report' => $existing->report];
        }

        return DB::transaction(function () use ($manifest, $report, $sha): array {
            $categories = [];
            foreach ($manifest['categories'] ?? [] as $row) {
                $categories[$row['slug']] = Category::updateOrCreate(
                    ['slug' => $row['slug']],
                    ['name' => $row['name'], 'status' => $row['status'] ?? 'draft'],
                );
            }

            $collections = [];
            foreach ($manifest['collections'] ?? [] as $row) {
                $collections[$row['slug']] = Collection::updateOrCreate(
                    ['slug' => $row['slug']],
                    ['name' => $row['name'], 'status' => $row['status'] ?? 'draft'],
                );
            }

            $products = [];
            foreach ($manifest['products'] ?? [] as $row) {
                $categoryId = isset($row['category_slug']) ? ($categories[$row['category_slug']]->getKey() ?? null) : null;
                $product = Product::updateOrCreate(
                    ['slug' => $row['slug']],
                    [
                        'category_id' => $categoryId,
                        'name' => $row['name'],
                        'description' => $row['description'] ?? null,
                        'brand' => $row['brand'] ?? null,
                        'colorway' => $row['colorway'] ?? null,
                        'tags' => $row['tags'] ?? null,
                        'status' => $row['status'] ?? 'draft',
                        'published_at' => $row['published_at'] ?? null,
                    ],
                );
                $product->collections()->sync(collect($row['collection_slugs'] ?? [])->map(fn ($slug) => $collections[$slug]->getKey())->all());
                $products[$row['slug']] = $product;
            }

            foreach ($manifest['variants'] ?? [] as $row) {
                ProductVariant::updateOrCreate(
                    ['sku' => $row['sku']],
                    [
                        'product_id' => $products[$row['product_slug']]->getKey(),
                        'title' => $row['title'],
                        'size' => $row['size'] ?? null,
                        'color' => $row['color'] ?? null,
                        'price_minor' => $row['price_minor'],
                        'compare_at_price_minor' => $row['compare_at_price_minor'] ?? null,
                        'currency' => $row['currency'] ?? 'IRR',
                        'is_active' => $row['is_active'] ?? true,
                    ],
                );
            }

            foreach ($manifest['media'] ?? [] as $row) {
                $asset = MediaAsset::query()->where('uuid', $row['media_uuid'])->firstOrFail();
                $subject = $this->attachments->subject($row['subject_type'], $row['subject_key']);
                $this->attachments->attach(
                    $asset,
                    $row['subject_type'],
                    (int) $subject->getKey(),
                    $row['role'],
                    (int) ($row['sort_order'] ?? 0),
                    $row['alt_text'] ?? null,
                );
            }

            $uuid = (string) Str::uuid();
            CatalogImportRun::create([
                'uuid' => $uuid,
                'manifest_sha256' => $sha,
                'manifest_version' => (int) $manifest['schema_version'],
                'source' => $manifest['source'] ?? null,
                'status' => 'applied',
                'report' => $report,
                'applied_at' => now(),
            ]);

            return ['status' => 'applied', 'manifest_sha256' => $sha, 'run_uuid' => $uuid, 'report' => $report];
        }, 3);
    }

    public function preflight(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new DomainException('CATALOG_MANIFEST_VERSION_UNSUPPORTED');
        }

        foreach (['categories', 'collections', 'products', 'variants', 'media'] as $key) {
            if (isset($manifest[$key]) && is_array($manifest[$key]) === false) {
                throw new DomainException('CATALOG_MANIFEST_SECTION_INVALID_'.$key);
            }
        }

        $categorySlugs = $this->uniqueKeys($manifest['categories'] ?? [], 'slug', 'CATEGORY');
        $collectionSlugs = $this->uniqueKeys($manifest['collections'] ?? [], 'slug', 'COLLECTION');
        $productSlugs = $this->uniqueKeys($manifest['products'] ?? [], 'slug', 'PRODUCT');
        $skus = $this->uniqueKeys($manifest['variants'] ?? [], 'sku', 'SKU');

        foreach ($manifest['categories'] ?? [] as $row) {
            $this->requiredString($row, 'name', 'CATEGORY_NAME');
        }

        foreach ($manifest['collections'] ?? [] as $row) {
            $this->requiredString($row, 'name', 'COLLECTION_NAME');
        }

        foreach ($manifest['products'] ?? [] as $row) {
            $this->requiredString($row, 'name', 'PRODUCT_NAME');
            if (isset($row['category_slug']) && in_array($row['category_slug'], $categorySlugs, true) === false) {
                throw new DomainException('CATALOG_PRODUCT_CATEGORY_REFERENCE_INVALID');
            }

            foreach ($row['collection_slugs'] ?? [] as $slug) {
                if (in_array($slug, $collectionSlugs, true) === false) {
                    throw new DomainException('CATALOG_PRODUCT_COLLECTION_REFERENCE_INVALID');
                }
            }
        }

        foreach ($manifest['variants'] ?? [] as $row) {
            $this->requiredString($row, 'title', 'VARIANT_TITLE');
            if (in_array($row['product_slug'] ?? null, $productSlugs, true) === false) {
                throw new DomainException('CATALOG_VARIANT_PRODUCT_REFERENCE_INVALID');
            }

            if (is_int($row['price_minor'] ?? null) === false || $row['price_minor'] < 0) {
                throw new DomainException('CATALOG_VARIANT_PRICE_INVALID');
            }

            if (isset($row['compare_at_price_minor']) && (is_int($row['compare_at_price_minor']) === false || $row['compare_at_price_minor'] < $row['price_minor'])) {
                throw new DomainException('CATALOG_VARIANT_COMPARE_PRICE_INVALID');
            }

            if (strlen((string) ($row['currency'] ?? 'IRR')) !== 3) {
                throw new DomainException('CATALOG_VARIANT_CURRENCY_INVALID');
            }
        }

        foreach ($manifest['media'] ?? [] as $row) {
            $uuid = $this->requiredString($row, 'media_uuid', 'MEDIA_UUID');
            $type = $this->requiredString($row, 'subject_type', 'MEDIA_SUBJECT_TYPE');
            $key = $this->requiredString($row, 'subject_key', 'MEDIA_SUBJECT_KEY');
            $this->requiredString($row, 'role', 'MEDIA_ROLE');
            if (isset(MediaAttachmentService::SUBJECTS[$type]) === false) {
                throw new DomainException('CATALOG_MEDIA_SUBJECT_TYPE_INVALID');
            }

            $validKey = match ($type) {
                'product' => in_array($key, $productSlugs, true),
                'variant' => in_array($key, $skus, true),
                'category' => in_array($key, $categorySlugs, true),
                'collection' => in_array($key, $collectionSlugs, true),
            };
            if ($validKey === false) {
                throw new DomainException('CATALOG_MEDIA_SUBJECT_REFERENCE_INVALID');
            }

            if (MediaAsset::query()->where('uuid', $uuid)->where('status', MediaAsset::STATUS_READY)->exists() === false) {
                throw new DomainException('CATALOG_MEDIA_ASSET_NOT_READY');
            }
        }

        return [
            'categories' => count($categorySlugs),
            'collections' => count($collectionSlugs),
            'products' => count($productSlugs),
            'variants' => count($skus),
            'media' => count($manifest['media'] ?? []),
        ];
    }

    private function uniqueKeys(array $rows, string $field, string $label): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                throw new DomainException('CATALOG_'.$label.'_ROW_INVALID');
            }

            $value = $this->requiredString($row, $field, $label.'_'.$field);
            if (isset($keys[$value])) {
                throw new DomainException('CATALOG_'.$label.'_DUPLICATE');
            }

            $keys[$value] = true;
        }

        return array_keys($keys);
    }

    private function requiredString(array $row, string $field, string $label): string
    {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '') {
            throw new DomainException('CATALOG_'.$label.'_REQUIRED');
        }

        return $value;
    }
}
