<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('badge', 50)->nullable()->after('slug');
            $table->string('currency', 10)->default('PKR')->after('price_yearly');
        });

        Schema::table('school_subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_term_months')->nullable()->after('coupon_id');
            $table->decimal('base_monthly_price', 10, 2)->nullable()->after('billing_term_months');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('base_monthly_price');
            $table->decimal('billed_amount', 10, 2)->nullable()->after('discount_percent');
            $table->string('currency', 10)->default('PKR')->nullable()->after('billed_amount');
            $table->unsignedBigInteger('package_price_id')->nullable()->after('currency');

            $table->foreign('package_price_id', 'sub_pkg_price_fk')
                ->references('id')
                ->on('package_prices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('school_subscriptions', function (Blueprint $table) {
            $table->dropForeign('sub_pkg_price_fk');
            $table->dropColumn([
                'billing_term_months',
                'base_monthly_price',
                'discount_percent',
                'billed_amount',
                'currency',
                'package_price_id',
            ]);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['badge', 'currency']);
        });
    }
};