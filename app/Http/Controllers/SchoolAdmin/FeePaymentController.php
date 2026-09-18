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

        $payments = FeePayment::with([
            'student:id,first_name,last_name,admission_no,class_id',
            'student.schoolClass:id,name',
            'feeChallan:id,challan_no,billing_period_key,total_payable,paid_amount,status',
            'feeStructure:id,fee_category_id,academic_year,frequency',
            'feeStructure.feeCategory:id,name,type',
            'collector:id,name',
        ])
            ->where('school_id', $sid)
            ->when($request->student_id, fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->status,     fn ($q) => $q->where('status', $request->status))
            ->when($request->class_id,   fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('class_id', $request->class_id)))
            ->when($request->month_year, fn ($q) => $q->where('month_year', $request->month_year))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('SchoolAdmin/Fees/Payments', [
            'payments' => $payments,
            'classes'  => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'filters'  => $request->only('student_id', 'status', 'class_id', 'month_year'),
            'stats'    => $this->getStats($sid),
        ]);
    }

    public function create(Request $request)
    {
        $sid = $this->getSchoolId();

        $student = null;
        $activeChallans = collect();
        $discounts = collect();
        $structures = collect();

        // 1. Resolve student via numeric student_id OR neutral query q / query
        $searchParam = $request->input('student_id') ?? $request->input('query') ?? $request->input('q');

        if ($searchParam) {
            $student = Student::where('school_id', $sid)
                ->where(function ($q) use ($searchParam) {
                    $q->where('admission_no', $searchParam);
                    if (is_numeric($searchParam)) {
                        $q->orWhere('id', (int) $searchParam);
                    }
                    $q->orWhere('first_name', 'like', "%{$searchParam}%")
                      ->orWhere('last_name', 'like', "%{$searchParam}%");
                })
                ->with(['schoolClass:id,name', 'section:id,name'])
                ->first();
        }

        if ($student) {
            // Load student's active unpaid/partial challans
            $activeChallans = FeeChallan::where('school_id', $sid)
                ->where('student_id', $student->id)
                ->whereIn('status', ['unpaid', 'partial'])
                ->with('items')
                ->latest()
                ->get();

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

        return Inertia::render('SchoolAdmin/Fees/Collect', [
            'student'            => $student,
            'activeChallans'     => $activeChallans,
            'selectedChallanId'  => $request->input('challan_id'),
            'discounts'          => $discounts,
            'structures'         => $structures,
            'classes'            => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'searchQuery'        => $searchParam ?? '',
            'idempotencyKey'     => (string) \Illuminate\Support\Str::uuid(),
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
        $feePayment->load([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'feeChallan.items',
            'feeStructure.feeCategory:id,name,type',
            'collector:id,name',
        ]);

        return Inertia::render('SchoolAdmin/Fees/Receipt', [
            'payment' => $feePayment,
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

        // Group modern challans by student
        $studentRows = [];

        foreach ($activeChallans as $ch) {
            $stId = $ch->student_id;
            if (! isset($studentRows[$stId])) {
                $studentRows[$stId] = [
                    'student'           => $ch->student,
                    'item_count'        => 0,
                    'gross_amount'      => 0,
                    'discount_amount'   => 0,
                    'total_due'         => 0,
                    'total_paid'        => 0,
                    'balance'           => 0,
                    'latest_challan_id' => $ch->id,
                    'challan_no'        => $ch->challan_no,
                ];
            }

            $grossCents    = Money::toCents($ch->gross_amount);
            $discountCents = Money::toCents($ch->discount_amount);
            $payableCents  = Money::toCents($ch->total_payable);
            $paidCents     = Money::toCents($ch->paid_amount);
            $balanceCents  = max(0, $payableCents - $paidCents);

            $studentRows[$stId]['item_count'] += $ch->items->count();
            $studentRows[$stId]['gross_amount'] += $grossCents;
            $studentRows[$stId]['discount_amount'] += $discountCents;
            $studentRows[$stId]['total_due'] += $payableCents;
            $studentRows[$stId]['total_paid'] += $paidCents;
            $studentRows[$stId]['balance'] += $balanceCents;
        }

        // Add legacy payments
        foreach ($legacyPayments as $lp) {
            $stId = $lp->student_id;
            if (! isset($studentRows[$stId])) {
                $studentRows[$stId] = [
                    'student'           => $lp->student,
                    'item_count'        => 0,
                    'gross_amount'      => 0,
                    'discount_amount'   => 0,
                    'total_due'         => 0,
                    'total_paid'        => 0,
                    'balance'           => 0,
                    'latest_challan_id' => null,
                    'challan_no'        => null,
                ];
            }

            $amountDueCents = Money::toCents($lp->amount_due);
            $fineCents      = Money::toCents($lp->fine);
            $discountCents  = Money::toCents($lp->discount);
            $paidCents      = Money::toCents($lp->amount_paid);
            $dueCents       = max(0, $amountDueCents + $fineCents - $discountCents);
            $balCents       = max(0, $dueCents - $paidCents);

            $studentRows[$stId]['item_count'] += 1;
            $studentRows[$stId]['gross_amount'] += $amountDueCents;
            $studentRows[$stId]['discount_amount'] += $discountCents;
            $studentRows[$stId]['total_due'] += $dueCents;
            $studentRows[$stId]['total_paid'] += $paidCents;
            $studentRows[$stId]['balance'] += $balCents;
        }

        // Format to standard decimals
        $totalOutstandingCents = 0;
        $formattedList = array_values(array_map(function ($row) use (&$totalOutstandingCents) {
            $totalOutstandingCents += $row['balance'];
            return [
                'student'           => $row['student'],
                'payment_count'     => $row['item_count'],
                'gross_amount'      => Money::toDecimal($row['gross_amount']),
                'discount_amount'   => Money::toDecimal($row['discount_amount']),
                'total_due'         => Money::toDecimal($row['total_due']),
                'total_paid'        => Money::toDecimal($row['total_paid']),
                'balance'           => Money::toDecimal($row['balance']),
                'latest_challan_id' => $row['latest_challan_id'],
                'challan_no'        => $row['challan_no'],
            ];
        }, $studentRows));

        return Inertia::render('SchoolAdmin/Fees/Outstanding', [
            'outstanding' => $formattedList,
            'classes'     => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'filters'     => $request->only('class_id'),
            'summary'     => [
                'total_students'    => count($formattedList),
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

        $paidCount = FeePayment::where('school_id', $sid)->where('status', 'paid')->count();
        $pendingCount = FeeChallan::where('school_id', $sid)->whereIn('status', ['unpaid', 'partial'])->count()
            + FeePayment::where('school_id', $sid)->whereNull('fee_challan_id')->whereIn('status', ['pending', 'overdue'])->count();

        return [
            'total_collected'   => Money::toDecimal($totalCollectedCents),
            'total_outstanding' => Money::toDecimal($totalOutstandingCents),
            'paid_count'        => $paidCount,
            'pending_count'     => $pendingCount,
        ];
    }
}
