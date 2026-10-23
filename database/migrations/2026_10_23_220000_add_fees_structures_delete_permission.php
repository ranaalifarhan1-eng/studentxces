<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $deletePerm = Permission::firstOrCreate(['name' => 'fees.structures.delete', 'guard_name' => 'web']);

        // Assign only to super-admin and school-admin roles by default
        $adminRoles = Role::whereIn('name', ['super-admin', 'school-admin'])
            ->where('guard_name', 'web')
            ->get();

        foreach ($adminRoles as $role) {
            $role->givePermissionTo($deletePerm);
        }

        // Note: accountant, teacher, and other staff roles do NOT get fees.structures.delete by default.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $perm = Permission::where('name', 'fees.structures.delete')
            ->where('guard_name', 'web')
            ->first();

        if ($perm) {
            $perm->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
