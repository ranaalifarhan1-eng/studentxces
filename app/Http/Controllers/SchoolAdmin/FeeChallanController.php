<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeChallan;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Rules\SchoolExists;
use App\Services\FeeBillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FeeChallanController extends Controller
{
    public function index(Request $request)
    {
        $sid = $this->getSchoolId();

        $challans = FeeChallan::with([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'schoolClass:id,name',
            'section:id,name',
            'items',
        ])
            ->where('school_id', $sid)
            ->when($request->class_id, fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->status,   fn ($q) => $q->where('status', $request->status))
            ->when($request->month,    fn ($q) => $q->where('billing_period_key', 'like', "%{$request->month}%"))
            ->latest('issue_date')
            ->paginate(25)
            ->withQueryString();

        $academicYears = AcademicYear::where('school_id', $sid)->orderByDesc('start_date')->get(['id', 'name', 'is_current']);
        $currentYear = $academicYears->firstWhere('is_current', true) ?? $academicYears->first();

        return Inertia::render('SchoolAdmin/Fees/Challans', [
            'challans'      => $challans,
            'classes'       => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'academicYears' => $academicYears,
            'currentYear'   => $currentYear,
            'filters'       => $request->only('class_id', 'status', 'month'),
        ]);
    }

    public function show(FeeChallan $feeChallan)
    {
        $sid = $this->getSchoolId();
        if ($feeChallan->school_id !== $sid) {
            abort(403);
        }

        $feeChallan->load(['items', 'student.schoolClass', 'student.section', 'payments', 'adjustments.creator']);
        $school = School::find($sid);

        $user = auth()->user();
        $canAdjust = $user ? ($user->hasRole(['school-admin', 'super-admin']) || $user->can('fees.adjustment')) : false;

        // Bank details check (No invented bank information)
        $bankConfig = null;
        if (! empty($school->bank_name) && ! empty($school->bank_account_no)) {
            $bankConfig = [
                'bank_name'   => $school->bank_name,
                'account_no'  => $school->bank_account_no,
                'branch'      => $school->bank_branch ?? null,
                'iban'        => $school->bank_iban ?? null,
            ];
        }

        return Inertia::render('SchoolAdmin/Fees/Challan', [
            'challan'    => $feeChallan,
            'canAdjust'  => $canAdjust,
            'school'     => [
                'name'    => $school->name,
                'logo'    => $school->logo_url ?? null,
                'address' => $school->address ?? null,
                'phone'   => $school->phone ?? null,
                'email'   => $school->email ?? null,
            ],
            'bankConfig' => $bankConfig,
        ]);
    }

    public function store(Request $request)
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'student_id'       => ['required', SchoolExists::make('students', 'id', $sid)],
            'academic_year_id' => ['required', SchoolExists::make('academic_years', 'id', $sid)],
            'month'            => 'required|date_format:Y-m',
            'due_date'         => 'nullable|date',
        ]);

        $student = Student::where('school_id', $sid)->findOrFail($data['student_id']);
        $academicYear = AcademicYear::where('school_id', $sid)->findOrFail($data['academic_year_id']);
        $targetMonth = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $dueDate = $data['due_date'] ? Carbon::parse($data['due_date']) : null;

        $challan = FeeBillingService::createChallan($student, $academicYear, $targetMonth, $dueDate);

        if (! $challan) {
            throw ValidationException::withMessages([
                'student_id' => 'Challan could not be generated. Either an active challan already exists for this period or no active fee structures apply.',
            ]);
        }

        return redirect()->route('school.fees.challans.show', $challan->id)
            ->with('success', "Challan #{$challan->challan_no} generated successfully.");
    }

    public function bulkGenerate(Request $request)
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'class_id'         => ['required', SchoolExists::make('classes', 'id', $sid)],
            'section_id'       => ['nullable', SchoolExists::make('sections', 'id', $sid)],
            'academic_year_id' => ['required', SchoolExists::make('academic_years', 'id', $sid)],
            'month'            => 'required|date_format:Y-m',
            'due_date'         => 'nullable|date',
        ]);

        $academicYear = AcademicYear::where('school_id', $sid)->findOrFail($data['academic_year_id']);
        $targetMonth = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $dueDate = $data['due_date'] ? Carbon::parse($data['due_date']) : null;

        $result = FeeBillingService::generateBulkChallans(
            $sid,
            (int) $data['class_id'],
            $data['section_id'] ? (int) $data['section_id'] : null,
            $academicYear,
            $targetMonth,
            $dueDate
        );

        $msg = "Bulk generation complete: {$result['generated_count']} challans created, {$result['skipped_count']} students skipped (already billed or no structures).";

        return back()->with('success', $msg);
    }

    public function printBulk(Request $request)
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'class_id' => ['required', SchoolExists::make('classes', 'id', $sid)],
            'month'    => 'nullable|string',
        ]);

        $challans = FeeChallan::with(['items', 'student.schoolClass', 'student.section'])
            ->where('school_id', $sid)
            ->where('class_id', $data['class_id'])
            ->whereIn('status', ['unpaid', 'partial'])
            ->when($data['month'], fn ($q) => $q->where('billing_period_key', 'like', "%{$data['month']}%"))
            ->get();

        $school = School::find($sid);

        $bankConfig = null;
        if (! empty($school->bank_name) && ! empty($school->bank_account_no)) {
            $bankConfig = [
                'bank_name'   => $school->bank_name,
                'account_no'  => $school->bank_account_no,
                'branch'      => $school->bank_branch ?? null,
                'iban'        => $school->bank_iban ?? null,
            ];
        }

        return Inertia::render('SchoolAdmin/Fees/BulkChallans', [
            'challans'   => $challans,
            'school'     => [
                'name'    => $school->name,
                'logo'    => $school->logo_url ?? null,
                'address' => $school->address ?? null,
                'phone'   => $school->phone ?? null,
            ],
            'bankConfig' => $bankConfig,
        ]);
    }

    public function voidChallan(Request $request, FeeChallan $feeChallan)
    {
        $sid = $this->getSchoolId();
        if ($feeChallan->school_id !== $sid) {
            abort(403);
        }

        if ($feeChallan->status !== 'unpaid') {
            throw ValidationException::withMessages([
                'challan' => 'Only issued unpaid challans can be voided. Partial or paid challans cannot be voided.',
            ]);
        }

        $data = $request->validate([
            'reason' => 'required|string|min:5|max:255',
        ]);

        $feeChallan->voidChallan($data['reason'], (int) auth()->id());

        return back()->with('success', "Challan #{$feeChallan->challan_no} has been voided.");
    }
}
