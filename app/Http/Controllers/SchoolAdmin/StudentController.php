<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Rules\SchoolExists;
use App\Services\FeeBillingService;
use App\Services\PortalCredentialService;
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
    public function guardianSearch(Request $request): JsonResponse
    {
        $sid = $this->getSchoolId();
        $q = trim((string) $request->input('q', ''));

        $guardians = Guardian::where('school_id', $sid)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('guardian_code', 'like', "%{$q}%");
                });
            })
            ->with(['students:id,guardian_id,first_name,last_name,admission_no', 'user:id,username,email,status'])
            ->limit(20)
            ->get()
            ->map(function ($g) {
                return [
                    'id'             => $g->id,
                    'guardian_code'  => $g->guardian_code,
                    'name'           => $g->name,
                    'relation'       => $g->relation,
                    'phone'          => $g->phone,
                    'email'          => $g->email,
                    'occupation'     => $g->occupation,
                    'address'        => $g->address,
                    'has_portal'     => ! empty($g->user_id),
                    'username'       => $g->user?->username,
                    'students_count' => $g->students->count(),
                    'students'       => $g->students->map(fn ($s) => "{$s->first_name} {$s->last_name} ({$s->admission_no})")->values(),
                ];
            });

        return response()->json($guardians);
    }

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
        $academicYears->each->append('year_aliases');

        $currentAcademicYear = $academicYears->firstWhere('is_current', true) ?? $academicYears->first();
        $currentAcademicYear?->append('year_aliases');

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
            'class_id'         => ['required', SchoolExists::make('classes', 'id', $sid)],
            'academic_year_id' => ['nullable', SchoolExists::make('academic_years', 'id', $sid)],
            'academic_year'    => 'nullable|string',
        ]);

        $resolvedAcademicYear = null;
        if ($request->filled('academic_year_id')) {
            $resolvedAcademicYear = AcademicYear::where('school_id', $sid)->find($request->academic_year_id);
        } elseif ($request->filled('academic_year')) {
            // Find academic year in current school whose name or aliases match the requested string
            $resolvedAcademicYear = AcademicYear::where('school_id', $sid)
                ->where('name', $request->academic_year)
                ->first();

            if (! $resolvedAcademicYear) {
                $candidates = AcademicYear::where('school_id', $sid)->get();
                foreach ($candidates as $candidate) {
                    if ($candidate->matchesYearString($request->academic_year)) {
                        $resolvedAcademicYear = $candidate;
                        break;
                    }
                }
            }
        }

        $structures = FeeStructure::where('school_id', $sid)
            ->where('class_id', $request->class_id)
            ->when($resolvedAcademicYear, function ($q) use ($resolvedAcademicYear) {
                $q->whereIn('academic_year', $resolvedAcademicYear->getYearAliases());
            }, function ($q) use ($request) {
                // Backward compatibility if no AcademicYear entity could be resolved but a string was passed
                $q->when($request->academic_year, fn ($sq) => $sq->where('academic_year', $request->academic_year));
            })
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
            'guardian_id'         => ['nullable', SchoolExists::make('guardians', 'id', $sid)],
            'guardian.name'       => 'required_without:guardian_id|nullable|string|max:150',
            'guardian.relation'   => 'required_without:guardian_id|nullable|string|max:50',
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
        $portalAccountCreated = null;

        DB::transaction(function () use ($data, $request, $sid, $user, &$student, &$challan, &$portalAccountCreated) {
            if (! empty($data['guardian_id'])) {
                $guardian = Guardian::where('school_id', $sid)->findOrFail($data['guardian_id']);
            } else {
                $guardian = Guardian::create(array_merge(
                    $data['guardian'] ?? [],
                    ['school_id' => $sid],
                ));
            }

            $student = Student::create(array_merge(
                collect($data)->except([
                    'guardian',
                    'guardian_id',
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

            $school = School::find($sid);

            // Automatically provision guardian portal account first (if not already existing)
            $guardianCreds = PortalCredentialService::provisionGuardianPortalAccount(
                $guardian,
                $school,
                null,
                $user
            );

            // Automatically provision student portal account
            $studentCreds = PortalCredentialService::provisionStudentPortalAccount(
                $student,
                $school,
                null,
                $user
            );

            $portalAccountCreated = [
                'student' => [
                    'username'     => $studentCreds['username'],
                    'email_queued' => $studentCreds['email_queued'],
                ],
                'guardian' => [
                    'name'         => $guardian->name,
                    'username'     => $guardianCreds['username'],
                    'is_existing'  => $guardianCreds['is_existing'] ?? false,
                    'email_queued' => $guardianCreds['email_queued'],
                ],
            ];

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

        return redirect()->route('school.students.show', $student)
            ->with('success', $message)
            ->with('portal_account_created', $portalAccountCreated);
    }

    public function show(Student $student): Response
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $student->load(['schoolClass', 'section', 'guardian.user', 'guardian.students', 'documents', 'user']);

        $user = auth()->user();
        $canViewCreds = $user ? ($user->hasRole('super-admin') || $user->can('students.portal_credentials.view') || $user->can('students.edit')) : false;
        $canResetCreds = $user ? ($user->hasRole('super-admin') || $user->can('students.portal_credentials.reset') || $user->can('students.edit')) : false;

        $financialSummary  = StudentFinancialLedgerService::summary($student);
        $challans          = StudentFinancialLedgerService::challans($student);
        $payments          = StudentFinancialLedgerService::payments($student);
        $activeAssignments = StudentFinancialLedgerService::activeAssignments($student);
        $activeDiscounts   = StudentFinancialLedgerService::activeDiscounts($student);

        $portalUser = $student->user ? [
            'id'                   => $student->user->id,
            'name'                 => $student->user->name,
            'username'             => $student->user->username,
            'email'                => $student->user->email ?: $student->email,
            'status'               => $student->user->status ?? 'active',
            'must_change_password' => (bool) $student->user->must_change_password,
            'has_active_temp_pass' => $student->user->hasActiveTemporaryPassword(),
            'temp_pass_expires_at' => $student->user->temporary_password_expires_at ? $student->user->temporary_password_expires_at->toDateTimeString() : null,
            'last_login_at'        => $student->user->last_login_at ? $student->user->last_login_at->toDateTimeString() : null,
        ] : null;

        $guardianPortalUser = ($student->guardian && $student->guardian->user) ? [
            'id'                    => $student->guardian->user->id,
            'guardian_id'           => $student->guardian->id,
            'guardian_name'         => $student->guardian->name,
            'guardian_code'         => $student->guardian->guardian_code,
            'name'                  => $student->guardian->user->name,
            'username'              => $student->guardian->user->username,
            'email'                 => $student->guardian->user->email ?: $student->guardian->email,
            'status'                => $student->guardian->user->status ?? 'active',
            'must_change_password'  => (bool) $student->guardian->user->must_change_password,
            'has_active_temp_pass'  => $student->guardian->user->hasActiveTemporaryPassword(),
            'temp_pass_expires_at'  => $student->guardian->user->temporary_password_expires_at ? $student->guardian->user->temporary_password_expires_at->toDateTimeString() : null,
            'last_login_at'         => $student->guardian->user->last_login_at ? $student->guardian->user->last_login_at->toDateTimeString() : null,
            'linked_children_count' => $student->guardian->students->count(),
            'linked_children'       => $student->guardian->students->map(fn ($s) => [
                'id'           => $s->id,
                'name'         => $s->full_name,
                'admission_no' => $s->admission_no,
            ])->values(),
        ] : null;

        return Inertia::render('SchoolAdmin/Students/Show', [
            'student'             => $student,
            'portalUser'          => $portalUser,
            'guardianPortalUser'  => $guardianPortalUser,
            'canViewCredentials'  => $canViewCreds,
            'canResetCredentials' => $canResetCreds,
            'financialSummary'    => $financialSummary,
            'challans'            => $challans,
            'payments'            => $payments,
            'assignments'         => $activeAssignments,
            'concessions'         => $activeDiscounts,
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

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.reset') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to create portal accounts.');
        }

        if ($student->user_id && $student->user) {
            return back()->with('error', 'Portal access account already exists for this student.');
        }

        if ($request->filled('email')) {
            $student->update(['email' => strtolower(trim($request->input('email')))]);
        }

        $school = School::find($sid);
        $result = PortalCredentialService::provisionStudentPortalAccount($student, $school, null, $actor);

        return back()->with('success', "Student portal access account created for {$student->full_name}. Temporary credentials can be revealed from the portal card.");
    }

    public function togglePortalAccessStatus(Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.reset') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to modify portal account status.');
        }

        if (! $student->user_id || ! $student->user) {
            return back()->with('error', 'No portal access account found for this student.');
        }

        $user = $student->user;
        $newStatus = ($user->status === 'active') ? 'inactive' : 'active';
        $user->status = $newStatus;
        $user->save();

        activity()
            ->causedBy($actor)
            ->withProperties([
                'school_id'  => $sid,
                'target_id'  => $user->id,
                'new_status' => $newStatus,
            ])
            ->log('Portal access status changed');

        $actionWord = ($newStatus === 'active') ? 'activated' : 'disabled';
        return back()->with('success', "Portal access {$actionWord} successfully.");
    }

    public function resetPortalPassword(Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.reset') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to reset portal credentials.');
        }

        if (! $student->user_id || ! $student->user) {
            return back()->with('error', 'No portal access account found for this student.');
        }

        $result = PortalCredentialService::resetPortalPassword($student->user, $actor, 'student');

        return back()->with('success', "Temporary password generated successfully for {$student->full_name}. You can reveal credentials from the portal card.");
    }

    public function revealPortalPassword(Student $student): JsonResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.view') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to view temporary credentials.');
        }

        if (! $student->user_id || ! $student->user) {
            return response()->json(['error' => 'No portal user account found.'], 404);
        }

        $plain = PortalCredentialService::revealTemporaryPassword($student->user, $actor);
        if ($plain === null) {
            return response()->json([
                'error' => 'No active temporary password found or it has already expired or been changed by the user.',
            ], 422);
        }

        return response()->json([
            'username'      => $student->user->username,
            'email'         => $student->user->email,
            'temp_password' => $plain,
            'expires_at'    => $student->user->temporary_password_expires_at?->toDateTimeString(),
        ]);
    }

    public function createGuardianPortalAccess(Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.reset') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to create portal accounts.');
        }

        $guardian = $student->guardian;
        if (! $guardian) {
            return back()->with('error', 'Student does not have a linked guardian.');
        }

        if ($guardian->user_id && $guardian->user) {
            return back()->with('error', 'Guardian already has a portal account.');
        }

        $school = School::find($sid);
        $result = PortalCredentialService::provisionGuardianPortalAccount($guardian, $school, null, $actor);

        return back()->with('success', "Parent portal access created for {$guardian->name}. Temporary credentials can be revealed from the portal card.");
    }

    public function revealGuardianPortalPassword(Student $student): JsonResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.view') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to view temporary credentials.');
        }

        $guardian = $student->guardian;
        if (! $guardian || ! $guardian->user_id || ! $guardian->user) {
            return response()->json(['error' => 'No parent portal user account found.'], 404);
        }

        $plain = PortalCredentialService::revealTemporaryPassword($guardian->user, $actor);
        if ($plain === null) {
            return response()->json([
                'error' => 'No active temporary password found or it has already expired or been changed by the user.',
            ], 422);
        }

        return response()->json([
            'username'      => $guardian->user->username,
            'email'         => $guardian->user->email,
            'temp_password' => $plain,
            'expires_at'    => $guardian->user->temporary_password_expires_at?->toDateTimeString(),
        ]);
    }

    public function resetGuardianPortalPassword(Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.reset') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to reset portal credentials.');
        }

        $guardian = $student->guardian;
        if (! $guardian || ! $guardian->user_id || ! $guardian->user) {
            return back()->with('error', 'No parent portal account found for this guardian.');
        }

        $result = PortalCredentialService::resetPortalPassword($guardian->user, $actor, 'guardian');

        return back()->with('success', "Temporary password generated successfully for {$guardian->name}. You can reveal credentials from the portal card.");
    }

    public function resendPortalCredentialsEmail(Request $request, Student $student): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($student->school_id !== $sid) {
            abort(403);
        }

        $actor = auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.view') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to resend portal credentials.');
        }

        $targetType = $request->input('type', 'student');
        $user = ($targetType === 'guardian')
            ? $student->guardian?->user
            : $student->user;

        if (! $user) {
            return back()->with('error', 'Target portal user account not found.');
        }

        $sent = PortalCredentialService::resendCredentialEmail($user, $actor, $targetType);
        if (! $sent) {
            return back()->with('error', 'Cannot send email: account has no email address or temporary credential has expired/been changed.');
        }

        return back()->with('success', 'Portal credentials email queued successfully.');
    }
}
