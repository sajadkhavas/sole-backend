<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
