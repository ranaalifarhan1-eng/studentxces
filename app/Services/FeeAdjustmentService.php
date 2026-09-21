<?php

namespace App\Services;

use App\Models\FeeChallan;
use App\Models\FeeChallanAdjustment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeeAdjustmentService
{
    /**
     * Applies an authoritative, audited collection-time adjustment to an issued challan.
     *
     * @param  FeeChallan  $challan
     * @param  array       $data
     * @param  User|null   $user
     * @return FeeChallanAdjustment
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public static function applyAdjustment(FeeChallan $challan, array $data, ?User $user): FeeChallanAdjustment
    {
        if (! $user) {
            throw new AuthorizationException('Authentication required to apply fee adjustments.');
        }

        $canAdjust = $user->hasRole(['school-admin', 'super-admin'])
            || $user->can('fees.adjustment');

        if (! $canAdjust) {
            throw new AuthorizationException('User does not have permission to apply collection adjustments.');
        }

        $userSchoolId = session('current_school_id') ?? $user->school_id;
        if ($userSchoolId && (int) $challan->school_id !== (int) $userSchoolId && ! $user->hasRole('super-admin')) {
            throw new AuthorizationException('Cross-tenant action forbidden.');
        }

        if ($challan->status === 'void') {
            throw ValidationException::withMessages([
                'adjustment' => 'Adjustments cannot be applied to void challans.',
            ]);
        }

        if ($challan->status === 'paid') {
            throw ValidationException::withMessages([
                'adjustment' => 'Adjustments cannot be applied to fully paid challans.',
            ]);
        }

        $grossCents        = Money::toCents($challan->gross_amount);
        $concessionCents   = Money::toCents($challan->discount_amount);
        $existingAdjCents  = Money::toCents($challan->adjustment_amount ?? '0.00');
        $payableCents      = Money::toCents($challan->total_payable);
        $paidCents         = Money::toCents($challan->paid_amount);
        $currentBalCents   = max(0, $payableCents - $paidCents);

        if ($currentBalCents <= 0) {
            throw ValidationException::withMessages([
                'adjustment' => 'Challan has zero balance. No adjustment can be applied.',
            ]);
        }

        $type = strtolower((string) ($data['adjustment_type'] ?? ''));
        if (! in_array($type, ['fixed', 'percentage'], true)) {
            throw ValidationException::withMessages([
                'adjustment_type' => 'Invalid adjustment type. Must be fixed or percentage.',
            ]);
        }

        $val = (float) ($data['value'] ?? 0);
        if ($val <= 0) {
            throw ValidationException::withMessages([
                'value' => 'Adjustment value must be greater than zero.',
            ]);
        }

        if ($type === 'percentage') {
            if ($val > 100) {
                throw ValidationException::withMessages([
                    'value' => 'Percentage adjustment cannot exceed 100%.',
                ]);
            }
            // Calculated strictly against current outstanding balance
            $adjCents = (int) round($currentBalCents * ($val / 100.0));
            if ($adjCents <= 0) {
                throw ValidationException::withMessages([
                    'value' => 'Percentage adjustment calculates to zero.',
                ]);
            }
        } else {
            $adjCents = Money::toCents((string) $data['value']);
            if ($adjCents <= 0) {
                throw ValidationException::withMessages([
                    'value' => 'Adjustment amount must be greater than zero.',
                ]);
            }
        }

        // Rule: Adjustment cannot exceed current outstanding balance
        if ($adjCents > $currentBalCents) {
            $maxAllowed = Money::toDecimal($currentBalCents);
            throw ValidationException::withMessages([
                'value' => "Adjustment amount exceeds current outstanding balance of PKR {$maxAllowed}.",
            ]);
        }

        // Rule: Concession + adjustment cannot exceed gross fee
        $totalDeductionsCents = $concessionCents + $existingAdjCents + $adjCents;
        if ($totalDeductionsCents > $grossCents) {
            throw ValidationException::withMessages([
                'value' => 'Total concessions and adjustments cannot exceed the gross fee amount.',
            ]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if (empty($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Adjustment reason / category is required.',
            ]);
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        if ($reason === 'other' && strlen($notes) < 3) {
            throw ValidationException::withMessages([
                'notes' => 'An explanatory note of at least 3 characters is required when reason is "Other".',
            ]);
        }

        $idempotencyKey = ! empty($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;

        // Pre-transaction idempotency check: if already processed, return existing record
        if ($idempotencyKey) {
            $existing = FeeChallanAdjustment::where('school_id', $user->school_id ?? $challan->school_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($challan, $user, $type, $val, $adjCents, $reason, $notes, $idempotencyKey) {
            /** @var FeeChallan $locked */
            $locked = FeeChallan::where('id', $challan->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existing = FeeChallanAdjustment::where('school_id', $locked->school_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $payableCents       = Money::toCents($locked->total_payable);
            $paidCents          = Money::toCents($locked->paid_amount);
            $currentBalCents    = max(0, $payableCents - $paidCents);

            if ($locked->status === 'void' || $locked->status === 'paid' || $currentBalCents <= 0) {
                throw ValidationException::withMessages([
                    'adjustment' => 'Challan is no longer eligible for adjustments.',
                ]);
            }

            if ($adjCents > $currentBalCents) {
                $maxAllowed = Money::toDecimal($currentBalCents);
                throw ValidationException::withMessages([
                    'value' => "Adjustment amount exceeds current outstanding balance of PKR {$maxAllowed}.",
                ]);
            }

            $newBalCents        = $currentBalCents - $adjCents;
            $newPayableCents    = $payableCents - $adjCents;
            $newAdjTotalCents   = Money::toCents($locked->adjustment_amount ?? '0.00') + $adjCents;

            try {
                $adjustment = FeeChallanAdjustment::create([
                    'school_id'         => $locked->school_id,
                    'fee_challan_id'    => $locked->id,
                    'student_id'        => $locked->student_id,
                    'adjustment_type'   => $type,
                    'value'             => $val,
                    'adjustment_amount' => Money::toDecimal($adjCents),
                    'previous_balance'  => Money::toDecimal($currentBalCents),
                    'new_balance'       => Money::toDecimal($newBalCents),
                    'reason'            => $reason,
                    'notes'             => $notes ?: null,
                    'idempotency_key'   => $idempotencyKey,
                    'created_by'        => $user->id,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
                if ($idempotencyKey && str_contains($e->getMessage(), 'uq_challan_adj_school_idempotency')) {
                    return FeeChallanAdjustment::where('school_id', $locked->school_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->firstOrFail();
                }
                throw $e;
            }

            $locked->adjustment_amount = Money::toDecimal($newAdjTotalCents);
            $locked->total_payable     = Money::toDecimal($newPayableCents);
            if ($newBalCents <= 0) {
                $locked->status = 'paid';
            } elseif ($paidCents > 0) {
                $locked->status = 'partial';
            }
            $locked->save();

            return $adjustment;
        });
    }
}
