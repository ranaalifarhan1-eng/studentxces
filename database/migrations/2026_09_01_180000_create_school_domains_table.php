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
        Schema::create('school_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('type', 20)->default('default'); // 'default', 'custom'
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('pending'); // 'pending', 'verified', 'active', 'failed', 'disabled'
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_status', 20)->default('pending'); // 'pending', 'active', 'failed'
            $table->timestamps();

            $table->index(['school_id', 'is_primary']);
            $table->index(['school_id', 'status']);
            $table->index(['type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_domains');
    }
};
