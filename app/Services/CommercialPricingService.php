<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackagePrice;
use InvalidArgumentException;

class CommercialPricingService
{
    /**
     * Supported commercial billing terms with their automatic discount percentages.
     * 3 months: 0% discount
     * 6 months: 5% discount
     * 12 months: 10% discount
     */
    public const CANONICAL_TERMS = [
        3  => 0.00,
        6  => 5.00,
        12 => 10.00,
    ];

    /**
     * Check if a billing term is supported.
     */
    public static function isSupportedTerm(int $months): bool
    {
        return array_key_exists($months, self::CANONICAL_TERMS);
    }

    /**
     * Calculate exact pricing details for a given base monthly rate and billing term.
     *
     * @throws InvalidArgumentException
     */
    public function calculateTermPrice(float $baseMonthly, int $months, string $currency = 'PKR'): array
    {
        if (! self::isSupportedTerm($months)) {
            throw new InvalidArgumentException("Unsupported billing term: {$months} months. StudentXces supports 3, 6, and 12-month billing commitments.");
        }

        $discountPercent = self::CANONICAL_TERMS[$months];
        $subtotal        = round($baseMonthly * $months, 2);
        $discountAmount  = round($subtotal * ($discountPercent / 100), 2);
        $totalPrice      = round($subtotal - $discountAmount, 2);
        $effectiveRate   = round($totalPrice / $months, 2);

        return [
            'term_months'             => $months,
            'base_monthly_price'      => round($baseMonthly, 2),
            'discount_percent'        => $discountPercent,
            'subtotal'                => $subtotal,
            'discount_amount'         => $discountAmount,
            'total_price'             => $totalPrice,
            'savings_amount'          => $discountAmount,
            'effective_monthly_price' => $effectiveRate,
            'currency'                => $currency,
        ];
    }

    /**
     * Generate all canonical term pricing structures for a base monthly rate.
     *
     * @return array<int, array>
     */
    public function calculateAllTerms(float $baseMonthly, string $currency = 'PKR'): array
    {
        $terms = [];
        foreach (array_keys(self::CANONICAL_TERMS) as $months) {
            $terms[$months] = $this->calculateTermPrice($baseMonthly, $months, $currency);
        }
        return $terms;
    }

    /**
     * Synchronize canonical PackagePrice records for a given Package model.
     */
    public function syncPackagePrices(Package $package, float $baseMonthly, string $currency = 'PKR'): void
    {
        $allTerms = $this->calculateAllTerms($baseMonthly, $currency);

        foreach ($allTerms as $months => $termData) {
            PackagePrice::updateOrCreate(
                [
                    'package_id'  => $package->id,
                    'term_months' => $months,
                    'currency'    => $currency,
                ],
                [
                    'base_monthly_price' => $termData['base_monthly_price'],
                    'discount_percent'   => $termData['discount_percent'],
                    'total_price'        => $termData['total_price'],
                    'is_active'          => true,
                ]
            );
        }

        // Maintain backward compatibility on legacy columns
        $package->update([
            'price_monthly' => round($baseMonthly, 2),
            'price_yearly'  => $allTerms[12]['total_price'],
            'currency'      => $currency,
        ]);
    }
}