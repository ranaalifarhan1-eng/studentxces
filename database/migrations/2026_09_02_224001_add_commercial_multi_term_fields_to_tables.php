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
            $table->boolean('is_internal')->default(false)->after('is_active');
        });

        Schema::table('school_subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_term_months')->nullable()->after('coupon_id');
            $table->decimal('base_monthly_price', 10, 2)->nullable()->after('billing_term_months');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('base_monthly_price');
            $table->decimal('billed_amount', 10, 2)->nullable()->after('discount_percent');
            $table->string('currency', 10)->nullable()->after('billed_amount');
            $table->unsignedBigInteger('package_price_id')->nullable()->after('currency');

            $table->foreign('package_price_id', 'sub_pkg_price_fk')
                ->references('id')
                ->on('package_prices')
                ->nullOnDelete();
        });

        // Explicit data backfill for existing Legacy All Access package
        \Illuminate\Support\Facades\DB::table('packages')
            ->where('slug', 'legacy-all-access')
            ->update([
                'is_internal' => true,
                'is_active'   => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('school_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('school_subscriptions', 'package_price_id')) {
                $table->dropForeign('sub_pkg_price_fk');
                $table->dropColumn('package_price_id');
            }
            if (Schema::hasColumn('school_subscriptions', 'billing_term_months')) {
                $table->dropColumn('billing_term_months');
            }
            if (Schema::hasColumn('school_subscriptions', 'base_monthly_price')) {
                $table->dropColumn('base_monthly_price');
            }
            if (Schema::hasColumn('school_subscriptions', 'discount_percent')) {
                $table->dropColumn('discount_percent');
            }
            if (Schema::hasColumn('school_subscriptions', 'billed_amount')) {
                $table->dropColumn('billed_amount');
            }
            if (Schema::hasColumn('school_subscriptions', 'currency')) {
                $table->dropColumn('currency');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'is_internal')) {
                $table->dropColumn('is_internal');
            }
            if (Schema::hasColumn('packages', 'badge')) {
                $table->dropColumn('badge');
            }
            if (Schema::hasColumn('packages', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};