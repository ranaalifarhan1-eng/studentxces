<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\FeePayment;
use App\Models\Guardian;
use App\Models\Mark;
use App\Models\Student;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ParentPortalController extends Controller
{
    public function dashboard()
    {
        $user     = auth()->user();
        $guardian = Guardian::with('students.schoolClass:id,name', 'students.section:id,name')
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $guardian) {
            return Inertia::render('Parent/Dashboard', [
                'guardian'  => null,
                'linked'    => false,
                'children'  => [],
                'announcements' => [],
            ]);
        }

        $now   = Carbon::now();
        $today = $now->toDateString();

        $children = $guardian->students->map(function (Student $student) use ($now, $today) {

            /* Attendance this month */
            $attRows = Attendance::where('school_id', $student->school_id)
                ->where('attendable_type', Student::class)
                ->where('attendable_id', $student->id)
                ->whereMonth('date', $now->month)
                ->whereYear('date', $now->year)
                ->select('status')
                ->get();

            $total   = $attRows->count();
            $present = $attRows->where('status', 'present')->count();

            /* Fee summary */
            $fee = FeePayment::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->select(DB::raw('SUM(amount_due) as due, SUM(amount_paid) as paid'))
                ->first();

            $dueCents     = Money::toCents($fee->due ?? 0);
            $paidCents    = Money::toCents($fee->paid ?? 0);
            $balanceCents = max(0, $dueCents - $paidCents);

            /* Recent marks */
            $marks = Mark::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->with(['exam:id,name', 'subject:id,name'])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn ($m) => [
                    'exam'    => $m->exam?->name,
                    'subject' => $m->subject?->name,
                    'marks'   => $m->marks_obtained,
                    'grade'   => $m->grade,
                    'absent'  => $m->is_absent,
                ]);

            /* Recent fee payments */
            $recentFees = FeePayment::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->orderByDesc('payment_date')
                ->limit(3)
                ->get()
                ->map(function ($f) {
                    $dueC   = Money::toCents($f->amount_due);
                    $paidC  = Money::toCents($f->amount_paid);
                    $balC   = max(0, $dueC - $paidC);
                    return [
                        'month'   => $f->month_year,
                        'paid'    => Money::toDecimal($paidC),
                        'balance' => Money::toDecimal($balC),
                        'status'  => $f->status,
                    ];
                });

            return [
                'id'           => $student->id,
                'full_name'    => $student->full_name,
                'admission_no' => $student->admission_no,
                'class'        => $student->schoolClass?->name,
                'section'      => $student->section?->name,
                'photo_url'    => $student->photo_url,
                'attendance'   => [
                    'total'      => $total,
                    'present'    => $present,
                    'absent'     => $attRows->where('status', 'absent')->count(),
                    'percentage' => $total ? round(($present / $total) * 100) : 0,
                ],
                'fees' => [
                    'total_due'  => Money::toDecimal($dueCents),
                    'total_paid' => Money::toDecimal($paidCents),
                    'balance'    => Money::toDecimal($balanceCents),
                    'recent'     => $recentFees,
                ],
                'marks'      => $marks,
            ];
        });

        /* Announcements for parents */
        $announcements = Announcement::where('school_id', $user->school_id)
            ->where(fn ($q) => $q->where('audience', 'all')->orWhere('audience', 'parents'))
            ->orderByDesc('published_at')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'id'     => $a->id,
                'title'  => $a->title,
                'body'   => $a->body,
                'pinned' => $a->is_pinned,
                'date'   => $a->published_at ? Carbon::parse($a->published_at)->diffForHumans() : null,
            ]);

        return Inertia::render('Parent/Dashboard', [
            'linked'       => true,
            'guardian'     => [
                'id'    => $guardian->id,
                'name'  => $guardian->name,
                'phone' => $guardian->phone,
                'email' => $guardian->email,
            ],
            'children'     => $children,
            'announcements'=> $announcements,
        ]);
    }

    private function resolveGuardian()
    {
        $user = auth()->user();
        return Guardian::with('students.schoolClass:id,name', 'students.section:id,name')
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function notLinked(string $page)
    {
        return Inertia::render($page, ['linked' => false, 'guardian' => null, 'children' => []]);
    }

    public function attendance()
    {
        $guardian = $this->resolveGuardian();
        if (! $guardian) return $this->notLinked('Parent/Attendance');

        $now      = Carbon::now();
        $children = $guardian->students->map(function (Student $student) use ($now) {
            $months = [];
            for ($m = 0; $m < 3; $m++) {
                $month = $now->copy()->subMonths($m);
                $rows  = Attendance::where('school_id', $student->school_id)
                    ->where('attendable_type', Student::class)
                    ->where('attendable_id', $student->id)
                    ->whereMonth('date', $month->month)
                    ->whereYear('date', $month->year)
                    ->orderBy('date')
                    ->get(['date', 'status']);

                $total   = $rows->count();
                $present = $rows->where('status', 'present')->count();
                $months[] = [
                    'label'      => $month->format('M Y'),
                    'total'      => $total,
                    'present'    => $present,
                    'absent'     => $rows->where('status', 'absent')->count(),
                    'late'       => $rows->where('status', 'late')->count(),
                    'percentage' => $total ? round($present / $total * 100) : 0,
                    'calendar'   => $rows->map(fn ($r) => ['date' => $r->date, 'status' => $r->status]),
                ];
            }
            return [
                'id'        => $student->id,
                'full_name' => $student->full_name,
                'class'     => $student->schoolClass?->name,
                'section'   => $student->section?->name,
                'months'    => $months,
            ];
        });

        return Inertia::render('Parent/Attendance', [
            'linked'   => true,
            'guardian' => ['name' => $guardian->name],
            'children' => $children,
        ]);
    }

    public function results()
    {
        $guardian = $this->resolveGuardian();
        if (! $guardian) return $this->notLinked('Parent/Results');

        $children = $guardian->students->map(function (Student $student) {
            $marks = \App\Models\Mark::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->with(['exam:id,name,type', 'subject:id,name'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($m) => [
                    'exam'    => $m->exam?->name,
                    'type'    => $m->exam?->type,
                    'subject' => $m->subject?->name,
                    'marks'   => $m->marks_obtained,
                    'total'   => $m->total_marks,
                    'grade'   => $m->grade,
                    'absent'  => $m->is_absent,
                ]);
            return [
                'id'        => $student->id,
                'full_name' => $student->full_name,
                'class'     => $student->schoolClass?->name,
                'marks'     => $marks,
            ];
        });

        return Inertia::render('Parent/Results', [
            'linked'   => true,
            'guardian' => ['name' => $guardian->name],
            'children' => $children,
        ]);
    }

    public function fees()
    {
        $guardian = $this->resolveGuardian();
        if (! $guardian) return $this->notLinked('Parent/Fees');

        $children = $guardian->students->map(function (Student $student) {
            $modernDue = \App\Models\FeeChallan::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('status', '!=', 'void')
                ->sum('total_payable');
            $modernDueCents = Money::toCents($modernDue);

            $legacyDue = FeePayment::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->whereNull('fee_challan_id')
                ->sum(DB::raw('amount_due + fine - discount'));
            $legacyDueCents = Money::toCents($legacyDue);

            $totalPaid = FeePayment::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->sum('amount_paid');
            $totalPaidCents = Money::toCents($totalPaid);

            $totalDueCents = $modernDueCents + $legacyDueCents;
            $balanceCents = max(0, $totalDueCents - $totalPaidCents);

            $payments = FeePayment::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->orderByDesc('payment_date')
                ->get(['id', 'receipt_no', 'month_year', 'amount_due', 'amount_paid', 'status', 'payment_date', 'balance_snapshot'])
                ->map(function ($f) {
                    $dueC  = Money::toCents($f->amount_due);
                    $paidC = Money::toCents($f->amount_paid);
                    $balC  = $f->balance_snapshot !== null ? Money::toCents($f->balance_snapshot) : max(0, $dueC - $paidC);
                    return [
                        'id'           => $f->id,
                        'receipt_no'   => $f->receipt_no,
                        'month'        => $f->month_year ?? '',
                        'due'          => Money::toDecimal($dueC),
                        'paid'         => Money::toDecimal($paidC),
                        'balance'      => Money::toDecimal($balC),
                        'status'       => $f->status,
                        'payment_date' => $f->payment_date ? Carbon::parse($f->payment_date)->format('d M Y') : null,
                    ];
                });

            $challans = \App\Models\FeeChallan::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('status', '!=', 'void')
                ->latest('issue_date')
                ->get(['id', 'challan_no', 'billing_period_key', 'due_date', 'total_payable', 'paid_amount', 'status'])
                ->map(function ($c) {
                    $totC  = Money::toCents($c->total_payable);
                    $paidC = Money::toCents($c->paid_amount);
                    $balC  = max(0, $totC - $paidC);
                    return [
                        'id'         => $c->id,
                        'challan_no' => $c->challan_no,
                        'period'     => $c->billing_period_key,
                        'due_date'   => $c->due_date ? Carbon::parse($c->due_date)->format('d M Y') : null,
                        'total'      => Money::toDecimal($totC),
                        'paid'       => Money::toDecimal($paidC),
                        'balance'    => Money::toDecimal($balC),
                        'status'     => $c->status,
                    ];
                });

            return [
                'id'         => $student->id,
                'full_name'  => $student->full_name,
                'class'      => $student->schoolClass?->name,
                'total_due'  => Money::toDecimal($totalDueCents),
                'total_paid' => Money::toDecimal($totalPaidCents),
                'balance'    => Money::toDecimal($balanceCents),
                'payments'   => $payments,
                'challans'   => $challans,
            ];
        });

        return Inertia::render('Parent/Fees', [
            'linked'   => true,
            'guardian' => ['name' => $guardian->name],
            'children' => $children,
        ]);
    }

    public function announcements()
    {
        $user     = auth()->user();
        $guardian = Guardian::where('school_id', $user->school_id)->where('user_id', $user->id)->first();
        if (! $guardian) return $this->notLinked('Parent/Announcements');

        $announcements = Announcement::where('school_id', $user->school_id)
            ->where(fn ($q) => $q->where('audience', 'all')->orWhere('audience', 'parents'))
            ->orderByDesc('published_at')
            ->get(['id', 'title', 'body', 'is_pinned', 'published_at'])
            ->map(fn ($a) => [
                'id'     => $a->id,
                'title'  => $a->title,
                'body'   => $a->body,
                'pinned' => $a->is_pinned,
                'date'   => $a->published_at ? Carbon::parse($a->published_at)->format('d M Y') : null,
            ]);

        return Inertia::render('Parent/Announcements', [
            'linked'        => true,
            'guardian'      => ['name' => $guardian->name],
            'announcements' => $announcements,
        ]);
    }
}
