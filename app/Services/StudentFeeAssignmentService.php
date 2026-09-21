<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\FeeStructure;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class StudentFeeAssignmentService
{
    /**
     * Authorize that the acting user has permission to assign fees.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public static function authorizeAssignment(?User $user): void
    {
        if (! $user) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Unauthenticated user cannot assign fees.");
        }

        if ($user->hasRole('super-admin')) {
            return;
        }

        if (! $user->can('fees.assign')) {
            throw new \Illuminate\Auth\Access\AuthorizationException("User does not have permission 'fees.assign'.");
        }
    }

    /**
     * Authorize that the acting user has permission to grant fee concessions/discounts.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public static function authorizeConcession(?User $user): void
    {
        if (! $user) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Unauthenticated user cannot grant fee concessions.");
        }

        if ($user->hasRole('super-admin')) {
            return;
        }

        if (! $user->can('fees.discount')) {
            throw new \Illuminate\Auth\Access\AuthorizationException("User does not have permission 'fees.discount'.");
        }
    }
    /**
     * Authoritatively initialize or synchronize fee assignments for a student in an academic year.
     * Enforces the invariant that all active mandatory class fee structures (is_optional = false)
     * are automatically included, and cannot be removed/deactivated by client payload manipulation.
     *
     * @param Student $student
     * @param AcademicYear $academicYear
     * @param int[] $feeStructureIds Optional or explicitly selected FeeStructure IDs
     * @param int|null $assignedBy User ID performing assignment
     * @param string|null $notes
     * @param Carbon|null $startsOn Default to start of billing month or academic year start
     * @param Carbon|null $endsOn
     * @return Collection<int, StudentFeeAssignment>
     *
     * @throws InvalidArgumentException on tenant, class, academic year or status mismatches
     */
    public static function initializeForStudent(
        Student $student,
        AcademicYear $academicYear,
        array $feeStructureIds = [],
        ?int $assignedBy = null,
        ?string $notes = null,
        ?Carbon $startsOn = null,
        ?Carbon $endsOn = null
    ): Collection {
        $sid = $student->school_id;

        // 1. Tenant Safety: Student and AcademicYear must belong to the same school
        if ($academicYear->school_id !== $sid) {
            throw new InvalidArgumentException(
                "Cross-tenant violation: Student belongs to School #{$sid}, but AcademicYear belongs to School #{$academicYear->school_id}."
            );
        }

        // 2. Fetch all active mandatory class structures for student's class and academic year
        $mandatoryStructures = FeeStructure::where('school_id', $sid)
            ->where('class_id', $student->class_id)
            ->where('academic_year', $academicYear->name)
            ->where('is_active', true)
            ->where('is_optional', false)
            ->get();

        // 3. Validate any explicitly submitted fee structures
        $submittedStructures = collect();
        if (! empty($feeStructureIds)) {
            $submittedStructures = FeeStructure::whereIn('id', $feeStructureIds)->get();

            if ($submittedStructures->count() !== count(array_unique($feeStructureIds))) {
                throw new InvalidArgumentException("One or more selected fee structures could not be found.");
            }

            foreach ($submittedStructures as $st) {
                if ($st->school_id !== $sid) {
                    throw new InvalidArgumentException(
                        "Cross-tenant violation: FeeStructure #{$st->id} belongs to School #{$st->school_id}, expected #{$sid}."
                    );
                }

                if ($st->class_id !== $student->class_id) {
                    throw new InvalidArgumentException(
                        "Class mismatch: FeeStructure #{$st->id} belongs to Class #{$st->class_id}, but student is in Class #{$student->class_id}."
                    );
                }

                if ($st->academic_year !== $academicYear->name) {
                    throw new InvalidArgumentException(
                        "Academic year mismatch: FeeStructure #{$st->id} specifies '{$st->academic_year}', but requested AcademicYear is '{$academicYear->name}'."
                    );
                }

                if (! $st->is_active) {
                    throw new InvalidArgumentException(
                        "FeeStructure #{$st->id} is inactive and cannot be assigned."
                    );
                }
            }
        }

        // 4. Server-authoritative union: all mandatory structures UNION submitted optional structures
        $structures = $mandatoryStructures->keyBy('id');
        foreach ($submittedStructures as $st) {
            $structures->put($st->id, $st);
        }

        // 5. Billing start semantic: first day of selected billing month (no proration in V1)
        $effectiveStartsOn = $startsOn
            ? Carbon::parse($startsOn)->startOfMonth()
            : Carbon::parse($academicYear->start_date)->startOfMonth();

        return DB::transaction(function () use (
            $sid, $student, $academicYear, $structures,
            $assignedBy, $notes, $effectiveStartsOn, $endsOn
        ) {
            // Fetch all existing assignments for this student and academic year (including inactive)
            $existingAssignments = StudentFeeAssignment::where('school_id', $sid)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $academicYear->id)
                ->with('feeStructure')
                ->get()
                ->keyBy('fee_structure_id');

            $assignedOrReactivatedIds = [];

            foreach ($structures as $st) {
                $assignedOrReactivatedIds[] = $st->id;

                if ($existingAssignments->has($st->id)) {
                    /** @var StudentFeeAssignment $assignment */
                    $assignment = $existingAssignments->get($st->id);

                    if (! $assignment->is_active) {
                        // Reactivate previously stopped assignment
                        $assignment->reactivate($assignedBy, $effectiveStartsOn, $endsOn);
                        if ($notes !== null) {
                            $assignment->update(['notes' => $notes]);
                        }

                        self::recordAudit(
                            'assignment_reactivated',
                            $sid,
                            $student->id,
                            $assignedBy,
                            "Reactivated fee structure #{$st->id} ({$st->frequency}) for student #{$student->id} in AY {$academicYear->name}",
                            ['fee_structure_id' => $st->id, 'academic_year_id' => $academicYear->id]
                        );
                    } else {
                        // Already active: update dates/notes if specified
                        $assignment->update([
                            'starts_on' => $effectiveStartsOn,
                            'ends_on'   => $endsOn ?? $assignment->ends_on,
                            'notes'     => $notes ?? $assignment->notes,
                        ]);
                    }
                } else {
                    // Create new assignment record
                    $assignment = StudentFeeAssignment::create([
                        'school_id'         => $sid,
                        'student_id'        => $student->id,
                        'fee_structure_id'  => $st->id,
                        'academic_year_id'  => $academicYear->id,
                        'starts_on'         => $effectiveStartsOn,
                        'ends_on'           => $endsOn,
                        'is_active'         => true,
                        'assigned_by'       => $assignedBy,
                        'notes'             => $notes,
                    ]);

                    self::recordAudit(
                        'assignment_created',
                        $sid,
                        $student->id,
                        $assignedBy,
                        "Assigned fee structure #{$st->id} ({$st->frequency}) to student #{$student->id} in AY {$academicYear->name}",
                        ['fee_structure_id' => $st->id, 'academic_year_id' => $academicYear->id]
                    );
                }
            }

            // Deactivate any previously active OPTIONAL assignments that were unselected during this update
            foreach ($existingAssignments as $existingStId => $existingAssignment) {
                if (! in_array($existingStId, $assignedOrReactivatedIds) && $existingAssignment->is_active) {
                    // Mandatory fee structures can NEVER be deactivated through normal sync
                    if ($existingAssignment->feeStructure && ! $existingAssignment->feeStructure->is_optional) {
                        continue;
                    }

                    $existingAssignment->deactivate(
                        $assignedBy ?? 0,
                        'Unselected during fee assignment synchronization'
                    );

                    self::recordAudit(
                        'assignment_deactivated',
                        $sid,
                        $student->id,
                        $assignedBy,
                        "Deactivated optional fee structure #{$existingStId} for student #{$student->id} (unselected)",
                        ['fee_structure_id' => $existingStId, 'academic_year_id' => $academicYear->id]
                    );
                }
            }

            return StudentFeeAssignment::where('school_id', $sid)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $academicYear->id)
                ->where('is_active', true)
                ->with('feeStructure.feeCategory')
                ->get();
        });
    }

    /**
     * Authoritatively validate and process admission / student profile financial payloads.
     * Enforces that non-financial users cannot submit forged fee assignments or concessions.
     *
     * @param User $actor Acting user submitting the admission or update
     * @param Student $student
     * @param AcademicYear $academicYear
     * @param array $payload [
     *     'fee_structure_ids'      => int[],
     *     'billing_start_month'    => string|Carbon,
     *     'concession'             => ?array,
     *     'generate_first_challan' => bool,
     *     'due_date'               => string|Carbon|null,
     *     'notes'                  => ?string,
     * ]
     * @return array [
     *     'assignments' => Collection<int, StudentFeeAssignment>,
     *     'concession'  => ?StudentFeeDiscount,
     *     'challan'     => ?FeeChallan,
     * ]
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException if financial payload provided without appropriate permissions
     */
    public static function processAdmissionFinancials(
        User $actor,
        Student $student,
        AcademicYear $academicYear,
        array $payload = []
    ): array {
        $hasFeeAssignmentData = ! empty($payload['fee_structure_ids']) || ! empty($payload['initialize_fees']);
        $hasConcessionData    = ! empty($payload['concession']);

        // 1. Authorization boundary checks
        if ($hasFeeAssignmentData) {
            self::authorizeAssignment($actor);
        }

        if ($hasConcessionData) {
            self::authorizeConcession($actor);
        }

        $result = [
            'assignments' => collect(),
            'concession'  => null,
            'challan'     => null,
        ];

        // If no financial payload submitted and no fee initialization requested, return cleanly
        if (! $hasFeeAssignmentData && ! $hasConcessionData) {
            return $result;
        }

        $startsOn = ! empty($payload['billing_start_month'])
            ? Carbon::parse($payload['billing_start_month'])->startOfMonth()
            : Carbon::parse($academicYear->start_date)->startOfMonth();

        // 2. Initialize assignments (authoritatively includes all mandatory structures)
        $feeStructureIds = $payload['fee_structure_ids'] ?? [];
        $notes = $payload['notes'] ?? null;

        $assignments = self::initializeForStudent(
            $student,
            $academicYear,
            $feeStructureIds,
            $actor->id,
            $notes,
            $startsOn
        );
        $result['assignments'] = $assignments;

        // 3. Process concession if authorized and provided
        if ($hasConcessionData) {
            $concession = self::createConcession(
                $student,
                $academicYear,
                $payload['concession'],
                $actor->id
            );
            $result['concession'] = $concession;
        }

        // 4. Optionally generate first challan
        if (! empty($payload['generate_first_challan'])) {
            $targetMonth = $startsOn->copy();
            $dueDate = ! empty($payload['due_date'])
                ? Carbon::parse($payload['due_date'])
                : $targetMonth->copy()->endOfMonth();

            $firstVoucherStructureIds = isset($payload['first_voucher_structure_ids'])
                ? (array) $payload['first_voucher_structure_ids']
                : null;

            $challan = FeeBillingService::createAdmissionChallan(
                $student,
                $academicYear,
                $targetMonth,
                $firstVoucherStructureIds,
                $dueDate,
                $result['concession']
            );
            $result['challan'] = $challan;
        }

        return $result;
    }

    /**
     * Explicitly deactivate an assignment with audit reason.
     * Mandatory fee structures (is_optional = false) cannot be deactivated.
     */
    public static function deactivateAssignment(
        StudentFeeAssignment $assignment,
        int $deactivatedBy,
        string $reason,
        bool $force = false
    ): StudentFeeAssignment {
        if (! $force) {
            $assignment->loadMissing('feeStructure');
            if ($assignment->feeStructure && ! $assignment->feeStructure->is_optional) {
                throw new InvalidArgumentException(
                    "Mandatory fee assignments (is_optional = false) cannot be deactivated. Apply a 100% concession for fee waivers or scholarships instead."
                );
            }
        }

        $assignment->deactivate($deactivatedBy, $reason);

        self::recordAudit(
            'assignment_deactivated',
            $assignment->school_id,
            $assignment->student_id,
            $deactivatedBy,
            "Deactivated fee structure #{$assignment->fee_structure_id} for student #{$assignment->student_id}: {$reason}",
            ['assignment_id' => $assignment->id, 'reason' => $reason]
        );

        return $assignment;
    }

    /**
     * Reactivate an existing assignment record.
     */
    public static function reactivateAssignment(
        StudentFeeAssignment $assignment,
        ?int $reactivatedBy = null,
        ?Carbon $startsOn = null,
        ?Carbon $endsOn = null
    ): StudentFeeAssignment {
        $assignment->reactivate($reactivatedBy, $startsOn, $endsOn);

        self::recordAudit(
            'assignment_reactivated',
            $assignment->school_id,
            $assignment->student_id,
            $reactivatedBy,
            "Reactivated assignment #{$assignment->id} for student #{$assignment->student_id}",
            ['assignment_id' => $assignment->id]
        );

        return $assignment;
    }

    /**
     * Create a validated, bounds-checked student fee discount (concession).
     *
     * @param Student $student
     * @param AcademicYear $academicYear
     * @param array $data ['title', 'type', 'value', 'fee_category_id' => null]
     * @param int|null $actorId
     * @return StudentFeeDiscount
     *
     * @throws InvalidArgumentException on validation or stacking violations
     */
    public static function createConcession(
        Student $student,
        AcademicYear $academicYear,
        array $data,
        ?int $actorId = null
    ): StudentFeeDiscount {
        $sid = $student->school_id;

        if ($academicYear->school_id !== $sid) {
            throw new InvalidArgumentException("Cross-tenant violation: Student and AcademicYear belong to different schools.");
        }

        $type = $data['type'] ?? 'percentage';
        $value = (float) ($data['value'] ?? 0);
        $title = trim($data['title'] ?? 'Fee Concession');
        $categoryId = ! empty($data['fee_category_id']) ? (int) $data['fee_category_id'] : null;

        if ($title === '') {
            throw new InvalidArgumentException("Concession title is required.");
        }

        if (! in_array($type, ['percentage', 'fixed'])) {
            throw new InvalidArgumentException("Discount type must be either 'percentage' or 'fixed'.");
        }

        if ($type === 'percentage') {
            if ($value <= 0 || $value > 100) {
                throw new InvalidArgumentException("Percentage discount must be greater than 0 and at most 100.");
            }
        } else {
            // Fixed discount
            if ($value <= 0) {
                throw new InvalidArgumentException("Fixed discount amount must be greater than 0.");
            }

            // Fixed concession cannot exceed applicable fee structure gross amount
            if ($categoryId !== null) {
                $applicableStructure = FeeStructure::where('school_id', $sid)
                    ->where('class_id', $student->class_id)
                    ->where('fee_category_id', $categoryId)
                    ->where('academic_year', $academicYear->name)
                    ->where('is_active', true)
                    ->first();

                if ($applicableStructure) {
                    $structureCents = Money::toCents($applicableStructure->amount);
                    $discountCents = Money::toCents($value);
                    if ($discountCents > $structureCents) {
                        throw new InvalidArgumentException(
                            "Fixed discount (" . Money::toDecimal($discountCents) . ") cannot exceed the fee head amount (" . Money::toDecimal($structureCents) . ")."
                        );
                    }
                }
            }
        }

        // Prevent ambiguous active discount stacking for same student, academic year, and category
        $query = StudentFeeDiscount::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('is_active', true);

        if ($categoryId !== null) {
            $query->where('fee_category_id', $categoryId);
        } else {
            $query->whereNull('fee_category_id');
        }

        if ($query->exists()) {
            throw new InvalidArgumentException(
                "An active discount already exists for this student and " .
                ($categoryId ? "fee category #{$categoryId}" : "general tuition") .
                " in academic year {$academicYear->name}."
            );
        }

        $discount = StudentFeeDiscount::create([
            'school_id'        => $sid,
            'student_id'       => $student->id,
            'fee_category_id'  => $categoryId,
            'academic_year_id' => $academicYear->id,
            'title'            => $title,
            'type'             => $type,
            'value'            => $value,
            'is_active'        => true,
        ]);

        self::recordAudit(
            'concession_created',
            $sid,
            $student->id,
            $actorId,
            "Created {$type} concession '{$title}' ({$value}) for student #{$student->id}",
            ['discount_id' => $discount->id, 'type' => $type, 'value' => $value]
        );

        return $discount;
    }

    /**
     * Deactivate an active concession.
     */
    public static function deactivateConcession(
        StudentFeeDiscount $discount,
        ?int $actorId = null,
        ?string $reason = null
    ): StudentFeeDiscount {
        $discount->update(['is_active' => false]);

        self::recordAudit(
            'concession_deactivated',
            $discount->school_id,
            $discount->student_id,
            $actorId,
            "Deactivated concession '{$discount->title}' for student #{$discount->student_id}: " . ($reason ?? 'No reason provided'),
            ['discount_id' => $discount->id, 'reason' => $reason]
        );

        return $discount;
    }

    /**
     * Safe internal audit logger supporting both Laravel Log and Spatie Activitylog.
     */
    protected static function recordAudit(
        string $event,
        int $schoolId,
        int $studentId,
        ?int $actorId,
        string $description,
        array $properties = []
    ): void {
        Log::info("[FEE-AUDIT] {$event}: {$description}", array_merge([
            'school_id'  => $schoolId,
            'student_id' => $studentId,
            'actor_id'   => $actorId,
        ], $properties));

        if (function_exists('activity')) {
            try {
                $activity = activity('fee-assignment')
                    ->withProperties(array_merge([
                        'school_id'  => $schoolId,
                        'student_id' => $studentId,
                        'event'      => $event,
                    ], $properties));

                if ($actorId) {
                    $user = User::find($actorId);
                    if ($user) {
                        $activity->causedBy($user);
                    }
                }

                $activity->log($description);
            } catch (\Throwable) {
                // Silently ignore activitylog failures
            }
        }
    }
}
