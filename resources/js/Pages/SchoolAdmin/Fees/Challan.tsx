import React, { Fragment, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Printer, AlertTriangle, XCircle, CheckCircle2 } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import AlliedFeeChallanSlip from './Components/AlliedFeeChallanSlip';

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
    billing_period_label?: string;
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
    guardian_name?: string | null;
    guardian_cnic?: string | null;
    student?: any;
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

    // Final required physical format: A4 Landscape, 3 copies side-by-side
    const copies = [
        { title: 'SCHOOL / OFFICE COPY', badge: 'OFFICE' },
        { title: 'BANK COPY', badge: 'BANK' },
        { title: 'STUDENT / PARENT COPY', badge: 'STUDENT' },
    ];

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
                    <div className="rounded-lg bg-slate-100 border border-slate-300 p-4 text-sm text-slate-700 flex items-center gap-3 no-print">
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

                {/* A4 Printable Challan Sheet (Allied School Style - 3 Copies Landscape) */}
                <div id="challan-print-sheet" className="challan-print-page max-w-[287mm] mx-auto bg-transparent print:bg-white p-0">
                    <div className="flex flex-col md:flex-row print:flex-row items-stretch justify-between w-full gap-4 print:gap-0">
                        {copies.map((copy, copyIdx) => (
                            <Fragment key={copyIdx}>
                                {copyIdx > 0 && (
                                    <div className="hidden print:flex flex-col items-center justify-between w-[2.5mm] select-none mx-0.5">
                                        <div className="w-[1px] h-full border-r border-dashed border-neutral-400 relative flex items-center justify-center">
                                            <span className="bg-white px-0.5 text-[8px] text-neutral-400 rotate-90 leading-none select-none">
                                                ✂
                                            </span>
                                        </div>
                                    </div>
                                )}
                                <div className="flex-1 min-w-0">
                                    <AlliedFeeChallanSlip
                                        challan={challan}
                                        school={school}
                                        bankConfig={bankConfig}
                                        copyTitle={copy.title}
                                        copyBadge={copy.badge}
                                    />
                                </div>
                            </Fragment>
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
