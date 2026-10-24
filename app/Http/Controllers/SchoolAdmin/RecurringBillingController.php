<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeBillingRun;
use App\Models\School;
use App\Services\RecurringFeeBillingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecurringBillingController extends Controller
{
    protected function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        if (! $user || (! $user->hasRole('super-admin') && ! $user->can('fees.bulk_bill'))) {
            abort(403, 'You do not have permission to manage recurring fee billing.');
        }
    }

    public function index(Request $request): Response
    {
        $this->authorizeAccess($request);
        $sid = $this->getSchoolId();

        $school = School::findOrFail($sid);
        $settings = RecurringFeeBillingService::getSchoolSettings($sid);

        $academicYears = AcademicYear::where('school_id', $sid)->orderByDesc('start_date')->get(['id', 'name', 'is_current']);
        $currentYear = $academicYears->firstWhere('is_current', true) ?? $academicYears->first();

        $recentRuns = FeeBillingRun::where('school_id', $sid)
            ->latest('id')
            ->limit(12)
            ->get();

        $tz = $school->timezone ?: config('app.timezone', 'Asia/Karachi');
        $currentMonth = Carbon::now($tz)->format('Y-m');

        return Inertia::render('SchoolAdmin/Fees/RecurringBilling', [
            'settings'      => $settings,
            'school'        => [
                'id'       => $school->id,
                'name'     => $school->name,
                'timezone' => $tz,
            ],
            'currentYear'   => $currentYear,
            'recentRuns'    => $recentRuns,
            'currentMonth'  => $currentMonth,
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request);
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'auto_billing_enabled'  => 'required|boolean',
            'auto_billing_day'      => 'required|integer|min:1|max:28',
            'recurring_fee_due_day' => 'required|integer|min:1|max:28',
        ]);

        RecurringFeeBillingService::saveSchoolSettings($sid, $data);

        return back()->with('success', 'Automatic fee billing settings saved successfully.');
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $sid = $this->getSchoolId();

        $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $school = School::findOrFail($sid);
        $tz = $school->timezone ?: config('app.timezone', 'Asia/Karachi');
        $monthStr = $request->input('month') ?: Carbon::now($tz)->format('Y-m');
        $targetMonth = Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();

        $preview = RecurringFeeBillingService::previewBillingCycle($sid, $targetMonth);

        return response()->json($preview);
    }

    public function runNow(Request $request)
    {
        $this->authorizeAccess($request);
        $sid = $this->getSchoolId();

        $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $school = School::findOrFail($sid);
        $tz = $school->timezone ?: config('app.timezone', 'Asia/Karachi');
        $monthStr = $request->input('month') ?: Carbon::now($tz)->format('Y-m');
        $targetMonth = Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();

        $result = RecurringFeeBillingService::executeBillingCycle($sid, $targetMonth, 'manual');

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        $parts = [];
        if (! empty($result['new_challans_created']) && $result['new_challans_created'] > 0) {
            $parts[] = "{$result['new_challans_created']} new voucher(s) created";
        }
        if (! empty($result['vouchers_updated']) && $result['vouchers_updated'] > 0) {
            $parts[] = "{$result['vouchers_updated']} existing voucher(s) updated with appended charges";
        }

        if (empty($parts)) {
            $msg = "All eligible students are already billed for {$monthStr} ({$result['challans_skipped']} existing voucher(s) verified, 0 new created).";
        } else {
            $msg = "Billing cycle completed: " . implode(', ', $parts) . " (PKR {$result['total_amount']}).";
        }

        return back()->with('success', $msg);
    }
}
