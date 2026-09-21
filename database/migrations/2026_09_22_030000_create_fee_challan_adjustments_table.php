<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add adjustment_amount column to fee_challans if not exists
        if (Schema::hasTable('fee_challans') && ! Schema::hasColumn('fee_challans', 'adjustment_amount')) {
            Schema::table('fee_challans', function (Blueprint $table) {
                $table->decimal('adjustment_amount', 10, 2)->default(0.00)->after('fine_amount');
            });
        }

        // 2. Create fee_challan_adjustments table
        if (! Schema::hasTable('fee_challan_adjustments')) {
            Schema::create('fee_challan_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('fee_challan_id')->constrained('fee_challans')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

                $table->enum('adjustment_type', ['fixed', 'percentage']);
                $table->decimal('value', 10, 2);
                $table->decimal('adjustment_amount', 10, 2);
                $table->decimal('previous_balance', 10, 2);
                $table->decimal('new_balance', 10, 2);

                $table->string('reason', 255);
                $table->text('notes')->nullable();
                $table->string('idempotency_key', 64)->nullable();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['school_id', 'idempotency_key'], 'uq_challan_adj_school_idempotency');
                $table->index(['school_id', 'fee_challan_id'], 'idx_challan_adjustments_challan');
                $table->index(['school_id', 'student_id'], 'idx_challan_adjustments_student');
            });
        }

        // 3. Register permission fees.adjustment and grant to school-admin role
        try {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $adjPerm = Permission::firstOrCreate(['name' => 'fees.adjustment', 'guard_name' => 'web']);

            $adminRoles = Role::where('name', 'school-admin')->where('guard_name', 'web')->get();
            foreach ($adminRoles as $role) {
                $role->givePermissionTo($adjPerm);
            }

            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        } catch (\Throwable) {
            // Permission tables may not exist yet during certain migration sequences
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_challan_adjustments');

        if (Schema::hasTable('fee_challans') && Schema::hasColumn('fee_challans', 'adjustment_amount')) {
            Schema::table('fee_challans', function (Blueprint $table) {
                $table->dropColumn('adjustment_amount');
            });
        }

        try {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $adjPerm = Permission::where('name', 'fees.adjustment')->where('guard_name', 'web')->first();
            if ($adjPerm) {
                $adjPerm->delete();
            }
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        } catch (\Throwable) {
        }
    }
};
