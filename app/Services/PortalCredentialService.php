<?php

namespace App\Services;

use App\Jobs\SendPortalCredentialsEmailJob;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class PortalCredentialService
{
    /**
     * Resolves a deterministic, tenant-safe uppercase short code for the school.
     */
    public static function resolveSchoolCode(School $school): string
    {
        // 1. Check explicit setting
        if (! empty($school->settings['school_code'])) {
            return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $school->settings['school_code']));
        }
        if (! empty($school->settings['code'])) {
            return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $school->settings['code']));
        }

        // 2. Extract acronym from school name words (e.g. "Lahore Cambridge School" -> "LCS")
        $words = preg_split('/[\s\-_]+/', trim($school->name));
        $acronym = '';
        foreach ($words as $w) {
            if (! empty($w)) {
                $acronym .= strtoupper($w[0]);
            }
        }

        if (strlen($acronym) >= 2) {
            // Verify no other school has identical acronym; if collision, append school ID
            $conflict = School::where('id', '!=', $school->id)
                ->where('name', 'like', $acronym . '%')
                ->exists();
            return $conflict ? $acronym . $school->id : $acronym;
        }

        // 3. Fallback: sanitize slug (e.g. "demo-school" -> "DEMO")
        $cleanSlug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $school->slug ?? ''));
        return substr($cleanSlug, 0, 4) ?: 'SCH' . $school->id;
    }

    /**
     * Generates a globally unique username for a student: {SCHOOL_CODE}-{ADMISSION_NO}
     */
    public static function generateStudentUsername(Student $student, School $school): string
    {
        $code = self::resolveSchoolCode($school);
        $cleanAdmission = strtoupper(trim(preg_replace('/[^A-Za-z0-9\-]/', '', $student->admission_no ?? '')));

        if (empty($cleanAdmission)) {
            $cleanAdmission = 'ADM-' . str_pad($student->id ?: 1, 4, '0', STR_PAD_LEFT);
        }

        $base = str_starts_with($cleanAdmission, $code . '-')
            ? $cleanAdmission
            : "{$code}-{$cleanAdmission}";

        $candidate = $base;
        $counter = 1;
        while (User::where('username', $candidate)->where('id', '!=', $student->user_id ?? 0)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    /**
     * Generates a globally unique username for a guardian: {SCHOOL_CODE}-PAR-{GUARDIAN_CODE}
     */
    public static function generateGuardianUsername(Guardian $guardian, School $school): string
    {
        $code = self::resolveSchoolCode($school);
        $guardianCode = $guardian->guardian_code;

        if (empty($guardianCode)) {
            $count = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->count() + 1;
            $guardianCode = 'PAR-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            $guardian->update(['guardian_code' => $guardianCode]);
        }

        $cleanCode = strtoupper(trim(preg_replace('/[^A-Za-z0-9\-]/', '', $guardianCode)));

        $base = str_starts_with($cleanCode, $code . '-')
            ? $cleanCode
            : "{$code}-{$cleanCode}";

        $candidate = $base;
        $counter = 1;
        while (User::where('username', $candidate)->where('id', '!=', $guardian->user_id ?? 0)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    /**
     * Provisions a student portal user account.
     */
    public static function provisionStudentPortalAccount(
        Student $student,
        School $school,
        ?string $plainPassword = null,
        ?User $actor = null
    ): array {
        // If student already has a user account
        if ($student->user_id && $student->user) {
            return [
                'user'          => $student->user,
                'username'      => $student->user->username,
                'email'         => $student->user->email,
                'temp_password' => null,
                'is_existing'   => true,
                'email_queued'  => false,
            ];
        }

        $username = self::generateStudentUsername($student, $school);
        $tempPassword = $plainPassword ?: Str::password(10, true, true, false);

        $email = ! empty($student->email) ? strtolower(trim($student->email)) : null;
        if ($email && User::where('email', $email)->exists()) {
            // If email is already in use by another user in this school, leave email null and use username
            $email = null;
        }

        $user = User::create([
            'school_id'                     => $school->id,
            'name'                          => $student->full_name,
            'username'                      => $username,
            'email'                         => $email,
            'password'                      => Hash::make($tempPassword),
            'temporary_password_encrypted'  => Crypt::encryptString($tempPassword),
            'temporary_password_expires_at' => now()->addDays(14),
            'must_change_password'          => true,
            'status'                        => 'active',
        ]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $user->assignRole('student');
        $student->update(['user_id' => $user->id]);

        activity()
            ->causedBy($actor ?? auth()->user())
            ->performedOn($student)
            ->withProperties([
                'school_id' => $school->id,
                'target_id' => $user->id,
                'username'  => $user->username,
                'role'      => 'student',
                'ip'        => request()?->ip(),
            ])
            ->log('Portal credentials created for student');

        $emailQueued = false;
        $hasValidEmail = (! empty($student->email) && filter_var($student->email, FILTER_VALIDATE_EMAIL))
            || (! empty($user->email) && filter_var($user->email, FILTER_VALIDATE_EMAIL));

        if ($hasValidEmail) {
            try {
                SendPortalCredentialsEmailJob::dispatch($user->id, 'student');
                $emailQueued = true;
            } catch (\Throwable $e) {
                Log::warning("Failed to queue student portal credential email: " . $e->getMessage());
            }
        }

        return [
            'user'          => $user,
            'username'      => $user->username,
            'email'         => $user->email,
            'temp_password' => $tempPassword,
            'is_existing'   => false,
            'email_queued'  => $emailQueued,
        ];
    }

    /**
     * Provisions a guardian portal user account if one does not already exist.
     */
    public static function provisionGuardianPortalAccount(
        Guardian $guardian,
        School $school,
        ?string $plainPassword = null,
        ?User $actor = null
    ): array {
        // If guardian already has a user account, DO NOT reset password or recreate
        if ($guardian->user_id && $guardian->user) {
            return [
                'user'          => $guardian->user,
                'username'      => $guardian->user->username,
                'email'         => $guardian->user->email,
                'temp_password' => null,
                'is_existing'   => true,
                'email_queued'  => false,
            ];
        }

        $username = self::generateGuardianUsername($guardian, $school);
        $tempPassword = $plainPassword ?: Str::password(10, true, true, false);

        $email = ! empty($guardian->email) ? strtolower(trim($guardian->email)) : null;
        if ($email && User::where('email', $email)->exists()) {
            $email = null;
        }

        $user = User::create([
            'school_id'                     => $school->id,
            'name'                          => $guardian->name,
            'username'                      => $username,
            'email'                         => $email,
            'password'                      => Hash::make($tempPassword),
            'temporary_password_encrypted'  => Crypt::encryptString($tempPassword),
            'temporary_password_expires_at' => now()->addDays(14),
            'must_change_password'          => true,
            'status'                        => 'active',
        ]);

        Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'web']);
        $user->assignRole('parent');
        $guardian->update(['user_id' => $user->id]);

        activity()
            ->causedBy($actor ?? auth()->user())
            ->performedOn($guardian)
            ->withProperties([
                'school_id' => $school->id,
                'target_id' => $user->id,
                'username'  => $user->username,
                'role'      => 'parent',
                'ip'        => request()?->ip(),
            ])
            ->log('Portal credentials created for guardian');

        $emailQueued = false;
        $hasValidEmail = (! empty($guardian->email) && filter_var($guardian->email, FILTER_VALIDATE_EMAIL))
            || (! empty($user->email) && filter_var($user->email, FILTER_VALIDATE_EMAIL));

        if ($hasValidEmail) {
            try {
                SendPortalCredentialsEmailJob::dispatch($user->id, 'guardian');
                $emailQueued = true;
            } catch (\Throwable $e) {
                Log::warning("Failed to queue guardian portal credential email: " . $e->getMessage());
            }
        }

        return [
            'user'          => $user,
            'username'      => $user->username,
            'email'         => $user->email,
            'temp_password' => $tempPassword,
            'is_existing'   => false,
            'email_queued'  => $emailQueued,
        ];
    }

    /**
     * Decrypts and reveals the temporary password for an authorized administrator.
     */
    public static function revealTemporaryPassword(User $user, ?User $actor = null): ?string
    {
        $actor = $actor ?? auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.view') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to view temporary credentials.');
        }

        if (empty($user->temporary_password_encrypted)) {
            return null;
        }

        if ($user->temporary_password_expires_at && $user->temporary_password_expires_at->isPast()) {
            return null;
        }

        try {
            $plain = Crypt::decryptString($user->temporary_password_encrypted);

            activity()
                ->causedBy($actor)
                ->withProperties([
                    'school_id' => $user->school_id,
                    'target_id' => $user->id,
                    'username'  => $user->username,
                    'ip'        => request()?->ip(),
                ])
                ->log('Portal credentials revealed');

            return $plain;
        } catch (\Throwable $e) {
            Log::error("Failed to decrypt temporary password for user {$user->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resets a user's portal password with a new temporary credential.
     */
    public static function resetPortalPassword(User $user, ?User $actor = null, string $targetType = 'student'): array
    {
        $actor = $actor ?? auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.reset') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to reset portal credentials.');
        }

        $newTempPassword = Str::password(10, true, true, false);

        $user->update([
            'password'                      => Hash::make($newTempPassword),
            'temporary_password_encrypted'  => Crypt::encryptString($newTempPassword),
            'temporary_password_expires_at' => now()->addDays(14),
            'must_change_password'          => true,
            'status'                        => 'active',
        ]);

        activity()
            ->causedBy($actor)
            ->withProperties([
                'school_id'   => $user->school_id,
                'target_id'   => $user->id,
                'username'    => $user->username,
                'target_type' => $targetType,
                'ip'          => request()?->ip(),
            ])
            ->log('Portal password reset');

        $emailQueued = false;
        $targetEmail = null;
        if ($targetType === 'student') {
            $student = $user->student;
            $targetEmail = (! empty($student?->email) && filter_var($student->email, FILTER_VALIDATE_EMAIL))
                ? $student->email
                : $user->email;
        } else {
            $guardian = $user->guardian;
            $targetEmail = (! empty($guardian?->email) && filter_var($guardian->email, FILTER_VALIDATE_EMAIL))
                ? $guardian->email
                : $user->email;
        }

        if (! empty($targetEmail) && ! str_ends_with($targetEmail, '.local') && filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                SendPortalCredentialsEmailJob::dispatch($user->id, $targetType);
                $emailQueued = true;
            } catch (\Throwable $e) {
                Log::warning("Failed to queue reset credential email: " . $e->getMessage());
            }
        }

        return [
            'username'      => $user->username,
            'email'         => $user->email,
            'temp_password' => $newTempPassword,
            'email_queued'  => $emailQueued,
        ];
    }

    /**
     * Resends the temporary credential email if email exists and credential is valid.
     */
    public static function resendCredentialEmail(User $user, ?User $actor = null, string $targetType = 'student'): bool
    {
        $actor = $actor ?? auth()->user();
        if ($actor && ! $actor->hasRole('super-admin') && ! $actor->can('students.portal_credentials.view') && ! $actor->can('students.edit')) {
            abort(403, 'You do not have permission to resend portal credentials.');
        }

        if (! $user->hasActiveTemporaryPassword()) {
            return false;
        }

        $targetEmail = null;
        if ($targetType === 'student') {
            $student = $user->student;
            $targetEmail = (! empty($student?->email) && filter_var($student->email, FILTER_VALIDATE_EMAIL))
                ? $student->email
                : $user->email;
        } else {
            $guardian = $user->guardian;
            $targetEmail = (! empty($guardian?->email) && filter_var($guardian->email, FILTER_VALIDATE_EMAIL))
                ? $guardian->email
                : $user->email;
        }

        if (empty($targetEmail) || ! filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            SendPortalCredentialsEmailJob::dispatch($user->id, $targetType);

            activity()
                ->causedBy($actor)
                ->withProperties([
                    'school_id' => $user->school_id,
                    'target_id' => $user->id,
                    'username'  => $user->username,
                    'ip'        => request()?->ip(),
                ])
                ->log('Portal credential email resent');

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to resend credential email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Completes user password change, securely purging temporary credentials.
     */
    public static function completePasswordChange(User $user, string $newPassword): void
    {
        $user->update([
            'password'                      => Hash::make($newPassword),
            'temporary_password_encrypted'  => null,
            'temporary_password_expires_at' => null,
            'must_change_password'          => false,
        ]);

        activity()
            ->causedBy($user)
            ->withProperties([
                'school_id' => $user->school_id,
                'target_id' => $user->id,
                'ip'        => request()?->ip(),
            ])
            ->log('User completed first password change');
    }
}
