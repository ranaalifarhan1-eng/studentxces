<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->foreignId('fee_challan_id')->nullable()->after('student_id')->constrained('fee_challans')->restrictOnDelete();
            $table->foreignId('collected_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
            $table->string('reference', 100)->nullable()->after('method');
            $table->decimal('balance_snapshot', 10, 2)->nullable()->after('amount_paid');
        });

        // Make fee_structure_id nullable for modern payments where the obligation is in fee_challans
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_structure_id')->nullable()->change();
            $table->string('method', 30)->default('cash')->change();
        });
    }

    public function down(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropForeign(['fee_challan_id']);
            $table->dropForeign(['collected_by']);
            $table->dropColumn(['fee_challan_id', 'collected_by', 'reference', 'balance_snapshot']);
        });
    }
};
