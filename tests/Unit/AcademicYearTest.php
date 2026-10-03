<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    public function test_academic_year_alias_matching_with_dates(): void
    {
        $ay = new AcademicYear([
            'name'       => 'Academic Year 2026-27',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-06-30',
        ]);

        $aliases = $ay->getYearAliases();

        // Exact name and standard derived spans
        $this->assertContains('Academic Year 2026-27', $aliases);
        $this->assertContains('2026-2027', $aliases);
        $this->assertContains('2026-27', $aliases);
        $this->assertContains('2026/2027', $aliases);
        $this->assertContains('2026/27', $aliases);

        // Required match cases
        $this->assertTrue($ay->matchesYearString('Academic Year 2026-27'));
        $this->assertTrue($ay->matchesYearString('2026-2027'));
        $this->assertTrue($ay->matchesYearString('2026-27'));
        $this->assertTrue($ay->matchesYearString('2026/2027'));
        $this->assertTrue($ay->matchesYearString('2026/27'));

        // Case insensitivity & trimming
        $this->assertTrue($ay->matchesYearString('  2026-2027  '));
        $this->assertTrue($ay->matchesYearString('academic year 2026-27'));

        // Ambiguous single year and non-matching years must NOT match
        $this->assertFalse($ay->matchesYearString('2026'));
        $this->assertFalse($ay->matchesYearString('2027'));
        $this->assertFalse($ay->matchesYearString('2025-2026'));
        $this->assertFalse($ay->matchesYearString('2025-26'));
        $this->assertFalse($ay->matchesYearString(null));
        $this->assertFalse($ay->matchesYearString(''));
    }

    public function test_academic_year_alias_matching_from_name_regex_when_dates_missing(): void
    {
        $ay = new AcademicYear([
            'name'       => 'Session 2028-2029',
            'start_date' => null,
            'end_date'   => null,
        ]);

        $this->assertTrue($ay->matchesYearString('Session 2028-2029'));
        $this->assertTrue($ay->matchesYearString('2028-2029'));
        $this->assertTrue($ay->matchesYearString('2028-29'));
        $this->assertTrue($ay->matchesYearString('2028/2029'));
        $this->assertTrue($ay->matchesYearString('2028/29'));

        $this->assertFalse($ay->matchesYearString('2028'));
        $this->assertFalse($ay->matchesYearString('2026-2027'));
    }

    public function test_single_year_is_never_included_for_multi_year_sessions(): void
    {
        $ay = new AcademicYear([
            'name'       => 'Academic Year 2026-27',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-06-30',
        ]);

        $aliases = $ay->getYearAliases();

        $this->assertNotContains('2026', $aliases);
        $this->assertNotContains('2027', $aliases);
    }
}
