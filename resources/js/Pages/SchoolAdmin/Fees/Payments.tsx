import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import {
    Plus, AlertCircle, Settings2, Tag, DollarSign, TrendingDown,
    CheckCircle2, Clock, Search, FileText, ExternalLink, Receipt as ReceiptIcon, X
} from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import type { SchoolClass } from '@/Types';

interface ChallanRow {
    id: number;
    challan_no: string;
    billing_period_label?: string;
    billing_period_key?: string;
    academic_year_name?: string;
    student_name?: string;
    admission_no?: string;
    class_name?: string;
    section_name?: string | null;
    due_date?: string;
    gross_amount?: string;
    discount_amount?: string;
    adjustment_amount?: string;
    total_payable: string;
    paid_amount: string;
    balance?: string;
    status: string;
    display_status?: string;
    settlement_classification?: string;
    student?: {
        id: number;
        first_name: string;
        last_name: string | null;
        admission_no: string;
        school_class?: { name: string };
        section?: { name: string };
    };
}

interface Payment {
    id: number;
    receipt_no: string;
    amount_due: string;
    amount_paid: string;
    discount: string;
    fine: string;
    status: 'paid' | 'partial' | 'unpaid' | 'overdue';
    method: string;
    payment_date: string | null;
    month_year: string | null;
    student?: { id: number; first_name: string; last_name: string | null; admission_no: string; school_class?: { name: string } };
    fee_structure?: { id: number; academic_year: string; fee_category?: { name: string } };
    fee_challan?: { id: number; challan_no: string };
    collector?: { id: number; name: string };
}

interface Paginated<T> {
    data: T[];
    current_page?: number;
    last_page?: number;
    total?: number;
    from?: number;
    to?: number;
    prev_page_url?: string | null;
    next_page_url?: string | null;
    meta?: {
        current_page: number;
        last_page: number;
        total: number;
        from: number;
        to: number;
    };
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    activeTab?: 'challans' | 'payments';
    challans: Paginated<ChallanRow>;
    payments: Paginated<Payment>;
    classes: SchoolClass[];
    filters: { tab?: string; class_id?: string; status?: string; search?: string; month_year?: string };
    stats: { total_collected: number; total_outstanding: number; total_adjustments?: number; paid_count: number; pending_count: number };
}

const STATUS_STYLE: Record<string, string> = {
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400',
    waived: 'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-400 border border-purple-300',
    paid_adjusted: 'bg-teal-100 text-teal-700 dark:bg-teal-950/40 dark:text-teal-400 border border-teal-300',
    partial: 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
    unpaid: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    overdue: 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400',
    void: 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
};
const METHOD_LABELS: Record<string, string> = {
    cash: 'Cash', card: 'Card', online: 'Online', bank_transfer: 'Bank Transfer', cheque: 'Cheque',
};

