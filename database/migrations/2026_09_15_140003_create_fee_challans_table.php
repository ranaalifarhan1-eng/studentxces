<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_challans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('challan_no', 40);
            $table->string('billing_period_key', 60);
            // active_period_key is non-null for active challans (draft, unpaid, partial, paid),
            // and cleared to NULL if voided, giving database-enforced deduplication per student & period.
            $table->string('active_period_key', 120)->nullable();

            // Relational keys (Protected from accidental cascade deletion)
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();

            // Immutable historical snapshots (Survives student promotion / class renames)
            $table->string('student_name', 150);
            $table->string('admission_no', 50);
            $table->string('class_name', 100);
            $table->string('section_name', 100)->nullable();
            $table->string('academic_year_name', 50);

            // Dates & Lifecycle
            $table->date('issue_date');
            $table->date('due_date');
            $table->timestamp('issued_at')->nullable();

            // Financial Snapshot (All decimal 10,2)
            $table->decimal('gross_amount', 10, 2)->default(0.00);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->string('discount_title', 100)->nullable();
            $table->decimal('fine_amount', 10, 2)->default(0.00);
            $table->decimal('total_payable', 10, 2)->default(0.00);
            $table->decimal('paid_amount', 10, 2)->default(0.00);

            // Informational Memo (Option A: NOT capitalized into total_payable)
            $table->decimal('previous_outstanding_snapshot', 10, 2)->default(0.00);

            // Status & Audit
            $table->enum('status', ['draft', 'unpaid', 'partial', 'paid', 'void'])->default('draft');
            $table->string('void_reason', 255)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'challan_no'], 'uq_school_challan_no');
            $table->unique(['school_id', 'active_period_key'], 'uq_school_active_period');
            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_challans');
    }
};
