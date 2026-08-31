<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class TenantOnboardingService
{
    public const DEFAULT_COUNTRY = 'PK';
    public const DEFAULT_TIMEZONE = 'Asia/Karachi';
    public const DEFAULT_CURRENCY = 'PKR';
    public const DEFAULT_LANGUAGE = 'en';
    public const DEFAULT_STATUS = 'active';
    public const DEFAULT_CITY = 'Lahore';
    public const DEFAULT_STATE = 'Punjab';

    /**
     * Prepare data with canonical defaults.
     */
    public function prepareData(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $slug = ! empty($data['slug']) ? Str::slug($data['slug']) : ($name ? Str::slug($name) : '');

        return [
            'name'                => $name,
            'slug'                => $slug,
            'email'               => ! empty($data['email']) ? trim($data['email']) : null,
            'phone'               => ! empty($data['phone']) ? trim($data['phone']) : null,
            'address'             => ! empty($data['address']) ? trim($data['address']) : null,
            'city'                => ! empty($data['city']) ? trim($data['city']) : self::DEFAULT_CITY,
            'state'               => ! empty($data['state']) ? trim($data['state']) : self::DEFAULT_STATE,
            'country'             => ! empty($data['country']) ? strtoupper(trim($data['country'])) : self::DEFAULT_COUNTRY,
            'timezone'            => ! empty($data['timezone']) ? trim($data['timezone']) : self::DEFAULT_TIMEZONE,
            'currency'            => ! empty($data['currency']) ? strtoupper(trim($data['currency'])) : self::DEFAULT_CURRENCY,
            'language'            => ! empty($data['language']) ? strtolower(trim($data['language'])) : self::DEFAULT_LANGUAGE,
            'status'              => ! empty($data['status']) ? strtolower(trim($data['status'])) : self::DEFAULT_STATUS,
            'admin_name'          => trim($data['admin_name'] ?? ''),
            'admin_email'         => strtolower(trim($data['admin_email'] ?? '')),
            'admin_password'      => $data['admin_password'] ?? '',
            'academic_year_name'  => trim($data['academic_year_name'] ?? ''),
            'academic_start'      => ! empty($data['academic_start']) ? trim($data['academic_start']) : null,
            'academic_end'        => ! empty($data['academic_end']) ? trim($data['academic_end']) : null,
        ];
    }

    /**
     * Validate all onboarding inputs before executing database mutations.
     */
    public function validate(array $data, bool $requirePassword = false): \Illuminate\Contracts\Validation\Validator
    {
        $rules = [
            'name'               => ['required', 'string', 'max:255'],
            'slug'               => ['required', 'string', 'max:100', 'unique:schools,slug'],
            'email'              => ['nullable', 'email', 'max:255'],
            'phone'              => ['nullable', 'string', 'max:30'],
            'address'            => ['nullable', 'string', 'max:500'],
            'city'               => ['nullable', 'string', 'max:100'],
            'state'              => ['nullable', 'string', 'max:100'],
            'country'            => ['required', 'string', 'size:2'],
            'timezone'           => ['required', 'string', 'max:50', 'timezone'],
            'currency'           => ['required', 'string', 'max:10'],
            'language'           => ['required', 'string', 'max:10'],
            'status'             => ['required', 'in:active,inactive,suspended'],
            'admin_name'         => ['required', 'string', 'max:255'],
            'admin_email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'academic_year_name' => ['required', 'string', 'max:50'],
            'academic_start'     => ['required', 'date_format:Y-m-d'],
            'academic_end'       => ['required', 'date_format:Y-m-d', 'after:academic_start'],
        ];

        if ($requirePassword) {
            $rules['admin_password'] = ['required', 'string', 'min:8'];
        }

        $validator = Validator::make($data, $rules, [
            'academic_end.after' => 'The academic year end date must be after the start date.',
            'slug.unique'        => "A school with slug '{$data['slug']}' already exists.",
            'admin_email.unique' => "A user with email '{$data['admin_email']}' already exists.",
            'timezone.timezone'  => "The timezone '{$data['timezone']}' is not a valid IANA timezone identifier.",
        ]);

        // Custom validation check: Duplicate School Name/Identity
        $validator->after(function ($v) use ($data) {
            if (! empty($data['name']) && School::where('name', $data['name'])->exists()) {
                $v->errors()->add('name', "A school with the name '{$data['name']}' already exists.");
            }

            // Prerequisite role check: school-admin must exist
            if (! Role::where('name', 'school-admin')->where('guard_name', 'web')->exists()) {
                $v->errors()->add('admin_role', "Required platform role 'school-admin' does not exist.");
            }
        });

        return $validator;
    }

    /**
     * Orchestrates transactional foundational onboarding.
     */
    public function onboard(array $rawInput, bool $execute = false): array
    {
        $data = $this->prepareData($rawInput);
        $validator = $this->validate($data, $execute);

        // Fail BEFORE creating any manifest or starting any transaction
        if ($validator->fails()) {
            return [
                'status'  => 'VALIDATION_FAILED',
                'message' => 'Onboarding validation failed.',
                'errors'  => $validator->errors()->all(),
                'payload' => $this->sanitizePayload($data),
            ];
        }

        // Dry-run / Simulation mode: Zero database mutations and zero manifests
        if (! $execute) {
            return [
                'status'    => 'DRY_RUN',
                'message'   => 'Dry run validation succeeded. No database mutations performed.',
                'payload'   => $this->sanitizePayload($data),
                'next_step' => 'Execute with --execute to commit foundation (temporary password will be prompted securely).',
            ];
        }

        $executionId = 'onboard_' . date('Ymd_His') . '_' . Str::random(6);
        $manifestFilename = "onboarding_manifests/{$executionId}_{$data['slug']}.json";

        $manifest = [
            'execution_id'       => $executionId,
            'timestamp'          => now()->toIso8601String(),
            'status'             => 'PENDING',
            'school_name'        => $data['name'],
            'school_slug'        => $data['slug'],
            'school_email'       => $data['email'],
            'country'            => $data['country'],
            'timezone'           => $data['timezone'],
            'currency'           => $data['currency'],
            'admin_name'         => $data['admin_name'],
            'admin_email'        => $data['admin_email'],
            'academic_year_name' => $data['academic_year_name'],
            'academic_start'     => $data['academic_start'],
            'academic_end'       => $data['academic_end'],
            'school_id'          => null,
            'admin_user_id'      => null,
            'academic_year_id'   => null,
        ];

        // Write PENDING manifest only after all validations (including password) have passed
        $this->writeManifest($manifestFilename, $manifest);

        try {
            $result = DB::transaction(function () use ($data, &$manifest) {
                // 1. Create School
                $school = School::create([
                    'name'     => $data['name'],
                    'slug'     => $data['slug'],
                    'email'    => $data['email'],
                    'phone'    => $data['phone'],
                    'address'  => $data['address'],
                    'city'     => $data['city'],
                    'state'    => $data['state'],
                    'country'  => $data['country'],
                    'timezone' => $data['timezone'],
                    'currency' => $data['currency'],
                    'language' => $data['language'],
                    'status'   => $data['status'],
                ]);

                // 2. Create Initial School Admin User
                $adminUser = User::create([
                    'name'      => $data['admin_name'],
                    'email'     => $data['admin_email'],
                    'password'  => Hash::make($data['admin_password']),
                    'school_id' => $school->id,
                    'status'    => 'active',
                ]);
                $adminUser->assignRole('school-admin');

                // 3. Create Initial Academic Year
                $academicYear = AcademicYear::create([
                    'school_id'  => $school->id,
                    'name'       => $data['academic_year_name'],
                    'start_date' => $data['academic_start'],
                    'end_date'   => $data['academic_end'],
                    'is_current' => true,
                ]);

                if (function_exists('activity')) {
                    activity()
                        ->performedOn($school)
                        ->log("Tenant foundation created: School [{$school->name}] (#{$school->id}), Admin [{$adminUser->email}], Academic Year [{$academicYear->name}]");
                }

                $manifest['school_id'] = $school->id;
                $manifest['admin_user_id'] = $adminUser->id;
                $manifest['academic_year_id'] = $academicYear->id;

                return [
                    'school'        => $school,
                    'admin_user'    => $adminUser,
                    'academic_year' => $academicYear,
                ];
            });

            // Post-commit manifest finalization
            $manifest['status'] = 'COMMITTED';
            $writeOk = $this->writeManifest($manifestFilename, $manifest);

            if (! $writeOk) {
                return [
                    'status'           => 'DB_COMMITTED_JOURNAL_INCOMPLETE',
                    'message'          => "Tenant foundation committed in DB, but manifest journal update was incomplete.",
                    'school_id'        => $result['school']->id,
                    'school_name'      => $result['school']->name,
                    'school_slug'      => $result['school']->slug,
                    'admin_user_id'    => $result['admin_user']->id,
                    'admin_email'      => $result['admin_user']->email,
                    'academic_year_id' => $result['academic_year']->id,
                    'manifest_file'    => $manifestFilename,
                    'next_step'        => "Run: php artisan entitlement:provision-legacy --school={$result['school']->id} --dry-run",
                ];
            }

            return [
                'status'           => 'FOUNDATION_CREATED',
                'message'          => "Tenant foundation created successfully for \"{$result['school']->name}\".",
                'school_id'        => $result['school']->id,
                'school_name'      => $result['school']->name,
                'school_slug'      => $result['school']->slug,
                'admin_user_id'    => $result['admin_user']->id,
                'admin_email'      => $result['admin_user']->email,
                'academic_year_id' => $result['academic_year']->id,
                'manifest_file'    => $manifestFilename,
                'next_step'        => "Run: php artisan entitlement:provision-legacy --school={$result['school']->id} --dry-run",
            ];
        } catch (\Throwable $e) {
            $manifest['status'] = 'FAILED_ROLLED_BACK';
            $manifest['error'] = 'Database transaction failed and rolled back.';
            $this->writeManifest($manifestFilename, $manifest);

            throw $e;
        }
    }

    /**
     * Reconciles an incomplete or PENDING onboarding manifest against database state.
     * Restricts file resolution strictly to canonical storage/app/private/onboarding_manifests/ directory.
     */
    public function reconcileManifest(string $target): array
    {
        // Path traversal and absolute path protection
        if (str_contains($target, '..') || str_contains($target, ':') || str_starts_with($target, '/') || str_starts_with($target, '\\')) {
            return [
                'status'  => 'PATH_TRAVERSAL_BLOCKED',
                'message' => 'Reconciliation path traversal or absolute paths are strictly blocked.',
            ];
        }

        $filename = basename($target);
        if (! str_ends_with($filename, '.json') || ($target !== $filename && $target !== "onboarding_manifests/{$filename}")) {
            return [
                'status'  => 'INVALID_MANIFEST_PATH',
                'message' => 'Reconciliation is restricted exclusively to .json files inside the canonical onboarding_manifests/ directory.',
            ];
        }

        $canonicalPath = "onboarding_manifests/{$filename}";
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');

        if (! $disk->exists($canonicalPath)) {
            return [
                'status'  => 'MANIFEST_NOT_FOUND',
                'message' => "Manifest file '{$canonicalPath}' does not exist.",
            ];
        }

        $raw = $disk->get($canonicalPath);
        $manifest = json_decode($raw, true);

        if (! is_array($manifest)) {
            return [
                'status'  => 'INVALID_MANIFEST_FORMAT',
                'message' => 'Manifest JSON is malformed or unreadable.',
            ];
        }

        if (($manifest['status'] ?? '') === 'COMMITTED') {
            return [
                'status'        => 'ALREADY_COMMITTED',
                'message'       => 'Manifest is already fully committed.',
                'school_id'     => $manifest['school_id'] ?? null,
                'admin_user_id' => $manifest['admin_user_id'] ?? null,
            ];
        }

        if (($manifest['status'] ?? '') !== 'PENDING') {
            return [
                'status'  => 'NON_RECONCILABLE_STATUS',
                'message' => "Manifest status '{$manifest['status']}' cannot be automatically reconciled.",
            ];
        }

        // Look for existing School, User, and AcademicYear
        $schoolSlug = $manifest['school_slug'] ?? null;
        $schoolName = $manifest['school_name'] ?? null;
        $adminEmail = $manifest['admin_email'] ?? null;
        $academicName = $manifest['academic_year_name'] ?? null;
        $academicStart = $manifest['academic_start'] ?? null;
        $academicEnd = $manifest['academic_end'] ?? null;

        if (! $schoolSlug || ! $adminEmail) {
            return [
                'status'  => 'INSUFFICIENT_METADATA',
                'message' => 'Manifest lacks required school_slug or admin_email for reconciliation.',
            ];
        }

        $school = School::where('slug', $schoolSlug)->whereNull('deleted_at')->first();
        $adminUser = User::where('email', $adminEmail)->whereNull('deleted_at')->first();

        // If none exist, safe to retry onboarding
        if (! $school && ! $adminUser) {
            return [
                'status'  => 'RETRYABLE_NOT_FOUND',
                'message' => 'Zero database records exist matching this manifest. Safe to retry onboarding.',
            ];
        }

        // Strict multi-model coherence validation:
        // 1. School must exist and match name
        // 2. Admin User must exist, belong to that exact school, and have 'school-admin' role
        // 3. Academic Year must exist, belong to that exact school, and match dates
        if ($school && $adminUser && (int) $adminUser->school_id === (int) $school->id && $adminUser->hasRole('school-admin')) {
            if ($schoolName && $school->name !== $schoolName) {
                return [
                    'status'  => 'AMBIGUOUS_MANUAL_REVIEW_REQUIRED',
                    'message' => 'School name mismatch with manifest metadata. Manual operator review required.',
                ];
            }

            $academicYear = AcademicYear::where('school_id', $school->id)
                ->where('name', $academicName)
                ->whereNull('deleted_at')
                ->first();

            if ($academicYear && (int) $academicYear->school_id === (int) $school->id) {
                if ($academicStart && $academicYear->start_date->format('Y-m-d') !== $academicStart) {
                    return [
                        'status'  => 'AMBIGUOUS_MANUAL_REVIEW_REQUIRED',
                        'message' => 'Academic year start date mismatch. Manual operator review required.',
                    ];
                }

                if ($academicEnd && $academicYear->end_date->format('Y-m-d') !== $academicEnd) {
                    return [
                        'status'  => 'AMBIGUOUS_MANUAL_REVIEW_REQUIRED',
                        'message' => 'Academic year end date mismatch. Manual operator review required.',
                    ];
                }

                // Promote to COMMITTED
                $manifest['status'] = 'COMMITTED';
                $manifest['school_id'] = $school->id;
                $manifest['admin_user_id'] = $adminUser->id;
                $manifest['academic_year_id'] = $academicYear->id;
                $manifest['reconciled_at'] = now()->toIso8601String();

                $this->writeManifest($canonicalPath, $manifest);

                return [
                    'status'           => 'RECONCILED',
                    'message'          => 'Pending manifest successfully reconciled to committed database foundation.',
                    'school_id'        => $school->id,
                    'admin_user_id'    => $adminUser->id,
                    'academic_year_id' => $academicYear->id,
                ];
            }
        }

        return [
            'status'  => 'AMBIGUOUS_MANUAL_REVIEW_REQUIRED',
            'message' => 'Partial or conflicting records found in database. Manual operator review required.',
        ];
    }

    /**
     * Sanitizes payload for logging/output, masking secrets.
     */
    public function sanitizePayload(array $data): array
    {
        $sanitized = $data;
        $sanitized['admin_password_supplied'] = ! empty($data['admin_password']) ? 'YES' : 'WILL BE REQUESTED SECURELY DURING EXECUTION';
        unset($sanitized['admin_password']);
        return $sanitized;
    }

    /**
     * Safely writes manifest to private storage. Returns boolean success.
     */
    public function writeManifest(string $filename, array $content): bool
    {
        try {
            $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');
            return (bool) $disk->put($filename, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            return false;
        }
    }
}