export default function FeePayments({ activeTab = 'challans', challans, payments, classes, filters, stats }: Props) {
    const { format: formatMoney } = useCurrency();
    const currentTab = (filters.tab as 'challans' | 'payments') || activeTab;
    const [searchTerm, setSearchTerm] = useState(filters.search || '');

    function applyFilter(key: string, value: string) {
        router.get('/school/fees/payments', {
            ...filters,
            tab: currentTab,
            [key]: value || undefined,
        }, { preserveScroll: true });
    }

    function handleSearchSubmit(e: React.FormEvent) {
        e.preventDefault();
        applyFilter('search', searchTerm);
    }

    function switchTab(newTab: 'challans' | 'payments') {
        router.get('/school/fees/payments', {
            ...filters,
            tab: newTab,
        }, { preserveScroll: true });
    }

    function clearFilters() {
        setSearchTerm('');
        router.get('/school/fees/payments', { tab: currentTab }, { preserveScroll: true });
    }

    const challanTotal = challans.total ?? challans.meta?.total ?? challans.data.length;
    const paymentTotal = payments.total ?? payments.meta?.total ?? payments.data.length;

    const statCards = [
        { label: 'Total Collected', value: formatMoney(stats.total_collected ?? 0), color: 'text-green-600', icon: DollarSign },
        { label: 'Outstanding', value: formatMoney(Math.max(0, stats.total_outstanding ?? 0)), color: 'text-red-600', icon: TrendingDown },
        { label: 'Total Adjustments', value: formatMoney(stats.total_adjustments ?? 0), color: 'text-indigo-600', icon: Tag },
        { label: 'Paid Receipts', value: stats.paid_count, color: 'text-indigo-600', icon: CheckCircle2 },
        { label: 'Pending / Unpaid', value: stats.pending_count, color: 'text-amber-600', icon: Clock },
    ];

    const hasFilters = Boolean(filters.search || filters.class_id || filters.status || filters.month_year);

    return (
        <AppLayout title="Fee Payments">
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Fee Management</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                            {currentTab === 'challans' ? `${challanTotal} fee challans & vouchers` : `${paymentTotal} payment records`}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href="/school/fees/outstanding">
                            <Button variant="outline" className="inline-flex items-center gap-2"><AlertCircle className="w-4 h-4" /> Outstanding</Button>
                        </Link>
                        <Link href="/school/fees/structures">
                            <Button variant="outline" className="inline-flex items-center gap-2"><Settings2 className="w-4 h-4" /> Structures</Button>
                        </Link>
                        <Link href="/school/fees/categories">
                            <Button variant="outline" className="inline-flex items-center gap-2"><Tag className="w-4 h-4" /> Categories</Button>
                        </Link>
                        <Link href="/school/fees/payments/collect">
                            <Button className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-2">
                                <Plus className="w-4 h-4" /> Collect Fee
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Stat Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                    {statCards.map((sc, i) => (
                        <Card key={i} className="border-slate-200 dark:border-slate-800">
                            <CardContent className="p-4 flex items-center justify-between">
                                <div>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">{sc.label}</p>
                                    <p className={`text-xl font-bold mt-1 ${sc.color}`}>{sc.value}</p>
                                </div>
                                <sc.icon className={`w-8 h-8 opacity-20 ${sc.color}`} />
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Tabs Switcher */}
                <div className="flex border-b border-slate-200 dark:border-slate-800 space-x-6">
                    <button
                        type="button"
                        onClick={() => switchTab('challans')}
                        className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-colors ${
                            currentTab === 'challans'
                                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                                : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
                        }`}
                    >
                        <FileText className="w-4 h-4" />
                        Challans / Vouchers
                        <span className={`px-2 py-0.5 text-xs rounded-full ${
                            currentTab === 'challans' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                        }`}>
                            {challanTotal}
                        </span>
                    </button>
                    <button
                        type="button"
                        onClick={() => switchTab('payments')}
                        className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-colors ${
                            currentTab === 'payments'
                                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                                : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
                        }`}
                    >
                        <ReceiptIcon className="w-4 h-4" />
                        Receipts / Payments
                        <span className={`px-2 py-0.5 text-xs rounded-full ${
                            currentTab === 'payments' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                        }`}>
                            {paymentTotal}
                        </span>
                    </button>
                </div>

                {/* Filters */}
                <div className="flex gap-3 flex-wrap items-center">
                    <form onSubmit={handleSearchSubmit} className="flex gap-2">
                        <div className="relative w-64">
                            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <Input
                                placeholder={currentTab === 'challans' ? "Search challan #, student..." : "Search receipt #, student..."}
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="pl-9 text-sm"
                            />
                        </div>
                        <Button type="submit" variant="secondary" size="sm">Search</Button>
                    </form>

                    <Select value={filters.class_id ?? ''} onValueChange={v => applyFilter('class_id', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All Classes" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">All Classes</SelectItem>
                            {classes.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>

                    <Select value={filters.status ?? ''} onValueChange={v => applyFilter('status', v)}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="All Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">All Status</SelectItem>
                            <SelectItem value="paid">Paid</SelectItem>
                            <SelectItem value="partial">Partial</SelectItem>
                            <SelectItem value="unpaid">Unpaid</SelectItem>
                            <SelectItem value="overdue">Overdue</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={clearFilters} className="text-slate-500 hover:text-slate-700 flex items-center gap-1">
                            <X className="w-3.5 h-3.5" /> Clear
                        </Button>
                    )}
                </div>

                {/* Tab 1: Challans Table */}
                {currentTab === 'challans' && (
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-slate-50 dark:bg-slate-900">
                                    <TableHead className="w-12">#</TableHead>
                                    <TableHead>Challan No</TableHead>
                                    <TableHead>Student</TableHead>
                                    <TableHead>Billing Period</TableHead>
                                    <TableHead>Due Date</TableHead>
                                    <TableHead className="text-right">Total Payable</TableHead>
                                    <TableHead className="text-right">Paid</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-32 text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {challans.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={10} className="text-center py-10 text-slate-500">
                                            No challans or vouchers found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    challans.data.map((c, idx) => {
                                        const s = c.student;
                                        const studentName = c.student_name || (s ? `${s.first_name} ${s.last_name || ''}`.trim() : '—');
                                        const admissionNo = c.admission_no || s?.admission_no || '—';
                                        const className = c.class_name || s?.school_class?.name || '—';
                                        const sectionName = c.section_name || s?.section?.name || '';
                                        const balance = c.balance ?? Math.max(0, Number(c.total_payable) - Number(c.paid_amount));
                                        const hasBalance = Number(balance) > 0;

                                        return (
                                            <TableRow key={c.id}>
                                                <TableCell className="text-slate-400 text-xs">{idx + 1}</TableCell>
                                                <TableCell>
                                                    <div className="font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                                        {c.challan_no}
                                                    </div>
                                                    {c.academic_year_name && (
                                                        <div className="text-xs text-slate-400">{c.academic_year_name}</div>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="font-medium text-sm text-slate-900 dark:text-white flex items-center gap-1.5">
                                                        {s?.id ? (
                                                            <Link href={`/school/students/${s.id}?tab=fees`} className="hover:text-indigo-600 inline-flex items-center gap-1">
                                                                {studentName}
                                                                <ExternalLink className="w-3 h-3 text-slate-400" />
                                                            </Link>
                                                        ) : (
                                                            studentName
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-slate-400">{className} {sectionName ? `· ${sectionName}` : ''} · ID: {admissionNo}</p>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                                        {c.billing_period_label || c.billing_period_key || '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-xs text-slate-500">
                                                    {c.due_date ? new Date(c.due_date).toLocaleDateString() : '—'}
                                                </TableCell>
                                                <TableCell className="text-right text-sm font-medium">
                                                    <div>{formatMoney(c.total_payable)}</div>
                                                    {Number(c.adjustment_amount || 0) > 0 && (
                                                        <span className="inline-block text-[10px] text-indigo-600 dark:text-indigo-400 font-medium">
                                                            Adj: -{formatMoney(Number(c.adjustment_amount))}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-sm font-semibold text-emerald-600">
                                                    {formatMoney(c.paid_amount)}
                                                </TableCell>
                                                <TableCell className={`text-right text-sm font-bold ${hasBalance ? 'text-red-600' : 'text-emerald-600'}`}>
                                                    {hasBalance ? formatMoney(balance) : '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge className={`border-0 text-xs font-medium ${STATUS_STYLE[c.display_status ?? c.status] ?? ''}`}>
                                                        {c.settlement_classification ?? (c.display_status || c.status)}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {hasBalance ? (
                                                        <Link href={`/school/fees/payments/collect?student_id=${s?.id || ''}&challan_id=${c.id}`}>
                                                            <Button size="sm" className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs h-8">
                                                                Collect Fee
                                                            </Button>
                                                        </Link>
                                                    ) : (
                                                        s?.id && (
                                                            <Link href={`/school/students/${s.id}?tab=fees`}>
                                                                <Button variant="outline" size="sm" className="text-xs h-8">
                                                                    View Ledger
                                                                </Button>
                                                            </Link>
                                                        )
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination for Challans */}
                        {(challans.last_page ?? challans.meta?.last_page ?? 1) > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200 dark:border-slate-800">
                                <p className="text-xs text-slate-500">
                                    Showing {challans.from ?? challans.meta?.from}–{challans.to ?? challans.meta?.to} of {challanTotal}
                                </p>
                                <div className="flex gap-1">
                                    {challans.prev_page_url && (
                                        <Button variant="outline" size="sm" onClick={() => router.get(challans.prev_page_url!)}>Previous</Button>
                                    )}
                                    {challans.next_page_url && (
                                        <Button variant="outline" size="sm" onClick={() => router.get(challans.next_page_url!)}>Next</Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Tab 2: Receipts / Payments Table */}
                {currentTab === 'payments' && (
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-slate-50 dark:bg-slate-900">
                                    <TableHead className="w-12">#</TableHead>
                                    <TableHead>Receipt No</TableHead>
                                    <TableHead>Student</TableHead>
                                    <TableHead>Fee Type</TableHead>
                                    <TableHead className="text-right">Due</TableHead>
                                    <TableHead className="text-right">Paid</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-24 text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payments.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={11} className="text-center py-10 text-slate-500">
                                            No payment records found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    payments.data.map((p, idx) => {
                                        const netDue = Number(p.amount_due) + Number(p.fine) - Number(p.discount);
                                        const balance = Math.max(0, netDue - Number(p.amount_paid));
                                        return (
                                            <TableRow key={p.id}>
                                                <TableCell className="text-slate-400 text-xs">{idx + 1}</TableCell>
                                                <TableCell className="font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                                    {p.receipt_no}
                                                </TableCell>
                                                <TableCell>
                                                    <p className="font-medium text-sm text-slate-900 dark:text-white">{p.student?.first_name} {p.student?.last_name}</p>
                                                    <p className="text-xs text-slate-400">{p.student?.school_class?.name} · ID: {p.student?.admission_no}</p>
                                                </TableCell>
                                                <TableCell>
                                                    <p className="text-sm text-slate-700 dark:text-slate-300">{p.fee_structure?.fee_category?.name || 'Fee Challan Payment'}</p>
                                                    <p className="text-xs text-slate-400">{p.fee_structure?.academic_year} {p.month_year ? `· ${p.month_year}` : ''}</p>
                                                </TableCell>
                                                <TableCell className="text-right text-sm">{formatMoney(p.amount_due)}</TableCell>
                                                <TableCell className="text-right text-sm font-semibold text-emerald-600">{formatMoney(p.amount_paid)}</TableCell>
                                                <TableCell className={`text-right text-sm font-medium ${Number(balance) > 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                                                    {Number(balance) > 0 ? formatMoney(balance) : '—'}
                                                </TableCell>
                                                <TableCell className="text-xs text-slate-500">{METHOD_LABELS[p.method] ?? p.method}</TableCell>
                                                <TableCell className="text-xs text-slate-500">
                                                    {p.payment_date ? new Date(p.payment_date).toLocaleDateString() : '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge className={`border-0 text-xs capitalize ${STATUS_STYLE[p.status] ?? ''}`}>{p.status}</Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Link href={`/school/fees/payments/${p.id}`}>
                                                        <Button variant="outline" size="sm" className="text-xs h-8">Receipt</Button>
                                                    </Link>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination for Payments */}
                        {(payments.last_page ?? payments.meta?.last_page ?? 1) > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200 dark:border-slate-800">
                                <p className="text-xs text-slate-500">
                                    Showing {payments.from ?? payments.meta?.from}–{payments.to ?? payments.meta?.to} of {paymentTotal}
                                </p>
                                <div className="flex gap-1">
                                    {payments.prev_page_url && (
                                        <Button variant="outline" size="sm" onClick={() => router.get(payments.prev_page_url!)}>Previous</Button>
                                    )}
                                    {payments.next_page_url && (
                                        <Button variant="outline" size="sm" onClick={() => router.get(payments.next_page_url!)}>Next</Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
