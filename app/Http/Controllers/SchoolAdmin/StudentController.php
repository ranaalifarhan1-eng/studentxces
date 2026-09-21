<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Rules\SchoolExists;
use App\Services\FeeBillingService;
use App\Services\StudentFeeAssignmentService;
use App\Services\StudentFinancialLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $students = Student::with(['schoolClass:id,name', 'section:id,name', 'guardian:id,name,phone'])
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                  ->orWhere('last_name',  'like', "%{$request->search}%")
                  ->orWhere('admission_no', 'like', "%{$request->search}%");
            }))
            ->when($request->class_id,  fn ($q) => $q->where('class_id',  $request->class_id))
            ->when($request->section_id, fn ($q) => $q->where('section_id', $request->section_id))
            ->when($request->status,    fn ($q) => $q->where('status',    $request->status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('SchoolAdmin/Students/Index', [
            'students' => [
                'data'  => $students->items(),
                'meta'  => [
                    'total'        => $students->total(),
                    'per_page'     => $students->perPage(),
                    'current_page' => $students->currentPage(),
                    'last_page'    => $students->lastPage(),
                    'from'         => $students->firstItem(),
                    'to'           => $students->lastItem(),
                ],
                'links' => [
                    'prev' => $students->previousPageUrl(),
                    'next' => $students->nextPageUrl(),
                ],
            ],
            'filters'  => $request->only('search', 'class_id', 'section_id', 'status'),
            'classes'  => SchoolClass::orderBy('numeric_name')->get(['id', 'name']),
            'sections' => Section::orderBy('name')->get(['id', 'class_id', 'name']),
            'stats'    => [
                'total'       => Student::count(),
                'active'      => Student::where('status', 'active')->count(),
                'alumni'      => Student::where('status', 'alumni')->count(),
                'transferred' => Student::where('status', 'transferred')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $sid = $this->getSchoolId();
        $user = auth()->user();

        $academicYears = AcademicYear::where('school_id', $sid)
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'end_date', 'is_current']);

        $currentAcademicYear = $academicYears->firstWhere('is_current', true) ?? $academicYears->first();

        $feeStructures = FeeStructure::where('school_id', $sid)
            ->where('is_active', true)
            ->with('feeCategory:id,name,type')
            ->get(['id', 'school_id', 'class_id', 'fee_category_id', 'academic_year', 'amount', 'frequency', 'is_optional', 'admission_voucher_policy', 'due_date', 'description']);

        $canAssign = $user ? ($user->hasRole('super-admin') || $user->can('fees.assign')) : false;
        $canDiscount = $user ? ($user->hasRole('super-admin') || $user->can('fees.discount')) : false;

        return Inertia::render('SchoolAdmin/Students/Create', [
            'classes'             => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'sections'            => Section::where('school_id', $sid)->orderBy('name')->get(['id', 'class_id', 'name']),
            'academicYears'       => $academicYears,
            'currentAcademicYear' => $currentAcademicYear,
            'feeStructures'       => $feeStructures,
            'canAssignFees'       => $canAssign,
            'canDiscountFees'     => $canDiscount,
        ]);
    }

    public function feeStructures(Request $request): JsonResponse
    {
        $sid = $this->getSchoolId();
        $request->validate([
            'class_id'      => ['required', SchoolExists::make('classes', 'id', $sid)],
            'academic_year' => 'nullable|string',
        ]);

        $structures = FeeStructure::where('school_id', $sid)
            ->where('class_id', $request->class_id)
            ->when($request->academic_year, fn ($q) => $q->where('academic_year', $request->academic_year))
            ->where('is_active', true)
            ->with('feeCategory:id,name,type')
            ->get(['id', 'school_id', 'class_id', 'fee_category_id', 'academic_year', 'amount', 'frequency', 'is_optional', 'admission_voucher_policy', 'due_date', 'description']);

        return response()->json($structures);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = $this->getSchoolId();
        $user = auth()->user();

        // Check if user is submitting financial payload
        $hasFeeAssignment = $request->boolean('initialize_fees') || ! empty($request->input('fee_structure_ids')) || ! empty($request->input('first_voucher_structure_ids'));
        $hasConcession    = ! empty($request->input('concession.title'));
        $hasChallan       = $request->boolean('generate_first_challan');
        $hasFinancials    = $hasFeeAssignment || $hasConcession || $hasChallan;

        // Authorization bounds for non-finance users
        if ($hasFinancials) {
            if (! $user || (! $user->hasRole('super-admin') && ! $user->can('fees.assign'))) {
                abort(403, 'You do not have permission to configure student fees.');
            }
        }

        if ($hasConcession) {
            if (! $user || (! $user->hasRole('super-admin') && ! $user->can('fees.discount'))) {
                abort(403, 'You do not have permission to grant concessions.');
            }
        }

        $rules = [
            // Personal
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'nullable|string|max:100',
            'gender'          => 'required|in:male,female,other',
            'date_of_birth'   => 'nullable|date',
            'blood_group'     => 'nullable|string|max:5',
            'religion'        => 'nullable|string|max:50',
            'nationality'     => 'nullable|string|max:50',
            'phone'           => 'nullable|string|max:20',
            'email'           => 'nullable|email|max:150',
            'address'         => 'nullable|string|max:500',
            'category'        => 'required|in:general,disabled,quota',
            'status'          => 'required|in:active,alumni,transferred,inactive',
            'admission_date'  => 'nullable|date',
            'previous_school' => 'nullable|string|max:200',
            'roll_no'         => 'nullable|string|max:50',
            // Class
            'class_id'        => ['required', SchoolExists::make('classes', 'id', $sid)],
            'section_id'      => ['nullable', SchoolExists::make('sections', 'id', $sid)],
            // Guardian
            'guardian.name'       => 'required|string|max:150',
            'guardian.relation'   => 'required|string|max:50',
            'guardian.phone'      => 'nullable|string|max:20',
            'guardian.email'      => 'nullable|email|max:150',
            'guardian.occupation' => 'nullable|string|max:100',
            'guardian.address'    => 'nullable|string|max:500',
            // Financial setup (optional / permission gated)
            'academic_year_id'              => ['nullable', SchoolExists::make('academic_years', 'id', $sid)],
            'fee_structure_ids'             => 'nullable|array',
            'fee_structure_ids.*'           => ['integer', SchoolExists::make('fee_structures', 'id', $sid)],
            'first_voucher_structure_ids'   => 'nullable|array',
            'first_voucher_structure_ids.*' => ['integer', SchoolExists::make('fee_structures', 'id', $sid)],
            'billing_start_month'           => 'nullable|string|max:20',
            'initialize_fees'               => 'nullable|boolean',
            'generate_first_challan'        => 'nullable|boolean',
            'due_date'                      => 'nullable|date',
            'financial_notes'               => 'nullable|string|max:500',
            'concession'                     => 'nullable|array',
            'concession.title'               => 'required_with:concession|nullable|string|max:100',
            'concession.type'                => 'required_with:concession|nullable|in:percentage,fixed',
            'concession.value'               => 'required_with:concession|nullable|numeric|min:0.01',
            'concession.fee_category_id'     => ['nullable', SchoolExists::make('fee_categories', 'id', $sid)],
        ];

        $data = $request->validate($rules);

        // Specific human-readable validation checks
        if (! empty($data['generate_first_challan']) && ! empty($data['due_date'])) {
            $startMonth = ! empty($data['billing_start_month'])
                ? Carbon::parse($data['billing_start_month'])->startOfMonth()
                : Carbon::now()->startOfMonth();
            $dueDate = Carbon::parse($data['due_date']);
            if ($dueDate->lt($startMonth)) {
                throw ValidationException::withMessages([
                    'due_date' => 'Due date cannot be before the voucher issue period.',
                ]);
            }
        }

        if (! empty($data['concession']) && ! empty($data['concession']['type']) && $data['concession']['type'] === 'percentage') {
            if ((float) $data['concession']['value'] > 100) {
                throw ValidationException::withMessages([
                    'concession.value' => 'Discount cannot exceed 100%.',
                ]);
            }
        }

        $student = null;
        $challan = null;

        DB::transaction(function () use ($data, $request, $sid, $user, &$student, &$challan) {
            $guardian = Guardian::create(array_merge(
                $data['guardian'],
                ['school_id' => $sid],
            ));

            $student = Student::create(array_merge(
                collect($data)->except([
                    'guardian',
                    'academic_year_id',
                    'fee_structure_ids',
                    'first_voucher_structure_ids',
                    'billing_start_month',
                    'initialize_fees',
                    'generate_first_challan',
                    'due_date',
                    'financial_notes',
                    'concession',
                ])->toArray(),
                [
                    'school_id'   => $sid,
                    'guardian_id' => $guardian->id,
                ],
            ));

            $hasFeeAssignment = $request->boolean('initialize_fees') || ! empty($data['fee_structure_ids']);
            $hasConcession    = ! empty($data['concession']) && ! empty($data['concession']['title']);
            $hasChallan       = $request->boolean('generate_first_challan');

            if ($hasFeeAssignment || $hasConcession || $hasChallan) {
                if (! empty($data['academic_year_id'])) {
                    $academicYear = AcademicYear::where('school_id', $sid)->findOrFail($data['academic_year_id']);
                } else {
                    $academicYear = AcademicYear::where('school_id', $sid)->where('is_current', true)->first()
                        ?? AcademicYear::where('school_id', $sid)->orderByDesc('start_date')->first();
                }

                if (! $academicYear) {
                    throw ValidationException::withMessages([
                        'academic_year_id' => 'No active academic year found for this school.',
                    ]);
                }

                try {
                    $financialPayload = [
                        'fee_structure_ids'           => $data['fee_structure_ids'] ?? [],
                        'first_voucher_structure_ids' => $data['first_voucher_structure_ids'] ?? null,
                        'initialize_fees'             => true,
                        'billing_start_month'         => $data['billing_start_month'] ?? null,
                        'generate_first_challan'      => $hasChallan,
                        'due_date'                    => $data['due_date'] ?? null,
                        'notes'                       => $data['financial_notes'] ?? null,
                    ];

                    if ($hasConcession) {
                        $financialPayload['concession'] = $data['concession'];
                    }

                    $financials = StudentFeeAssignmentService::processAdmissionFinancials(
                        $user,
                        $student,
                        $academicYear,
                        $financialPayload
                    );

                    $challan = $financials['challan'] ?? null;
                } catch (\InvalidArgumentException $e) {
                    $errorKey = str_contains($e->getMessage(), 'admission voucher')
                        ? 'first_voucher_structure_ids'
                        : 'fee_setup';
                    throw ValidationException::withMessages([
                        $errorKey => $e->getMessage(),
                    ]);
                }
            }
        });

        $message = $challan
            ? 'Student admitted and first fee voucher generated successfully.'
            : 'Student admitted successfully.';

        return redirect()->route('school.students.show', $student)->with('success', $message);
    }

    public function show(Student $student): Response
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $student->load(['schoolClass', 'section', 'guardian', 'documents', 'user']);

        $financialSummary  = StudentFinancialLedgerService::summary($student);
        $challans          = StudentFinancialLedgerService::challans($student);
        $payments          = StudentFinancialLedgerService::payments($student);
        $activeAssignments = StudentFinancialLedgerService::activeAssignments($student);
        $activeDiscounts   = StudentFinancialLedgerService::activeDiscounts($student);

        $portalUser = $student->user ? [
            'id'            => $student->user->id,
            'name'          => $student->user->name,
            'email'         => $student->user->email,
            'status'        => $student->user->status ?? 'active',
            'last_login_at' => $student->user->last_login_at ? $student->user->last_login_at->toDateTimeString() : null,
        ] : null;

        return Inertia::render('SchoolAdmin/Students/Show', [
            'student'          => $student,
            'portalUser'       => $portalUser,
            'financialSummary' => $financialSummary,
            'challans'         => $challans,
            'payments'         => $payments,
            'assignments'      => $activeAssignments,
            'concessions'      => $activeDiscounts,
        ]);
    }

    public function edit(Student $student): Response
    {
        $student->load('guardian');

        return Inertia::render('SchoolAdmin/Students/Edit', [
            'student'  => $student,
            'classes'  => SchoolClass::orderBy('numeric_name')->get(['id', 'name']),
            'sections' => Section::orderBy('name')->get(['id', 'class_id', 'name']),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'nullable|string|max:100',
            'gender'          => 'required|in:male,female,other',
            'date_of_birth'   => 'nullable|date',
            'blood_group'     => 'nullable|string|max:5',
            'religion'        => 'nullable|string|max:50',
            'nationality'     => 'nullable|string|max:50',
            'phone'           => 'nullable|string|max:20',
            'email'           => 'nullable|email|max:150',
            'address'         => 'nullable|string|max:500',
            'category'        => 'required|in:general,disabled,quota',
            'status'          => 'required|in:active,alumni,transferred,inactive',
            'admission_date'  => 'nullable|date',
            'previous_school' => 'nullable|string|max:200',
            'roll_no'         => 'nullable|string|max:50',
            'class_id'        => ['required', SchoolExists::make('classes', 'id', $sid)],
            'section_id'      => ['nullable', SchoolExists::make('sections', 'id', $sid)],
            'guardian.name'       => 'required|string|max:150',
            'guardian.relation'   => 'required|string|max:50',
            'guardian.phone'      => 'nullable|string|max:20',
            'guardian.email'      => 'nullable|email|max:150',
            'guardian.occupation' => 'nullable|string|max:100',
            'guardian.address'    => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($data, $student) {
            $student->update(collect($data)->except('guardian')->toArray());

            if ($student->guardian) {
                $student->guardian->update($data['guardian']);
            } else {
                $guardian = Guardian::create(array_merge(
                    $data['guardian'],
                    ['school_id' => $student->school_id],
                ));
                $student->update(['guardian_id' => $guardian->id]);
            }
        });

        return redirect()->route('school.students.show', $student)->with('success', 'Student updated.');
    }

    public function destroy(Student $student): RedirectResponse
    {
        $student->delete();

        return redirect()->route('school.students.index')->with('success', 'Student removed.');
    }

    public function uploadDocument(Request $request, Student $student): RedirectResponse
    {
        if (! auth()->user()->hasRole('super-admin') && $student->school_id !== $this->getSchoolId()) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'title' => 'required|string|max:150',
            'file'  => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $path = $request->file('file')->store(
            \App\Services\TenantStorage::studentDocumentPath($student->school_id, $student->id),
            'private'
        );

        StudentDocument::create([
            'school_id'  => $student->school_id,
            'student_id' => $student->id,
            'title'      => $request->title,
            'file_path'  => $path,
            'file_type'  => $request->file('file')->getMimeType(),
            'file_size'  => $request->file('file')->getSize(),
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function downloadDocument(StudentDocument $document)
    {
        $isSuperAdmin = auth()->user()->hasRole('super-admin');
        return \App\Services\TenantStorage::downloadPrivateDocument($document, $this->getSchoolId(), $isSuperAdmin);
    }

    public function deleteDocument(StudentDocument $document): RedirectResponse
    {
        if (! auth()->user()->hasRole('super-admin') && $document->school_id !== $this->getSchoolId()) {
            abort(403, 'Unauthorized action.');
        }

        \App\Services\TenantStorage::privateDisk()->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Document deleted.');
    }

    public function createPortalAccess(Request $request, Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        if ($student->user_id && $student->user) {
            return back()->with('error', 'Portal access account already exists for this student.');
        }

        $data = $request->validate([
            'email' => 'nullable|email|max:150',
        ]);

        $email = ! empty($data['email']) ? trim(strtolower($data['email'])) : null;
        if (! $email) {
            $email = ! empty($student->email) ? trim(strtolower($student->email)) : null;
        }
        if (! $email) {
            $cleanAdmission = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $student->admission_no));
            $email = "{$cleanAdmission}@student.school.local";
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            if ($existing->school_id === $sid) {
                $student->update(['user_id' => $existing->id]);
                return back()->with('info', 'Linked existing user account to student.');
            }
            throw ValidationException::withMessages([
                'email' => 'A user with this email address already exists in the system.',
            ]);
        }

        $tempPassword = Str::password(10, true, true, false);

        $user = User::create([
            'school_id' => $sid,
            'name'      => $student->full_name,
            'email'     => $email,
            'password'  => Hash::make($tempPassword),
            'status'    => 'active',
        ]);

        $user->assignRole('student');
        $student->update(['user_id' => $user->id]);

        return back()->with([
            'success'            => "Portal access created for {$student->full_name}.",
            'portal_credentials' => [
                'email'         => $user->email,
                'temp_password' => $tempPassword,
            ],
        ]);
    }

    public function togglePortalAccessStatus(Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        if (! $student->user_id || ! $student->user) {
            return back()->with('error', 'No portal access account found for this student.');
        }

        $user = $student->user;
        $newStatus = ($user->status === 'active') ? 'inactive' : 'active';
        $user->status = $newStatus;
        $user->save();

        $actionWord = ($newStatus === 'active') ? 'activated' : 'disabled';
        return back()->with('success', "Portal access {$actionWord} successfully.");
    }

    public function resetPortalPassword(Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        if (! $student->user_id || ! $student->user) {
            return back()->with('error', 'No portal access account found for this student.');
        }

        $user = $student->user;
        $tempPassword = Str::password(10, true, true, false);
        $user->password = Hash::make($tempPassword);
        $user->save();

        return back()->with([
            'success'            => "Password reset successfully for {$student->full_name}.",
            'portal_credentials' => [
                'email'         => $user->email,
                'temp_password' => $tempPassword,
            ],
        ]);
    }
}
