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

        $bulkBillPerm = Permission::firstOrCreate(['name' => 'fees.bulk_bill', 'guard_name' => 'web']);

        // Assign to school-admin role(s)
        $adminRoles = Role::where('name', 'school-admin')->where('guard_name', 'web')->get();
        foreach ($adminRoles as $role) {
            $role->givePermissionTo($bulkBillPerm);
        }

        // Note: accountant, teacher, receptionist do NOT get fees.bulk_bill by default.
        // It requires explicit assignment.

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = Permission::whereIn('name', ['fees.bulk_bill'])
            ->where('guard_name', 'web')
            ->get();

        foreach ($permissions as $perm) {
            $perm->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
