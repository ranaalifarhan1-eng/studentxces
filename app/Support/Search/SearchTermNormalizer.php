<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

class SearchTermNormalizer
{
    /**
     * Normalize a raw search term:
     * - Trims leading and trailing whitespace.
     * - Collapses multiple consecutive whitespace characters to a single space.
     * - Returns an empty string if null or whitespace-only.
     */
    public static function normalize(?string $query): string
    {
        if ($query === null) {
            return '';
        }

        $trimmed = trim($query);

        return preg_replace('/\s+/', ' ', $trimmed) ?? '';
    }

    /**
     * Extract non-empty tokens from query.
     *
     * @return array<int, string>
     */
    public static function tokens(?string $query): array
    {
        $normalized = self::normalize($query);
        if ($normalized === '') {
            return [];
        }

        return explode(' ', $normalized);
    }

    /**
     * Escape special LIKE wildcard characters (% and _ and \) for literal matching.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Determine if a query is empty or whitespace-only.
     */
    public static function isEmpty(?string $query): bool
    {
        return self::normalize($query) === '';
    }

    /**
     * Apply student search to a query builder.
     *
     * Matches admission_no (exact/partial), optional numeric student ID, or multi-token full name (NULL-safe).
     */
    public static function applyStudentSearch(Builder $query, ?string $rawQuery, bool $includeId = false): Builder
    {
        $normalized = self::normalize($rawQuery);
        if ($normalized === '') {
            return $query;
        }

        $tokens = self::tokens($normalized);
        $escapedFull = self::escapeLike($normalized);

        return $query->where(function (Builder $sub) use ($normalized, $tokens, $escapedFull, $includeId) {
            $sub->where('admission_no', 'like', "%{$escapedFull}%");

            if ($includeId && is_numeric($normalized)) {
                $sub->orWhere('id', (int) $normalized);
            }

            $sub->orWhere(function (Builder $nameSub) use ($tokens) {
                foreach ($tokens as $token) {
                    $escapedToken = self::escapeLike($token);
                    $nameSub->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ["%{$escapedToken}%"]);
                }
            });
        });
    }

    /**
     * Apply staff search to a query builder.
     *
     * Matches emp_id, email, or multi-token full name (NULL-safe).
     */
    public static function applyStaffSearch(Builder $query, ?string $rawQuery): Builder
    {
        $normalized = self::normalize($rawQuery);
        if ($normalized === '') {
            return $query;
        }

        $tokens = self::tokens($normalized);
        $escapedFull = self::escapeLike($normalized);

        return $query->where(function (Builder $sub) use ($tokens, $escapedFull) {
            $sub->where('emp_id', 'like', "%{$escapedFull}%")
                ->orWhere('email', 'like', "%{$escapedFull}%")
                ->orWhere(function (Builder $nameSub) use ($tokens) {
                    foreach ($tokens as $token) {
                        $escapedToken = self::escapeLike($token);
                        $nameSub->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ["%{$escapedToken}%"]);
                    }
                });
        });
    }

    /**
     * Apply fee challan search to a query builder.
     *
     * Matches challan_no, admission_no, or multi-token student_name.
     */
    public static function applyChallanSearch(Builder $query, ?string $rawQuery): Builder
    {
        $normalized = self::normalize($rawQuery);
        if ($normalized === '') {
            return $query;
        }

        $tokens = self::tokens($normalized);
        $escapedFull = self::escapeLike($normalized);

        return $query->where(function (Builder $sub) use ($tokens, $escapedFull) {
            $sub->where('challan_no', 'like', "%{$escapedFull}%")
                ->orWhere('admission_no', 'like', "%{$escapedFull}%")
                ->orWhere(function (Builder $nameSub) use ($tokens) {
                    foreach ($tokens as $token) {
                        $escapedToken = self::escapeLike($token);
                        $nameSub->where('student_name', 'like', "%{$escapedToken}%");
                    }
                });
        });
    }

    /**
     * Apply fee payment search to a query builder.
     *
     * Matches receipt_no, or student's admission_no or multi-token full name (NULL-safe).
     */
    public static function applyFeePaymentSearch(Builder $query, ?string $rawQuery): Builder
    {
        $normalized = self::normalize($rawQuery);
        if ($normalized === '') {
            return $query;
        }

        $tokens = self::tokens($normalized);
        $escapedFull = self::escapeLike($normalized);

        return $query->where(function (Builder $sub) use ($tokens, $escapedFull) {
            $sub->where('receipt_no', 'like', "%{$escapedFull}%")
                ->orWhereHas('student', function (Builder $studentQuery) use ($tokens, $escapedFull) {
                    $studentQuery->where(function (Builder $sq) use ($tokens, $escapedFull) {
                        $sq->where('admission_no', 'like', "%{$escapedFull}%")
                           ->orWhere(function (Builder $nameSub) use ($tokens) {
                               foreach ($tokens as $token) {
                                   $escapedToken = self::escapeLike($token);
                                   $nameSub->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ["%{$escapedToken}%"]);
                               }
                           });
                    });
                });
        });
    }
}
