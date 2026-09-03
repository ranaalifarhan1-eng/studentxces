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
        Schema::create('domain_provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_domain_id')->constrained('school_domains')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50)->default('provision');
            $table->string('status', 50)->default('queued')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('safe_error_code', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['school_domain_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_provisioning_requests');
    }
};
