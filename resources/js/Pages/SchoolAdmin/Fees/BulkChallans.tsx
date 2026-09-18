import { Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Printer } from 'lucide-react';
import { useCurrency } from '@/lib/currency';

interface ChallanItem {
    id: number;
    fee_head_name: string;
    gross_amount: string;
    discount_amount: string;
    net_amount: string;
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
    total_payable: string;
    paid_amount: string;
    previous_outstanding_snapshot: string;
    status: 'draft' | 'unpaid' | 'partial' | 'paid' | 'void';
    items?: ChallanItem[];
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
    challans: FeeChallan[];
    school: SchoolDetails;
    bankConfig?: BankConfig | null;
}

export default function BulkChallansView({ challans, school, bankConfig }: Props) {
    const { format: formatMoney } = useCurrency();

    const copies = [
        { title: 'School / Office Copy', badge: 'Accounts' },
        { title: 'Student / Parent Copy', badge: 'Student' },
    ];

    if (bankConfig) {
        copies.push({ title: 'Bank Copy', badge: 'Bank' });
    }

    return (
        <AppLayout title={`Bulk Challans Print (${challans.length})`}>
            <div className="space-y-6">
                {/* Print Toolbar (Hidden on Print) */}
                <div className="flex items-center justify-between no-print flex-wrap gap-4 bg-white dark:bg-slate-900 p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/challans" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Challans List
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                            Bulk Print Challans ({challans.length} challans)
                        </h1>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button onClick={() => window.print()} className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs inline-flex items-center gap-1.5">
                            <Printer className="w-4 h-4" /> Print All ({challans.length})
                        </Button>
                    </div>
                </div>

                {challans.length === 0 ? (
                    <div className="p-12 text-center text-slate-500 bg-white dark:bg-slate-950 rounded-xl border border-slate-200 dark:border-slate-800">
                        No outstanding or partial challans found for the selected class and period.
                    </div>
                ) : (
                    <div className="space-y-8 print:space-y-0">
                        {challans.map((challan, index) => {
                            const balance = Math.max(0, Number(challan.total_payable) - Number(challan.paid_amount));
                            return (
                                <div
                                    key={challan.id}
                                    className="print:page-break print:m-0 print:p-0 print:h-screen print:flex print:flex-col print:justify-around"
                                    style={{ pageBreakAfter: index === challans.length - 1 ? 'auto' : 'always' }}
                                >
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
                                                            <span className="font-mono text-slate-500 font-medium">
                                                                #{challan.challan_no}
                                                            </span>
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
                                                            <span>Gross Total</span>
                                                            <span>{formatMoney(Number(challan.gross_amount))}</span>
                                                        </div>
                                                        {Number(challan.discount_amount) > 0 && (
                                                            <div className="flex justify-between text-emerald-600">
                                                                <span>Concession ({challan.discount_title ?? 'Discount'})</span>
                                                                <span>-{formatMoney(Number(challan.discount_amount))}</span>
                                                            </div>
                                                        )}
                                                        {Number(challan.fine_amount) > 0 && (
                                                            <div className="flex justify-between text-red-600">
                                                                <span>Fine / Late Surcharge</span>
                                                                <span>+{formatMoney(Number(challan.fine_amount))}</span>
                                                            </div>
                                                        )}
                                                        <div className="flex justify-between font-bold text-sm border-t border-slate-200 dark:border-slate-800 pt-1.5 text-slate-900 dark:text-white">
                                                            <span>Current Challan Payable</span>
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
                                                            <span>{formatMoney(balance)}</span>
                                                        </div>

                                                        {/* Memo previous outstanding snapshot */}
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

                                                        {/* Bank Details */}
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
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
