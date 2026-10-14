import { usePage } from '@inertiajs/react';

/**
 * Returns a calendar date string in YYYY-MM-DD format for a given date in the specified timezone.
 *
 * If timezone is invalid or omitted, falls back to the browser/local calendar date.
 * Never uses UTC-based toISOString().
 */
export function formatCalendarDate(
    date: Date = new Date(),
    timeZone?: string | null
): string {
    if (timeZone && typeof timeZone === 'string') {
        const trimmed = timeZone.trim();
        if (trimmed) {
            try {
                const parts = new Intl.DateTimeFormat('en-US', {
                    timeZone: trimmed,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                }).formatToParts(date);

                const year = parts.find(p => p.type === 'year')?.value;
                const month = parts.find(p => p.type === 'month')?.value;
                const day = parts.find(p => p.type === 'day')?.value;

                if (year && month && day) {
                    return `${year}-${month}-${day}`;
                }
            } catch {
                // Invalid timezone string (RangeError): safely falls through to browser/local date fallback
            }
        }
    }

    // Browser-local calendar date fallback (strictly local clock components, never UTC toISOString)
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Returns a calendar month string in YYYY-MM format for a given date in the specified timezone.
 */
export function formatCalendarMonth(
    date: Date = new Date(),
    timeZone?: string | null
): string {
    return formatCalendarDate(date, timeZone).slice(0, 7);
}

/**
 * Resolves the active school/locale timezone from Inertia PageProps.
 * Order of precedence:
 *   1. active school timezone (props.active_school?.timezone || props.school?.timezone)
 *   2. shared app/locale timezone (props.locale?.timezone)
 *   3. undefined (which triggers browser-local date fallback)
 */
export function resolveSchoolTimezone(props?: Record<string, any> | null): string | undefined {
    if (!props) return undefined;
    return (
        props.active_school?.timezone ||
        props.school?.timezone ||
        props.locale?.timezone ||
        undefined
    );
}

/**
 * Convenience helper to get today's calendar date in YYYY-MM-DD format respecting the school timezone.
 */
export function getSchoolToday(props?: Record<string, any> | null, referenceDate: Date = new Date()): string {
    return formatCalendarDate(referenceDate, resolveSchoolTimezone(props));
}

/**
 * Convenience helper to get the current calendar month in YYYY-MM format respecting the school timezone.
 */
export function getSchoolMonth(props?: Record<string, any> | null, referenceDate: Date = new Date()): string {
    return formatCalendarMonth(referenceDate, resolveSchoolTimezone(props));
}

/**
 * React hook to access the active tenant's resolved timezone and calendar date formatters.
 */
export function useSchoolDate() {
    const page = usePage();
    const props = page.props as Record<string, any>;
    const timeZone = resolveSchoolTimezone(props);

    return {
        timeZone,
        today: formatCalendarDate(new Date(), timeZone),
        currentMonth: formatCalendarMonth(new Date(), timeZone),
        formatCalendarDate: (date: Date = new Date()) => formatCalendarDate(date, timeZone),
        formatCalendarMonth: (date: Date = new Date()) => formatCalendarMonth(date, timeZone),
    };
}
