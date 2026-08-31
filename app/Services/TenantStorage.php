<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantStorage
{
    /**
     * Tenant Student Document directory path.
     * schools/{school_id}/students/{student_id}/documents
     */
    public static function studentDocumentPath(int|string $schoolId, int|string $studentId, ?string $filename = null): string
    {
        $dir = "schools/{$schoolId}/students/{$studentId}/documents";
        return $filename ? "{$dir}/{$filename}" : $dir;
    }

    /**
     * Tenant Staff Document directory path.
     * schools/{school_id}/staff/{staff_id}/documents
     */
    public static function staffDocumentPath(int|string $schoolId, int|string $staffId, ?string $filename = null): string
    {
        $dir = "schools/{$schoolId}/staff/{$staffId}/documents";
        return $filename ? "{$dir}/{$filename}" : $dir;
    }

    /**
     * Tenant Student Photo directory path.
     * schools/{school_id}/students/{student_id}/photos
     */
    public static function studentPhotoPath(int|string $schoolId, int|string $studentId, ?string $filename = null): string
    {
        $dir = "schools/{$schoolId}/students/{$studentId}/photos";
        return $filename ? "{$dir}/{$filename}" : $dir;
    }

    /**
     * Tenant Staff Photo directory path.
     * schools/{school_id}/staff/{staff_id}/photos
     */
    public static function staffPhotoPath(int|string $schoolId, int|string $staffId, ?string $filename = null): string
    {
        $dir = "schools/{$schoolId}/staff/{$staffId}/photos";
        return $filename ? "{$dir}/{$filename}" : $dir;
    }

    /**
     * Tenant School Branding directory path (Logo, Favicon, etc.).
     * schools/{school_id}/branding
     */
    public static function schoolBrandingPath(int|string $schoolId, ?string $filename = null): string
    {
        $dir = "schools/{$schoolId}/branding";
        return $filename ? "{$dir}/{$filename}" : $dir;
    }

    /**
     * Platform-level Branding directory path.
     * platform
     */
    public static function platformBrandingPath(?string $filename = null): string
    {
        $dir = "platform";
        return $filename ? "{$dir}/{$filename}" : $dir;
    }

    /**
     * Resolve private disk.
     */
    public static function privateDisk(): Filesystem
    {
        return Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');
    }

    /**
     * Resolve public disk.
     */
    public static function publicDisk(): Filesystem
    {
        return Storage::disk('public');
    }

    /**
     * Securely download or stream a private tenant document with multi-tenant authorization
     * and backward compatibility for legacy file paths.
     */
    public static function downloadPrivateDocument(Model $document, ?int $authSchoolId = null, bool $isSuperAdmin = false): StreamedResponse
    {
        // 1. Enforce tenant authorization
        if (! $isSuperAdmin && (int) $document->school_id !== (int) $authSchoolId) {
            abort(403, 'Unauthorized access to document.');
        }

        $disk = self::privateDisk();

        // 2. Resolve file path with backward-compatibility fallback
        $path = $document->file_path;

        if (! $disk->exists($path)) {
            // Check legacy alternative path if applicable
            $legacyPath = null;
            if ($document instanceof \App\Models\StudentDocument) {
                $legacyPath = "students/{$document->student_id}/documents/" . basename($path);
            } elseif ($document instanceof \App\Models\StaffDocument) {
                $legacyPath = "staff/{$document->staff_id}/documents/" . basename($path);
            }

            if ($legacyPath && $disk->exists($legacyPath)) {
                $path = $legacyPath;
            } else {
                abort(404, 'Document file not found on disk.');
            }
        }

        $filename = $document->title ? ($document->title . '.' . pathinfo($path, PATHINFO_EXTENSION)) : basename($path);

        return $disk->download($path, $filename);
    }
}
