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

        $viewPerm = Permission::firstOrCreate(['name' => 'students.portal_credentials.view', 'guard_name' => 'web']);
        $resetPerm = Permission::firstOrCreate(['name' => 'students.portal_credentials.reset', 'guard_name' => 'web']);

        // Assign only to super-admin and school-admin roles
        $adminRoles = Role::whereIn('name', ['super-admin', 'school-admin'])
            ->where('guard_name', 'web')
            ->get();

        foreach ($adminRoles as $role) {
            $role->givePermissionTo($viewPerm, $resetPerm);
        }

        // Note: principal, teacher, accountant, receptionist, librarian, warden do NOT get these permissions by default.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = Permission::whereIn('name', [
            'students.portal_credentials.view',
            'students.portal_credentials.reset',
        ])->where('guard_name', 'web')->get();

        foreach ($permissions as $perm) {
            $perm->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
