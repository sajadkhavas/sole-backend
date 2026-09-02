<?php

namespace App\Services\Content;

use App\Models\ContentPage;
use App\Models\ContentPageRevision;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentPublicationService
{
    public function requestReview(ContentPage $page, ?User $actor = null): ContentPage
    {
        return $this->transition($page, 'draft', 'review', 'CONTENT_REVIEW_REQUIRES_DRAFT', $actor);
    }

    public function publish(ContentPage $page, ?User $actor = null): ContentPage
    {
        return DB::transaction(function () use ($page, $actor): ContentPage {
            $locked = ContentPage::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'review') {
                throw new DomainException('CONTENT_PUBLISH_REQUIRES_REVIEW');
            }
            if ($locked->blocks === [] || trim($locked->seo_title) === '' || trim($locked->seo_description) === '') {
                throw new DomainException('CONTENT_PUBLISH_COMPLETE_SEO_AND_BODY_REQUIRED');
            }

            $before = $this->snapshot($locked);
            $locked->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'version' => $locked->version + 1,
            ])->save();
            $this->record($locked, 'publish', $before, $this->snapshot($locked), $actor);

            return $locked->fresh();
        }, 3);
    }

    public function rollbackLatestPublication(ContentPage $page, ?User $actor = null): ContentPage
    {
        return DB::transaction(function () use ($page, $actor): ContentPage {
            $locked = ContentPage::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
            $revision = ContentPageRevision::query()
                ->where('content_page_id', $locked->getKey())
                ->where('action', 'publish')
                ->latest('id')->first();
            if ($revision === null || ContentPageRevision::query()->where('rollback_of_uuid', $revision->uuid)->exists()) {
                throw new DomainException('CONTENT_PUBLICATION_ROLLBACK_UNAVAILABLE');
            }
            if ($this->snapshot($locked) !== $revision->after) {
                throw new DomainException('CONTENT_PUBLICATION_ROLLBACK_STALE');
            }

            $before = $this->snapshot($locked);
            $locked->forceFill($revision->before)->save();
            $this->record($locked, 'rollback', $before, $this->snapshot($locked), $actor, $revision->uuid);

            return $locked->fresh();
        }, 3);
    }

    private function transition(ContentPage $page, string $from, string $to, string $error, ?User $actor): ContentPage
    {
        return DB::transaction(function () use ($page, $from, $to, $error, $actor): ContentPage {
            $locked = ContentPage::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw new DomainException($error);
            }
            $before = $this->snapshot($locked);
            $locked->forceFill(['status' => $to])->save();
            $this->record($locked, $to, $before, $this->snapshot($locked), $actor);

            return $locked->fresh();
        }, 3);
    }

    private function snapshot(ContentPage $page): array
    {
        return [
            'status' => $page->status,
            'published_at' => $page->published_at?->toAtomString(),
            'version' => (int) $page->version,
        ];
    }

    private function record(ContentPage $page, string $action, array $before, array $after, ?User $actor, ?string $rollbackOf = null): void
    {
        ContentPageRevision::create([
            'uuid' => (string) Str::uuid(),
            'content_page_id' => $page->getKey(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'rollback_of_uuid' => $rollbackOf,
            'created_at' => now(),
        ]);
    }
}
