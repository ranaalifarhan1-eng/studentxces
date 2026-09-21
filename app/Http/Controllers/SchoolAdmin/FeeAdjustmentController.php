<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\FeeChallan;
use App\Services\FeeAdjustmentService;
use App\Support\Money;
use Illuminate\Http\Request;

class FeeAdjustmentController extends Controller
{
    public function store(Request $request, FeeChallan $feeChallan)
    {
        $sid = $this->getSchoolId();
        if ($feeChallan->school_id !== $sid) {
            abort(403, 'Cross-tenant action forbidden.');
        }

        $user = auth()->user();
        if (! $user->hasRole(['school-admin', 'super-admin'])
            && ! $user->can('fees.adjustment')) {
            abort(403, 'Unauthorized to apply fee adjustments.');
        }

        $data = $request->validate([
            'adjustment_type' => 'required|string|in:fixed,percentage',
            'value'           => 'required|numeric|min:0.01',
            'reason'          => 'required|string|max:255',
            'notes'           => array_values(array_filter([
                $request->input('reason') === 'other' ? 'required' : 'nullable',
                'string',
                $request->input('reason') === 'other' ? 'min:3' : null,
                'max:1000',
            ])),
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $adjustment = FeeAdjustmentService::applyAdjustment($feeChallan, $data, auth()->user());

        $formatted = Money::toDecimal(Money::toCents($adjustment->adjustment_amount));

        return back()->with('success', "One-time adjustment of PKR {$formatted} applied successfully to Challan #{$feeChallan->challan_no}.");
    }
}
