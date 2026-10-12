/**
 * Resolves the target month_year string ("YYYY-MM") based on the provided timezone,
 * falling back safely to the environment's local calendar date if timezone is absent or invalid.
 *
 * Resolution hierarchy:
 * 1. configured school timezone
 * 2. shared locale/application timezone if explicitly available
 * 3. browser/local system calendar month fallback (date.getFullYear(), date.getMonth() + 1)
 */
export function getLocalMonthYear(timezone?: string | null, date: Date = new Date()): string {
    const fallback = (): string => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        return `${year}-${month}`;
    };

    const trimmedTz = timezone ? timezone.trim() : '';
    if (!trimmedTz) {
        return fallback();
    }

    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: trimmedTz,
            year: 'numeric',
            month: '2-digit',
        }).formatToParts(date);

        const year = parts.find((p) => p.type === 'year')?.value;
        const month = parts.find((p) => p.type === 'month')?.value;

        if (year && month) {
            return `${year}-${month}`;
        }
    } catch {
        // Safe fallback if timezone identifier is unrecognized or invalid (e.g. RangeError)
    }

    return fallback();
}
