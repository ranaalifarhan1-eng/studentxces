<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username', 100)->nullable()->unique()->after('name');
            }
            if (Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->change();
            }
            if (! Schema::hasColumn('users', 'temporary_password_encrypted')) {
                $table->text('temporary_password_encrypted')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'temporary_password_expires_at')) {
                $table->timestamp('temporary_password_expires_at')->nullable()->after('temporary_password_encrypted');
            }
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('temporary_password_expires_at');
            }
        });

        Schema::table('guardians', function (Blueprint $table) {
            if (! Schema::hasColumn('guardians', 'guardian_code')) {
                $table->string('guardian_code', 50)->nullable()->after('user_id');
            }
        });

        // Ensure tenant-scoped composite unique index
        $indexes = collect(\Illuminate\Support\Facades\DB::select("SHOW INDEXES FROM guardians"))->pluck('Key_name')->all();
        if (in_array('guardians_guardian_code_unique', $indexes, true)) {
            Schema::table('guardians', function (Blueprint $table) {
                $table->dropUnique('guardians_guardian_code_unique');
            });
        }
        if (! in_array('guardians_school_guardian_code_unique', $indexes, true)) {
            Schema::table('guardians', function (Blueprint $table) {
                $table->unique(['school_id', 'guardian_code'], 'guardians_school_guardian_code_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            $indexes = collect(\Illuminate\Support\Facades\DB::select("SHOW INDEXES FROM guardians"))->pluck('Key_name')->all();
            if (in_array('guardians_school_guardian_code_unique', $indexes, true)) {
                $table->dropUnique('guardians_school_guardian_code_unique');
            }
            if (Schema::hasColumn('guardians', 'guardian_code')) {
                $table->dropColumn('guardian_code');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('users', 'username')) {
                $columnsToDrop[] = 'username';
            }
            if (Schema::hasColumn('users', 'temporary_password_encrypted')) {
                $columnsToDrop[] = 'temporary_password_encrypted';
            }
            if (Schema::hasColumn('users', 'temporary_password_expires_at')) {
                $columnsToDrop[] = 'temporary_password_expires_at';
            }
            if (Schema::hasColumn('users', 'must_change_password')) {
                $columnsToDrop[] = 'must_change_password';
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
