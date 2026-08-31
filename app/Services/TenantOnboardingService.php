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
use Illuminate\Validation\ValidationException;

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
    public function validate(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name'               => ['required', 'string', 'max:255'],
            'slug'               => ['required', 'string', 'max:100', 'unique:schools,slug'],
            'email'              => ['nullable', 'email', 'max:255'],
            'phone'              => ['nullable', 'string', 'max:30'],
            'address'            => ['nullable', 'string', 'max:500'],
            'city'               => ['nullable', 'string', 'max:100'],
            'state'              => ['nullable', 'string', 'max:100'],
            'country'            => ['required', 'string', 'size:2'],
            'timezone'           => ['required', 'string', 'max:50'],
            'currency'           => ['required', 'string', 'max:10'],
            'language'           => ['required', 'string', 'max:10'],
            'status'             => ['required', 'in:active,inactive,suspended'],
            'admin_name'         => ['required', 'string', 'max:255'],
            'admin_email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password'     => ['required', 'string', 'min:8'],
            'academic_year_name' => ['required', 'string', 'max:50'],
            'academic_start'     => ['required', 'date_format:Y-m-d'],
            'academic_end'       => ['required', 'date_format:Y-m-d', 'after:academic_start'],
        ], [
            'academic_end.after' => 'The academic year end date must be after the start date.',
            'slug.unique'        => "A school with slug '{$data['slug']}' already exists.",
            'admin_email.unique' => "A user with email '{$data['admin_email']}' already exists.",
        ]);
    }

    /**
     * Orchestrates transactional foundational onboarding.
     */
    public function onboard(array $rawInput, bool $execute = false): array
    {
        $data = $this->prepareData($rawInput);
        $validator = $this->validate($data);

        if ($validator->fails()) {
            return [
                'status'  => 'VALIDATION_FAILED',
                'message' => 'Onboarding validation failed.',
                'errors'  => $validator->errors()->all(),
                'payload' => $this->sanitizePayload($data),
            ];
        }

        // Dry-run / Simulation mode: Zero database mutations
        if (! $execute) {
            return [
                'status'  => 'DRY_RUN',
                'message' => 'Dry run validation succeeded. No database mutations performed.',
                'payload' => $this->sanitizePayload($data),
                'next_step' => 'Execute with --execute to commit foundation.',
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

            $manifest['status'] = 'COMMITTED';
            $this->writeManifest($manifestFilename, $manifest);

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
            $manifest['error'] = $e->getMessage();
            $this->writeManifest($manifestFilename, $manifest);

            throw $e;
        }
    }

    /**
     * Sanitizes payload for logging/output, masking secrets.
     */
    public function sanitizePayload(array $data): array
    {
        $sanitized = $data;
        $sanitized['admin_password_supplied'] = ! empty($data['admin_password']) ? 'YES' : 'NO';
        unset($sanitized['admin_password']);
        return $sanitized;
    }

    /**
     * Safely writes manifest to private storage.
     */
    protected function writeManifest(string $filename, array $content): void
    {
        try {
            $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');
            $disk->put($filename, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // Silently ignore filesystem error in test environments where private disk is mocked
        }
    }
}
