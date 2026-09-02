import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/Types';

/**
 * Format a monetary amount using the tenant's active currency code.
 * E.g. formatCurrency(12500, 'PKR') -> "PKR 12,500"
 *      formatCurrency(0, 'PKR') -> "PKR 0"
 */
export function formatCurrency(
    amount: number | string | null | undefined,
    currencyCode: string = 'PKR',
    fractionDigits: number = 0
): string {
    const num = Number(amount ?? 0);
    const validNum = isNaN(num) ? 0 : num;
    const formatted = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    }).format(validNum);

    const code = (currencyCode || 'PKR').toUpperCase().trim();
    return `${code} ${formatted}`;
}

/**
 * React hook to access the active tenant's currency code and formatter function.
 */
export function useCurrency() {
    const { props } = usePage<PageProps>();
    const currency = props.locale?.currency_code || props.active_school?.currency || props.branding?.currency || 'PKR';

    return {
        currency,
        format: (amount: number | string | null | undefined, fractionDigits: number = 0) =>
            formatCurrency(amount, currency, fractionDigits),
    };
}
