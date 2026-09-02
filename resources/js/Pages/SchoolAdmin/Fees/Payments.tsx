import { router, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import {
    Plus, AlertCircle, Settings2, Tag, DollarSign, TrendingDown,
    CheckCircle2, Clock,
} from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import type { SchoolClass } from '@/Types';

interface Payment {
    id: number;
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
}

interface Props {
    payments: { data: Payment[]; meta?: { total: number; current_page: number; last_page: number } };
    classes: SchoolClass[];
    filters: { class_id?: string; status?: string; search?: string };
    stats: { total_collected: number; total_outstanding: number; paid_count: number; pending_count: number };
}

const STATUS_STYLE: Record<string, string> = {
    paid: 'bg-green-100 text-green-700',
    partial: 'bg-amber-100 text-amber-700',
    unpaid: 'bg-red-100 text-red-700',
    overdue: 'bg-red-100 text-red-700',
};
const METHOD_LABELS: Record<string, string> = {
    cash: 'Cash', card: 'Card', online: 'Online', bkash: 'bKash', nagad: 'Nagad', rocket: 'Rocket',
};

export default function FeePayments({ payments, classes, filters, stats }: Props) {
    const { format: formatMoney } = useCurrency();

    function applyFilter(key: string, value: string) {
        router.get('/school/fees/payments', { ...filters, [key]: value || undefined }, { preserveScroll: true });
    }

    const statCards = [
        { label: 'Total Collected', value: formatMoney(stats.total_collected ?? 0), color: 'text-green-600', icon: DollarSign },
        { label: 'Outstanding', value: formatMoney(Math.max(0, stats.total_outstanding ?? 0)), color: 'text-red-600', icon: TrendingDown },
        { label: 'Paid Receipts', value: stats.paid_count, color: 'text-indigo-600', icon: CheckCircle2 },
        { label: 'Pending', value: stats.pending_count, color: 'text-amber-600', icon: Clock },
    ];

    return (
        <AppLayout title="Fee Payments">
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Fee Management</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">{payments.meta?.total ?? 0} payment records</p>
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
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
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

                {/* Filters */}
                <div className="flex gap-3 flex-wrap">
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
                </div>

                {/* Payments Table */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50 dark:bg-slate-900">
                                <TableHead>#</TableHead>
                                <TableHead>Student</TableHead>
                                <TableHead>Fee Type</TableHead>
                                <TableHead className="text-right">Due</TableHead>
                                <TableHead className="text-right">Paid</TableHead>
                                <TableHead className="text-right">Balance</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-20"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payments.data.map((p, idx) => {
                                const netDue = Number(p.amount_due) + Number(p.fine) - Number(p.discount);
                                const balance = Math.max(0, netDue - Number(p.amount_paid));
                                return (
                                    <TableRow key={p.id}>
                                        <TableCell className="text-slate-400 text-xs">{idx + 1}</TableCell>
                                        <TableCell>
                                            <p className="font-medium text-sm text-slate-900 dark:text-white">{p.student?.first_name} {p.student?.last_name}</p>
                                            <p className="text-xs text-slate-400">{p.student?.school_class?.name} · ID: {p.student?.admission_no}</p>
                                        </TableCell>
                                        <TableCell>
                                            <p className="text-sm text-slate-700 dark:text-slate-300">{p.fee_structure?.fee_category?.name}</p>
                                            <p className="text-xs text-slate-400">{p.fee_structure?.academic_year} {p.month_year ? `· ${p.month_year}` : ''}</p>
                                        </TableCell>
                                        <TableCell className="text-right text-sm">{formatMoney(p.amount_due)}</TableCell>
                                        <TableCell className="text-right text-sm font-semibold text-green-600">{formatMoney(p.amount_paid)}</TableCell>
                                        <TableCell className={`text-right text-sm font-medium ${Number(balance) > 0 ? 'text-red-600' : 'text-green-600'}`}>
                                            {Number(balance) > 0 ? formatMoney(balance) : '—'}
                                        </TableCell>
                                        <TableCell className="text-xs text-slate-500">{METHOD_LABELS[p.method] ?? p.method}</TableCell>
                                        <TableCell className="text-xs text-slate-500">
                                            {p.payment_date ? new Date(p.payment_date).toLocaleDateString() : '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={`border-0 text-xs capitalize ${STATUS_STYLE[p.status] ?? ''}`}>{p.status}</Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Link href={`/school/fees/payments/${p.id}`}>
                                                <Button variant="outline" size="sm" className="text-xs">Receipt</Button>
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
