import { useState, useEffect } from 'react';
import { useForm, router, usePage, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Search, User, DollarSign, FileText, CheckCircle2 } from 'lucide-react';
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

interface FeeChallan {
    id: number;
    challan_no: string;
    billing_period_key: string;
    gross_amount: string;
    discount_amount: string;
    discount_title: string | null;
    fine_amount: string;
    total_payable: string;
    paid_amount: string;
    status: string;
    due_date: string;
    items?: ChallanItem[];
}

interface FeeStructure {
    id: number;
    academic_year: string;
    amount: string;
    frequency: string;
    fee_category?: { id: number; name: string; type: string };
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
    activeChallans: FeeChallan[];
    selectedChallanId?: string | number | null;
    discounts: StudentDiscount[];
    structures: FeeStructure[];
    classes: SchoolClass[];
    searchQuery?: string;
    idempotencyKey?: string;
}

export default function CollectFee({
    student,
    activeChallans,
    selectedChallanId,
    discounts,
    structures,
    classes,
    searchQuery = '',
    idempotencyKey = '',
}: Props) {
    const { flash } = usePage<PageProps>().props;
    const { currency, format: formatMoney } = useCurrency();
    const [searchInput, setSearchInput] = useState(searchQuery);

    const initialChallan = activeChallans.find(c => String(c.id) === String(selectedChallanId))
        ?? activeChallans[0]
        ?? null;

    const [selectedChallan, setSelectedChallan] = useState<FeeChallan | null>(initialChallan);

    const payable = Number(selectedChallan?.total_payable ?? 0);
    const alreadyPaid = Number(selectedChallan?.paid_amount ?? 0);
    const currentBalance = Math.max(0, payable - alreadyPaid);

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
                                                                Challan #{c.challan_no} ({c.billing_period_key}) — Total: {formatMoney(Number(c.total_payable))} · Balance: {formatMoney(bal)}
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
                                                    <span>Concession ({selectedChallan.discount_title ?? 'Discount'})</span>
                                                    <span>-{formatMoney(Number(selectedChallan.discount_amount))}</span>
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
                                                <span>{formatMoney(currentBalance)}</span>
                                            </div>
                                        </div>
                                    )}

                                    {/* Collection Input */}
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
                                </form>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {!student && (
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 py-20 text-center">
                        <User className="w-12 h-12 mx-auto text-slate-300 mb-3" />
                        <p className="text-slate-500 font-medium">Search for a student to collect fees</p>
                        <p className="text-xs text-slate-400 mt-1">Enter admission number (e.g. LCS-2024-001) or student name above.</p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
