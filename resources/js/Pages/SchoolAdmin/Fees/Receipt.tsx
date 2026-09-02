import { Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Printer } from 'lucide-react';
import { useCurrency } from '@/lib/currency';

interface FeePayment {
    id: number; receipt_no: string; amount_due: string; amount_paid: string;
    discount: string; fine: string; payment_date: string | null; month_year: string | null;
    method: string; status: string; note: string | null; created_at: string;
    student?: {
        id: number; first_name: string; last_name: string | null;
        admission_no: string; school_class?: { name: string };
    };
    fee_structure?: {
        id: number; academic_year: string; frequency: string;
        fee_category?: { name: string; type: string };
    };
}

const STATUS_STYLE: Record<string, string> = {
    paid:    'bg-green-100 text-green-700',
    partial: 'bg-amber-100 text-amber-700',
    pending: 'bg-slate-100 text-slate-600',
    overdue: 'bg-red-100 text-red-700',
};
const METHOD_LABELS: Record<string, string> = {
    cash: 'Cash', card: 'Card', online: 'Online', bkash: 'bKash', nagad: 'Nagad', rocket: 'Rocket',
};

export default function FeeReceipt({ payment }: { payment: FeePayment }) {
    const { format: formatMoney } = useCurrency();
    const netDue = Number(payment.amount_due) + Number(payment.fine) - Number(payment.discount);
    const balance = Math.max(0, netDue - Number(payment.amount_paid));

    return (
        <AppLayout title={`Receipt #${payment.receipt_no}`}>
            <div className="max-w-xl mx-auto space-y-6">
                <div className="flex items-center justify-between no-print">
                    <Link href="/school/fees/payments" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                        <ArrowLeft className="w-4 h-4" /> Payments
                    </Link>
                    <Button onClick={() => window.print()} variant="outline" className="inline-flex items-center gap-2">
                        <Printer className="w-4 h-4" /> Print Receipt
                    </Button>
                </div>

                {/* Printable Receipt Card */}
                <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-8 shadow-sm space-y-6 print:shadow-none print:border-none">
                    {/* Header */}
                    <div className="flex justify-between items-start pb-6 border-b border-slate-100 dark:border-slate-800">
                        <div>
                            <h2 className="text-xl font-bold text-slate-900 dark:text-white">Fee Receipt</h2>
                            <p className="font-mono text-xs text-slate-400 mt-1">#{payment.receipt_no}</p>
                        </div>
                        <Badge className={`border-0 text-xs capitalize ${STATUS_STYLE[payment.status] ?? ''}`}>
                            {payment.status}
                        </Badge>
                    </div>

                    {/* Student Info */}
                    <div className="grid grid-cols-2 gap-4 pb-6 border-b border-slate-100 dark:border-slate-800 text-sm">
                        <div>
                            <p className="text-xs text-slate-400 uppercase tracking-wide">Student</p>
                            <p className="font-semibold text-slate-900 dark:text-white mt-0.5">
                                {payment.student?.first_name} {payment.student?.last_name}
                            </p>
                            <p className="text-xs text-slate-500">ID: {payment.student?.admission_no}</p>
                        </div>
                        <div>
                            <p className="text-xs text-slate-400 uppercase tracking-wide">Class</p>
                            <p className="font-semibold text-slate-900 dark:text-white mt-0.5">
                                {payment.student?.school_class?.name ?? '—'}
                            </p>
                        </div>
                    </div>

                    {/* Fee Details */}
                    <div className="space-y-3 pb-6 border-b border-slate-100 dark:border-slate-800">
                        <div className="flex justify-between items-start text-sm">
                            <div>
                                <p className="font-semibold text-slate-900 dark:text-white">{payment.fee_structure?.fee_category?.name ?? 'Tuition Fee'}</p>
                                <p className="text-xs text-slate-400 capitalize">{payment.fee_structure?.frequency} fee</p>
                            </div>
                            <div className="text-right">
                                <p className="font-medium text-slate-700 dark:text-slate-300 mt-0.5">{payment.fee_structure?.academic_year}</p>
                                {payment.month_year && <p className="text-xs text-slate-400">Month: {payment.month_year}</p>}
                            </div>
                        </div>

                        {/* Amount Breakdown */}
                        <div className="space-y-2">
                            <div className="flex justify-between text-sm">
                                <span className="text-slate-500">Amount Due</span>
                                <span className="text-slate-900 dark:text-white">{formatMoney(payment.amount_due)}</span>
                            </div>
                            {Number(payment.fine) > 0 && (
                                <div className="flex justify-between text-sm">
                                    <span className="text-red-500">Fine</span>
                                    <span className="text-red-500">+ {formatMoney(payment.fine)}</span>
                                </div>
                            )}
                            {Number(payment.discount) > 0 && (
                                <div className="flex justify-between text-sm">
                                    <span className="text-green-500">Discount</span>
                                    <span className="text-green-500">- {formatMoney(payment.discount)}</span>
                                </div>
                            )}
                            <div className="border-t border-slate-100 dark:border-slate-800 pt-2 flex justify-between font-semibold">
                                <span className="text-slate-700 dark:text-slate-300">Net Due</span>
                                <span className="text-slate-900 dark:text-white">
                                    {formatMoney(netDue)}
                                </span>
                            </div>
                            <div className="flex justify-between font-bold text-lg">
                                <span className="text-slate-700 dark:text-slate-300">Amount Paid</span>
                                <span className="text-green-600">{formatMoney(payment.amount_paid)}</span>
                            </div>
                            {balance > 0 && (
                                <div className="flex justify-between text-sm font-medium text-red-600">
                                    <span>Balance Due</span>
                                    <span>{formatMoney(balance)}</span>
                                </div>
                            )}
                        </div>

                        {/* Payment Info */}
                        <div className="grid grid-cols-3 gap-3 pb-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                            <div>
                                <p className="text-xs text-slate-400 uppercase tracking-wide">Date</p>
                                <p className="font-medium text-slate-700 dark:text-slate-300 text-sm mt-0.5">
                                    {payment.payment_date ? new Date(payment.payment_date).toLocaleDateString() : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-400 uppercase tracking-wide">Method</p>
                                <p className="font-medium text-slate-700 dark:text-slate-300 text-sm mt-0.5">{METHOD_LABELS[payment.method] ?? payment.method}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-400 uppercase tracking-wide">Status</p>
                                <Badge className={`border-0 text-xs capitalize mt-0.5 ${STATUS_STYLE[payment.status] ?? ''}`}>{payment.status}</Badge>
                            </div>
                        </div>

                        {payment.note && (
                            <div>
                                <p className="text-xs text-slate-400 uppercase tracking-wide">Note</p>
                                <p className="text-sm text-slate-600 dark:text-slate-400 mt-0.5">{payment.note}</p>
                            </div>
                        )}

                        <p className="text-xs text-center text-slate-400 pt-2">Generated on {new Date(payment.created_at).toLocaleString()}</p>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
