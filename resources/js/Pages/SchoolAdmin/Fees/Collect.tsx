import { useState, useEffect } from 'react';
import { useForm, router, usePage, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Search, User, DollarSign, FileText, CheckCircle2, PlusCircle, Percent, AlertCircle, X, ShieldAlert } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import type { SchoolClass, PageProps } from '@/Types';

interface Student {
    id: number;
    first_name: string;
    last_name: string | null;
    admission_no: string;
    class_id: number;
    school_class?: { name: string };
    section?: { name: string };
}

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
    display_label?: string;
    gross_amount: string;
    discount_amount: string;
    discount_title: string | null;
    fine_amount: string;
    adjustment_amount?: string;
    total_payable: string;
    paid_amount: string;
    status: string;
    due_date: string;
    items?: ChallanItem[];
    adjustments?: FeeChallanAdjustment[];
}

interface FeeStructure {
    id: number;
    academic_year: string;
    amount: string;
    frequency: string;
    fee_category?: { id: number; name: string; type: string };
}

interface StudentCandidate {
    id: number;
    first_name: string;
    last_name: string | null;
    admission_no: string;
    class_id: number;
    school_class?: { name: string };
    section?: { name: string };
    guardian?: { id: number; name: string; phone: string | null };
}

interface StudentDiscount {
    id: number;
    title: string;
    type: string;
    value: string;
    fee_category?: { name: string };
}

interface Props {
    student: Student | null;
    studentCandidates?: StudentCandidate[];
    activeChallans: FeeChallan[];
    selectedChallanId?: string | number | null;
    discounts: StudentDiscount[];
    structures: FeeStructure[];
    classes: SchoolClass[];
    searchQuery?: string;
    idempotencyKey?: string;
    canAdjust?: boolean;
}

