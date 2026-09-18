<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DocumentSequenceService
{
    /**
     * Atomically generates the next sequence number for a school and document type.
     * Concurrency-safe against race conditions, including first-row initialization.
     */
    public static function nextNumber(int $schoolId, string $documentType, string $prefix, ?int $year = null): string
    {
        $year = $year ?? (int) date('Y');
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $allocated = DB::transaction(function () use ($schoolId, $documentType, $year) {
                // Attempt to lock existing sequence row
                $seq = DB::table('school_document_sequences')
                    ->where('school_id', $schoolId)
                    ->where('document_type', $documentType)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                if ($seq) {
                    $next = $seq->current_number + 1;
                    DB::table('school_document_sequences')
                        ->where('id', $seq->id)
                        ->update([
                            'current_number' => $next,
                            'updated_at'     => now(),
                        ]);
                    return $next;
                }

                // If missing, attempt atomic insert with current_number = 1
                try {
                    DB::table('school_document_sequences')->insert([
                        'school_id'      => $schoolId,
                        'document_type'  => $documentType,
                        'year'           => $year,
                        'current_number' => 1,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                    return 1;
                } catch (\Illuminate\Database\QueryException $e) {
                    // Retry ONLY when the database error is specifically the expected unique-key/duplicate-entry race
                    if (self::isDuplicateKeyViolation($e)) {
                        return null; // Triggers retry on next attempt under lock
                    }

                    // All other database exceptions must be rethrown immediately
                    throw $e;
                }
            });

            if ($allocated !== null) {
                return sprintf('%s-%d-%05d', $prefix, $year, $allocated);
            }
        }

        throw new RuntimeException("Failed to allocate document sequence number for type [{$documentType}] after {$maxAttempts} attempts.");
    }

    public static function isDuplicateKeyViolation(\Illuminate\Database\QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message  = strtolower($e->getMessage());

        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        if (str_contains($message, 'unique constraint failed') || str_contains($message, 'duplicate entry')) {
            return true;
        }

        return false;
    }

    public static function nextChallanNumber(int $schoolId, ?int $year = null): string
    {
        return self::nextNumber($schoolId, 'fee_challan', 'CHL', $year);
    }

    public static function nextReceiptNumber(int $schoolId, ?int $year = null): string
    {
        return self::nextNumber($schoolId, 'fee_receipt', 'RCP', $year);
    }
}
