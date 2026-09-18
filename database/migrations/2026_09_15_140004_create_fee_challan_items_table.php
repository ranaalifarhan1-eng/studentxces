<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_challan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_challan_id')->constrained('fee_challans')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->constrained('fee_structures')->nullOnDelete();

            // Line-item charge period key (e.g. AY1-M-2026-04, AY1-Q1, AY1-ANNUAL, ONETIME)
            $table->string('charge_period_key', 60);

            // Database-enforced active charge key: {student_id}_{fee_structure_id}_{charge_period_key}
            // Cleared to NULL if the parent challan is voided.
            $table->string('active_charge_key', 150)->nullable();

            // Immutable line snapshot
            $table->string('fee_head_name', 100);
            $table->decimal('gross_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('net_amount', 10, 2);

            $table->timestamps();

            $table->unique(['school_id', 'active_charge_key'], 'uq_school_active_charge_key');
            $table->index(['fee_challan_id']);
            $table->index(['school_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_challan_items');
    }
};
