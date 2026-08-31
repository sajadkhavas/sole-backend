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

    public function test_user_audit_records_security_state_without_name_email_or_password_pii(): void
    {
        $user = User::factory()->create([
            'name' => 'Private Customer',
            'email' => 'private-customer@example.test',
            'is_active' => false,
            'account_status' => 'active',
        ]);

        $created = AuditLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->getKey())
            ->where('action', 'created')
            ->firstOrFail();

        $this->assertSame('active', $created->after['account_status']);
        $this->assertArrayNotHasKey('name', $created->after);
        $this->assertArrayNotHasKey('email', $created->after);
        $this->assertArrayNotHasKey('password', $created->after);

        $user->forceFill([
            'name' => 'Changed Private Name',
            'email' => 'changed-private@example.test',
            'account_status' => 'deletion_requested',
        ])->save();

        $updated = AuditLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->getKey())
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('active', $updated->before['account_status']);
        $this->assertSame('deletion_requested', $updated->after['account_status']);
        $this->assertArrayNotHasKey('name', $updated->before);
        $this->assertArrayNotHasKey('email', $updated->before);
        $this->assertArrayNotHasKey('name', $updated->after);
        $this->assertArrayNotHasKey('email', $updated->after);
    }
}
