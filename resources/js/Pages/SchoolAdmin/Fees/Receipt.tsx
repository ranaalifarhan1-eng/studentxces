import { Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Printer, FileText, CheckCircle2 } from 'lucide-react';
import { useCurrency } from '@/lib/currency';

interface ChallanItem {
    id: number;
    fee_head_name: string;
    gross_amount: string;
    discount_amount: string;
    net_amount: string;
}

interface FeeChallanAdjustment {
    id: number;
    adjustment_type: 'fixed' | 'percentage';
    value: string;
    adjustment_amount: string;
    previous_balance: string;
    new_balance: string;
    reason: string;
    notes?: string | null;
    created_at: string;
    creator?: { id: number; name: string };
}

interface FeeChallan {
    id: number;
    challan_no: string;
    billing_period_key: string;
    gross_amount: string;
    discount_amount: string;
    discount_title: string | null;
    fine_amount: string;
    adjustment_amount?: string;
    total_payable: string;
    paid_amount: string;
    status: string;
    items?: ChallanItem[];
    adjustments?: FeeChallanAdjustment[];
}

interface FeePayment {
    id: number;
    receipt_no: string;
    amount_due: string;
    amount_paid: string;
    discount: string;
    fine: string;
    balance_snapshot: string | null;
    payment_date: string | null;
    month_year: string | null;
    method: string;
    reference: string | null;
    status: string;
    note: string | null;
    created_at: string;
    fee_challan_id: number | null;
    fee_challan?: FeeChallan | null;
    adjustments?: FeeChallanAdjustment[];
    previous_paid?: string;
    remaining_balance?: string;
    billing_period?: string;
    academic_year?: string;
    due_date?: string | null;
    student?: {
        id: number;
        first_name: string;
        last_name: string | null;
        admission_no: string;
        school_class?: { name: string };
        section?: { name: string };
    };
    fee_structure?: {
        id: number;
        academic_year: string;
        frequency: string;
        fee_category?: { name: string; type: string };
    };
    collector?: {
        id: number;
        name: string;
    };
}

const STATUS_STYLE: Record<string, string> = {
    paid:    'bg-green-100 text-green-700',
    partial: 'bg-amber-100 text-amber-700',
    pending: 'bg-slate-100 text-slate-600',
    overdue: 'bg-red-100 text-red-700',
};

const METHOD_LABELS: Record<string, string> = {
    cash: 'Cash',
    bank_transfer: 'Bank Transfer',
    cheque: 'Cheque',
    card: 'Card',
    online: 'Online',
    bkash: 'bKash',
    nagad: 'Nagad',
    rocket: 'Rocket',
};

