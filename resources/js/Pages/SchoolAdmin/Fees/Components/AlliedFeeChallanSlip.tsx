import React from 'react';
import { useCurrency } from '@/lib/currency';

export interface ChallanItem {
    id: number;
    fee_head_name: string;
    gross_amount: string | number;
    discount_amount: string | number;
    net_amount: string | number;
}

export interface FeeChallanAdjustment {
    id: number;
    adjustment_type: 'fixed' | 'percentage';
    value: string;
    adjustment_amount: string | number;
    previous_balance: string | number;
    new_balance: string | number;
    reason: string;
    notes?: string | null;
    created_at: string;
    creator?: { id: number; name: string };
}

export interface FeeChallanData {
    id: number;
    challan_no: string;
    billing_period_key?: string;
    billing_period_label?: string;
    student_id: number;
    student_name: string;
    admission_no: string;
    class_name: string;
    section_name?: string | null;
    academic_year_name?: string;
    issue_date: string;
    due_date: string;
    gross_amount: string | number;
    discount_amount: string | number;
    discount_title?: string | null;
    fine_amount?: string | number;
    adjustment_amount?: string | number;
    total_payable: string | number;
    paid_amount: string | number;
    previous_outstanding_snapshot?: string | number;
    status: string;
    display_status?: string;
    settlement_classification?: string;
    settlement_date?: string | null;
    guardian_name?: string | null;
    guardian_cnic?: string | null;
    student?: {
        id: number;
        admission_no?: string;
        first_name?: string;
        last_name?: string;
        guardian?: {
            id?: number;
            name?: string;
            relation?: string;
            phone?: string;
            cnic?: string | null;
        } | null;
    } | null;
    items?: ChallanItem[];
    adjustments?: FeeChallanAdjustment[];
}

export interface SchoolDetails {
    name: string;
    logo?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
}

export interface BankConfig {
    bank_name: string;
    account_no: string;
    branch?: string | null;
    iban?: string | null;
}

interface AlliedFeeChallanSlipProps {
    challan: FeeChallanData;
    school: SchoolDetails;
    bankConfig?: BankConfig | null;
    copyTitle: string; // e.g. "Office Copy" or "Student Copy"
    copyBadge?: string;
}

export function formatDate(dateStr?: string | null): string {
    if (!dateStr) return '—';
    try {
        const cleanStr = String(dateStr).split('T')[0].trim();
        const parts = cleanStr.split(/[-/]/);
        if (parts.length === 3) {
            if (parts[0].length === 4) {
                // YYYY-MM-DD -> DD-MM-YYYY
                return `${parts[2].padStart(2, '0')}-${parts[1].padStart(2, '0')}-${parts[0]}`;
            } else if (parts[2].length === 4) {
                // DD-MM-YYYY
                return `${parts[0].padStart(2, '0')}-${parts[1].padStart(2, '0')}-${parts[2]}`;
            }
        }
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        return `${day}-${month}-${year}`;
    } catch {
        return dateStr;
    }
}

export function formatFeeTitle(key?: string, issueDate?: string, label?: string): string {
    if (label && !/^\d{4}-\d{2}$/.test(label) && !/^AY\d+/i.test(label)) {
        return label;
    }
    if (key && /^\d{4}-\d{2}$/.test(key)) {
        const [year, month] = key.split('-');
        const monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        const mIdx = parseInt(month, 10) - 1;
        if (mIdx >= 0 && mIdx < 12) {
            return `${monthNames[mIdx]} ${year}`;
        }
    }
    if (issueDate) {
        const d = new Date(issueDate);
        if (!isNaN(d.getTime())) {
            return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        }
    }
    return label || key || 'Fee Challan';
}

