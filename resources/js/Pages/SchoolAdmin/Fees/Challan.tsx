import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Printer, AlertTriangle, XCircle, CheckCircle2 } from 'lucide-react';
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
    student_id: number;
    student_name: string;
    admission_no: string;
    class_name: string;
    section_name: string | null;
    academic_year_name: string;
    issue_date: string;
    due_date: string;
    gross_amount: string;
    discount_amount: string;
    discount_title: string | null;
    fine_amount: string;
    adjustment_amount?: string;
    total_payable: string;
    paid_amount: string;
    previous_outstanding_snapshot: string;
    status: string;
    display_status?: string;
    settlement_classification?: string;
    void_reason: string | null;
    items?: ChallanItem[];
    adjustments?: FeeChallanAdjustment[];
}

interface SchoolDetails {
    name: string;
    logo: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
}

interface BankConfig {
    bank_name: string;
    account_no: string;
    branch?: string | null;
    iban?: string | null;
}

interface Props {
    challan: FeeChallan;
    school: SchoolDetails;
    bankConfig?: BankConfig | null;
    canAdjust?: boolean;
}

export default function FeeChallanView({ challan, school, bankConfig }: Props) {
    const { format: formatMoney } = useCurrency();
    const [showVoidModal, setShowVoidModal] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        reason: '',
    });

    function handleVoid(e: React.FormEvent) {
        e.preventDefault();
        post(`/school/fees/challans/${challan.id}/void`, {
            onSuccess: () => setShowVoidModal(false),
        });
    }

    const balance = Math.max(0, Number(challan.total_payable) - Number(challan.paid_amount));

    // Determine copies to render (School Copy, Student Copy, and optional Bank Copy if configured)
    const copies = [
        { title: 'School / Office Copy', badge: 'Accounts' },
        { title: 'Student / Parent Copy', badge: 'Student' },
    ];

    if (bankConfig) {
        copies.push({ title: 'Bank Copy', badge: 'Bank' });
    }

    return (
        <AppLayout title={`Challan #${challan.challan_no}`}>
            <div className="space-y-6">
                {/* Actions Toolbar (Hidden on Print) */}
                <div className="flex items-center justify-between no-print flex-wrap gap-4">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/challans" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Challan List
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Challan #{challan.challan_no}
                        </h1>
                        <Badge className={`border-0 text-xs font-medium ${
                            (challan.display_status ?? challan.status) === 'waived' ? 'bg-purple-100 text-purple-700 border border-purple-200' :
                            (challan.display_status ?? challan.status) === 'paid_adjusted' ? 'bg-teal-100 text-teal-700 border border-teal-200' :
                            challan.status === 'paid' ? 'bg-green-100 text-green-700' :
                            challan.status === 'partial' ? 'bg-amber-100 text-amber-700' :
                            challan.status === 'void' ? 'bg-slate-200 text-slate-600' :
                            'bg-red-100 text-red-700'
                        }`}>
                            {challan.settlement_classification ?? (challan.display_status || challan.status)}
                        </Badge>
                    </div>

                    <div className="flex gap-2">
                        {challan.status === 'unpaid' && (
                            <Button
                                variant="outline"
                                onClick={() => setShowVoidModal(true)}
                                className="text-red-600 border-red-200 hover:bg-red-50 text-xs inline-flex items-center gap-1.5"
                            >
                                <XCircle className="w-4 h-4" /> Void Challan
                            </Button>
                        )}
                        {['unpaid', 'partial'].includes(challan.status) && (
                            <Link href={`/school/fees/payments/collect?student_id=${challan.student_id}&challan_id=${challan.id}`}>
                                <Button className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs inline-flex items-center gap-1.5">
                                    <CheckCircle2 className="w-4 h-4" /> Collect Payment
                                </Button>
                            </Link>
                        )}
                        <Button onClick={() => window.print()} variant="outline" className="text-xs inline-flex items-center gap-1.5">
                            <Printer className="w-4 h-4" /> Print Challan
                        </Button>
                    </div>
                </div>

                {challan.status === 'void' && (
                    <div className="rounded-lg bg-slate-100 border border-slate-300 p-4 text-sm text-slate-700 flex items-center gap-3">
                        <AlertTriangle className="w-5 h-5 text-slate-500 flex-shrink-0" />
                        <div>
                            <p className="font-semibold">This challan was VOIDED and is no longer an active obligation.</p>
                            <p className="text-xs text-slate-500 mt-0.5">Reason: {challan.void_reason ?? 'Administrative correction'}</p>
                        </div>
                    </div>
                )}

                {/* Post-Issue Adjustments Audit Trail (Web view only) */}
                {challan.adjustments && challan.adjustments.length > 0 && (
                    <div className="rounded-xl border border-indigo-200 dark:border-indigo-900 bg-indigo-50/50 dark:bg-indigo-950/20 p-4 space-y-3 no-print">
                        <div className="flex items-center justify-between">
                            <h3 className="font-semibold text-sm text-indigo-950 dark:text-indigo-200 flex items-center gap-2">
                                <CheckCircle2 className="w-4 h-4 text-indigo-600" /> Post-Issue Collection Adjustments ({challan.adjustments.length})
                            </h3>
                            <span className="text-xs font-mono font-bold text-indigo-700 dark:text-indigo-300">
                                Total Waived: -{formatMoney(Number(challan.adjustment_amount || 0))}
                            </span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-indigo-200 dark:border-indigo-800 text-slate-500 text-left">
                                        <th className="py-1">Date</th>
                                        <th className="py-1">Type</th>
                                        <th className="py-1">Reason</th>
                                        <th className="py-1">Notes / Ref</th>
                                        <th className="py-1 text-right">Adjustment</th>
                                        <th className="py-1 text-right">Balance Impact</th>
                                        <th className="py-1">Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-indigo-100 dark:divide-indigo-900/40">
                                    {challan.adjustments.map((adj) => (
                                        <tr key={adj.id}>
                                            <td className="py-1.5 text-slate-600">{new Date(adj.created_at).toLocaleDateString()}</td>
                                            <td className="py-1.5 capitalize font-medium">{adj.adjustment_type}</td>
                                            <td className="py-1.5 font-semibold text-slate-800 dark:text-slate-200">{adj.reason}</td>
                                            <td className="py-1.5 text-slate-500 max-w-xs truncate">{adj.notes || '—'}</td>
                                            <td className="py-1.5 text-right font-bold text-emerald-600">-{formatMoney(Number(adj.adjustment_amount))}</td>
                                            <td className="py-1.5 text-right font-mono text-slate-500">
                                                {formatMoney(Number(adj.previous_balance))} → {formatMoney(Number(adj.new_balance))}
                                            </td>
                                            <td className="py-1.5 text-slate-600">{adj.creator?.name ?? 'Admin'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* A4 Printable Challan Sheets */}
                <div className="print:p-0 print:m-0">
                    <div className={`grid grid-cols-1 ${copies.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2'} gap-6 print:grid-cols-2 print:gap-4 print:text-black`}>
                        {copies.map((copy, copyIdx) => (
                            <div
                                key={copyIdx}
                                className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-6 shadow-sm print:shadow-none print:border-slate-400 print:rounded-none flex flex-col justify-between"
                            >
                                <div className="space-y-4">
                                    {/* Header */}
                                    <div className="text-center pb-3 border-b border-slate-200 dark:border-slate-800">
                                        <h2 className="font-bold text-base uppercase tracking-tight text-slate-900 dark:text-white">
                                            {school.name}
                                        </h2>
                                        {school.address && <p className="text-xs text-slate-500">{school.address}</p>}
                                        <div className="mt-2 flex justify-between items-center text-xs">
                                            <span className="font-semibold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded">
                                                {copy.title}
                                            </span>
                                            <div className="flex items-center gap-1.5">
                                                <span className="font-mono text-slate-500 font-medium">
                                                    #{challan.challan_no}
                                                </span>
                                                <Badge variant="outline" className="text-[10px] py-0 px-1.5 font-medium border-slate-300">
                                                    {challan.settlement_classification ?? (challan.display_status || challan.status)}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Student Info Box */}
                                    <div className="grid grid-cols-2 gap-2 text-xs bg-slate-50 dark:bg-slate-900/50 p-3 rounded-lg border border-slate-100 dark:border-slate-800">
                                        <div>
                                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Student Name</p>
                                            <p className="font-bold text-slate-900 dark:text-white text-sm mt-0.5">{challan.student_name}</p>
                                        </div>
                                        <div>
                                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Admission No</p>
                                            <p className="font-mono font-semibold text-slate-800 dark:text-slate-200 mt-0.5">{challan.admission_no}</p>
                                        </div>
                                        <div>
                                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Class / Section</p>
                                            <p className="font-medium text-slate-800 dark:text-slate-200 mt-0.5">
                                                {challan.class_name} {challan.section_name ? `(${challan.section_name})` : ''}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Academic Year</p>
                                            <p className="font-medium text-slate-800 dark:text-slate-200 mt-0.5">{challan.academic_year_name}</p>
                                        </div>
                                        <div>
                                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Issue Date</p>
                                            <p className="text-slate-700 dark:text-slate-300 mt-0.5">{new Date(challan.issue_date).toLocaleDateString()}</p>
                                        </div>
                                        <div>
                                            <p className="text-slate-400 uppercase tracking-wider text-[10px]">Due Date</p>
                                            <p className="font-semibold text-red-600 mt-0.5">{new Date(challan.due_date).toLocaleDateString()}</p>
                                        </div>
                                    </div>

                                    {/* Fee Line Items Table */}
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-b border-slate-200 dark:border-slate-800 text-slate-500 font-semibold">
                                                <th className="text-left py-1.5">Fee Head</th>
                                                <th className="text-right py-1.5">Gross</th>
                                                <th className="text-right py-1.5">Disc</th>
                                                <th className="text-right py-1.5">Net</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                            {challan.items?.map((it, i) => (
                                                <tr key={i}>
                                                    <td className="py-1.5 text-slate-800 dark:text-slate-200 font-medium">{it.fee_head_name}</td>
                                                    <td className="py-1.5 text-right text-slate-500">{formatMoney(Number(it.gross_amount))}</td>
                                                    <td className="py-1.5 text-right text-emerald-600">{Number(it.discount_amount) > 0 ? `-${formatMoney(Number(it.discount_amount))}` : '—'}</td>
                                                    <td className="py-1.5 text-right font-semibold text-slate-900 dark:text-white">{formatMoney(Number(it.net_amount))}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>

                                    {/* Financial Breakdown */}
                                    <div className="space-y-1.5 border-t border-slate-200 dark:border-slate-800 pt-2 text-xs">
                                        <div className="flex justify-between text-slate-600">
                                            <span>Original Gross Total</span>
                                            <span>{formatMoney(Number(challan.gross_amount))}</span>
                                        </div>
                                        {Number(challan.discount_amount) > 0 && (
                                            <div className="flex justify-between text-emerald-600">
                                                <span>Student Concession ({challan.discount_title ?? 'Discount'})</span>
                                                <span>-{formatMoney(Number(challan.discount_amount))}</span>
                                            </div>
                                        )}
                                        {Number(challan.fine_amount) > 0 && (
                                            <div className="flex justify-between text-red-600">
                                                <span>Fine / Late Surcharge</span>
                                                <span>+{formatMoney(Number(challan.fine_amount))}</span>
                                            </div>
                                        )}
                                        {Number(challan.adjustment_amount || 0) > 0 && (
                                            <div className="flex justify-between text-indigo-600 font-medium">
                                                <span>Post-Issue Adjustments</span>
                                                <span>-{formatMoney(Number(challan.adjustment_amount))}</span>
                                            </div>
                                        )}
                                        <div className="flex justify-between font-bold text-sm border-t border-slate-200 dark:border-slate-800 pt-1.5 text-slate-900 dark:text-white">
                                            <span>Revised Payable Amount</span>
                                            <span>{formatMoney(Number(challan.total_payable))}</span>
                                        </div>
                                        {Number(challan.paid_amount) > 0 && (
                                            <div className="flex justify-between text-emerald-600 font-medium pt-1">
                                                <span>Paid to Date</span>
                                                <span>{formatMoney(Number(challan.paid_amount))}</span>
                                            </div>
                                        )}
                                        <div className="flex justify-between font-bold text-xs pt-1 text-red-600">
                                            <span>Current Balance Due</span>
                                            <span className={balance === 0 ? 'text-emerald-600' : ''}>{balance === 0 ? 'PKR 0.00 (Cleared)' : formatMoney(balance)}</span>
                                        </div>

                                        {/* Informational Memo Block (Option A: NOT capitalized into total_payable) */}
                                        {Number(challan.previous_outstanding_snapshot) > 0 && (
                                            <div className="mt-3 p-2 bg-slate-50 dark:bg-slate-900 rounded border border-dashed border-slate-300 dark:border-slate-700 text-[11px] text-slate-600 space-y-1">
                                                <div className="flex justify-between font-medium">
                                                    <span>Previous Arrears (Separate Memo):</span>
                                                    <span>{formatMoney(Number(challan.previous_outstanding_snapshot))}</span>
                                                </div>
                                                <div className="flex justify-between font-bold border-t border-slate-200 dark:border-slate-800 pt-1 text-slate-900 dark:text-white">
                                                    <span>Total Cumulative Liability:</span>
                                                    <span>{formatMoney(Number(challan.total_payable) + Number(challan.previous_outstanding_snapshot))}</span>
                                                </div>
                                            </div>
                                        )}

                                        {/* Bank Details Block (Rendered ONLY if real bank configured) */}
                                        {bankConfig && (
                                            <div className="mt-3 p-2 bg-slate-50 dark:bg-slate-900 rounded border border-slate-200 dark:border-slate-800 text-[11px] space-y-0.5">
                                                <p className="font-bold text-slate-900 dark:text-white">{bankConfig.bank_name}</p>
                                                <p className="text-slate-600">Account No: <span className="font-mono font-medium">{bankConfig.account_no}</span></p>
                                                {bankConfig.branch && <p className="text-slate-500">Branch: {bankConfig.branch}</p>}
                                                {bankConfig.iban && <p className="text-slate-500">IBAN: <span className="font-mono">{bankConfig.iban}</span></p>}
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Signatures */}
                                <div className="pt-8 grid grid-cols-2 gap-4 text-center text-[10px] text-slate-400">
                                    <div className="border-t border-slate-300 dark:border-slate-700 pt-1">
                                        Cashier / Officer Signature
                                    </div>
                                    <div className="border-t border-slate-300 dark:border-slate-700 pt-1">
                                        Parent / Depositor Signature
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Void Modal */}
                {showVoidModal && (
                    <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
                        <div className="bg-white dark:bg-slate-900 rounded-xl max-w-md w-full p-6 space-y-4 shadow-xl border border-slate-200 dark:border-slate-800">
                            <h3 className="font-bold text-lg text-slate-900 dark:text-white">Void Challan #{challan.challan_no}</h3>
                            <p className="text-xs text-slate-500">
                                Voiding an issued unpaid challan removes its liability from student records and frees the billing period so a corrected challan can be generated.
                            </p>
                            <form onSubmit={handleVoid} className="space-y-4">
                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Mandatory Void Reason <span className="text-red-500">*</span>
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={data.reason}
                                        onChange={e => setData('reason', e.target.value)}
                                        placeholder="e.g. Student transferred class, or wrong fee structure applied."
                                        className="w-full rounded-md border border-slate-300 dark:border-slate-700 p-2 text-xs focus:ring-indigo-500 focus:border-indigo-500"
                                        required
                                    />
                                    {errors.reason && <p className="text-xs text-red-500">{errors.reason}</p>}
                                </div>
                                <div className="flex justify-end gap-2 pt-2">
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setShowVoidModal(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing} variant="destructive" size="sm">
                                        {processing ? 'Voiding...' : 'Confirm Void'}
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
