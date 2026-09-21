import { useState } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Plus, MoreHorizontal, Pencil, Trash2, ArrowLeft, Settings2, Users, Copy } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { useCurrency } from '@/lib/currency';
import type { SchoolClass, PageProps, PaginatedResponse } from '@/Types';

interface FeeCategory { id: number; name: string; type: string; }
interface FeeStructure {
    id: number; class_id: number; fee_category_id: number; academic_year: string;
    amount: string; due_date: string | null; frequency: string; description: string | null; is_active: boolean;
    is_optional: boolean;
    admission_voucher_policy: 'required' | 'optional' | 'excluded';
    school_class?: { name: string }; fee_category?: FeeCategory;
}

interface Props {
    structures: PaginatedResponse<FeeStructure>;
    classes: SchoolClass[];
    categories: FeeCategory[];
    filters: { class_id?: string; category_id?: string; academic_year?: string };
    currentYear: string;
}

const FREQ_LABELS: Record<string, string> = {
    monthly: 'Monthly', quarterly: 'Quarterly', annual: 'Annual', one_time: 'One-Time',
};
const FREQ_COLORS: Record<string, string> = {
    monthly: 'bg-blue-100 text-blue-700', quarterly: 'bg-purple-100 text-purple-700',
    annual: 'bg-teal-100 text-teal-700', one_time: 'bg-amber-100 text-amber-700',
};