export default function CollectFee({
    student,
    studentCandidates = [],
    activeChallans,
    selectedChallanId,
    discounts,
    structures,
    classes,
    searchQuery = '',
    idempotencyKey = '',
    canAdjust = false,
}: Props) {
    const { flash } = usePage<PageProps>().props;
    const { currency, format: formatMoney } = useCurrency();
    const [searchInput, setSearchInput] = useState(searchQuery);

    const initialChallan = activeChallans.find(c => String(c.id) === String(selectedChallanId))
        ?? activeChallans[0]
        ?? null;

    const [selectedChallan, setSelectedChallan] = useState<FeeChallan | null>(initialChallan);

    const [showAdjustmentModal, setShowAdjustmentModal] = useState(false);
    const [adjType, setAdjType] = useState<'fixed' | 'percentage'>('fixed');
    const [adjValue, setAdjValue] = useState('');
    const [adjReason, setAdjReason] = useState('Special Management Discount');
    const [customReason, setCustomReason] = useState('');
    const [adjNotes, setAdjNotes] = useState('');
    const [adjIdempotencyKey, setAdjIdempotencyKey] = useState('');
    const [adjProcessing, setAdjProcessing] = useState(false);
    const [adjError, setAdjError] = useState<string | null>(null);

    const openAdjustmentModal = () => {
        setAdjIdempotencyKey(typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `adj-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`);
        setAdjError(null);
        setShowAdjustmentModal(true);
    };

    const payable = Number(selectedChallan?.total_payable ?? 0);
    const alreadyPaid = Number(selectedChallan?.paid_amount ?? 0);
    const currentBalance = Math.max(0, payable - alreadyPaid);

    // Live preview values for Adjustment Modal
    const previewGross = Number(selectedChallan?.gross_amount ?? 0);
    const previewConcession = Number(selectedChallan?.discount_amount ?? 0);
    const previewPrevAdj = Number(selectedChallan?.adjustment_amount ?? 0);
    const previewNetObligation = Number(selectedChallan?.total_payable ?? 0);
    const previewPrevPaid = Number(selectedChallan?.paid_amount ?? 0);
    const previewCurBal = Math.max(0, previewNetObligation - previewPrevPaid);

    let previewNewAdj = 0;
    const inputValNum = Number(adjValue || 0);
    if (adjType === 'percentage') {
        const pct = Math.min(100, Math.max(0, inputValNum));
        previewNewAdj = Math.min(previewCurBal, Math.round(previewCurBal * (pct / 100) * 100) / 100);
    } else {
        previewNewAdj = Math.min(previewCurBal, Math.max(0, inputValNum));
    }
    const previewRevisedBal = Math.max(0, previewCurBal - previewNewAdj);

    const { data, setData, post, processing, errors, reset } = useForm({
        student_id:       student?.id ? String(student.id) : '',
        fee_challan_id:   initialChallan ? String(initialChallan.id) : '',
        fee_structure_id: '',
        amount_paid:      currentBalance > 0 ? String(currentBalance.toFixed(2)) : '',
        payment_date:     new Date().toISOString().split('T')[0],
        method:           'cash',
        reference:        '',
        note:             '',
        idempotency_key:  idempotencyKey || '',
    });

    useEffect(() => {
        if (student?.id) {
            setData(d => ({ ...d, student_id: String(student.id) }));
        }
    }, [student]);

    // Refresh selected challan when activeChallans prop changes
    useEffect(() => {
        if (selectedChallan) {
            const fresh = activeChallans.find(c => String(c.id) === String(selectedChallan.id));
            if (fresh) {
                setSelectedChallan(fresh);
                const freshBal = Math.max(0, Number(fresh.total_payable) - Number(fresh.paid_amount));
                setData(d => ({ ...d, amount_paid: freshBal > 0 ? freshBal.toFixed(2) : '' }));
            }
        }
    }, [activeChallans]);

    function handleApplyAdjustment(e: React.FormEvent) {
        e.preventDefault();
        if (!selectedChallan) return;
        setAdjProcessing(true);
        setAdjError(null);

        const finalReason = adjReason === 'Other' ? (customReason.trim() || 'Other Adjustment') : adjReason;

        router.post(`/school/fees/challans/${selectedChallan.id}/adjustments`, {
            adjustment_type: adjType,
            value: adjValue,
            reason: finalReason,
            notes: adjNotes,
            idempotency_key: adjIdempotencyKey,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowAdjustmentModal(false);
                setAdjValue('');
                setAdjNotes('');
                setCustomReason('');
                setAdjProcessing(false);
                setAdjIdempotencyKey('');
            },
            onError: (errs) => {
                setAdjProcessing(false);
                const firstErr = Object.values(errs)[0];
                setAdjError(typeof firstErr === 'string' ? firstErr : 'Failed to apply adjustment.');
            },
        });
    }

    function onChallanSelect(challanIdStr: string) {
        const ch = activeChallans.find(c => String(c.id) === challanIdStr) ?? null;
        setSelectedChallan(ch);
        const bal = Math.max(0, Number(ch?.total_payable ?? 0) - Number(ch?.paid_amount ?? 0));
        setData(d => ({
            ...d,
            fee_challan_id: challanIdStr,
            amount_paid: bal > 0 ? String(bal.toFixed(2)) : '',
        }));
    }

    function doSearch(e: React.FormEvent) {
        e.preventDefault();
        if (!searchInput.trim()) return;
        router.get('/school/fees/payments/collect', { query: searchInput.trim() }, { preserveScroll: true });
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/school/fees/payments', {
            onSuccess: () => reset(),
        });
    }

    const payingNum = Number(data.amount_paid || 0);
    const postBalance = Math.max(0, currentBalance - payingNum);
    const isOverpaying = payingNum > currentBalance && currentBalance > 0;

    return (
        <AppLayout title="Collect Fee">
            <div className="max-w-3xl mx-auto space-y-6">
                <div className="flex items-center gap-3">
                    <Link href="/school/fees/outstanding" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                        <ArrowLeft className="w-4 h-4" /> Outstanding Fees
                    </Link>
                    <span className="text-slate-300 dark:text-slate-700">|</span>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Collect Payment</h1>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">{flash.success}</div>
                )}

                {/* Student Lookup Card */}
                <Card className="border-slate-200 dark:border-slate-800">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base flex items-center gap-2">
                            <Search className="w-4 h-4 text-indigo-600" /> Find Student
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={doSearch} className="flex gap-2">
                            <Input
                                placeholder="Search by Admission No (e.g. LCS-2024-001) or Student Name"
                                value={searchInput}
                                onChange={e => setSearchInput(e.target.value)}
                                className="flex-1"
                            />
                            <Button type="submit" variant="outline" className="inline-flex items-center gap-2">
                                <Search className="w-4 h-4" /> Search
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {student && (
                    <div className="space-y-6">
                        {/* Student Banner */}
                        <Card className="border-indigo-200 dark:border-indigo-900 bg-indigo-50/50 dark:bg-indigo-950/20">
                            <CardContent className="p-4 flex items-center gap-4">
                                <div className="w-12 h-12 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-base">
                                    {student.first_name[0]}{student.last_name?.[0] ?? ''}
                                </div>
                                <div>
                                    <p className="font-semibold text-slate-900 dark:text-white text-base">
                                        {student.first_name} {student.last_name}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        Admission No: <span className="font-mono font-medium text-slate-700 dark:text-slate-300">{student.admission_no}</span> · Class: {student.school_class?.name ?? '—'} {student.section?.name ? `(${student.section.name})` : ''}
                                    </p>
                                </div>
                                <Badge className="ml-auto bg-indigo-100 text-indigo-700 border-0">Student Verified</Badge>
                            </CardContent>
                        </Card>

                        {/* Active Discounts Pill */}
                        {discounts.length > 0 && (
                            <div className="flex items-center gap-2 flex-wrap">
                                <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Approved Concessions:</span>
                                {discounts.map(d => (
                                    <Badge key={d.id} className="bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs">
                                        {d.title} ({d.type === 'percentage' ? `${d.value}%` : formatMoney(Number(d.value))})
                                    </Badge>
                                ))}
                            </div>
                        )}

                        {/* Payment Collection Form */}
                        <Card className="border-slate-200 dark:border-slate-800">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <DollarSign className="w-4 h-4 text-emerald-600" /> Transaction Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleSubmit} className="space-y-4">
                                    {/* Challan Selector */}
                                    {activeChallans.length > 0 ? (
                                        <div className="space-y-1.5">
                                            <Label>Select Active Challan / Obligation <span className="text-red-500">*</span></Label>
                                            <Select value={data.fee_challan_id} onValueChange={onChallanSelect}>
                                                <SelectTrigger><SelectValue placeholder="Select challan" /></SelectTrigger>
                                                <SelectContent>
                                                    {activeChallans.map(c => {
                                                        const bal = Math.max(0, Number(c.total_payable) - Number(c.paid_amount));
                                                        return (
                                                            <SelectItem key={c.id} value={String(c.id)}>
                                                                {c.display_label || `Challan #${c.challan_no} (${c.billing_period_label || c.billing_period_key}) — Total: ${formatMoney(Number(c.total_payable))} · Balance: ${formatMoney(bal)}`}
                                                            </SelectItem>
                                                        );
                                                    })}
                                                </SelectContent>
                                            </Select>
                                            {errors.fee_challan_id && <p className="text-xs text-red-500">{errors.fee_challan_id}</p>}
                                        </div>
                                    ) : (
                                        <div className="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
                                            No active pending challan found for this student. You may record an ad-hoc payment against a fee structure below, or generate a challan first.
                                        </div>
                                    )}

                                    {/* Breakdown Box if Challan Selected */}
                                    {selectedChallan && (
                                        <div className="rounded-xl border border-slate-200 dark:border-slate-800 p-4 bg-slate-50/50 dark:bg-slate-900/50 space-y-2 text-sm">
                                            <div className="flex justify-between text-slate-600">
                                                <span>Gross Fees</span>
                                                <span>{formatMoney(Number(selectedChallan.gross_amount))}</span>
                                            </div>
                                            {Number(selectedChallan.discount_amount) > 0 && (
                                                <div className="flex justify-between text-emerald-600">
                                                    <span>Student Concession ({selectedChallan.discount_title ?? 'Approved Concession'})</span>
                                                    <span>-{formatMoney(Number(selectedChallan.discount_amount))}</span>
                                                </div>
                                            )}
                                            {Number(selectedChallan.adjustment_amount || 0) > 0 && (
                                                <div className="flex justify-between text-indigo-600 font-medium">
                                                    <span>Post-Issue Collection Adjustments</span>
                                                    <span>-{formatMoney(Number(selectedChallan.adjustment_amount))}</span>
                                                </div>
                                            )}
                                            <div className="flex justify-between font-semibold border-t border-slate-200 dark:border-slate-800 pt-1.5">
                                                <span>Net Obligation</span>
                                                <span>{formatMoney(Number(selectedChallan.total_payable))}</span>
                                            </div>
                                            <div className="flex justify-between text-slate-600">
                                                <span>Already Paid</span>
                                                <span className="text-emerald-600 font-medium">{formatMoney(Number(selectedChallan.paid_amount))}</span>
                                            </div>
                                            <div className="flex justify-between font-bold text-base border-t border-slate-200 dark:border-slate-800 pt-1.5 text-red-600">
                                                <span>Outstanding Balance</span>
                                                <span className={currentBalance === 0 ? 'text-emerald-600' : ''}>{formatMoney(currentBalance)}</span>
                                            </div>

                                            <div className="flex items-center justify-between gap-2 pt-2 border-t border-slate-200 dark:border-slate-800 flex-wrap">
                                                {canAdjust && currentBalance > 0 && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={openAdjustmentModal}
                                                        className="text-xs text-indigo-600 border-indigo-200 hover:bg-indigo-50 dark:hover:bg-indigo-950 inline-flex items-center gap-1.5"
                                                    >
                                                        <PlusCircle className="w-3.5 h-3.5" /> Apply One-Time Adjustment
                                                    </Button>
                                                )}
                                                {student && (
                                                    <Link
                                                        href={`/school/students/${student.id}?tab=fees`}
                                                        className="text-xs text-slate-500 hover:text-indigo-600 underline ml-auto inline-flex items-center gap-1"
                                                    >
                                                        Manage Student Concession
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {selectedChallan && currentBalance <= 0 && (
                                        <div className={`rounded-lg p-4 text-sm flex items-center justify-between gap-4 border ${
                                            Number(selectedChallan.paid_amount) === 0 && Number(selectedChallan.adjustment_amount || 0) > 0
                                                ? 'bg-purple-50 border-purple-200 text-purple-900 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-200'
                                                : Number(selectedChallan.paid_amount) > 0 && Number(selectedChallan.adjustment_amount || 0) > 0
                                                    ? 'bg-teal-50 border-teal-200 text-teal-900 dark:bg-teal-950/30 dark:border-teal-800 dark:text-teal-200'
                                                    : 'bg-emerald-50 border-emerald-200 text-emerald-800'
                                        }`}>
                                            <div className="flex items-center gap-2">
                                                <CheckCircle2 className="w-5 h-5 flex-shrink-0" />
                                                <div>
                                                    <p className="font-semibold">
                                                        {Number(selectedChallan.paid_amount) === 0 && Number(selectedChallan.adjustment_amount || 0) > 0
                                                            ? 'This challan has been fully cleared via adjustment (Settled — Adjusted / Waived).'
                                                            : Number(selectedChallan.paid_amount) > 0 && Number(selectedChallan.adjustment_amount || 0) > 0
                                                                ? 'This challan has been fully settled through payments and adjustments (Settled — Paid + Adjusted).'
                                                                : 'This challan has been fully paid.'}
                                                    </p>
                                                    <p className="text-xs mt-0.5 opacity-90">
                                                        Outstanding balance is PKR 0.00. No cash payment is due.
                                                    </p>
                                                </div>
                                            </div>
                                            <Link href={`/school/fees/challans/${selectedChallan.id}`}>
                                                <Button type="button" size="sm" variant="outline" className="text-xs bg-white dark:bg-slate-900">View Challan</Button>
                                            </Link>
                                        </div>
                                    )}

                                    {/* Collection Input (Hidden when challan is fully settled) */}
                                    {(!selectedChallan || currentBalance > 0) && (
                                        <>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div className="space-y-1.5">
                                                    <Label>Amount Receiving ({currency}) <span className="text-red-500">*</span></Label>
                                                    <Input
                                                        type="number"
                                                        min="0.01"
                                                        step="0.01"
                                                        value={data.amount_paid}
                                                        onChange={e => setData('amount_paid', e.target.value)}
                                                        className={isOverpaying ? 'border-red-500 focus-visible:ring-red-500' : ''}
                                                    />
                                                    {errors.amount_paid && <p className="text-xs text-red-500">{errors.amount_paid}</p>}
                                                    {isOverpaying && (
                                                        <p className="text-xs text-red-500 font-medium">Cannot overpay. Maximum receivable is {formatMoney(currentBalance)}.</p>
                                                    )}
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label>Remaining Balance After Payment</Label>
                                                    <div className={`h-10 rounded-md border px-3 flex items-center font-semibold text-sm ${postBalance > 0 ? 'text-red-600 border-red-200 bg-red-50/50' : 'text-emerald-600 border-emerald-200 bg-emerald-50/50'}`}>
                                                        {postBalance > 0 ? formatMoney(postBalance) : 'Fully Cleared'}
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Payment Date & Method */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div className="space-y-1.5">
                                                    <Label>Payment Date <span className="text-red-500">*</span></Label>
                                                    <Input
                                                        type="date"
                                                        value={data.payment_date}
                                                        onChange={e => setData('payment_date', e.target.value)}
                                                    />
                                                    {errors.payment_date && <p className="text-xs text-red-500">{errors.payment_date}</p>}
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label>Payment Method <span className="text-red-500">*</span></Label>
                                                    <Select value={data.method} onValueChange={v => setData('method', v)}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="cash">Cash</SelectItem>
                                                            <SelectItem value="card">Debit / Credit Card</SelectItem>
                                                            <SelectItem value="online">Online Transfer</SelectItem>
                                                            <SelectItem value="bank_transfer">Bank Transfer</SelectItem>
                                                            <SelectItem value="cheque">Cheque</SelectItem>
                                                            <SelectItem value="other">Other</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    {errors.method && <p className="text-xs text-red-500">{errors.method}</p>}
                                                </div>
                                            </div>

                                            {/* Reference & Notes */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div className="space-y-1.5">
                                                    <Label>Cheque / Transaction Reference</Label>
                                                    <Input
                                                        placeholder="e.g. Cheque #84920 or Online Ref"
                                                        value={data.reference}
                                                        onChange={e => setData('reference', e.target.value)}
                                                    />
                                                    {errors.reference && <p className="text-xs text-red-500">{errors.reference}</p>}
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label>Administrative Note</Label>
                                                    <Input
                                                        placeholder="Optional remarks"
                                                        value={data.note}
                                                        onChange={e => setData('note', e.target.value)}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                                                <Link href="/school/fees/outstanding">
                                                    <Button type="button" variant="ghost">Cancel</Button>
                                                </Link>
                                                <Button
                                                    type="submit"
                                                    disabled={processing || isOverpaying || payingNum <= 0}
                                                    className="bg-indigo-600 hover:bg-indigo-700 text-white font-medium inline-flex items-center gap-2"
                                                >
                                                    <CheckCircle2 className="w-4 h-4" />
                                                    {processing ? 'Recording...' : 'Record Payment & Print Receipt'}
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </form>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {!student && studentCandidates && studentCandidates.length > 1 && (
                    <Card className="border-indigo-200 dark:border-indigo-900 bg-white dark:bg-slate-950">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base flex items-center gap-2 text-indigo-700 dark:text-indigo-400">
                                <User className="w-4 h-4" /> Multiple Students Found ({studentCandidates.length})
                            </CardTitle>
                            <p className="text-xs text-slate-500">
                                Multiple students matched your search. Please select the correct student below to proceed with fee collection:
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {studentCandidates.map(cand => (
                                <div
                                    key={cand.id}
                                    className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 hover:border-indigo-400 dark:hover:border-indigo-600 bg-slate-50/50 dark:bg-slate-900/50 flex items-center justify-between transition-colors"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 flex items-center justify-center font-bold text-sm">
                                            {cand.first_name[0]}{cand.last_name?.[0] ?? ''}
                                        </div>
                                        <div>
                                            <p className="font-semibold text-sm text-slate-900 dark:text-white">
                                                {cand.first_name} {cand.last_name}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                Admission No: <span className="font-mono font-medium text-slate-700 dark:text-slate-300">{cand.admission_no}</span>
                                                {' · '}Class: {cand.school_class?.name ?? '—'} {cand.section?.name ? `(${cand.section.name})` : ''}
                                                {cand.guardian?.name && ` · Guardian: ${cand.guardian.name} (${cand.guardian.phone || 'No phone'})`}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        size="sm"
                                        className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs"
                                        onClick={() => router.get('/school/fees/payments/collect', { student_id: cand.id }, { preserveScroll: true })}
                                    >
                                        Select Student
                                    </Button>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {!student && (!studentCandidates || studentCandidates.length <= 1) && (
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 py-20 text-center">
                        <User className="w-12 h-12 mx-auto text-slate-300 mb-3" />
                        <p className="text-slate-500 font-medium">Search for a student to collect fees</p>
                        <p className="text-xs text-slate-400 mt-1">Enter admission number (e.g. LCS-2024-001) or student name above.</p>
                    </div>
                )}

                {/* Apply One-Time Adjustment Modal */}
                {showAdjustmentModal && selectedChallan && (
                    <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50 overflow-y-auto">
                        <div className="bg-white dark:bg-slate-900 rounded-xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-200 dark:border-slate-800 my-8">
                            <div className="flex justify-between items-start border-b border-slate-100 dark:border-slate-800 pb-3">
                                <div>
                                    <h3 className="font-bold text-lg text-slate-900 dark:text-white flex items-center gap-2">
                                        <PlusCircle className="w-5 h-5 text-indigo-600" /> Apply One-Time Adjustment
                                    </h3>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Challan #{selectedChallan.challan_no} · Student: {student?.first_name} {student?.last_name} ({student?.admission_no})
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 w-8 p-0"
                                    onClick={() => setShowAdjustmentModal(false)}
                                >
                                    <X className="w-4 h-4" />
                                </Button>
                            </div>

                            {adjError && (
                                <div className="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700 flex items-center gap-2">
                                    <AlertCircle className="w-4 h-4 text-red-600 flex-shrink-0" />
                                    <span>{adjError}</span>
                                </div>
                            )}

                            <form onSubmit={handleApplyAdjustment} className="space-y-4">
                                {/* Type Selector */}
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1">
                                        <Label className="text-xs">Adjustment Type <span className="text-red-500">*</span></Label>
                                        <Select value={adjType} onValueChange={(v: 'fixed' | 'percentage') => setAdjType(v)}>
                                            <SelectTrigger className="text-xs"><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="fixed">Fixed Amount (PKR)</SelectItem>
                                                <SelectItem value="percentage">Percentage (%)</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">
                                            {adjType === 'percentage' ? 'Percentage Value (%)' : `Amount Value (${currency})`} <span className="text-red-500">*</span>
                                        </Label>
                                        <Input
                                            type="number"
                                            min="0.01"
                                            step={adjType === 'percentage' ? '1' : '0.01'}
                                            max={adjType === 'percentage' ? '100' : String(currentBalance)}
                                            placeholder={adjType === 'percentage' ? 'e.g. 10' : 'e.g. 500'}
                                            value={adjValue}
                                            onChange={e => setAdjValue(e.target.value)}
                                            required
                                            className="text-xs"
                                        />
                                    </div>
                                </div>

                                {/* Reason / Category */}
                                <div className="space-y-1">
                                    <Label className="text-xs">Reason / Category <span className="text-red-500">*</span></Label>
                                    <Select value={adjReason} onValueChange={setAdjReason}>
                                        <SelectTrigger className="text-xs"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Special Management Discount">Special Management Discount</SelectItem>
                                            <SelectItem value="Principal Approved Waiver">Principal Approved Waiver</SelectItem>
                                            <SelectItem value="Financial Assistance">Financial Assistance</SelectItem>
                                            <SelectItem value="Late Fee Waiver">Late Fee Waiver</SelectItem>
                                            <SelectItem value="Other">Other (custom reason)</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                {adjReason === 'Other' && (
                                    <div className="space-y-1">
                                        <Label className="text-xs">Custom Reason Title <span className="text-red-500">*</span></Label>
                                        <Input
                                            placeholder="Enter specific reason"
                                            value={customReason}
                                            onChange={e => setCustomReason(e.target.value)}
                                            required
                                            className="text-xs"
                                        />
                                    </div>
                                )}

                                {/* Note / Reference */}
                                <div className="space-y-1">
                                    <Label className="text-xs">
                                        Administrative Note / Reference {adjReason === 'Other' ? <span className="text-red-500">*</span> : <span className="text-slate-400 font-normal">(Optional)</span>}
                                    </Label>
                                    <textarea
                                        rows={2}
                                        value={adjNotes}
                                        onChange={e => setAdjNotes(e.target.value)}
                                        placeholder={adjReason === 'Other' ? "Please provide explanatory note (min 3 characters)" : "Optional reference or memo (e.g. Principal approval, memo #)"}
                                        required={adjReason === 'Other'}
                                        className="w-full rounded-md border border-slate-300 dark:border-slate-700 p-2 text-xs focus:ring-indigo-500 focus:border-indigo-500"
                                    />
                                </div>

                                {/* Live Calculation Preview */}
                                <div className="rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 p-3 space-y-1.5 text-xs">
                                    <p className="font-semibold text-slate-700 dark:text-slate-300 mb-1">Live Calculation Preview</p>
                                    <div className="flex justify-between text-slate-500">
                                        <span>Original Challan Amount</span>
                                        <span>{formatMoney(previewGross)}</span>
                                    </div>
                                    <div className="flex justify-between text-slate-500">
                                        <span>Existing Student Concession</span>
                                        <span>-{formatMoney(previewConcession)}</span>
                                    </div>
                                    <div className="flex justify-between text-slate-500">
                                        <span>Post-Issue Adjustments (Prior)</span>
                                        <span>-{formatMoney(previewPrevAdj)}</span>
                                    </div>
                                    <div className="flex justify-between font-medium text-slate-700 dark:text-slate-300 border-t border-slate-200 dark:border-slate-800 pt-1">
                                        <span>Net Obligation</span>
                                        <span>{formatMoney(previewNetObligation)}</span>
                                    </div>
                                    <div className="flex justify-between text-slate-500">
                                        <span>Previously Paid</span>
                                        <span>{formatMoney(previewPrevPaid)}</span>
                                    </div>
                                    <div className="flex justify-between font-semibold text-slate-800 dark:text-slate-200 border-t border-slate-200 dark:border-slate-800 pt-1">
                                        <span>Current Outstanding Balance</span>
                                        <span>{formatMoney(previewCurBal)}</span>
                                    </div>
                                    <div className="flex justify-between font-bold text-indigo-600 dark:text-indigo-400">
                                        <span>New Adjustment Amount</span>
                                        <span>-{formatMoney(previewNewAdj)}</span>
                                    </div>
                                    <div className="flex justify-between font-bold text-sm border-t border-slate-300 dark:border-slate-700 pt-1 text-red-600">
                                        <span>Revised Outstanding Balance</span>
                                        <span className={previewRevisedBal === 0 ? 'text-emerald-600' : ''}>
                                            {previewRevisedBal === 0 ? 'PKR 0.00 (Fully Settled)' : formatMoney(previewRevisedBal)}
                                        </span>
                                    </div>
                                </div>

                                <div className="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setShowAdjustmentModal(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={adjProcessing || previewNewAdj <= 0 || (adjReason === 'Other' && adjNotes.trim().length < 3)}
                                        className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs inline-flex items-center gap-1.5"
                                    >
                                        {adjProcessing ? 'Applying...' : 'Apply Adjustment'}
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
