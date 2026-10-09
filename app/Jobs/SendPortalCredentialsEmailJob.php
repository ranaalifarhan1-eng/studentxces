<?php

namespace App\Jobs;

use App\Mail\ParentPortalCredentialsMail;
use App\Mail\StudentPortalCredentialsMail;
use App\Models\School;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPortalCredentialsEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     * Note: Payload contains strictly non-secret identifiers (userId, targetType).
     * Recipient contact email and credentials are resolved fresh at runtime by the worker.
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $targetType, // 'student' | 'guardian'
    ) {}

    /**
     * Execute the job.
     * Loads target user and resolves business contact email at runtime, validates active
     * temporary credential, decrypts strictly in memory, and dispatches email synchronously.
     */
    public function handle(): void
    {
        $user = User::with(['school', 'student', 'guardian.students'])->find($this->userId);
        if (! $user) {
            return;
        }

        // Must have an active, unexpired temporary password
        if (empty($user->temporary_password_encrypted)) {
            return;
        }

        if ($user->temporary_password_expires_at && $user->temporary_password_expires_at->isPast()) {
            return;
        }

        // Resolve recipient contact email at runtime
        $toEmail = null;
        if ($this->targetType === 'student') {
            $toEmail = (! empty($user->student?->email) && filter_var($user->student->email, FILTER_VALIDATE_EMAIL))
                ? $user->student->email
                : $user->email;
        } else {
            $toEmail = (! empty($user->guardian?->email) && filter_var($user->guardian->email, FILTER_VALIDATE_EMAIL))
                ? $user->guardian->email
                : $user->email;
        }

        if (empty($toEmail) || ! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $plainPassword = null;
        try {
            $plainPassword = Crypt::decryptString($user->temporary_password_encrypted);
        } catch (\Throwable) {
            Log::error("Failed to decrypt temporary credential in worker for user ID {$this->userId}");
            return;
        }

        $school = $user->school ?? School::find($user->school_id);
        $schoolName = $school?->name ?? config('app.name');

        try {
            if ($this->targetType === 'student') {
                $student = $user->student;
                Mail::to($toEmail)->send(new StudentPortalCredentialsMail(
                    schoolName: $schoolName,
                    studentName: $student?->full_name ?? $user->name,
                    username: $user->username,
                    tempPassword: $plainPassword,
                    loginUrl: url('/login'),
                    admissionNo: $student?->admission_no,
                ));
            } else {
                $guardian = $user->guardian;
                $studentNames = $guardian ? $guardian->students()->pluck('first_name')->implode(', ') : null;
                Mail::to($toEmail)->send(new ParentPortalCredentialsMail(
                    schoolName: $schoolName,
                    guardianName: $user->name,
                    username: $user->username,
                    tempPassword: $plainPassword,
                    loginUrl: url('/login'),
                    studentNames: $studentNames,
                ));
            }
        } catch (\Throwable) {
            Log::warning("Failed to deliver portal credential email for user ID {$this->userId} ({$this->targetType})");
        } finally {
            unset($plainPassword);
        }
    }
}
