<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceTestDatabaseSafetyGuard();
    }

    /**
     * Fail-closed safeguard: Prevents PHPUnit from ever executing against
     * the production / demo database (genius_school) or any unapproved database.
     */
    protected function enforceTestDatabaseSafetyGuard(): void
    {
        if (app()->environment() !== 'testing') {
            throw new RuntimeException(
                'Unsafe test environment detected. App environment is [' . app()->environment() . '], expected [testing].'
            );
        }

        $activeDatabase = DB::connection()->getDatabaseName();

        if ($activeDatabase !== 'genius_school_test') {
            throw new RuntimeException(
                "Unsafe test database detected. PHPUnit is not allowed to run against database [{$activeDatabase}]. " .
                "Approved test database is [genius_school_test]."
            );
        }
    }
}
