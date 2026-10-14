import assert from 'node:assert';
import {
    formatCalendarDate,
    formatCalendarMonth,
    resolveSchoolTimezone,
    getSchoolToday,
    getSchoolMonth,
} from '../../resources/js/lib/school-date.ts';

console.log('--- Running School Calendar Date Boundary Tests ---');

// 1. Boundary Tokyo:
// UTC 2026-10-31 20:30
// In Asia/Tokyo (UTC+9), 20:30 + 9h = 05:30 on November 1, 2026.
// Expect default payment_date = 2026-11-01
{
    const utcInstant = new Date('2026-10-31T20:30:00Z');
    const tokyoPageProps = { active_school: { timezone: 'Asia/Tokyo' } };

    const paymentDate = getSchoolToday(tokyoPageProps, utcInstant);
    assert.strictEqual(
        paymentDate,
        '2026-11-01',
        `Tokyo boundary failed: expected 2026-11-01 but got ${paymentDate}`
    );
    console.log('✓ Boundary Tokyo passed: UTC 2026-10-31 20:30 -> Asia/Tokyo = 2026-11-01');
}

// 2. Boundary New York:
// UTC 2026-11-01 02:30
// In America/New_York (EDT is UTC-4), 02:30 - 4h = 22:30 on October 31, 2026.
// Expect default payment_date = 2026-10-31
{
    const utcInstant = new Date('2026-11-01T02:30:00Z');
    const nyPageProps = { active_school: { timezone: 'America/New_York' } };

    const paymentDate = getSchoolToday(nyPageProps, utcInstant);
    assert.strictEqual(
        paymentDate,
        '2026-10-31',
        `New York boundary failed: expected 2026-10-31 but got ${paymentDate}`
    );
    console.log('✓ Boundary New York passed: UTC 2026-11-01 02:30 -> America/New_York = 2026-10-31');
}

// 3. Timezone resolution order of precedence:
// active school timezone -> shared app/locale timezone -> browser-local fallback
{
    // Precedence: active_school takes priority over locale
    const mixedProps = {
        active_school: { timezone: 'Asia/Tokyo' },
        locale: { timezone: 'America/New_York' },
    };
    assert.strictEqual(resolveSchoolTimezone(mixedProps), 'Asia/Tokyo');

    // Shared locale timezone used when active_school timezone is absent
    const localeOnlyProps = {
        active_school: { name: 'Test School' },
        locale: { timezone: 'Europe/London' },
    };
    assert.strictEqual(resolveSchoolTimezone(localeOnlyProps), 'Europe/London');

    console.log('✓ Timezone precedence passed: active school -> locale -> fallback');
}

// 4. Missing timezone -> browser-local date fallback
{
    const refDate = new Date('2026-10-31T20:30:00Z');
    const localExpected = `${refDate.getFullYear()}-${String(refDate.getMonth() + 1).padStart(2, '0')}-${String(refDate.getDate()).padStart(2, '0')}`;

    const emptyPropsResult = getSchoolToday({}, refDate);
    assert.strictEqual(
        emptyPropsResult,
        localExpected,
        `Missing timezone failed: expected ${localExpected} but got ${emptyPropsResult}`
    );

    const nullPropsResult = getSchoolToday(null, refDate);
    assert.strictEqual(nullPropsResult, localExpected);

    console.log('✓ Missing timezone passed: gracefully falls back to browser-local date');
}

// 5. Invalid timezone -> browser-local date fallback without crashing
{
    const refDate = new Date('2026-10-31T20:30:00Z');
    const localExpected = `${refDate.getFullYear()}-${String(refDate.getMonth() + 1).padStart(2, '0')}-${String(refDate.getDate()).padStart(2, '0')}`;

    const invalidProps = { active_school: { timezone: 'Invalid/NonExistent_Zone' } };
    const invalidResult = getSchoolToday(invalidProps, refDate);

    assert.strictEqual(
        invalidResult,
        localExpected,
        `Invalid timezone failed: expected ${localExpected} but got ${invalidResult}`
    );
    console.log('✓ Invalid timezone passed: gracefully falls back to browser-local date');
}

// 6. formatCalendarMonth boundary
{
    const tokyoInstant = new Date('2026-10-31T20:30:00Z');
    assert.strictEqual(getSchoolMonth({ active_school: { timezone: 'Asia/Tokyo' } }, tokyoInstant), '2026-11');

    const nyInstant = new Date('2026-11-01T02:30:00Z');
    assert.strictEqual(getSchoolMonth({ active_school: { timezone: 'America/New_York' } }, nyInstant), '2026-10');
    console.log('✓ formatCalendarMonth boundaries passed');
}

console.log('=== All 6 Boundary Tests PASSED Successfully ===');
