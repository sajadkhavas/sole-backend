<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RbacProvisioner
{
    public function sync(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];

            foreach (config('sole.permissions', []) as $slug => $name) {
                $permission = Permission::query()->updateOrCreate(['slug' => $slug], ['name' => $name]);
                $permissionIds[$slug] = $permission->getKey();
            }

            foreach (config('sole.roles', []) as $slug => $definition) {
                $role = Role::query()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $definition['name']],
                );

                $slugs = $definition['permissions'] === ['*']
                    ? array_keys($permissionIds)
                    : $definition['permissions'];

                $ids = collect($slugs)->map(function (string $permission) use ($permissionIds): int {
                    if (! isset($permissionIds[$permission])) {
                        throw new RuntimeException("Unknown SOLE permission [{$permission}].");
                    }

                    return $permissionIds[$permission];
                })->all();

                $role->permissions()->sync($ids);
            }
        });
    }
}
