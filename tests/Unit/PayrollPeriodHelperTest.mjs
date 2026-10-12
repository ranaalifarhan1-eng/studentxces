import test from 'node:test';
import assert from 'node:assert/strict';
import { getLocalMonthYear } from '../../resources/js/lib/payroll-period.ts';

test('getLocalMonthYear resolves school timezone accurately', () => {
    // Normal date in October
    const date = new Date('2026-10-15T12:00:00Z');
    assert.equal(getLocalMonthYear('Asia/Karachi', date), '2026-10');
    assert.equal(getLocalMonthYear('America/New_York', date), '2026-10');
    assert.equal(getLocalMonthYear('Asia/Tokyo', date), '2026-10');
});

test('getLocalMonthYear handles boundary where UTC is previous month but school is already next month', () => {
    // UTC: 2026-10-31 21:30:00
    // Asia/Karachi (UTC+5): 2026-11-01 02:30:00
    // America/New_York (UTC-4): 2026-10-31 17:30:00
    const boundaryUtcDate = new Date('2026-10-31T21:30:00Z');

    assert.equal(
        getLocalMonthYear('Asia/Karachi', boundaryUtcDate),
        '2026-11',
        'Should produce 2026-11 for Karachi where local clock is past midnight Nov 1'
    );

    assert.equal(
        getLocalMonthYear('America/New_York', boundaryUtcDate),
        '2026-10',
        'Should produce 2026-10 for New York where local clock is still Oct 31'
    );
});

test('getLocalMonthYear falls back to browser/local system calendar month when timezone is absent', () => {
    const testDate = new Date(2026, 9, 15); // Local Oct 15 2026
    const expectedLocal = `${testDate.getFullYear()}-${String(testDate.getMonth() + 1).padStart(2, '0')}`;

    assert.equal(getLocalMonthYear(undefined, testDate), expectedLocal);
    assert.equal(getLocalMonthYear(null, testDate), expectedLocal);
    assert.equal(getLocalMonthYear('', testDate), expectedLocal);
    assert.equal(getLocalMonthYear('   ', testDate), expectedLocal);
});

test('getLocalMonthYear falls back to browser/local system calendar month when timezone identifier is invalid', () => {
    const testDate = new Date(2026, 4, 10); // Local May 10 2026
    const expectedLocal = `${testDate.getFullYear()}-${String(testDate.getMonth() + 1).padStart(2, '0')}`;

    assert.equal(getLocalMonthYear('Invalid/Timezone_Name', testDate), expectedLocal);
    assert.equal(getLocalMonthYear('Foo/Bar', testDate), expectedLocal);
    assert.equal(getLocalMonthYear('12345', testDate), expectedLocal);
});
