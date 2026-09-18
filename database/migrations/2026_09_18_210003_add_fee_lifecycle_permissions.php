<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $assignPerm = Permission::firstOrCreate(['name' => 'fees.assign', 'guard_name' => 'web']);
        $discountPerm = Permission::firstOrCreate(['name' => 'fees.discount', 'guard_name' => 'web']);

        // Assign to school-admin role(s)
        $adminRoles = Role::where('name', 'school-admin')->where('guard_name', 'web')->get();
        foreach ($adminRoles as $role) {
            $role->givePermissionTo($assignPerm, $discountPerm);
        }

        // Assign fees.assign to accountant role(s)
        $accountantRoles = Role::where('name', 'accountant')->where('guard_name', 'web')->get();
        foreach ($accountantRoles as $role) {
            $role->givePermissionTo($assignPerm);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = Permission::whereIn('name', ['fees.assign', 'fees.discount'])
            ->where('guard_name', 'web')
            ->get();

        foreach ($permissions as $perm) {
            $perm->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
