import { router, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { ArrowLeft, AlertCircle, Users, DollarSign, FileText, Tag } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import type { SchoolClass } from '@/Types';

interface OutstandingRow {
    id: number;
    type: 'challan' | 'legacy';
    challan_no: string | null;
    billing_period_label?: string;
    academic_year_name?: string | null;
    due_date: string | null;
    is_overdue: boolean;
    status: string;
    student: {
        id: number;
        first_name: string;
        last_name: string | null;
        admission_no: string;
        school_class?: { name: string };
        schoolClass?: { name: string };
        section?: { name: string };
    };
    gross_amount: number | string;
    discount_amount: number | string;
    adjustment_amount?: number | string;
    total_due: number | string;
    total_paid: number | string;
    balance: number | string;
    heads_breakdown?: string;
}

interface Props {
    outstanding: OutstandingRow[];
    classes: SchoolClass[];
    filters: { class_id?: string };
    summary: { total_students: number; total_outstanding: number | string };
}

export default function OutstandingFees({ outstanding, classes, filters, summary }: Props) {
    const { format: formatMoney } = useCurrency();

    function applyFilter(key: string, value: string) {
        router.get('/school/fees/outstanding', { ...filters, [key]: value || undefined }, { preserveScroll: true });
    }

    return (
        <AppLayout title="Outstanding Fees">
            <div className="space-y-6">
                <div className="flex items-center justify-between flex-wrap gap-4">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/payments" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Payments Hub
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Outstanding Fees</h1>
                    </div>
                    <div className="flex gap-2">
                        <Link href="/school/fees/payments?tab=challans">
                            <Button variant="outline" size="sm" className="inline-flex items-center gap-2">
                                <FileText className="w-4 h-4" /> All Challans
                            </Button>
                        </Link>
                        <Link href="/school/fees/discounts">
                            <Button variant="outline" size="sm" className="inline-flex items-center gap-2">
                                <Tag className="w-4 h-4" /> Concessions
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Card className="border-slate-200 dark:border-slate-800">
                        <CardContent className="p-4 flex items-center gap-3">
                            <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-red-500"><Users className="w-5 h-5" /></div>
                            <div>
                                <p className="text-2xl font-bold text-red-600">{summary.total_students}</p>
                                <p className="text-xs text-slate-500">Students with Pending Dues</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-slate-200 dark:border-slate-800">
                        <CardContent className="p-4 flex items-center gap-3">
                            <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-red-500"><DollarSign className="w-5 h-5" /></div>
                            <div>
                                <p className="text-xl font-bold text-red-600">{formatMoney(Number(summary.total_outstanding))}</p>
                                <p className="text-xs text-slate-500">Total Outstanding Balance</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter */}
                <div className="flex gap-3">
                    <Select value={filters.class_id ?? ''} onValueChange={v => applyFilter('class_id', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All Classes" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">All Classes</SelectItem>
                            {classes.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>

                {outstanding.length === 0 ? (
                    <div className="rounded-xl border border-green-200 dark:border-green-900 bg-green-50 dark:bg-green-950/20 py-16 text-center">
                        <AlertCircle className="w-12 h-12 mx-auto text-green-300 mb-3" />
                        <p className="text-green-600 font-medium">No outstanding fees!</p>
                        <p className="text-sm text-green-500 mt-1">All fees have been collected.</p>
                    </div>
                ) : (
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-slate-50 dark:bg-slate-900">
                                    <TableHead className="w-12">#</TableHead>
                                    <TableHead>Challan No</TableHead>
                                    <TableHead>Student</TableHead>
                                    <TableHead>Class / Section</TableHead>
                                    <TableHead>Billing Period</TableHead>
                                    <TableHead>Due Date</TableHead>
                                    <TableHead className="text-right">Total Due</TableHead>
                                    <TableHead className="text-right">Paid</TableHead>
                                    <TableHead className="text-right">Outstanding</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {outstanding.map((row, idx) => {
                                    const className = row.student?.schoolClass?.name || row.student?.school_class?.name || '—';
                                    const sectionName = row.student?.section?.name || '';
                                    const collectUrl = row.type === 'challan'
                                        ? `/school/fees/payments/collect?student_id=${row.student?.id}&challan_id=${row.id}`
                                        : `/school/fees/payments/collect?student_id=${row.student?.id}`;

                                    return (
                                        <TableRow key={`${row.type}-${row.id}`} className={row.is_overdue ? "bg-red-50/40 dark:bg-red-950/10" : undefined}>
                                            <TableCell className="text-slate-400 text-xs">{idx + 1}</TableCell>
                                            <TableCell>
                                                {row.challan_no ? (
                                                    <span className="font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                                        {row.challan_no}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-slate-500 italic">Direct Entry</span>
                                                )}
                                                {row.heads_breakdown && (
                                                    <p className="text-xs text-slate-400 line-clamp-1">{row.heads_breakdown}</p>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Link href={`/school/students/${row.student?.id}?tab=fees`} className="hover:text-indigo-600 font-medium text-slate-900 dark:text-white text-sm">
                                                    {row.student?.first_name} {row.student?.last_name}
                                                </Link>
                                                <p className="text-xs text-slate-400">ID: {row.student?.admission_no}</p>
                                            </TableCell>
                                            <TableCell className="text-slate-600 dark:text-slate-400 text-sm">
                                                {className}{sectionName ? ` · ${sectionName}` : ''}
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                                    {row.billing_period_label || '—'}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1.5">
                                                    <span className="text-xs text-slate-600 dark:text-slate-400">
                                                        {row.due_date ? new Date(row.due_date).toLocaleDateString() : '—'}
                                                    </span>
                                                    {row.is_overdue && (
                                                        <Badge variant="destructive" className="text-[10px] px-1.5 py-0 h-4">
                                                            Overdue
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right text-sm text-slate-700 dark:text-slate-300 font-medium">
                                                <div>{formatMoney(Number(row.total_due))}</div>
                                                {Number(row.adjustment_amount ?? 0) > 0 && (
                                                    <span className="inline-block text-[10px] text-indigo-600 dark:text-indigo-400 font-medium">
                                                        Adj: -{formatMoney(Number(row.adjustment_amount))}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-sm text-emerald-600">
                                                {formatMoney(Number(row.total_paid))}
                                            </TableCell>
                                            <TableCell className="text-right font-bold text-red-600">
                                                {formatMoney(Number(row.balance))}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Link href={collectUrl}>
                                                        <Button size="sm" className="text-xs bg-indigo-600 hover:bg-indigo-700 text-white h-8">
                                                            Collect
                                                        </Button>
                                                    </Link>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