export default function FeeReceipt({ payment }: { payment: FeePayment }) {
    const { format: formatMoney } = useCurrency();

    // Remaining balance is determined from authoritative remaining_balance / balance_snapshot or fallback calculation
    const balance = payment.remaining_balance !== undefined
        ? Number(payment.remaining_balance)
        : (payment.balance_snapshot !== null
            ? Number(payment.balance_snapshot)
            : Math.max(0, Number(payment.amount_due) + Number(payment.fine) - Number(payment.discount) - Number(payment.amount_paid)));

    const prevPaid = Number(payment.previous_paid || 0);

    return (
        <AppLayout title={`Receipt #${payment.receipt_no}`}>
            <div className="max-w-xl mx-auto space-y-6">
                {/* Navigation and Print Bar */}
                <div className="flex items-center justify-between no-print">
                    <Link
                        href="/school/fees/payments"
                        className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white"
                    >
                        <ArrowLeft className="w-4 h-4" /> Fee Payments
                    </Link>
                    <div className="flex gap-2">
                        {payment.fee_challan_id && (
                            <Link href={`/school/fees/challans/${payment.fee_challan_id}`}>
                                <Button variant="outline" size="sm" className="inline-flex items-center gap-1.5 text-xs">
                                    <FileText className="w-4 h-4" /> View Challan
                                </Button>
                            </Link>
                        )}
                        <Button onClick={() => window.print()} size="sm" className="inline-flex items-center gap-1.5 text-xs bg-indigo-600 hover:bg-indigo-700 text-white">
                            <Printer className="w-4 h-4" /> Print Receipt
                        </Button>
                    </div>
                </div>

                {/* Printable Receipt Card */}
                <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-8 shadow-sm space-y-6 print:shadow-none print:border-slate-300 print:rounded-none">
                    {/* Header */}
                    <div className="flex justify-between items-start pb-4 border-b border-slate-200 dark:border-slate-800">
                        <div>
                            <div className="flex items-center gap-2">
                                <CheckCircle2 className="w-5 h-5 text-green-600 no-print" />
                                <h2 className="text-xl font-bold text-slate-900 dark:text-white">Official Fee Receipt</h2>
                            </div>
                            <p className="font-mono text-sm text-indigo-600 font-semibold mt-1">#{payment.receipt_no}</p>
                        </div>
                        <Badge className={`border-0 text-xs capitalize ${STATUS_STYLE[payment.status] ?? 'bg-green-100 text-green-700'}`}>
                            Received
                        </Badge>
                    </div>

                    {/* Student & Billing Info */}
                    <div className="grid grid-cols-2 gap-4 pb-4 border-b border-slate-100 dark:border-slate-800 text-xs bg-slate-50 dark:bg-slate-900/50 p-4 rounded-lg">
                        <div>
                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Student</p>
                            <p className="font-bold text-slate-900 dark:text-white text-sm mt-0.5">
                                {payment.student?.first_name} {payment.student?.last_name}
                            </p>
                            <p className="font-mono text-slate-500 mt-0.5">Admission: {payment.student?.admission_no}</p>
                            <p className="text-slate-600 dark:text-slate-400 mt-0.5">
                                Class: {payment.student?.school_class?.name ?? '—'} {payment.student?.section ? `(${payment.student.section.name})` : ''}
                            </p>
                        </div>
                        <div>
                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Billing Details</p>
                            {payment.academic_year && (
                                <p className="text-slate-700 dark:text-slate-300 font-medium mt-0.5">
                                    Academic Year: <span className="font-semibold">{payment.academic_year}</span>
                                </p>
                            )}
                            <p className="text-slate-700 dark:text-slate-300 font-medium mt-0.5">
                                Period: <span className="font-semibold">{payment.billing_period || payment.month_year || '—'}</span>
                            </p>
                            {payment.due_date && (
                                <p className="text-slate-500 mt-0.5">
                                    Due Date: {new Date(payment.due_date).toLocaleDateString()}
                                </p>
                            )}
                            {payment.fee_challan && (
                                <p className="text-indigo-600 font-mono mt-0.5 font-semibold">Challan: #{payment.fee_challan.challan_no}</p>
                            )}
                        </div>
                    </div>

                    {/* Challan Items or Fee Head Details */}
                    {payment.fee_challan?.items && payment.fee_challan.items.length > 0 ? (
                        <div className="space-y-2 pb-4 border-b border-slate-100 dark:border-slate-800">
                            <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">Challan Fee Items Breakdown</p>
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-200 dark:border-slate-800 text-slate-400 text-left">
                                        <th className="py-1">Item</th>
                                        <th className="py-1 text-right">Gross</th>
                                        <th className="py-1 text-right">Concession</th>
                                        <th className="py-1 text-right">Net</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {payment.fee_challan.items.map((it, idx) => (
                                        <tr key={idx}>
                                            <td className="py-1 text-slate-800 dark:text-slate-200">{it.fee_head_name}</td>
                                            <td className="py-1 text-right text-slate-500">{formatMoney(Number(it.gross_amount))}</td>
                                            <td className="py-1 text-right text-emerald-600">{Number(it.discount_amount) > 0 ? `-${formatMoney(Number(it.discount_amount))}` : '—'}</td>
                                            <td className="py-1 text-right font-medium">{formatMoney(Number(it.net_amount))}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="space-y-1 pb-4 border-b border-slate-100 dark:border-slate-800 text-xs">
                            <div className="flex justify-between items-start">
                                <div>
                                    <p className="font-semibold text-slate-900 dark:text-white">{payment.fee_structure?.fee_category?.name ?? 'School Fee'}</p>
                                    <p className="text-slate-400 capitalize">{payment.fee_structure?.frequency ?? 'Tuition'} fee</p>
                                </div>
                                <div className="text-right">
                                    <p className="font-medium text-slate-700 dark:text-slate-300">{payment.fee_structure?.academic_year}</p>
                                    {payment.month_year && <p className="text-slate-400">Period: {payment.month_year}</p>}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Financial Summary */}
                    <div className="space-y-2 pb-4 border-b border-slate-100 dark:border-slate-800 text-xs">
                        <div className="flex justify-between text-slate-600">
                            <span>Gross Fees</span>
                            <span className="font-medium">
                                {payment.fee_challan ? formatMoney(Number(payment.fee_challan.gross_amount)) : formatMoney(Number(payment.amount_due))}
                            </span>
                        </div>
                        {(Number(payment.fee_challan?.discount_amount ?? payment.discount) > 0) && (
                            <div className="flex justify-between text-emerald-600">
                                <span>Concession (Student Level)</span>
                                <span>-{formatMoney(Number(payment.fee_challan?.discount_amount ?? payment.discount))}</span>
                            </div>
                        )}
                        {payment.adjustments && payment.adjustments.length > 0 ? (
                            payment.adjustments.map((adj) => (
                                <div key={adj.id} className="flex justify-between text-indigo-600">
                                    <span>One-Time Adjustment: <span className="italic font-medium">{adj.reason}</span></span>
                                    <span>-{formatMoney(Number(adj.adjustment_amount))}</span>
                                </div>
                            ))
                        ) : (Number(payment.fee_challan?.adjustment_amount || 0) > 0 ? (
                            <div className="flex justify-between text-indigo-600">
                                <span>One-Time Adjustment</span>
                                <span>-{formatMoney(Number(payment.fee_challan?.adjustment_amount))}</span>
                            </div>
                        ) : null)}
                        {Number(payment.fine) > 0 && (
                            <div className="flex justify-between text-red-600">
                                <span>Late Fine / Surcharge</span>
                                <span>+{formatMoney(Number(payment.fine))}</span>
                            </div>
                        )}
                        {payment.fee_challan && (
                            <div className="flex justify-between font-semibold border-t border-slate-200 dark:border-slate-800 pt-1 text-slate-900 dark:text-white">
                                <span>Net Payable</span>
                                <span>{formatMoney(Number(payment.fee_challan.total_payable))}</span>
                            </div>
                        )}
                        {prevPaid > 0 && (
                            <div className="flex justify-between text-slate-600">
                                <span>Previously Paid On Challan</span>
                                <span className="font-medium text-emerald-600">{formatMoney(prevPaid)}</span>
                            </div>
                        )}
                        <div className="flex justify-between font-bold text-sm border-t border-slate-200 dark:border-slate-800 pt-2 text-emerald-700 dark:text-emerald-400">
                            <span>Amount Paid (This Receipt)</span>
                            <span>{formatMoney(Number(payment.amount_paid))}</span>
                        </div>
                        <div className="flex justify-between font-semibold text-xs pt-1 text-slate-700 dark:text-slate-300">
                            <span>Balance Remaining</span>
                            <span className={balance > 0 ? 'text-red-600' : 'text-emerald-600'}>
                                {balance > 0 ? formatMoney(balance) : 'Fully Cleared'}
                            </span>
                        </div>
                    </div>

                    {/* Transaction Metadata */}
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs text-slate-600 dark:text-slate-400">
                        <div>
                            <p className="text-slate-400 text-[10px] uppercase">Payment Date</p>
                            <p className="font-medium text-slate-800 dark:text-slate-200 mt-0.5">
                                {payment.payment_date ? new Date(payment.payment_date).toLocaleDateString() : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-slate-400 text-[10px] uppercase">Payment Method</p>
                            <p className="font-medium text-slate-800 dark:text-slate-200 mt-0.5">
                                {METHOD_LABELS[payment.method] ?? payment.method}
                            </p>
                        </div>
                        <div>
                            <p className="text-slate-400 text-[10px] uppercase">Reference / Transaction ID</p>
                            <p className="font-mono text-slate-800 dark:text-slate-200 mt-0.5">
                                {payment.reference ?? 'Cash / None'}
                            </p>
                        </div>
                        {payment.collector && (
                            <div>
                                <p className="text-slate-400 text-[10px] uppercase">Collected By</p>
                                <p className="font-medium text-slate-800 dark:text-slate-200 mt-0.5">
                                    {payment.collector.name}
                                </p>
                            </div>
                        )}
                        {payment.note && (
                            <div className="col-span-2">
                                <p className="text-slate-400 text-[10px] uppercase">Note</p>
                                <p className="text-slate-700 dark:text-slate-300 mt-0.5">{payment.note}</p>
                            </div>
                        )}
                    </div>

                    {/* Signatures */}
                    <div className="pt-8 grid grid-cols-2 gap-8 text-center text-[10px] text-slate-400">
                        <div className="border-t border-slate-300 dark:border-slate-700 pt-1">
                            Authorized Cashier Signature
                        </div>
                        <div className="border-t border-slate-300 dark:border-slate-700 pt-1">
                            School Seal / Stamp
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
