<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->boolean('is_optional')->default(false)->after('is_active');
            $table->index(['school_id', 'class_id', 'is_optional', 'is_active'], 'fee_struct_optional_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->dropIndex('fee_struct_optional_idx');
            $table->dropColumn('is_optional');
        });
    }
};
