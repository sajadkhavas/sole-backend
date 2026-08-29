<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AdminAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_access_is_deny_by_default_and_requires_explicit_permission(): void
    {
        app(RbacProvisioner::class)->sync();
        $panel = Filament::getPanel('admin');

        $inactive = User::factory()->create(['is_active' => false]);
        $this->assertFalse($inactive->canAccessPanel($panel));

        $activeWithoutRole = User::factory()->create(['is_active' => true]);
        $this->assertFalse($activeWithoutRole->canAccessPanel($panel));

        $admin = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role);

        $this->assertTrue($admin->canAccessPanel($panel));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Product::class));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', Product::factory()->create()));
    }

    public function test_rbac_sync_is_idempotent_and_never_grants_a_user_access(): void
    {
        $provisioner = app(RbacProvisioner::class);
        $provisioner->sync();
        $provisioner->sync();

        $this->assertDatabaseCount('permissions', count(config('sole.permissions')));
        $this->assertDatabaseCount('roles', count(config('sole.roles')));
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_admin_grant_and_revoke_are_explicit_and_audited(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'is_active' => false]);

        $this->assertSame(0, Artisan::call('sole:admin:grant', ['email' => $user->email]));
        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue($user->fresh()->roles()->where('slug', 'super-admin')->exists());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.access.granted',
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
        ]);

        $this->assertSame(0, Artisan::call('sole:admin:revoke', ['email' => $user->email]));
        $this->assertFalse($user->fresh()->is_active);
        $this->assertFalse($user->fresh()->roles()->where('slug', 'super-admin')->exists());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.access.revoked',
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
        ]);
        $this->assertGreaterThanOrEqual(2, AuditLog::query()->where('subject_type', User::class)->where('subject_id', $user->getKey())->count());
    }
}