export default function FeeStructures({ structures, classes, categories, filters, currentYear }: Props) {
    const { flash } = usePage<PageProps>().props;
    const { currency, format: formatMoney } = useCurrency();
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<FeeStructure | null>(null);

    // Copy structure modal state
    const [copyOpen, setCopyOpen] = useState(false);
    const [copyingStructure, setCopyingStructure] = useState<FeeStructure | null>(null);
    const [selectedCopyClassIds, setSelectedCopyClassIds] = useState<number[]>([]);
    const [copying, setCopying] = useState(false);

    const { data, setData, post, put, delete: destroy, processing, errors, reset } = useForm({ 
        class_id: '', fee_category_id: '', academic_year: currentYear || '2025-2026', amount: '', due_date: '', frequency: 'monthly', description: '', is_active: true, is_optional: false,
        admission_voucher_policy: 'optional' as 'required' | 'optional' | 'excluded',
    });

    function applyFilter(key: string, value: string) {
        router.get('/school/fees/structures', { ...filters, [key]: value || undefined }, { preserveScroll: true });
    }

    function openCreate() { 
        reset(); 
        setData({
            class_id: classes[0] ? String(classes[0].id) : '',
            fee_category_id: categories[0] ? String(categories[0].id) : '',
            academic_year: currentYear || '2025-2026',
            amount: '',
            due_date: '',
            frequency: 'monthly',
            description: '',
            is_active: true,
            is_optional: false,
            admission_voucher_policy: 'optional',
        });
        setEditing(null); 
        setOpen(true); 
    }

    function openEdit(s: FeeStructure) {
        setData({
            class_id: String(s.class_id), fee_category_id: String(s.fee_category_id),
            academic_year: s.academic_year, amount: s.amount,
            due_date: s.due_date ?? '', frequency: s.frequency,
            description: s.description ?? '', is_active: s.is_active,
            is_optional: Boolean(s.is_optional),
            admission_voucher_policy: s.admission_voucher_policy || 'optional',
        });
        setEditing(s);
        setOpen(true);
    }

    function openCopy(s: FeeStructure) {
        setCopyingStructure(s);
        setSelectedCopyClassIds([]);
        setCopyOpen(true);
    }

    function handleCopySubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!copyingStructure || selectedCopyClassIds.length === 0) return;

        setCopying(true);
        router.post(`/school/fees/structures/${copyingStructure.id}/copy`, {
            target_class_ids: selectedCopyClassIds,
        }, {
            onSuccess: () => {
                setCopyOpen(false);
                setCopyingStructure(null);
                setSelectedCopyClassIds([]);
            },
            onFinish: () => setCopying(false),
        });
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (editing) {
            put(`/school/fees/structures/${editing.id}`, { onSuccess: () => { setOpen(false); setEditing(null); reset(); } });
        } else {
            post('/school/fees/structures', { onSuccess: () => { setOpen(false); reset(); } });
        }
    }

    function handleDelete(s: FeeStructure) {
        if (!confirm(`Delete fee structure for "${s.fee_category?.name}"?`)) return;
        destroy(`/school/fees/structures/${s.id}`);
    }

    // Helper names for form Select values to avoid raw numeric ID bug
    const selectedClassName = classes.find(c => String(c.id) === String(data.class_id))?.name || 'Select class';
    const selectedCategoryName = categories.find(c => String(c.id) === String(data.fee_category_id))?.name || 'Select category';

    return (
        <AppLayout title="Fee Structures">
            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/payments" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Payments
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <div>
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Fee Structures</h1>
                            <p className="text-sm text-slate-500">Define fees for each class and academic year</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/school/fees/structures/bulk-assign">
                            <Button className="bg-emerald-600 hover:bg-emerald-700 text-white inline-flex items-center gap-2">
                                <Users className="w-4 h-4" /> Assign to Existing Students
                            </Button>
                        </Link>
                        <Link href="/school/fees/categories">
                            <Button variant="outline" className="inline-flex items-center gap-2"><Settings2 className="w-4 h-4" /> Categories</Button>
                        </Link>
                        <Button onClick={openCreate} className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-2">
                            <Plus className="w-4 h-4" /> Add Structure
                        </Button>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">{flash.success}</div>
                )}

                <div className="flex gap-3 flex-wrap">
                    <Select value={filters.class_id || 'all'} onValueChange={v => applyFilter('class_id', v === 'all' ? '' : v)}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Classes">
                                {classes.find(c => String(c.id) === String(filters.class_id))?.name || 'All Classes'}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Classes</SelectItem>
                            {classes.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.category_id || 'all'} onValueChange={v => applyFilter('category_id', v === 'all' ? '' : v)}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All Categories">
                                {categories.find(c => String(c.id) === String(filters.category_id))?.name || 'All Categories'}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Categories</SelectItem>
                            {categories.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Input
                        className="w-36"
                        placeholder="Year e.g. 2025-2026"
                        defaultValue={filters.academic_year ?? ''}
                        onBlur={e => applyFilter('academic_year', e.target.value)}
                    />
                </div>

                <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50 dark:bg-slate-900">
                                <TableHead>Category / Type</TableHead>
                                <TableHead>Class</TableHead>
                                <TableHead>Academic Year</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                                <TableHead>Frequency</TableHead>
                                <TableHead>Assignment</TableHead>
                                <TableHead>First Voucher</TableHead>
                                <TableHead>Due Date</TableHead>
                                <TableHead className="text-center">Status</TableHead>
                                <TableHead className="w-12"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {structures.data.length === 0 ? (
                                <TableRow><TableCell colSpan={10} className="text-center py-16 text-slate-400">No fee structures configured yet.</TableCell></TableRow>
                            ) : structures.data.map(s => (
                                <TableRow key={s.id}>
                                    <TableCell>
                                        <p className="font-medium text-slate-900 dark:text-white">{s.fee_category?.name}</p>
                                        <p className="text-xs text-slate-400 capitalize">{s.fee_category?.type}</p>
                                    </TableCell>
                                    <TableCell className="text-slate-600 dark:text-slate-400">{s.school_class?.name}</TableCell>
                                    <TableCell className="text-slate-600 dark:text-slate-400">{s.academic_year}</TableCell>
                                    <TableCell className="text-right font-semibold text-slate-900 dark:text-white">
                                        {formatMoney(s.amount)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge className={`border-0 text-xs ${FREQ_COLORS[s.frequency] ?? ''}`}>{FREQ_LABELS[s.frequency] ?? s.frequency}</Badge>
                                    </TableCell>
                                    <TableCell>
                                        {s.is_optional ? (
                                             <Badge variant="outline" className="border-amber-300 bg-amber-50 text-amber-800 text-xs">Optional</Badge>
                                        ) : (
                                            <Badge variant="outline" className="border-indigo-300 bg-indigo-50 text-indigo-800 text-xs">Required</Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {s.admission_voucher_policy === 'required' && (
                                            <Badge variant="outline" className="border-indigo-300 bg-indigo-50 text-indigo-800 text-xs">Required</Badge>
                                        )}
                                        {s.admission_voucher_policy === 'excluded' && (
                                            <Badge variant="outline" className="border-slate-300 bg-slate-100 text-slate-700 text-xs">Later Billing</Badge>
                                        )}
                                        {(!s.admission_voucher_policy || s.admission_voucher_policy === 'optional') && (
                                            <Badge variant="outline" className="border-amber-300 bg-amber-50 text-amber-800 text-xs">Optional</Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-slate-500 text-sm">
                                        {s.due_date ? new Date(s.due_date).toLocaleDateString() : '—'}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        <Badge className={`border-0 text-xs ${s.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                                            {s.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="w-8 h-8"><MoreHorizontal className="w-4 h-4" /></Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem asChild>
                                                    <Link href={`/school/fees/structures/bulk-assign?structure_id=${s.id}`} className="flex items-center gap-2 text-sm text-emerald-700 font-medium cursor-pointer">
                                                        <Users className="w-4 h-4 text-emerald-600" /> Assign to Students
                                                    </Link>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={() => openCopy(s)} className="flex items-center gap-2 text-sm cursor-pointer">
                                                    <Copy className="w-4 h-4 text-indigo-600" /> Copy to Other Classes
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={() => openEdit(s)} className="flex items-center gap-2 text-sm cursor-pointer">
                                                    <Pencil className="w-4 h-4" /> Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={() => handleDelete(s)} className="flex items-center gap-2 text-sm text-red-600 focus:text-red-600 cursor-pointer">
                                                    <Trash2 className="w-4 h-4" /> Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            {/* Responsive Add / Edit Fee Structure Dialog */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-2xl max-h-[90vh] flex flex-col p-0 overflow-hidden">
                    <DialogHeader className="p-6 pb-4 border-b border-slate-200 dark:border-slate-800 shrink-0 bg-white dark:bg-slate-950">
                        <DialogTitle>{editing ? 'Edit Structure' : 'Add Fee Structure'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
                        <div className="flex-1 overflow-y-auto p-6 space-y-4">
                            {/* Responsive 2-column desktop / 1-column mobile */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label>Class <span className="text-red-500">*</span></Label>
                                    <Select value={data.class_id} onValueChange={v => setData('class_id', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select class">
                                                {selectedClassName}
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            {classes.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                    {errors.class_id && <p className="text-xs text-red-500">{errors.class_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Category <span className="text-red-500">*</span></Label>
                                    <Select value={data.fee_category_id} onValueChange={v => setData('fee_category_id', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select category">
                                                {selectedCategoryName}
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                    {errors.fee_category_id && <p className="text-xs text-red-500">{errors.fee_category_id}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label>Academic Year <span className="text-red-500">*</span></Label>
                                    <Input value={data.academic_year} onChange={e => setData('academic_year', e.target.value)} placeholder="2025-2026" />
                                    {errors.academic_year && <p className="text-xs text-red-500">{errors.academic_year}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Amount ({currency}) <span className="text-red-500">*</span></Label>
                                    <Input type="number" min="0" step="0.01" value={data.amount} onChange={e => setData('amount', e.target.value)} placeholder="0.00" />
                                    {errors.amount && <p className="text-xs text-red-500">{errors.amount}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label>Frequency <span className="text-red-500">*</span></Label>
                                    <Select value={data.frequency} onValueChange={v => setData('frequency', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="monthly">Monthly</SelectItem>
                                            <SelectItem value="quarterly">Quarterly</SelectItem>
                                            <SelectItem value="annual">Annual</SelectItem>
                                            <SelectItem value="one_time">One-Time</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Due Date</Label>
                                    <Input type="date" value={data.due_date} onChange={e => setData('due_date', e.target.value)} />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label>Description</Label>
                                <Input value={data.description} onChange={e => setData('description', e.target.value)} placeholder="Optional description" />
                            </div>

                            <div className="space-y-2 rounded-lg border border-slate-200 dark:border-slate-800 p-3 bg-slate-50/50 dark:bg-slate-900/50">
                                <Label className="text-sm font-medium text-slate-900 dark:text-white">Student Assignment</Label>
                                <div className="space-y-2">
                                    <label className="flex items-start gap-2.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="student_assignment"
                                            checked={!data.is_optional}
                                            onChange={() => setData('is_optional', false)}
                                            className="mt-1 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div>
                                            <span className="text-sm font-medium text-slate-900 dark:text-white">Required for students in this class</span>
                                            <p className="text-xs text-slate-500">Automatically assigned to every enrolled student.</p>
                                        </div>
                                    </label>
                                    <label className="flex items-start gap-2.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="student_assignment"
                                            checked={!!data.is_optional}
                                            onChange={() => setData('is_optional', true)}
                                            className="mt-1 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div>
                                            <span className="text-sm font-medium text-slate-900 dark:text-white">Optional / assigned individually</span>
                                            <p className="text-xs text-slate-500">Assigned per-student during admission or profile updates (transport, clubs, etc.).</p>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <div className="space-y-2 rounded-lg border border-slate-200 dark:border-slate-800 p-3 bg-slate-50/50 dark:bg-slate-900/50">
                                <Label className="text-sm font-medium text-slate-900 dark:text-white">First Admission Voucher</Label>
                                <div className="space-y-2">
                                    <label className="flex items-start gap-2.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="admission_voucher_policy"
                                            checked={data.admission_voucher_policy === 'required'}
                                            onChange={() => setData('admission_voucher_policy', 'required')}
                                            className="mt-1 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div>
                                            <span className="text-sm font-medium text-slate-900 dark:text-white">Required on first voucher</span>
                                            <p className="text-xs text-slate-500">Must be included on the student's initial admission voucher.</p>
                                        </div>
                                    </label>
                                    <label className="flex items-start gap-2.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="admission_voucher_policy"
                                            checked={data.admission_voucher_policy === 'optional'}
                                            onChange={() => setData('admission_voucher_policy', 'optional')}
                                            className="mt-1 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div>
                                            <span className="text-sm font-medium text-slate-900 dark:text-white">Optional on first voucher</span>
                                            <p className="text-xs text-slate-500">Operator can choose whether to bill this immediately at admission or during regular billing.</p>
                                        </div>
                                    </label>
                                    <label className="flex items-start gap-2.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="admission_voucher_policy"
                                            checked={data.admission_voucher_policy === 'excluded'}
                                            onChange={() => setData('admission_voucher_policy', 'excluded')}
                                            className="mt-1 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div>
                                            <span className="text-sm font-medium text-slate-900 dark:text-white">Do not charge at admission</span>
                                            <p className="text-xs text-slate-500">Deferred to regular billing cycles (Later Billing, such as exam or annual fees).</p>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <input type="checkbox" id="is_active_struct" checked={data.is_active} onChange={e => setData('is_active', e.target.checked)} className="w-4 h-4 rounded text-indigo-600" />
                                <Label htmlFor="is_active_struct" className="cursor-pointer">Active</Label>
                            </div>
                        </div>

                        <DialogFooter className="p-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 shrink-0 sticky bottom-0">
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing} className="bg-indigo-600 hover:bg-indigo-700 text-white">
                                {processing ? 'Saving...' : editing ? 'Update' : 'Add'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Copy / Create Structure for Other Classes Dialog */}
            <Dialog open={copyOpen} onOpenChange={setCopyOpen}>
                <DialogContent className="sm:max-w-lg max-h-[85vh] flex flex-col p-0 overflow-hidden">
                    <DialogHeader className="p-6 pb-4 border-b border-slate-200 dark:border-slate-800 shrink-0 bg-white dark:bg-slate-950">
                        <DialogTitle>Copy Fee Structure to Other Classes</DialogTitle>
                    </DialogHeader>
                    {copyingStructure && (
                        <form onSubmit={handleCopySubmit} className="flex flex-col flex-1 overflow-hidden">
                            <div className="flex-1 overflow-y-auto p-6 space-y-4">
                                <div className="rounded-lg bg-indigo-50 border border-indigo-100 p-3 text-sm text-indigo-900">
                                    <p className="font-semibold">{copyingStructure.fee_category?.name} ({FREQ_LABELS[copyingStructure.frequency] ?? copyingStructure.frequency})</p>
                                    <p className="text-xs text-indigo-700 mt-0.5">
                                        Source: {copyingStructure.school_class?.name} • AY {copyingStructure.academic_year} • {formatMoney(copyingStructure.amount)}
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label className="text-sm font-semibold">Select Destination Classes</Label>
                                    <p className="text-xs text-slate-500">
                                        Choose the other classes where an equivalent Fee Structure should be created.
                                    </p>
                                    <div className="grid grid-cols-2 gap-2 mt-2 max-h-48 overflow-y-auto border border-slate-200 rounded-lg p-2.5">
                                        {classes
                                            .filter(c => c.id !== copyingStructure.class_id)
                                            .map(c => {
                                                const checked = selectedCopyClassIds.includes(c.id);
                                                return (
                                                    <label key={c.id} className="flex items-center gap-2 p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer text-sm">
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            onChange={e => {
                                                                if (e.target.checked) {
                                                                    setSelectedCopyClassIds([...selectedCopyClassIds, c.id]);
                                                                } else {
                                                                    setSelectedCopyClassIds(selectedCopyClassIds.filter(id => id !== c.id));
                                                                }
                                                            }}
                                                            className="w-4 h-4 rounded text-indigo-600"
                                                        />
                                                        <span>{c.name}</span>
                                                    </label>
                                                );
                                            })}
                                    </div>
                                    {selectedCopyClassIds.length > 0 && (
                                        <p className="text-xs text-emerald-600 font-medium">
                                            {selectedCopyClassIds.length} class(es) selected for replication.
                                        </p>
                                    )}
                                </div>
                            </div>
                            <DialogFooter className="p-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 shrink-0 sticky bottom-0">
                                <Button type="button" variant="ghost" onClick={() => setCopyOpen(false)}>Cancel</Button>
                                <Button
                                    type="submit"
                                    disabled={copying || selectedCopyClassIds.length === 0}
                                    className="bg-indigo-600 hover:bg-indigo-700 text-white"
                                >
                                    {copying ? 'Copying...' : `Copy to ${selectedCopyClassIds.length} Class(es)`}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
