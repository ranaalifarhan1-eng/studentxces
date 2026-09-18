<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('document_type', 30); // 'challan', 'receipt'
            $table->unsignedInteger('year');
            $table->unsignedBigInteger('current_number')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'document_type', 'year'], 'uq_school_doc_year');
            $table->index(['school_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_document_sequences');
    }
};
