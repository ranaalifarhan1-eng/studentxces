<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('package_id')->index();
            $table->unsignedSmallInteger('term_months'); // 3, 6, 12
            $table->decimal('base_monthly_price', 10, 2);
            $table->decimal('discount_percent', 5, 2)->default(0.00);
            $table->decimal('total_price', 10, 2);
            $table->string('currency', 10)->default('PKR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('package_id', 'pp_package_fk')
                ->references('id')
                ->on('packages')
                ->cascadeOnDelete();

            $table->unique(['package_id', 'term_months', 'currency'], 'pp_pkg_term_curr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_prices');
    }
};