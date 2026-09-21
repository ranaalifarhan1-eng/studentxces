<?php

namespace App\Services;

use App\Mail\FeeVoucherIssuedMail;
use App\Models\AcademicYear;
use App\Models\FeeChallan;
use App\Models\FeeChallanItem;
use App\Models\FeeStructure;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class FeeBulkAssignService
{
    /**
     * Authorize that the acting user has permission to perform bulk fee operations.
     *
     * @throws AuthorizationException
     */
    public static function authorizeUser(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException("Unauthenticated user cannot perform bulk fee operations.");
        }

        if ($user->hasRole('super-admin')) {
            return;
        }

        if (! $user->can('fees.bulk_bill')) {
            throw new AuthorizationException("User does not have permission 'fees.bulk_bill'.");
        }
    }

    /**
     * Resolve target students based on targeting mode and filters.
     * Enforces tenant and class scoping.
     *
     * @return Collection<int, Student>
     */
    public static function resolveTargetStudents(
        int $schoolId,
        int $classId,
        array $params = []
    ): Collection {
        $targetMode = $params['target_mode'] ?? 'entire_class';
        $sectionIds = array_filter((array) ($params['section_ids'] ?? []));
        $studentIds = array_filter((array) ($params['student_ids'] ?? []));
        $activeOnly = isset($params['active_only']) ? (bool) $params['active_only'] : true;

        $query = Student::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->with(['schoolClass:id,name', 'section:id,name', 'guardian:id,user_id,name,email,phone']);

        if ($activeOnly) {
            $query->where('status', 'active');
        }

        if ($targetMode === 'selected_sections' && ! empty($sectionIds)) {
            $query->whereIn('section_id', $sectionIds);
        } elseif ($targetMode === 'selected_students' && ! empty($studentIds)) {
            $query->whereIn('id', $studentIds);
        }

        return $query->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * Calculate comprehensive preview metrics without mutating the database.
     */
    public static function preview(
        int $schoolId,
        int $feeStructureId,
        int $academicYearId,
        int $classId,
        array $params = []
    ): array {
        $structure = FeeStructure::with(['feeCategory', 'schoolClass'])
            ->where('school_id', $schoolId)
            ->findOrFail($feeStructureId);

        $academicYear = AcademicYear::where('school_id', $schoolId)->findOrFail($academicYearId);

        if ($structure->class_id !== $classId) {
            throw new InvalidArgumentException("Fee structure belongs to class #{$structure->class_id}, but targeted class is #{$classId}.");
        }

        $billingLabel = trim($params['billing_label'] ?? $structure->feeCategory?->name ?? 'Fee Voucher');
        $executionMode = $params['execution_mode'] ?? 'assign_and_bill';

        // Query all students in this class matching selection
        $students = self::resolveTargetStudents($schoolId, $classId, $params);

        // Fetch existing assignments in one query
        $existingAssignments = StudentFeeAssignment::where('school_id', $schoolId)
            ->where('fee_structure_id', $structure->id)
            ->where('academic_year_id', $academicYear->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        // Fetch existing challans/items for this billing label
        $existingChallans = FeeChallan::where('school_id', $schoolId)
            ->where('billing_period_key', $billingLabel)
            ->where('status', '!=', 'void')
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $existingItems = FeeChallanItem::where('school_id', $schoolId)
            ->where('fee_structure_id', $structure->id)
            ->where('charge_period_key', $billingLabel)
            ->whereNotNull('active_charge_key')
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $structureGrossCents = Money::toCents($structure->amount);

        $totalStudents = $students->count();
        $eligibleCount = 0;
        $alreadyAssignedCount = 0;
        $alreadyBilledCount = 0;
        $inactiveSkippedCount = 0;
        $missingStudentEmailCount = 0;
        $missingGuardianEmailCount = 0;
        $newAssignmentsCount = 0;
        $vouchersToGenerateCount = 0;
        $estimatedBillingCents = 0;

        $studentRows = [];

        foreach ($students as $student) {
            $isInactive = $student->status !== 'active';
            if ($isInactive) {
                $inactiveSkippedCount++;
            }

            $isAssigned = $existingAssignments->has($student->id) && $existingAssignments->get($student->id)->is_active;
            if ($isAssigned) {
                $alreadyAssignedCount++;
            } else {
                $newAssignmentsCount++;
            }

            $isBilled = $existingChallans->has($student->id) || $existingItems->has($student->id);
            if ($isBilled) {
                $alreadyBilledCount++;
            }

            $hasStudentEmail = ! empty($student->email);
            $hasGuardianEmail = ! empty($student->guardian?->email);

            if (! $hasStudentEmail) {
                $missingStudentEmailCount++;
            }
            if (! $hasGuardianEmail) {
                $missingGuardianEmailCount++;
            }

            $canBill = (! $isInactive) && (! $isBilled) && ($executionMode === 'assign_and_bill');
            if ($canBill) {
                $vouchersToGenerateCount++;
                $estimatedBillingCents += $structureGrossCents;
            }

            if (! $isInactive) {
                $eligibleCount++;
            }

            $assignmentStatus = $isAssigned ? 'already_assigned' : 'new';
            $billingStatus = $isBilled ? 'already_billed' : ($executionMode === 'assign_and_bill' ? 'to_bill' : 'skipped');

            $studentRows[] = [
                'id'                   => $student->id,
                'name'                 => $student->full_name,
                'admission_no'         => $student->admission_no,
                'class_name'           => $student->schoolClass?->name ?? '—',
                'section_name'         => $student->section?->name ?? '—',
                'status'               => $student->status,
                'is_active'            => ! $isInactive,
                'assignment_status'    => $assignmentStatus,
                'billing_status'       => $billingStatus,
                'has_student_email'    => $hasStudentEmail,
                'student_email'        => $student->email,
                'has_guardian_email'   => $hasGuardianEmail,
                'guardian_email'       => $student->guardian?->email,
                'amount'               => Money::toDecimal($structureGrossCents),
                'amount_formatted'     => 'PKR ' . number_format($structureGrossCents / 100, 2),
            ];
        }

        return [
            'summary' => [
                'total_students'             => $totalStudents,
                'eligible'                   => $eligibleCount,
                'already_assigned'           => $alreadyAssignedCount,
                'already_billed'             => $alreadyBilledCount,
                'inactive_skipped'           => $inactiveSkippedCount,
                'missing_student_emails'     => $missingStudentEmailCount,
                'missing_guardian_emails'    => $missingGuardianEmailCount,
                'new_assignments'            => $newAssignmentsCount,
                'vouchers_to_generate'       => $vouchersToGenerateCount,
                'estimated_billing_cents'    => $estimatedBillingCents,
                'estimated_billing_amount'   => Money::toDecimal($estimatedBillingCents),
                'estimated_billing_formatted'=> 'PKR ' . number_format($estimatedBillingCents / 100, 2),
                'execution_mode'             => $executionMode,
                'billing_label'              => $billingLabel,
                'structure_name'             => $structure->feeCategory?->name ?? 'Fee Head',
                'class_name'                 => $structure->schoolClass?->name ?? 'Class',
                'academic_year_name'         => $academicYear->name,
            ],
            'students' => $studentRows,
        ];
    }

    /**
     * Authoritatively execute the bulk fee operation within a database transaction.
     *
     * @throws AuthorizationException|InvalidArgumentException
     */
    public static function execute(
        int $schoolId,
        int $feeStructureId,
        int $academicYearId,
        int $classId,
        array $payload = [],
        ?User $user = null
    ): array {
        self::authorizeUser($user);

        $school = School::findOrFail($schoolId);
        $structure = FeeStructure::with(['feeCategory', 'schoolClass'])
            ->where('school_id', $schoolId)
            ->findOrFail($feeStructureId);

        $academicYear = AcademicYear::where('school_id', $schoolId)->findOrFail($academicYearId);

        if ($structure->class_id !== $classId) {
            throw new InvalidArgumentException("Cross-class error: Fee structure belongs to class #{$structure->class_id}, but targeted class is #{$classId}.");
        }

        $executionMode = $payload['execution_mode'] ?? 'assign_and_bill';
        $billingLabel = trim($payload['billing_label'] ?? $structure->feeCategory?->name ?? 'Fee Voucher');
        $dueDateStr = $payload['due_date'] ?? null;
        $dueDate = $dueDateStr ? Carbon::parse($dueDateStr) : Carbon::now()->addDays(15);
        $issueDate = $payload['issue_date'] ?? Carbon::now()->toDateString();
        $notifyStudent = (bool) ($payload['notify_student'] ?? false);
        $notifyGuardian = (bool) ($payload['notify_guardian'] ?? false);

        $students = self::resolveTargetStudents($schoolId, $classId, $payload);

        $assignedBy = $user?->id;
        $structureGrossCents = Money::toCents($structure->amount);
        $effectiveStartsOn = Carbon::parse($academicYear->start_date)->startOfMonth();

        $assignmentsCreated = 0;
        $alreadyAssigned = 0;
        $vouchersGenerated = 0;
        $alreadyBilled = 0;
        $notificationsQueued = 0;
        $missingEmailCount = 0;
        $failures = 0;
        $createdChallans = [];

        DB::beginTransaction();
        try {
            foreach ($students as $student) {
                // 1. Idempotent Assignment
                $existingAssignment = StudentFeeAssignment::where('school_id', $schoolId)
                    ->where('student_id', $student->id)
                    ->where('fee_structure_id', $structure->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->first();

                if ($existingAssignment) {
                    if (! $existingAssignment->is_active) {
                        $existingAssignment->reactivate($assignedBy);
                        $assignmentsCreated++;
                    } else {
                        $alreadyAssigned++;
                    }
                } else {
                    StudentFeeAssignment::create([
                        'school_id'         => $schoolId,
                        'student_id'        => $student->id,
                        'fee_structure_id'  => $structure->id,
                        'academic_year_id'  => $academicYear->id,
                        'is_active'         => true,
                        'starts_on'         => $effectiveStartsOn,
                        'assigned_by'       => $assignedBy,
                        'assigned_at'       => now(),
                        'notes'             => $billingLabel,
                    ]);
                    $assignmentsCreated++;
                }

                // 2. Voucher Generation (if mode is assign_and_bill)
                if ($executionMode === 'assign_and_bill') {
                    $activePeriodKey = "{$student->id}_{$billingLabel}";
                    $activeChargeKey = "{$student->id}_{$structure->id}_{$billingLabel}";

                    // Check idempotency for challan and item
                    $existingChallan = FeeChallan::where('school_id', $schoolId)
                        ->where(function ($q) use ($activePeriodKey, $student, $billingLabel) {
                            $q->where('active_period_key', $activePeriodKey)
                              ->orWhere(function ($sub) use ($student, $billingLabel) {
                                  $sub->where('student_id', $student->id)
                                      ->where('billing_period_key', $billingLabel)
                                      ->where('status', '!=', 'void');
                              });
                        })->first();

                    $existingItem = FeeChallanItem::where('school_id', $schoolId)
                        ->where('active_charge_key', $activeChargeKey)
                        ->first();

                    if ($existingChallan || $existingItem) {
                        $alreadyBilled++;
                        continue;
                    }

                    // Calculate discount / concession
                    $discount = StudentFeeDiscount::where('school_id', $schoolId)
                        ->where('student_id', $student->id)
                        ->where('is_active', true)
                        ->where(function ($q) use ($structure) {
                            $q->whereNull('fee_category_id')
                              ->orWhere('fee_category_id', $structure->fee_category_id);
                        })->first();

                    $discountCents = $discount ? $discount->calculateDiscountCents($structureGrossCents) : 0;
                    $discountCents = min($discountCents, $structureGrossCents);
                    $netCents = max(0, $structureGrossCents - $discountCents);

                    $challanNo = DocumentSequenceService::nextNumber($schoolId, 'challan', 'CHL', Carbon::now()->year);
                    $prevDebtCents = FeeBillingService::computePreviousOutstandingCents($student);

                    $challan = FeeChallan::create([
                        'school_id'                     => $schoolId,
                        'challan_no'                    => $challanNo,
                        'billing_period_key'            => $billingLabel,
                        'active_period_key'             => $activePeriodKey,
                        'student_id'                    => $student->id,
                        'class_id'                      => $student->class_id,
                        'section_id'                    => $student->section_id,
                        'academic_year_id'              => $academicYear->id,
                        'student_name'                  => $student->full_name,
                        'admission_no'                  => $student->admission_no,
                        'class_name'                    => $student->schoolClass?->name,
                        'section_name'                  => $student->section?->name,
                        'academic_year_name'            => $academicYear->name,
                        'issue_date'                    => $issueDate,
                        'due_date'                      => $dueDate->toDateString(),
                        'issued_at'                     => now(),
                        'gross_amount'                  => Money::toDecimal($structureGrossCents),
                        'discount_amount'               => Money::toDecimal($discountCents),
                        'discount_title'                => $discount?->title,
                        'fine_amount'                   => '0.00',
                        'total_payable'                 => Money::toDecimal($netCents),
                        'paid_amount'                   => '0.00',
                        'previous_outstanding_snapshot' => Money::toDecimal($prevDebtCents),
                        'status'                        => 'unpaid',
                        'notes'                         => $billingLabel,
                    ]);

                    FeeChallanItem::create([
                        'school_id'          => $schoolId,
                        'student_id'         => $student->id,
                        'fee_challan_id'     => $challan->id,
                        'fee_structure_id'   => $structure->id,
                        'charge_period_key'  => $billingLabel,
                        'active_charge_key'  => $activeChargeKey,
                        'fee_head_name'      => $structure->feeCategory?->name ?? 'Fee Head',
                        'gross_amount'       => Money::toDecimal($structureGrossCents),
                        'discount_amount'    => Money::toDecimal($discountCents),
                        'net_amount'         => Money::toDecimal($netCents),
                    ]);

                    $vouchersGenerated++;
                    $createdChallans[] = [
                        'challan_no'   => $challan->challan_no,
                        'student_name' => $student->full_name,
                        'admission_no' => $student->admission_no,
                        'amount'       => $challan->total_payable,
                    ];

                    // 3. Dispatch Notifications (Non-blocking: failures do not roll back financial records)
                    $feeHeadName = $structure->feeCategory?->name ?? 'Fee Head';
                    $classSec = ($student->schoolClass?->name ?? '') . ($student->section ? " ({$student->section->name})" : '');

                    if ($notifyStudent) {
                        if (! empty($student->email)) {
                            try {
                                Mail::to($student->email)->send(new FeeVoucherIssuedMail(
                                    schoolName: $school->name,
                                    studentName: $student->full_name,
                                    admissionNo: $student->admission_no,
                                    classSection: $classSec,
                                    academicYear: $academicYear->name,
                                    challanNo: $challan->challan_no,
                                    billingLabel: $billingLabel,
                                    feeHeads: $feeHeadName,
                                    grossAmount: number_format($structureGrossCents / 100, 2),
                                    concessionAmount: number_format($discountCents / 100, 2),
                                    amountPayable: number_format($netCents / 100, 2),
                                    dueDate: $dueDate->format('d M Y'),
                                    recipientRole: 'student',
                                    recipientName: $student->full_name
                                ));
                                $notificationsQueued++;
                            } catch (\Throwable $mailErr) {
                                Log::warning("Bulk fee voucher email to student {$student->id} failed: " . $mailErr->getMessage());
                            }
                        } else {
                            $missingEmailCount++;
                        }
                    }

                    if ($notifyGuardian) {
                        if (! empty($student->guardian?->email)) {
                            try {
                                Mail::to($student->guardian->email)->send(new FeeVoucherIssuedMail(
                                    schoolName: $school->name,
                                    studentName: $student->full_name,
                                    admissionNo: $student->admission_no,
                                    classSection: $classSec,
                                    academicYear: $academicYear->name,
                                    challanNo: $challan->challan_no,
                                    billingLabel: $billingLabel,
                                    feeHeads: $feeHeadName,
                                    grossAmount: number_format($structureGrossCents / 100, 2),
                                    concessionAmount: number_format($discountCents / 100, 2),
                                    amountPayable: number_format($netCents / 100, 2),
                                    dueDate: $dueDate->format('d M Y'),
                                    recipientRole: 'guardian',
                                    recipientName: $student->guardian->name ?? $student->guardian->full_name ?? 'Parent / Guardian'
                                ));
                                $notificationsQueued++;
                            } catch (\Throwable $mailErr) {
                                Log::warning("Bulk fee voucher email to guardian of student {$student->id} failed: " . $mailErr->getMessage());
                            }
                        } else {
                            $missingEmailCount++;
                        }
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Bulk fee execution transaction failed: " . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }

        return [
            'students_targeted'    => $students->count(),
            'assignments_created'  => $assignmentsCreated,
            'already_assigned'     => $alreadyAssigned,
            'vouchers_generated'   => $vouchersGenerated,
            'already_billed'       => $alreadyBilled,
            'notifications_queued' => $notificationsQueued,
            'missing_email_count'  => $missingEmailCount,
            'failures'             => $failures,
            'created_challans'     => $createdChallans,
        ];
    }
}
