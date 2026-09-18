<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->constrained('fee_structures')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason', 255)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Unique logical identity: one assignment record per student / structure / academic year
            $table->unique(
                ['school_id', 'student_id', 'fee_structure_id', 'academic_year_id'],
                'uq_sfa_student_structure_ay'
            );

            // Indexes for querying active assignments and school-level lookups
            $table->index(['school_id', 'student_id', 'is_active'], 'idx_sfa_school_student_active');
            $table->index(['school_id', 'academic_year_id'], 'idx_sfa_school_ay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_assignments');
    }
};
