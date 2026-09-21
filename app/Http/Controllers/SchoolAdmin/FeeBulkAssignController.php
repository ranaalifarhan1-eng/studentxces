<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Rules\SchoolExists;
use App\Services\FeeBulkAssignService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeeBulkAssignController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->hasRole('super-admin') && ! $user->can('fees.bulk_bill'))) {
            abort(403, 'You do not have permission to access bulk fee billing.');
        }

        $sid = $this->getSchoolId();

        $structures = FeeStructure::with(['schoolClass:id,name', 'feeCategory:id,name,type'])
            ->where('school_id', $sid)
            ->where('is_active', true)
            ->orderBy('academic_year', 'desc')
            ->orderByRaw('(SELECT numeric_name FROM classes WHERE classes.id = fee_structures.class_id) ASC')
            ->get();

        $classes = SchoolClass::with('sections:id,class_id,name')
            ->where('school_id', $sid)
            ->orderBy('numeric_name')
            ->get(['id', 'name', 'numeric_name']);

        $academicYears = AcademicYear::where('school_id', $sid)
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'is_current']);

        $currentYear = $academicYears->firstWhere('is_current', true) ?? $academicYears->first();

        $mailDriver = config('mail.default');
        $safeMailTransport = in_array($mailDriver, ['log', 'array', 'mailpit', 'null']);

        // Check if preselected structure is requested
        $preselectedStructureId = $request->query('structure_id');

        return Inertia::render('SchoolAdmin/Fees/BulkAssign', [
            'structures'             => $structures,
            'classes'                => $classes,
            'academicYears'          => $academicYears,
            'currentYear'            => $currentYear,
            'preselectedStructureId' => $preselectedStructureId ? (int) $preselectedStructureId : null,
            'mailConfig'             => [
                'driver'    => $mailDriver,
                'is_safe'   => $safeMailTransport,
            ],
        ]);
    }

    public function preview(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->hasRole('super-admin') && ! $user->can('fees.bulk_bill'))) {
            abort(403, 'Unauthorized.');
        }

        $sid = $this->getSchoolId();

        $data = $request->validate([
            'fee_structure_id' => ['required', SchoolExists::make('fee_structures', 'id', $sid)],
            'academic_year_id' => ['required', SchoolExists::make('academic_years', 'id', $sid)],
            'class_id'         => ['required', SchoolExists::make('classes', 'id', $sid)],
            'target_mode'      => 'required|in:entire_class,selected_sections,selected_students',
            'section_ids'      => 'nullable|array',
            'section_ids.*'    => SchoolExists::make('sections', 'id', $sid),
            'student_ids'      => 'nullable|array',
            'student_ids.*'    => SchoolExists::make('students', 'id', $sid),
            'active_only'      => 'boolean',
            'execution_mode'   => 'required|in:assign_only,assign_and_bill',
            'billing_label'    => 'required|string|max:100',
        ]);

        $preview = FeeBulkAssignService::preview(
            $sid,
            (int) $data['fee_structure_id'],
            (int) $data['academic_year_id'],
            (int) $data['class_id'],
            $data
        );

        return response()->json($preview);
    }

    public function execute(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->hasRole('super-admin') && ! $user->can('fees.bulk_bill'))) {
            abort(403, 'Unauthorized.');
        }

        $sid = $this->getSchoolId();

        $data = $request->validate([
            'fee_structure_id' => ['required', SchoolExists::make('fee_structures', 'id', $sid)],
            'academic_year_id' => ['required', SchoolExists::make('academic_years', 'id', $sid)],
            'class_id'         => ['required', SchoolExists::make('classes', 'id', $sid)],
            'target_mode'      => 'required|in:entire_class,selected_sections,selected_students',
            'section_ids'      => 'nullable|array',
            'section_ids.*'    => SchoolExists::make('sections', 'id', $sid),
            'student_ids'      => 'nullable|array',
            'student_ids.*'    => SchoolExists::make('students', 'id', $sid),
            'active_only'      => 'boolean',
            'execution_mode'   => 'required|in:assign_only,assign_and_bill',
            'billing_label'    => 'required|string|max:100',
            'due_date'         => 'nullable|date',
            'issue_date'       => 'nullable|date',
            'notify_student'   => 'boolean',
            'notify_guardian'  => 'boolean',
        ]);

        $result = FeeBulkAssignService::execute(
            $sid,
            (int) $data['fee_structure_id'],
            (int) $data['academic_year_id'],
            (int) $data['class_id'],
            $data,
            $user
        );

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->route('school.fees.structures.bulk-assign')
            ->with('bulkResult', $result)
            ->with('success', 'Bulk fee operation completed successfully.');
    }

    public function classStudents(Request $request, SchoolClass $schoolClass)
    {
        $sid = $this->getSchoolId();
        if ($schoolClass->school_id !== $sid) {
            abort(403);
        }

        $students = Student::where('school_id', $sid)
            ->where('class_id', $schoolClass->id)
            ->with(['section:id,name', 'guardian:id,user_id,full_name,email'])
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'admission_no', 'section_id', 'status', 'email']);

        return response()->json($students);
    }
}
