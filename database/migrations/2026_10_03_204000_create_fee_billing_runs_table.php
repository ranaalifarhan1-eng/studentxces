<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_billing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('target_month', 7); // Format: YYYY-MM
            $table->string('billing_period_key', 60); // Format: AY{id}-{YYYY-MM}
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            $table->string('triggered_by', 20)->default('scheduler'); // 'scheduler' or 'manual'
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_executed_at')->nullable();
            $table->unsignedInteger('execution_count')->default(1);
            $table->unsignedInteger('students_scanned')->default(0);
            $table->unsignedInteger('challans_created')->default(0);
            $table->unsignedInteger('last_challans_created')->default(0);
            $table->unsignedInteger('vouchers_updated')->default(0);
            $table->unsignedInteger('last_vouchers_updated')->default(0);
            $table->unsignedInteger('charges_appended')->default(0);
            $table->unsignedInteger('last_charges_appended')->default(0);
            $table->unsignedInteger('challans_skipped')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0.00);
            $table->text('error_summary')->nullable();
            $table->timestamps();

            // Unique constraint: at most one billing run record per school and target month
            $table->unique(['school_id', 'target_month'], 'uq_fbr_school_target_month');
            $table->index(['school_id', 'status'], 'idx_fbr_school_status');
            $table->index(['school_id', 'created_at'], 'idx_fbr_school_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_billing_runs');
    }
};
