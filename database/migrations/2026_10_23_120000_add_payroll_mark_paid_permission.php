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

        $markPaidPerm = Permission::firstOrCreate(['name' => 'payroll.mark_paid', 'guard_name' => 'web']);

        // Assign only to super-admin and school-admin roles by default
        $adminRoles = Role::whereIn('name', ['super-admin', 'school-admin'])
            ->where('guard_name', 'web')
            ->get();

        foreach ($adminRoles as $role) {
            $role->givePermissionTo($markPaidPerm);
        }

        // Note: principal, teacher, accountant, and librarian do NOT get payroll.mark_paid by default.
        // It requires explicit permission assignment.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $perm = Permission::where('name', 'payroll.mark_paid')
            ->where('guard_name', 'web')
            ->first();

        if ($perm) {
            $perm->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
