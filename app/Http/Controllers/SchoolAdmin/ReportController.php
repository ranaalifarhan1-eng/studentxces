<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Exam;
use App\Models\FeePayment;
use App\Models\Homework;
use App\Models\Mark;
use App\Models\Payroll;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Rules\SchoolExists;
use App\Models\Activity;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    // ── Dashboard ─────────────────────────────────────────────────

    public function dashboard()
    {
        $sid  = $this->getSchoolId();
        $role = auth()->user()?->getRoleNames()->first() ?? 'school-admin';

        $data = match (true) {
            in_array($role, ['school-admin', 'principal']) => $this->adminDashboard($sid),
            $role === 'teacher'                             => $this->teacherDashboard($sid),
            $role === 'accountant'                          => $this->accountantDashboard($sid),
            $role === 'super-admin'                         => $this->adminDashboard($sid),
            default                                         => $this->adminDashboard($sid),
        };

        return Inertia::render('SchoolAdmin/Reports/Dashboard', array_merge($data, ['role' => $role]));
    }

    private function adminDashboard(int $sid): array
    {
        $totalStudents = Student::withoutGlobalScopes()->where('school_id', $sid)->count();
        $totalStaff    = Staff::where('school_id', $sid)->where('status', 'active')->count();

        $todayAttStats = Attendance::where('school_id', $sid)
            ->whereDate('date', today())
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count")
            ->first();
        $totalToday    = (int) ($todayAttStats?->total ?? 0);
        $presentToday  = (int) ($todayAttStats?->present_count ?? 0);
        $attendancePct = $totalToday > 0 ? round(($presentToday / $totalToday) * 100, 1) : 0;

        $financialStats = app(\App\Services\FeeFinancialSummaryService::class)->getDashboardFinancialStats($sid);
        $monthFees   = $financialStats['monthFees'];
        $pendingFees = $financialStats['pendingFees'];
        $feeChart    = $financialStats['feeChart'];

        // Attendance trend last 7 days (single aggregated query)
        $sevenDaysAgo = now()->subDays(6)->startOfDay();
        $dailyAttendance = Attendance::where('school_id', $sid)
            ->where('date', '>=', $sevenDaysAgo)
            ->selectRaw("DATE_FORMAT(date, '%Y-%m-%d') as att_date,
                         SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                         SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            ->groupBy('att_date')
            ->get()
            ->keyBy('att_date');

        $attChart = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $dateStr = $day->format('Y-m-d');
            $rec = $dailyAttendance->get($dateStr);
            $attChart[] = [
                'day'     => $day->format('D'),
                'present' => (int) ($rec?->present_count ?? 0),
                'absent'  => (int) ($rec?->absent_count ?? 0),
            ];
        }

        $pendingHomework = Homework::where('school_id', $sid)->where('is_active', true)->withCount([
            'submissions as pending_count' => fn ($q) => $q->where('status', 'submitted'),
        ])->get()->sum('pending_count');

        $recentActivity = Activity::forTenant($sid)
            ->with('causer')
            ->latest()
            ->take(10)
            ->get();

        return compact('totalStudents', 'totalStaff', 'attendancePct', 'monthFees', 'pendingFees', 'feeChart', 'attChart', 'pendingHomework', 'recentActivity');
    }

    private function teacherDashboard(int $sid): array
    {
        $pending = Homework::where('school_id', $sid)->where('is_active', true)->withCount([
            'submissions as pending_count' => fn ($q) => $q->where('status', 'submitted'),
        ])->get()->sum('pending_count');

        return ['pendingHomework' => $pending, 'totalStudents' => 0, 'totalStaff' => 0, 'attendancePct' => 0, 'monthFees' => 0, 'pendingFees' => 0, 'feeChart' => [], 'attChart' => [], 'recentActivity' => collect()];
    }

    private function accountantDashboard(int $sid): array
    {
        $financialStats  = app(\App\Services\FeeFinancialSummaryService::class)->getAccountantDashboardFinancialStats($sid);
        $todayCollection = $financialStats['todayCollection'];
        $monthFees       = $financialStats['monthFees'];
        $outstanding     = $financialStats['pendingFees'];
        $feeChart        = $financialStats['feeChart'];

        return ['totalStudents' => 0, 'totalStaff' => 0, 'attendancePct' => 0, 'monthFees' => $monthFees, 'pendingFees' => $outstanding, 'todayCollection' => $todayCollection, 'feeChart' => $feeChart, 'attChart' => [], 'pendingHomework' => 0, 'recentActivity' => collect()];
    }

    private function superAdminDashboard(): array
    {
        $schools  = School::count();
        $students = Student::withoutGlobalScopes()->count();
        $revenue  = FeePayment::sum('amount_paid');

        return ['schools' => $schools, 'totalStudents' => $students, 'totalStaff' => 0, 'attendancePct' => 0, 'monthFees' => $revenue, 'pendingFees' => 0, 'feeChart' => [], 'attChart' => [], 'pendingHomework' => 0, 'recentActivity' => collect()];
    }

    // ── Attendance Report ─────────────────────────────────────────

    private function normalizeFilter(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $str = trim((string) $val);
        if ($str === '' || strtolower($str) === 'all') {
            return null;
        }
        return $str;
    }

    public function attendance(Request $request)
    {
        $sid = $this->getSchoolId();

        $classId = $this->normalizeFilter($request->class_id);
        $status  = $this->normalizeFilter($request->status);

        if ($classId) {
            $validClass = SchoolClass::where('school_id', $sid)->where('id', $classId)->exists();
            if (! $validClass) {
                $classId = -1;
            }
        }

        $query = Attendance::where('school_id', $sid)
            ->where('attendable_type', Student::class)
            ->with(['attendable.schoolClass:id,name'])
            ->when($request->from_date, fn ($q) => $q->whereDate('date', '>=', $request->from_date))
            ->when($request->to_date,   fn ($q) => $q->whereDate('date', '<=', $request->to_date))
            ->when($status,             fn ($q) => $q->where('status', $status))
            ->when($classId,            fn ($q) => $q->whereHasMorph(
                'attendable', [Student::class],
                fn ($sq) => $sq->where('class_id', $classId)
            ));

        $records = $query->latest('date')->paginate(50)->withQueryString()
            ->through(function ($a) {
                $attendable = $a->attendable;
                $name = '—';
                $admissionNo = null;
                $className   = null;

                if ($attendable) {
                    if (isset($attendable->first_name)) {
                        $name = trim($attendable->first_name . ' ' . ($attendable->last_name ?? ''));
                        $admissionNo = $attendable->admission_no ?? null;
                        $className   = $attendable->schoolClass?->name;
                    } else {
                        $name = $attendable->name ?? '—';
                    }
                }

                return [
                    'id'             => $a->id,
                    'date'           => $a->date,
                    'status'         => $a->status,
                    'remarks'        => $a->remarks,
                    'attendable_type'=> class_basename($a->attendable_type ?? ''),
                    'name'           => $name,
                    'admission_no'   => $admissionNo,
                    'class'          => $className,
                ];
            });

        $summary = Attendance::where('school_id', $sid)
            ->where('attendable_type', Student::class)
            ->when($request->from_date, fn ($q) => $q->whereDate('date', '>=', $request->from_date))
            ->when($request->to_date,   fn ($q) => $q->whereDate('date', '<=', $request->to_date))
            ->when($classId,            fn ($q) => $q->whereHasMorph(
                'attendable', [Student::class],
                fn ($sq) => $sq->where('class_id', $classId)
            ))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('SchoolAdmin/Reports/Attendance', [
            'records' => $records,
            'summary' => $summary,
            'classes' => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'filters' => [
                'class_id'  => $classId,
                'from_date' => $request->from_date,
                'to_date'   => $request->to_date,
                'status'    => $status,
            ],
        ]);
    }

    // ── Academic Report ───────────────────────────────────────────

    public function academic(Request $request)
    {
        $sid = $this->getSchoolId();

        $exams = Exam::where('school_id', $sid)->orderByDesc('start_date')->get(['id', 'name']);

        $classPerformance = [];
        if ($request->exam_id) {
            $classPerformance = SchoolClass::where('school_id', $sid)
                ->with(['marks' => fn ($q) => $q->where('exam_id', $request->exam_id)])
                ->get()
                ->map(function ($class) use ($request) {
                    $marks = Mark::whereHas('student', fn ($q) => $q->where('class_id', $class->id))
                        ->where('exam_id', $request->exam_id)
                        ->get();
                    $total   = $marks->count();
                    $passed  = $marks->where('is_pass', true)->count();
                    $avgPct  = $total ? round($marks->avg('percentage'), 1) : 0;
                    return [
                        'class_name'  => $class->name,
                        'total'       => $total,
                        'passed'      => $passed,
                        'failed'      => $total - $passed,
                        'pass_rate'   => $total ? round($passed / $total * 100, 1) : 0,
                        'avg_percent' => $avgPct,
                    ];
                })->values();
        }

        $subjectPerformance = [];
        if ($request->exam_id) {
            $subjectPerformance = Subject::where('school_id', $sid)
                ->get()
                ->map(function ($subject) use ($request) {
                    $marks = Mark::where('subject_id', $subject->id)->where('exam_id', $request->exam_id)->get();
                    return [
                        'subject'     => $subject->name,
                        'avg_percent' => $marks->count() ? round($marks->avg('percentage'), 1) : 0,
                        'pass_rate'   => $marks->count() ? round($marks->where('is_pass', true)->count() / $marks->count() * 100, 1) : 0,
                    ];
                })->filter(fn ($s) => $s['avg_percent'] > 0)->values();
        }

        return Inertia::render('SchoolAdmin/Reports/Academic', [
            'exams'              => $exams,
            'classPerformance'   => $classPerformance,
            'subjectPerformance' => $subjectPerformance,
            'filters'            => $request->only('exam_id', 'class_id'),
        ]);
    }

    // ── Finance Report ────────────────────────────────────────────

    public function finance(Request $request)
    {
        $sid = $this->getSchoolId();

        $from = $request->from_date ?? now()->startOfMonth()->toDateString();
        $to   = $request->to_date   ?? now()->toDateString();

        $stats = app(\App\Services\FeeFinancialSummaryService::class)->getFinanceReportStats($sid, $from, $to);
        $collected   = $stats['collected'];
        $outstanding = $stats['outstanding'];
        $dailyChart  = $stats['dailyChart'];

        $payroll = Payroll::where('school_id', $sid)
            ->where('month_year', now()->format('Y-m'))
            ->sum('net_salary');

        // Recent payments
        $payments = FeePayment::with('student:id,first_name,last_name,admission_no')
            ->where('school_id', $sid)
            ->whereBetween('payment_date', [$from, $to])
            ->latest('payment_date')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('SchoolAdmin/Reports/Finance', [
            'collected'   => $collected,
            'outstanding' => $outstanding,
            'payroll'     => $payroll,
            'dailyChart'  => $dailyChart,
            'payments'    => $payments,
            'filters'     => ['from_date' => $from, 'to_date' => $to],
        ]);
    }

    // ── Custom Report Builder ─────────────────────────────────────

    public function customBuilder()
    {
        $sid = $this->getSchoolId();

        return Inertia::render('SchoolAdmin/Reports/CustomBuilder', [
            'classes'  => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'subjects' => Subject::where('school_id', $sid)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function runCustomReport(Request $request)
    {
        $sid = $this->getSchoolId();

        if ($request->has('filters')) {
            $filters = $request->input('filters');
            if (is_array($filters)) {
                if (array_key_exists('class_id', $filters)) {
                    $filters['class_id'] = $this->normalizeFilter($filters['class_id']);
                }
                if (array_key_exists('status', $filters)) {
                    $filters['status'] = $this->normalizeFilter($filters['status']);
                }
                $request->merge(['filters' => $filters]);
            }
        }

        $data = $request->validate([
            'entity'            => 'required|in:students,attendance,marks,fees,staff',
            'filters'           => 'nullable|array',
            'filters.class_id'  => ['nullable', SchoolExists::make('classes', 'id', $sid)],
            'filters.from_date' => 'nullable|date',
            'filters.to_date'   => 'nullable|date',
            'filters.status'    => 'nullable|string',
        ]);

        $entity   = $data['entity'];
        $f        = $data['filters'] ?? [];
        $isExport = $request->boolean('is_export');

        [$results, $totalCount] = match ($entity) {
            'students' => (function () use ($sid, $f, $isExport) {
                $q = Student::withoutGlobalScopes()
                    ->where('school_id', $sid)
                    ->when($f['class_id'] ?? null, fn ($sq) => $sq->where('class_id', $f['class_id']));
                $total = (clone $q)->count();
                $res = $q->with('schoolClass:id,name')
                    ->when(! $isExport, fn ($sq) => $sq->limit(500))
                    ->get(['id', 'first_name', 'last_name', 'admission_no', 'class_id', 'gender', 'status']);
                return [$res, $total];
            })(),

            'attendance' => (function () use ($sid, $f, $isExport) {
                $q = Attendance::where('school_id', $sid)
                    ->where('attendable_type', Student::class)
                    ->when($f['class_id'] ?? null, fn ($sq) => $sq->whereHasMorph(
                        'attendable', [Student::class],
                        fn ($ssq) => $ssq->where('class_id', $f['class_id'])
                    ))
                    ->when($f['from_date'] ?? null,  fn ($sq) => $sq->whereDate('date', '>=', $f['from_date']))
                    ->when($f['to_date'] ?? null,    fn ($sq) => $sq->whereDate('date', '<=', $f['to_date']))
                    ->when($f['status'] ?? null,     fn ($sq) => $sq->where('status', $f['status']));
                $total = (clone $q)->count();
                $res = $q->with(['attendable.schoolClass:id,name'])
                    ->latest('date')
                    ->when(! $isExport, fn ($sq) => $sq->limit(500))
                    ->get()
                    ->map(function ($a) {
                        $attendable = $a->attendable;
                        $name = '—';
                        $admissionNo = null;
                        $className   = null;

                        if ($attendable instanceof Student) {
                            $name = trim($attendable->first_name . ' ' . ($attendable->last_name ?? ''));
                            $admissionNo = $attendable->admission_no ?? null;
                            $className   = $attendable->schoolClass?->name;
                        } elseif ($attendable) {
                            $name = $attendable->name ?? '—';
                        }

                        return [
                            'id'           => $a->id,
                            'date'         => $a->date,
                            'student_name' => $name,
                            'admission_no' => $admissionNo,
                            'class'        => $className,
                            'status'       => $a->status,
                            'remarks'      => $a->remarks,
                        ];
                    });
                return [$res, $total];
            })(),

            'marks' => (function () use ($sid, $f, $isExport) {
                $q = Mark::whereHas('student', fn ($sq) => $sq->where('school_id', $sid))
                    ->when($f['class_id'] ?? null,  fn ($sq) => $sq->whereHas('student', fn ($s) => $s->where('class_id', $f['class_id'])));
                $total = (clone $q)->count();
                $res = $q->with(['student:id,first_name,last_name,admission_no', 'subject:id,name', 'exam:id,name'])
                    ->when(! $isExport, fn ($sq) => $sq->limit(500))
                    ->get();
                return [$res, $total];
            })(),

            'fees' => (function () use ($sid, $f, $isExport) {
                $q = FeePayment::where('school_id', $sid)
                    ->when($f['from_date'] ?? null, fn ($sq) => $sq->whereDate('payment_date', '>=', $f['from_date']))
                    ->when($f['to_date'] ?? null,   fn ($sq) => $sq->whereDate('payment_date', '<=', $f['to_date']))
                    ->when($f['status'] ?? null,    fn ($sq) => $sq->where('status', $f['status']));
                $total = (clone $q)->count();
                $res = $q->with('student:id,first_name,last_name,admission_no')
                    ->latest('payment_date')
                    ->when(! $isExport, fn ($sq) => $sq->limit(500))
                    ->get();
                return [$res, $total];
            })(),

            'staff' => (function () use ($sid, $f, $isExport) {
                $q = Staff::where('school_id', $sid)
                    ->when($f['status'] ?? null, fn ($sq) => $sq->where('status', $f['status']));
                $total = (clone $q)->count();
                $res = $q->with(['department:id,name', 'designation:id,name'])
                    ->when(! $isExport, fn ($sq) => $sq->limit(500))
                    ->get(['id', 'first_name', 'last_name', 'email', 'status', 'department_id', 'designation_id']);
                return [$res, $total];
            })(),

            default => [collect(), 0],
        };

        $returnedCount = $results->count();

        return response()->json([
            'data'           => $results,
            'count'          => $returnedCount,
            'total_count'    => $totalCount,
            'returned_count' => $returnedCount,
            'is_truncated'   => $totalCount > $returnedCount,
        ]);
    }

    // ── Audit Log ─────────────────────────────────────────────────

    public function auditLog(Request $request)
    {
        $sid          = $this->getSchoolId();
        $isSuperAdmin = auth()->user()?->hasRole('super-admin');

        $logs = Activity::with('causer:id,name,school_id')
            ->when(! $isSuperAdmin, fn ($q) => $q->forTenant($sid))
            ->when($request->causer_id, fn ($q) => $q->where('causer_id', $request->causer_id))
            ->when($request->subject_type, fn ($q) => $q->where('subject_type', 'like', '%' . $request->subject_type . '%'))
            ->when($request->from_date, fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date,   fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $users = \App\Models\User::when(! $isSuperAdmin, function ($q) use ($sid) {
                $q->where('school_id', $sid)
                  ->whereDoesntHave('roles', fn ($rq) => $rq->where('name', 'super-admin'));
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('SchoolAdmin/Reports/AuditLog', [
            'logs'    => $logs,
            'users'   => $users,
            'filters' => $request->only('causer_id', 'subject_type', 'from_date', 'to_date'),
        ]);
    }

    // ── PDF Exports ───────────────────────────────────────────────

    public function exportAttendancePdf(Request $request)
    {
        $sid     = $this->getSchoolId();
        $classId = $this->normalizeFilter($request->class_id);
        $status  = $this->normalizeFilter($request->status);

        if ($classId) {
            $validClass = SchoolClass::where('school_id', $sid)->where('id', $classId)->exists();
            if (! $validClass) {
                $classId = -1;
            }
        }

        $query = Attendance::where('school_id', $sid)
            ->where('attendable_type', Student::class)
            ->with(['attendable.schoolClass:id,name'])
            ->when($request->from_date, fn ($q) => $q->whereDate('date', '>=', $request->from_date))
            ->when($request->to_date,   fn ($q) => $q->whereDate('date', '<=', $request->to_date))
            ->when($status,             fn ($q) => $q->where('status', $status))
            ->when($classId,            fn ($q) => $q->whereHasMorph(
                'attendable', [Student::class],
                fn ($sq) => $sq->where('class_id', $classId)
            ));

        $count = (clone $query)->count();
        if ($count > 1000) {
            $message = "This PDF export exceeds the safe limit of 1,000 attendance records (found " . number_format($count) . " records). Please narrow the date range or use CSV export for the complete dataset.";
            if ($request->hasSession()) {
                session()->flash('error', $message);
            }
            if ($request->header('X-Inertia')) {
                return back()->with('error', $message);
            }
            return response(
                '<div style="font-family:sans-serif;padding:32px;max-width:640px;margin:50px auto;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:8px;line-height:1.5;">' .
                '<h2 style="margin-top:0;color:#c2410c;">Export Limit Notice</h2>' .
                '<p>' . htmlspecialchars($message) . '</p>' .
                '<p><button onclick="window.close()" style="background:#ea580c;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-weight:500;">Close Window</button></p>' .
                '</div>',
                200,
                ['Content-Type' => 'text/html']
            );
        }

        $records = $query->latest('date')->get();

        $school = School::find($sid);
        $pdf = Pdf::loadView('reports.attendance', compact('records', 'school'))->setPaper('a4', 'landscape');
        return $pdf->download('attendance-report.pdf');
    }

    public function exportFinancePdf(Request $request)
    {
        $sid      = $this->getSchoolId();
        $from     = $request->from_date ?? now()->startOfMonth()->toDateString();
        $to       = $request->to_date   ?? now()->toDateString();
        $payments = FeePayment::with('student:id,first_name,last_name,admission_no')
            ->where('school_id', $sid)
            ->whereBetween('payment_date', [$from, $to])
            ->latest('payment_date')->get();

        $school = School::find($sid);
        $pdf = Pdf::loadView('reports.finance', compact('payments', 'from', 'to', 'school'))->setPaper('a4', 'landscape');
        return $pdf->download('finance-report.pdf');
    }

    public function exportCsv(Request $request)
    {
        $data = $request->validate(['entity' => 'required|in:students,attendance,marks,fees,staff']);

        if (! $request->has('filters')) {
            $filters = [];
            if ($request->filled('class_id')) {
                $filters['class_id'] = $request->query('class_id');
            }
            if ($request->filled('from_date')) {
                $filters['from_date'] = $request->query('from_date');
            }
            if ($request->filled('to_date')) {
                $filters['to_date'] = $request->query('to_date');
            }
            if ($request->filled('status')) {
                $filters['status'] = $request->query('status');
            }
            if (! empty($filters)) {
                $request->merge(['filters' => $filters]);
            }
        }

        $request->merge(['is_export' => true]);
        $response = $this->runCustomReport($request);
        $items    = json_decode($response->getContent(), true)['data'] ?? [];

        if (empty($items)) {
            return back()->with('error', 'No data to export.');
        }

        $headers  = array_keys($items[0] ?? []);
        $csv      = implode(',', $headers) . "\n";
        foreach ($items as $row) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"', array_values($row))) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $data['entity'] . '-report.csv"',
        ]);
    }
}
