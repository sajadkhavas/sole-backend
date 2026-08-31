<?php

namespace App\Services\Catalog;

use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\ProductPublicationRevision;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductPublicationService
{
    public function requestReview(Product $product, ?User $actor = null): Product
    {
        return DB::transaction(function () use ($product, $actor): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new DomainException('CATALOG_REVIEW_REQUIRES_DRAFT');
            }

            $before = $this->snapshot($locked);
            $locked->forceFill(['status' => 'review'])->save();
            $this->record($locked, $actor, 'review', $before, $this->snapshot($locked));

            return $locked->fresh();
        }, 3);
    }

    public function publish(Product $product, ?User $actor = null): Product
    {
        return DB::transaction(function () use ($product, $actor): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'review') {
                throw new DomainException('CATALOG_PUBLISH_REQUIRES_REVIEW');
            }

            if (! $locked->variants()->where('is_active', true)->exists()) {
                throw new DomainException('CATALOG_PUBLISH_ACTIVE_VARIANT_REQUIRED');
            }

            $hasAccessibleMedia = MediaAttachment::query()
                ->where('subject_type', 'product')
                ->where('subject_id', $locked->getKey())
                ->where('role', '!=', 'decorative')
                ->whereNotNull('alt_text')
                ->where('alt_text', '!=', '')
                ->whereHas('asset', fn ($query) => $query->where('status', MediaAsset::STATUS_READY))
                ->exists();
            if (! $hasAccessibleMedia) {
                throw new DomainException('CATALOG_PUBLISH_ACCESSIBLE_MEDIA_REQUIRED');
            }

            $before = $this->snapshot($locked);
            $locked->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $this->record($locked, $actor, 'publish', $before, $this->snapshot($locked));

            return $locked->fresh();
        }, 3);
    }

    public function rollbackLatestPublication(Product $product, ?User $actor = null): Product
    {
        return DB::transaction(function () use ($product, $actor): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $revision = ProductPublicationRevision::query()
                ->where('product_id', $locked->getKey())
                ->where('action', 'publish')
                ->latest('id')
                ->first();
            if ($revision === null) {
                throw new DomainException('CATALOG_PUBLICATION_ROLLBACK_NOT_FOUND');
            }

            if (ProductPublicationRevision::query()->where('rollback_of_uuid', $revision->uuid)->exists()) {
                throw new DomainException('CATALOG_PUBLICATION_ALREADY_ROLLED_BACK');
            }

            if ($this->snapshot($locked) !== $revision->after) {
                throw new DomainException('CATALOG_PUBLICATION_ROLLBACK_STALE');
            }

            $beforeRollback = $this->snapshot($locked);
            $locked->forceFill([
                'status' => $revision->before['status'],
                'published_at' => $revision->before['published_at'],
            ])->save();
            $this->record(
                $locked,
                $actor,
                'rollback',
                $beforeRollback,
                $this->snapshot($locked),
                $revision->uuid,
            );

            return $locked->fresh();
        }, 3);
    }

    private function snapshot(Product $product): array
    {
        return [
            'status' => $product->status,
            'published_at' => $product->published_at?->toAtomString(),
        ];
    }

    private function record(Product $product, ?User $actor, string $action, array $before, array $after, ?string $rollbackOf = null): void
    {
        ProductPublicationRevision::create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->getKey(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'rollback_of_uuid' => $rollbackOf,
            'created_at' => now(),
        ]);
    }
}
