import { useState } from 'react';
import { router, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { ArrowLeft, Plus, Users, Printer, FileText, CheckCircle2 } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import type { SchoolClass } from '@/Types';

interface ChallanRow {
    id: number;
    challan_no: string;
    billing_period_key: string;
    student_name: string;
    admission_no: string;
    class_name: string;
    section_name: string | null;
    academic_year_name: string;
    total_payable: string;
    paid_amount: string;
    status: string;
    display_status?: string;
    settlement_classification?: string;
    issue_date: string;
    due_date: string;
    items?: { id: number; fee_head_name: string; gross_amount: string; net_amount: string }[];
}

interface AcademicYear {
    id: number;
    name: string;
    is_current: boolean;
}

interface Props {
    challans: { data: ChallanRow[]; meta?: { total: number; current_page: number; last_page: number } };
    classes: SchoolClass[];
    academicYears: AcademicYear[];
    currentYear?: AcademicYear;
    filters: { class_id?: string; status?: string; month?: string };
}

export default function FeeChallansIndex({ challans, classes, academicYears, currentYear, filters }: Props) {
    const { format: formatMoney } = useCurrency();
    const [showBulkModal, setShowBulkModal] = useState(false);

    const { data: bulkData, setData: setBulkData, post: postBulk, processing: bulkProcessing, errors: bulkErrors, reset: resetBulk } = useForm({
        class_id:         '',
        section_id:       '',
        academic_year_id: currentYear?.id ? String(currentYear.id) : '',
        month:            new Date().toISOString().slice(0, 7),
        due_date:         '',
    });

    function applyFilter(key: string, value: string) {
        router.get('/school/fees/challans', { ...filters, [key]: value || undefined }, { preserveScroll: true });
    }

    function handleBulkSubmit(e: React.FormEvent) {
        e.preventDefault();
        postBulk('/school/fees/challans/bulk', {
            onSuccess: () => {
                setShowBulkModal(false);
                resetBulk();
            },
        });
    }

    return (
        <AppLayout title="Fee Challans">
            <div className="space-y-6">
                <div className="flex items-center justify-between flex-wrap gap-4">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/payments" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Payments
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Fee Challans</h1>
                    </div>
                    <div className="flex gap-2">
                        {filters.class_id && (
                            <Link href={`/school/fees/challans-print-bulk?class_id=${filters.class_id}${filters.month ? `&month=${filters.month}` : ''}`}>
                                <Button variant="outline" size="sm" className="inline-flex items-center gap-2">
                                    <Printer className="w-4 h-4" /> Print Filtered Class
                                </Button>
                            </Link>
                        )}
                        <Button
                            onClick={() => setShowBulkModal(true)}
                            className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs inline-flex items-center gap-2"
                        >
                            <Users className="w-4 h-4" /> Generate Bulk Challans
                        </Button>
                    </div>
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
                            <SelectItem value="unpaid">Unpaid</SelectItem>
                            <SelectItem value="partial">Partial</SelectItem>
                            <SelectItem value="paid">Paid</SelectItem>
                            <SelectItem value="void">Void</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50 dark:bg-slate-900">
                                <TableHead>Challan No</TableHead>
                                <TableHead>Student</TableHead>
                                <TableHead>Class</TableHead>
                                <TableHead>Period</TableHead>
                                <TableHead className="text-right">Payable</TableHead>
                                <TableHead className="text-right">Paid</TableHead>
                                <TableHead className="text-right">Balance</TableHead>
                                <TableHead>Due Date</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {challans.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={10} className="text-center py-10 text-slate-400">
                                        No challans found. Click "Generate Bulk Challans" to issue fees for a class.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                challans.data.map(c => {
                                    const bal = Math.max(0, Number(c.total_payable) - Number(c.paid_amount));
                                    return (
                                        <TableRow key={c.id}>
                                            <TableCell className="font-mono font-medium text-xs text-indigo-600">
                                                #{c.challan_no}
                                            </TableCell>
                                            <TableCell>
                                                <p className="font-medium text-sm text-slate-900 dark:text-white">{c.student_name}</p>
                                                <p className="text-xs text-slate-400">{c.admission_no}</p>
                                            </TableCell>
                                            <TableCell className="text-sm text-slate-600 dark:text-slate-400">
                                                {c.class_name} {c.section_name ? `(${c.section_name})` : ''}
                                            </TableCell>
                                            <TableCell className="text-xs text-slate-500 font-mono">
                                                {c.billing_period_key}
                                            </TableCell>
                                            <TableCell className="text-right text-sm font-semibold">{formatMoney(Number(c.total_payable))}</TableCell>
                                            <TableCell className="text-right text-sm text-green-600">{formatMoney(Number(c.paid_amount))}</TableCell>
                                            <TableCell className={`text-right text-sm font-bold ${bal > 0 ? 'text-red-600' : 'text-green-600'}`}>
                                                {formatMoney(bal)}
                                            </TableCell>
                                            <TableCell className="text-xs text-slate-500">
                                                {new Date(c.due_date).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={`border-0 text-xs font-medium ${
                                                    (c.display_status ?? c.status) === 'waived' ? 'bg-purple-100 text-purple-700 border border-purple-200' :
                                                    (c.display_status ?? c.status) === 'paid_adjusted' ? 'bg-teal-100 text-teal-700 border border-teal-200' :
                                                    c.status === 'paid' ? 'bg-green-100 text-green-700' :
                                                    c.status === 'partial' ? 'bg-amber-100 text-amber-700' :
                                                    c.status === 'void' ? 'bg-slate-200 text-slate-600' :
                                                    'bg-red-100 text-red-700'
                                                }`}>
                                                    {c.settlement_classification ?? (c.display_status || c.status)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Link href={`/school/fees/challans/${c.id}`}>
                                                    <Button size="sm" variant="outline" className="text-xs">View</Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Bulk Generate Modal */}
                {showBulkModal && (
                    <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
                        <div className="bg-white dark:bg-slate-900 rounded-xl max-w-md w-full p-6 space-y-4 shadow-xl border border-slate-200 dark:border-slate-800">
                            <h3 className="font-bold text-lg text-slate-900 dark:text-white">Generate Bulk Challans</h3>
                            <p className="text-xs text-slate-500">
                                Generates individual fee challans for all active students in the selected class based on active fee structures and student concessions.
                            </p>
                            <form onSubmit={handleBulkSubmit} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label>Class <span className="text-red-500">*</span></Label>
                                    <Select value={bulkData.class_id} onValueChange={v => setBulkData('class_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select Class" /></SelectTrigger>
                                        <SelectContent>
                                            {classes.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                    {bulkErrors.class_id && <p className="text-xs text-red-500">{bulkErrors.class_id}</p>}
                                </div>

                                <div className="space-y-1.5">
                                    <Label>Academic Year <span className="text-red-500">*</span></Label>
                                    <Select value={bulkData.academic_year_id} onValueChange={v => setBulkData('academic_year_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select Academic Year" /></SelectTrigger>
                                        <SelectContent>
                                            {academicYears.map(y => (
                                                <SelectItem key={y.id} value={String(y.id)}>
                                                    {y.name} {y.is_current ? '(Current)' : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {bulkErrors.academic_year_id && <p className="text-xs text-red-500">{bulkErrors.academic_year_id}</p>}
                                </div>

                                <div className="space-y-1.5">
                                    <Label>Billing Month <span className="text-red-500">*</span></Label>
                                    <Input
                                        type="month"
                                        value={bulkData.month}
                                        onChange={e => setBulkData('month', e.target.value)}
                                    />
                                    {bulkErrors.month && <p className="text-xs text-red-500">{bulkErrors.month}</p>}
                                </div>

                                <div className="space-y-1.5">
                                    <Label>Due Date (Optional)</Label>
                                    <Input
                                        type="date"
                                        value={bulkData.due_date}
                                        onChange={e => setBulkData('due_date', e.target.value)}
                                    />
                                </div>

                                <div className="flex justify-end gap-2 pt-2">
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setShowBulkModal(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={bulkProcessing} className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs">
                                        {bulkProcessing ? 'Generating...' : 'Generate Challans'}
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
