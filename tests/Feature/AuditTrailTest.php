<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_mutations_record_actor_before_and_after_evidence_and_are_append_only(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->actingAs($actor);

        $category = Category::factory()->create(['name' => 'Running']);
        $category->update(['name' => 'Performance Running']);

        $created = AuditLog::query()
            ->where('actor_id', $actor->getKey())
            ->where('subject_type', Category::class)
            ->where('subject_id', $category->getKey())
            ->where('action', 'created')
            ->firstOrFail();

        $updated = AuditLog::query()
            ->where('actor_id', $actor->getKey())
            ->where('subject_type', Category::class)
            ->where('subject_id', $category->getKey())
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertNull($created->before);
        $this->assertSame('Running', $created->after['name']);
        $this->assertSame('Running', $updated->before['name']);
        $this->assertSame('Performance Running', $updated->after['name']);

        $this->expectException(LogicException::class);
        $updated->forceFill(['action' => 'tampered'])->save();
    }
}