export default function AlliedFeeChallanSlip({
    challan,
    school,
    bankConfig,
    copyTitle,
}: AlliedFeeChallanSlipProps) {
    const { format: formatMoney } = useCurrency();

    const gross = Number(challan.gross_amount || 0);
    const discount = Number(challan.discount_amount || 0);
    const adjustment = Number(challan.adjustment_amount || 0);
    const fine = Number(challan.fine_amount || 0);
    const totalPayable = Number(challan.total_payable || 0);
    const paid = Number(challan.paid_amount || 0);
    const balance = Math.max(0, totalPayable - paid);
    const previousOutstanding = Number(challan.previous_outstanding_snapshot || 0);

    const fatherName = challan.guardian_name || challan.student?.guardian?.name || null;
    const fatherCnic = challan.guardian_cnic || challan.student?.guardian?.cnic || null;

    const isSettled = ['paid', 'paid_adjusted', 'waived'].includes(challan.display_status || challan.status) || balance === 0;

    return (
        <div className="challan-slip relative bg-white text-black p-2 border border-black font-sans select-none box-border shadow-none flex flex-col justify-between h-full">
            {/* 0. Top Copy Header Bar for immediate identification after cutting */}
            <div className="text-center font-extrabold text-[8.5px] uppercase tracking-wider border-b border-black py-0.5 mb-1 bg-neutral-100 print:bg-neutral-100">
                {copyTitle}
            </div>

            {/* Top Block: School Header, Challan Info & Student Details */}
            <div className="flex-shrink-0">
                {/* 1. Header Block */}
                <div className="text-center pb-0.5">
                    {school.logo ? (
                        <img
                            src={school.logo}
                            alt={school.name}
                            className="h-6 w-auto max-w-[70px] object-contain mx-auto mb-0.5"
                        />
                    ) : null}
                    <h2 className="font-extrabold text-[12.5px] uppercase tracking-wide text-black leading-tight">
                        {school.name || 'Lahore Cambridge School'}
                    </h2>
                    {school.address && (
                        <p className="text-[8px] text-neutral-800 font-medium leading-tight mt-0.5">
                            {school.address}
                        </p>
                    )}
                    {school.phone && (
                        <p className="text-[7.5px] text-neutral-600 leading-tight">
                            Ph: {school.phone}
                        </p>
                    )}
                </div>

                {/* 2. Challan No, Fee Title & Dates Bar */}
                <div className="border-t border-b border-black py-0.5 my-1 grid grid-cols-2 gap-x-1 text-[8.5px] leading-snug">
                    <div className="space-y-0.5">
                        <div className="flex items-center">
                            <span className="font-semibold text-neutral-800 w-16 flex-shrink-0">Challan No :</span>
                            <span className="font-bold tracking-tight text-black truncate">{challan.challan_no}</span>
                        </div>
                        <div className="flex items-center">
                            <span className="font-semibold text-neutral-800 w-16 flex-shrink-0">Fee Title :</span>
                            <span className="font-bold text-neutral-900 truncate">
                                {formatFeeTitle(challan.billing_period_key, challan.issue_date, challan.billing_period_label)}
                            </span>
                        </div>
                    </div>
                    <div className="space-y-0.5">
                        <div className="flex items-center justify-end">
                            <span className="font-semibold text-neutral-800 w-16 flex-shrink-0 text-right pr-1">Issue Date :</span>
                            <span className="font-medium text-neutral-900">{formatDate(challan.issue_date)}</span>
                        </div>
                        <div className="flex items-center justify-end">
                            <span className="font-semibold text-neutral-800 w-16 flex-shrink-0 text-right pr-1">Due Date :</span>
                            <span className="font-bold text-black">{formatDate(challan.due_date)}</span>
                        </div>
                    </div>
                </div>

                {/* 3. Student Details with Underlined Presentation (Allied Reference Style) */}
                <div className="space-y-0.5 text-[8.5px] leading-snug mb-1 pt-0.5">
                    <div className="flex items-end">
                        <span className="font-semibold text-neutral-800 whitespace-nowrap mr-1 min-w-[70px]">Admission No :</span>
                        <span className="font-bold uppercase tracking-wider text-black flex-1 border-b border-neutral-800 pb-0.5 truncate">
                            {challan.admission_no || '—'}
                        </span>
                    </div>
                    <div className="flex items-end">
                        <span className="font-semibold text-neutral-800 whitespace-nowrap mr-1 min-w-[70px]">Student Name :</span>
                        <span className="font-bold uppercase tracking-wide text-neutral-950 flex-1 border-b border-neutral-800 pb-0.5 truncate">
                            {challan.student_name}
                        </span>
                    </div>
                    <div className="flex items-end">
                        <span className="font-semibold text-neutral-800 whitespace-nowrap mr-1 min-w-[70px]">Father Name :</span>
                        <span className="font-bold uppercase tracking-wide text-neutral-900 flex-1 border-b border-neutral-800 pb-0.5 truncate">
                            {fatherName || '—'}
                        </span>
                    </div>
                    {fatherCnic && (
                        <div className="flex items-end">
                            <span className="font-semibold text-neutral-800 whitespace-nowrap mr-1 min-w-[70px]">Father CNIC :</span>
                            <span className="font-mono font-medium text-neutral-900 flex-1 border-b border-neutral-800 pb-0.5 truncate">
                                {fatherCnic}
                            </span>
                        </div>
                    )}
                    <div className="flex items-end">
                        <span className="font-semibold text-neutral-800 whitespace-nowrap mr-1 min-w-[70px]">Class / Sec :</span>
                        <span className="font-bold uppercase text-neutral-900 flex-1 border-b border-neutral-800 pb-0.5 truncate">
                            {challan.class_name}
                            {challan.section_name ? ` - ${challan.section_name}` : ''}
                            {challan.academic_year_name ? ` (${challan.academic_year_name})` : ''}
                        </span>
                    </div>
                </div>
            </div>

            {/* 4. Fee Table & Reserved Expandable Area */}
            <div className="flex-1 flex flex-col justify-start min-h-[26mm] relative my-0.5">
                <table className="w-full border-collapse border border-black text-[8.5px] leading-tight">
                    <thead>
                        <tr className="border-b border-black bg-neutral-100/60 print:bg-transparent font-bold">
                            <th className="w-5 text-center border-r border-black py-0.5">#</th>
                            <th className="text-left px-1.5 border-r border-black py-0.5">Fee Description</th>
                            <th className="w-16 text-right px-1.5 py-0.5">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {challan.items && challan.items.length > 0 ? (
                            challan.items.map((item, idx) => (
                                <tr key={item.id || idx} className="border-b border-neutral-800">
                                    <td className="text-center border-r border-black py-0.5 font-medium">{idx + 1}</td>
                                    <td className="px-1.5 border-r border-black py-0.5 font-medium truncate max-w-[130px]">{item.fee_head_name}</td>
                                    <td className="text-right px-1.5 py-0.5 font-mono">{formatMoney(Number(item.gross_amount))}</td>
                                </tr>
                            ))
                        ) : (
                            <tr className="border-b border-neutral-800">
                                <td className="text-center border-r border-black py-0.5 font-medium">1</td>
                                <td className="px-1.5 border-r border-black py-0.5 font-medium">Fee Obligation</td>
                                <td className="text-right px-1.5 py-0.5 font-mono">{formatMoney(gross)}</td>
                            </tr>
                        )}

                        {/* Concession / Discount line item */}
                        {discount > 0 && (
                            <tr className="border-b border-neutral-800 italic text-neutral-800">
                                <td className="text-center border-r border-black py-0.5">•</td>
                                <td className="px-1.5 border-r border-black py-0.5 truncate max-w-[130px]">
                                    {challan.discount_title ? `${challan.discount_title}` : 'Concession / Discount'}
                                </td>
                                <td className="text-right px-1.5 py-0.5 font-mono text-neutral-900">
                                    -{formatMoney(discount)}
                                </td>
                            </tr>
                        )}

                        {/* Post-issue collection adjustment line item */}
                        {adjustment > 0 && (
                            <tr className="border-b border-neutral-800 italic text-neutral-800">
                                <td className="text-center border-r border-black py-0.5">•</td>
                                <td className="px-1.5 border-r border-black py-0.5 font-medium truncate max-w-[130px]">
                                    Approved Adjustment
                                </td>
                                <td className="text-right px-1.5 py-0.5 font-mono text-neutral-900">
                                    -{formatMoney(adjustment)}
                                </td>
                            </tr>
                        )}

                        {/* Prior Outstanding / Arrears Informational Row (Rule 8) */}
                        {previousOutstanding > 0 && (
                            <tr className="border-b border-black bg-neutral-50/70 print:bg-transparent">
                                <td className="text-center border-r border-black py-0.5 font-bold">*</td>
                                <td className="px-1.5 border-r border-black py-0.5 font-semibold text-neutral-900 truncate max-w-[130px]">
                                    Previous Outstanding (Informational)
                                </td>
                                <td className="text-right px-1.5 py-0.5 font-mono font-semibold">
                                    {formatMoney(previousOutstanding)}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {/* Settle / Paid Stamp Watermark (subtle, clean in reserved area) */}
                {isSettled && (
                    <div className="mx-auto my-1.5 rotate-[-5deg] border border-emerald-800 text-emerald-800 px-2 py-0.5 rounded text-center pointer-events-none select-none z-10 print:border-blue-900 print:text-blue-900 bg-white/90">
                        <p className="text-[9px] font-black uppercase tracking-wider leading-none">
                            {challan.settlement_classification ?? (challan.display_status === 'waived' ? 'WAIVED' : 'PAID')}
                        </p>
                        <p className="text-[6.5px] font-bold uppercase tracking-tight mt-0.5">{school.name}</p>
                        {challan.settlement_date ? (
                            <p className="text-[6px] font-medium tracking-tight mt-0.5">{formatDate(challan.settlement_date)}</p>
                        ) : null}
                    </div>
                )}
            </div>

            {/* Bottom Block: Totals Box, Bank Config, Signatures */}
            <div className="flex-shrink-0 mt-0.5">
                {/* 5. Totals Box with Vertical Copy Tab */}
                <div className="flex border border-black mb-1">
                    {/* Vertical Copy Banner */}
                    <div className="w-5 border-r border-black flex items-center justify-center bg-neutral-50 print:bg-transparent py-1 select-none">
                        <span className="[writing-mode:vertical-rl] rotate-180 uppercase font-black text-[7px] tracking-wider text-neutral-900 whitespace-nowrap">
                            {copyTitle}
                        </span>
                    </div>

                    {/* Financial Totals (Zero-value rows hidden) */}
                    <div className="flex-1 p-1 space-y-0.5 text-[8.5px] leading-tight">
                        <div className="flex justify-between text-neutral-800">
                            <span>Sub Total :</span>
                            <span className="font-mono font-medium">{formatMoney(gross)}</span>
                        </div>
                        {discount > 0 && (
                            <div className="flex justify-between text-neutral-900">
                                <span>Concession :</span>
                                <span className="font-mono font-medium">-{formatMoney(discount)}</span>
                            </div>
                        )}
                        {adjustment > 0 && (
                            <div className="flex justify-between text-neutral-900">
                                <span>Adjustment :</span>
                                <span className="font-mono font-medium">-{formatMoney(adjustment)}</span>
                            </div>
                        )}
                        <div className="flex justify-between font-black text-[9.5px] border-t border-black pt-0.5 text-black">
                            <span>Total Fee :</span>
                            <span className="font-mono">{formatMoney(totalPayable)}</span>
                        </div>
                        {paid > 0 && (
                            <div className="flex justify-between text-neutral-900 font-medium pt-0.5">
                                <span>Already Paid :</span>
                                <span className="font-mono font-semibold">{formatMoney(paid)}</span>
                            </div>
                        )}
                        <div className="flex justify-between font-black text-[9px] border-t border-neutral-400 pt-0.5 text-neutral-950">
                            <span>Balance Due :</span>
                            <span className="font-mono">
                                {balance === 0 ? 'PKR 0.00 (Cleared)' : formatMoney(balance)}
                            </span>
                        </div>
                        {fine > 0 && (
                            <div className="flex justify-between font-semibold text-[8px] text-neutral-900 border-t border-dashed border-neutral-400 pt-0.5">
                                <span>After Due Date :</span>
                                <span className="font-mono font-bold">{formatMoney(totalPayable + fine)}</span>
                            </div>
                        )}
                    </div>
                </div>

                {/* Bank Account Details (Shown when bankConfig is available) */}
                {bankConfig && bankConfig.bank_name && (
                    <div className="mb-1 p-0.5 border border-neutral-300 text-[7px] text-center text-neutral-800 leading-tight">
                        <span className="font-bold">{bankConfig.bank_name}</span>
                        {bankConfig.account_no && (
                            <> | A/C: <span className="font-mono font-semibold">{bankConfig.account_no}</span></>
                        )}
                        {bankConfig.branch && <> | Branch: {bankConfig.branch}</>}
                        {bankConfig.iban && <> | IBAN: <span className="font-mono">{bankConfig.iban}</span></>}
                    </div>
                )}

                {/* 6. Signatures Area */}
                <div className="mt-4 pt-0.5 grid grid-cols-2 gap-2 text-center text-[7.5px] text-neutral-800 font-medium">
                    <div>
                        <div className="border-t border-black pt-0.5 mx-1">
                            Cashier / Officer Signature
                        </div>
                    </div>
                    <div>
                        <div className="border-t border-black pt-0.5 mx-1">
                            Parent / Depositor Signature
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
