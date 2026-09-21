<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentFeeDiscount;
use App\Rules\SchoolExists;
use App\Services\DocumentSequenceService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FeePaymentController extends Controller
{
    public function index(Request $request)
    {
        $sid = $this->getSchoolId();
        $activeTab = $request->input('tab', 'challans');

        // 1. Modern Challans Query (unpaid admission vouchers immediately appear here)
        $challansQuery = FeeChallan::with([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'items',
            'academicYear',
        ])
            ->where('school_id', $sid)
            ->when($request->class_id, fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->search, fn ($q) => $q->where(function ($sq) use ($request) {
                $sq->where('challan_no', 'like', "%{$request->search}%")
                   ->orWhere('student_name', 'like', "%{$request->search}%")
                   ->orWhere('admission_no', 'like', "%{$request->search}%");
            }))
            ->when($request->status, function ($q, $status) {
                if ($status === 'overdue') {
                    $q->whereIn('status', ['unpaid', 'partial'])->where('due_date', '<', now()->toDateString());
                } else {
                    $q->where('status', $status);
                }
            })
            ->latest('issue_date')
            ->latest('id');

        $challans = $challansQuery->paginate(25, ['*'], 'challans_page')->withQueryString();

        // 2. Payments / Receipts Query
        $paymentsQuery = FeePayment::with([
            'student:id,first_name,last_name,admission_no,class_id',
            'student.schoolClass:id,name',
            'feeChallan:id,challan_no,billing_period_key,total_payable,paid_amount,status',
            'feeStructure:id,fee_category_id,academic_year,frequency',
            'feeStructure.feeCategory:id,name,type',
            'collector:id,name',
        ])
            ->where('school_id', $sid)
            ->when($request->student_id, fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->class_id,   fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('class_id', $request->class_id)))
            ->when($request->search, fn ($q) => $q->where(function ($sq) use ($request) {
                $sq->where('receipt_no', 'like', "%{$request->search}%")
                   ->orWhereHas('student', fn ($ssq) => $ssq->where('first_name', 'like', "%{$request->search}%")
                       ->orWhere('last_name', 'like', "%{$request->search}%")
                       ->orWhere('admission_no', 'like', "%{$request->search}%"));
            }))
            ->when($request->month_year, fn ($q) => $q->where('month_year', $request->month_year))
            ->latest('payment_date')
            ->latest('id');

        $payments = $paymentsQuery->paginate(25, ['*'], 'payments_page')->withQueryString();

        return Inertia::render('SchoolAdmin/Fees/Payments', [
            'activeTab' => $activeTab,
            'challans'  => $challans,
            'payments'  => $payments,
            'classes'   => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'filters'   => $request->only('tab', 'student_id', 'status', 'class_id', 'search', 'month_year'),
            'stats'     => $this->getStats($sid),
        ]);
    }

    public function create(Request $request)
    {
        $sid = $this->getSchoolId();

        $student = null;
        $studentCandidates = collect();
        $activeChallans = collect();
        $discounts = collect();
        $structures = collect();

        // 1. Resolve student via numeric student_id OR search query
        $studentId = $request->input('student_id');
        $searchParam = $request->input('query') ?? $request->input('q') ?? $request->input('search');

        if ($studentId) {
            $student = Student::where('school_id', $sid)
                ->with(['schoolClass:id,name', 'section:id,name', 'guardian:id,name,phone'])
                ->find($studentId);
        } elseif ($searchParam) {
            $candidates = Student::where('school_id', $sid)
                ->where(function ($q) use ($searchParam) {
                    $q->where('admission_no', $searchParam);
                    if (is_numeric($searchParam)) {
                        $q->orWhere('id', (int) $searchParam);
                    }
                    $q->orWhere('first_name', 'like', "%{$searchParam}%")
                      ->orWhere('last_name', 'like', "%{$searchParam}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchParam}%"]);
                })
                ->with(['schoolClass:id,name', 'section:id,name', 'guardian:id,name,phone'])
                ->limit(10)
                ->get();

            if ($candidates->count() === 1) {
                $student = $candidates->first();
            } elseif ($candidates->count() > 1) {
                $studentCandidates = $candidates;
            }
        }

        if ($student) {
            // Load student's active unpaid/partial challans (plus selected challan if settled)
            $selectedChallanId = $request->input('challan_id');
            $activeChallans = FeeChallan::where('school_id', $sid)
                ->where('student_id', $student->id)
                ->where(function ($q) use ($selectedChallanId) {
                    $q->whereIn('status', ['unpaid', 'partial']);
                    if ($selectedChallanId) {
                        $q->orWhere('id', $selectedChallanId);
                    }
                })
                ->with(['items', 'academicYear', 'adjustments.creator'])
                ->latest('due_date')
                ->get();

            $activeChallans->transform(function ($c) {
                $heads = $c->items->pluck('fee_head_name')->filter()->unique()->implode(', ') ?: 'Tuition Fee';
                $payableCents = Money::toCents($c->total_payable);
                $paidCents    = Money::toCents($c->paid_amount);
                $bal = Money::toDecimal(max(0, $payableCents - $paidCents));
                $c->display_label = "{$c->challan_no} — {$c->billing_period_label} — {$heads} — Balance PKR {$bal}";
                return $c;
            });

            // Load student's active discounts
            $discounts = StudentFeeDiscount::where('school_id', $sid)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->with('feeCategory:id,name')
                ->get();

            // Available fee structures for class
            $structures = FeeStructure::with('feeCategory:id,name,type')
                ->where('school_id', $sid)
                ->where('class_id', $student->class_id)
                ->where('is_active', true)
                ->get();
        }

        $user = auth()->user();
        $canAdjust = $user ? (
            $user->hasRole(['school-admin', 'super-admin'])
            || $user->can('fees.adjustment')
        ) : false;

        return Inertia::render('SchoolAdmin/Fees/Collect', [
            'student'            => $student,
            'studentCandidates'  => $studentCandidates,
            'activeChallans'     => $activeChallans,
            'selectedChallanId'  => $request->input('challan_id'),
            'discounts'          => $discounts,
            'structures'         => $structures,
            'classes'            => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'searchQuery'        => $searchParam ?? ($student ? $student->admission_no : ''),
            'idempotencyKey'     => (string) \Illuminate\Support\Str::uuid(),
            'canAdjust'          => $canAdjust,
        ]);
    }

    public function store(Request $request)
    {
        $sid = $this->getSchoolId();

        if ($request->has('challan_id') && ! $request->has('fee_challan_id')) {
            $request->merge(['fee_challan_id' => $request->input('challan_id')]);
        }

        $isModernPayment = ! empty($request->input('fee_challan_id'));

        $data = $request->validate([
            'student_id'       => ['required', SchoolExists::make('students', 'id', $sid)],
            'fee_challan_id'   => ['nullable', SchoolExists::make('fee_challans', 'id', $sid)],
            'fee_structure_id' => ['nullable', SchoolExists::make('fee_structures', 'id', $sid)],
            'amount_paid'      => 'required|numeric|min:0.01',
            'payment_date'     => 'required|date',
            'method'           => 'required|string|in:cash,card,online,bank_transfer,cheque,bkash,nagad,rocket,other',
            'reference'        => 'nullable|string|max:100',
            'note'             => 'nullable|string|max:500',
            'idempotency_key'  => $isModernPayment
                ? ['required', 'string', 'uuid', 'max:64']
                : ['nullable', 'string', 'max:64'],
        ], [
            'idempotency_key.required' => 'An idempotency key is required for challan fee payments.',
            'idempotency_key.uuid'     => 'The idempotency key must be a valid UUID.',
        ]);

        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = FeePayment::where('school_id', $sid)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return redirect()->route('school.fees.payments.show', $existing->id)
                    ->with('info', "Payment already processed. Showing existing receipt #{$existing->receipt_no}.");
            }
        }

        $payingCents = Money::toCents((string) $data['amount_paid']);
        if ($payingCents <= 0) {
            throw ValidationException::withMessages(['amount_paid' => 'Payment amount must be greater than zero.']);
        }

        try {
            $payment = DB::transaction(function () use ($sid, $data, $payingCents, $idempotencyKey) {
                if ($idempotencyKey) {
                    $existing = FeePayment::where('school_id', $sid)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                $challan = null;
                $newBalanceCents = 0;
                $amountDueFormatted = '0.00';
                $discountFormatted = '0.00';
                $fineFormatted = '0.00';
                $monthYear = null;

                if (! empty($data['fee_challan_id'])) {
                    // Lock challan row pessimistically
                    $challan = FeeChallan::where('school_id', $sid)
                        ->where('id', $data['fee_challan_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (! in_array($challan->status, ['unpaid', 'partial'], true)) {
                        throw ValidationException::withMessages([
                            'fee_challan_id' => "Challan {$challan->challan_no} cannot accept payments (status: {$challan->status}).",
                            'challan_id'     => "Challan {$challan->challan_no} cannot accept payments (status: {$challan->status}).",
                        ]);
                    }

                    $payableCents = Money::toCents($challan->total_payable);
                    $paidCents    = Money::toCents($challan->paid_amount);
                    $balanceCents = max(0, $payableCents - $paidCents);

                    if ($payingCents > $balanceCents) {
                        $maxPayable = Money::toDecimal($balanceCents);
                        throw ValidationException::withMessages([
                            'amount_paid' => "Payment amount exceeds current balance of {$maxPayable}.",
                        ]);
                    }

                    $newBalanceCents = $balanceCents - $payingCents;
                    $newPaidTotalCents = $paidCents + $payingCents;

                    $amountDueFormatted = $challan->total_payable;
                    $discountFormatted  = $challan->discount_amount;
                    $fineFormatted      = $challan->fine_amount;
                    $monthYear          = $challan->issue_date ? \Carbon\Carbon::parse($challan->issue_date)->format('Y-m') : now()->format('Y-m');

                    // Update Challan state
                    $challan->paid_amount = Money::toDecimal($newPaidTotalCents);
                    $challan->status = ($newBalanceCents <= 0) ? 'paid' : 'partial';
                    $challan->save();
                } else {
                    // Standalone legacy payment recording
                    $amountDueFormatted = Money::toDecimal($payingCents);
                    $monthYear = now()->format('Y-m');
                }

                // Atomic concurrency-safe receipt number
                $receiptNo = DocumentSequenceService::nextReceiptNumber($sid);

                return FeePayment::create([
                    'school_id'        => $sid,
                    'student_id'       => $data['student_id'],
                    'fee_challan_id'   => $challan?->id,
                    'fee_structure_id' => $data['fee_structure_id'] ?? null,
                    'receipt_no'       => $receiptNo,
                    'amount_due'       => $amountDueFormatted,
                    'amount_paid'      => Money::toDecimal($payingCents),
                    'discount'         => $discountFormatted,
                    'fine'             => $fineFormatted,
                    'balance_snapshot' => Money::toDecimal($newBalanceCents),
                    'payment_date'     => $data['payment_date'],
                    'month_year'       => $monthYear,
                    'method'           => $data['method'],
                    'reference'        => $data['reference'] ?? null,
                    'idempotency_key'  => $idempotencyKey,
                    'status'           => 'paid',
                    'note'             => $data['note'] ?? null,
                    'collected_by'     => auth()->id(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($idempotencyKey && DocumentSequenceService::isDuplicateKeyViolation($e)) {
                $existing = FeePayment::where('school_id', $sid)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return redirect()->route('school.fees.payments.show', $existing->id)
                        ->with('info', "Payment already processed. Showing existing receipt #{$existing->receipt_no}.");
                }
            }
            throw $e;
        }

        $formattedPaying = \App\Support\Money::toDecimal($payingCents);
        return redirect()->route('school.fees.payments.show', $payment->id)
            ->with('success', "Payment of {$formattedPaying} recorded successfully. Receipt #{$payment->receipt_no}");
    }

    public function show(FeePayment $feePayment)
    {
        $sid = $this->getSchoolId();
        if ($feePayment->school_id !== $sid) {
            abort(403);
        }

        $feePayment->load([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'feeChallan.items',
            'feeChallan.adjustments.creator',
            'feeChallan.academicYear',
            'feeStructure.feeCategory:id,name,type',
            'collector:id,name',
        ]);

        $previousPaid = '0.00';
        $remainingBalance = '0.00';

        if ($feePayment->feeChallan) {
            $totalChallanPaidCents = Money::toCents($feePayment->feeChallan->paid_amount);
            $thisPaidCents = Money::toCents($feePayment->amount_paid);
            $prevPaidCents = max(0, $totalChallanPaidCents - $thisPaidCents);
            $previousPaid = Money::toDecimal($prevPaidCents);

            $payableCents = Money::toCents($feePayment->feeChallan->total_payable);
            $remainingBalance = Money::toDecimal(max(0, $payableCents - $totalChallanPaidCents));
        }

        $paymentData = array_merge($feePayment->toArray(), [
            'previous_paid'     => $previousPaid,
            'remaining_balance' => $feePayment->balance_snapshot !== null ? $feePayment->balance_snapshot : $remainingBalance,
            'billing_period'    => $feePayment->feeChallan?->billing_period_label ?: ($feePayment->month_year ? \Carbon\Carbon::parse($feePayment->month_year)->format('F Y') : 'General'),
            'academic_year'             => $feePayment->feeChallan?->academic_year_name ?: ($feePayment->feeStructure?->academic_year ?: '2026-2027'),
            'due_date'                  => $feePayment->feeChallan?->due_date ? $feePayment->feeChallan->due_date->format('Y-m-d') : null,
            'adjustments'               => $feePayment->feeChallan?->adjustments ?? [],
            'settlement_classification' => $feePayment->feeChallan?->settlement_classification,
            'display_status'            => $feePayment->feeChallan?->display_status,
        ]);

        return Inertia::render('SchoolAdmin/Fees/Receipt', [
            'payment' => $paymentData,
        ]);
    }

    public function outstanding(Request $request)
    {
        $sid = $this->getSchoolId();

        // 1. Fetch active modern challans (unpaid, partial)
        $challanQuery = FeeChallan::with([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'items',
            'academicYear',
        ])
            ->where('school_id', $sid)
            ->whereIn('status', ['unpaid', 'partial'])
            ->when($request->class_id, fn ($q) => $q->where('class_id', $request->class_id))
            ->orderBy('due_date');

        $activeChallans = $challanQuery->get();

        // 2. Fetch legacy unpaid payments without challan
        $legacyPayments = FeePayment::with([
            'student:id,first_name,last_name,admission_no,class_id',
            'student.schoolClass:id,name',
            'feeStructure.feeCategory:id,name',
        ])
            ->where('school_id', $sid)
            ->whereNull('fee_challan_id')
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->when($request->class_id, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('class_id', $request->class_id)))
            ->get();

        $today = now()->toDateString();
        $formattedList = [];
        $totalOutstandingCents = 0;
        $totalStudentsSet = [];

        foreach ($activeChallans as $ch) {
            $payableCents = Money::toCents($ch->total_payable);
            $paidCents    = Money::toCents($ch->paid_amount);
            $balanceCents = max(0, $payableCents - $paidCents);
            $totalOutstandingCents += $balanceCents;
            $totalStudentsSet[$ch->student_id] = true;

            $isOverdue = $ch->due_date && $ch->due_date->format('Y-m-d') < $today;

            $formattedList[] = [
                'id'                   => $ch->id,
                'type'                 => 'challan',
                'challan_no'           => $ch->challan_no,
                'billing_period_label' => $ch->billing_period_label,
                'academic_year_name'   => $ch->academic_year_name ?: $ch->academicYear?->name,
                'due_date'             => $ch->due_date ? $ch->due_date->format('Y-m-d') : null,
                'is_overdue'                => $isOverdue,
                'status'                    => $isOverdue ? 'overdue' : $ch->status,
                'display_status'            => $ch->display_status,
                'settlement_classification' => $ch->settlement_classification,
                'student'                   => $ch->student,
                'gross_amount'         => $ch->gross_amount,
                'discount_amount'      => $ch->discount_amount,
                'adjustment_amount'    => $ch->adjustment_amount,
                'total_due'            => $ch->total_payable,
                'total_paid'           => $ch->paid_amount,
                'balance'              => Money::toDecimal($balanceCents),
                'heads_breakdown'      => $ch->items->pluck('fee_head_name')->filter()->unique()->implode(', ') ?: 'Tuition Fee',
            ];
        }

        foreach ($legacyPayments as $lp) {
            $dueCents  = Money::toCents($lp->amount_due) + Money::toCents($lp->fine) - Money::toCents($lp->discount);
            $paidCents = Money::toCents($lp->amount_paid);
            $balanceCents = max(0, $dueCents - $paidCents);
            $totalOutstandingCents += $balanceCents;
            $totalStudentsSet[$lp->student_id] = true;

            $formattedList[] = [
                'id'                   => $lp->id,
                'type'                 => 'legacy',
                'challan_no'           => null,
                'billing_period_label' => $lp->month_year ?: 'General',
                'academic_year_name'   => $lp->feeStructure?->academic_year,
                'due_date'             => $lp->payment_date,
                'is_overdue'           => $lp->status === 'overdue',
                'status'               => $lp->status,
                'student'              => $lp->student,
                'gross_amount'         => $lp->amount_due,
                'discount_amount'      => $lp->discount,
                'total_due'            => Money::toDecimal($dueCents),
                'total_paid'           => $lp->amount_paid,
                'balance'              => Money::toDecimal($balanceCents),
                'heads_breakdown'      => $lp->feeStructure?->feeCategory?->name ?: 'Direct Fee',
            ];
        }

        return Inertia::render('SchoolAdmin/Fees/Outstanding', [
            'outstanding' => $formattedList,
            'classes'     => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'filters'     => $request->only('class_id'),
            'summary'     => [
                'total_students'    => count($totalStudentsSet),
                'total_outstanding' => Money::toDecimal($totalOutstandingCents),
            ],
        ]);
    }

    private function getStats(int $sid): array
    {
        // 1. Total collected (all FeePayment rows represent real received money)
        $totalCollected = FeePayment::where('school_id', $sid)->sum('amount_paid');
        $totalCollectedCents = Money::toCents($totalCollected);

        // 2. Modern Challan outstanding
        $modernOutstanding = FeeChallan::where('school_id', $sid)
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('SUM(total_payable - paid_amount) as bal')
            ->value('bal');
        $modernOutstandingCents = Money::toCents($modernOutstanding);

        // 3. Legacy payments outstanding (only where fee_challan_id is NULL)
        $legacyOutstanding = FeePayment::where('school_id', $sid)
            ->whereNull('fee_challan_id')
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw('SUM(amount_due + fine - discount - amount_paid) as bal')
            ->value('bal');
        $legacyOutstandingCents = Money::toCents($legacyOutstanding);

        $totalOutstandingCents = max(0, $modernOutstandingCents + $legacyOutstandingCents);

        $totalAdjustments = FeeChallan::where('school_id', $sid)->sum('adjustment_amount');
        $totalAdjustmentsCents = Money::toCents($totalAdjustments);

        $paidCount = FeePayment::where('school_id', $sid)->where('status', 'paid')->count();
        $pendingCount = FeeChallan::where('school_id', $sid)->whereIn('status', ['unpaid', 'partial'])->count()
            + FeePayment::where('school_id', $sid)->whereNull('fee_challan_id')->whereIn('status', ['pending', 'overdue'])->count();

        return [
            'total_collected'   => Money::toDecimal($totalCollectedCents),
            'total_outstanding' => Money::toDecimal($totalOutstandingCents),
            'total_adjustments' => Money::toDecimal($totalAdjustmentsCents),
            'paid_count'        => $paidCount,
            'pending_count'     => $pendingCount,
        ];
    }
}
