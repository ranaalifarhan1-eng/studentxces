import { useState } from 'react';
import { useForm, router, usePage, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Plus, MoreHorizontal, Pencil, Trash2, ArrowLeft, Percent, Filter } from 'lucide-react';
import type { PageProps } from '@/Types';
import { useCurrency } from '@/lib/currency';

interface StudentFeeDiscount {
    id: number;
    school_id: number;
    student_id: number;
    fee_category_id: number | null;
    academic_year_id: number | null;
    title: string;
    type: 'percentage' | 'fixed';
    value: string;
    is_active: boolean;
    student?: {
        id: number;
        first_name: string;
        last_name: string;
        admission_no: string;
        school_class?: { id: number; name: string };
    };
    fee_category?: { id: number; name: string } | null;
    academic_year?: { id: number; name: string } | null;
}

interface Props {
    discounts: {
        data: StudentFeeDiscount[];
        current_page: number;
        last_page: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    classes: { id: number; name: string }[];
    categories: { id: number; name: string }[];
    academicYears: { id: number; name: string; is_current: boolean }[];
    filters: { class_id?: string; status?: string };
}

export default function FeeDiscounts({ discounts, classes, categories, academicYears, filters }: Props) {
    const { flash } = usePage<PageProps>().props;
    const { format: formatMoney } = useCurrency();
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<StudentFeeDiscount | null>(null);

    const emptyForm = {
        student_id: '',
        fee_category_id: '',
        academic_year_id: academicYears.find(y => y.is_current)?.id ? String(academicYears.find(y => y.is_current)?.id) : '',
        title: '',
        type: 'percentage',
        value: '',
        is_active: true,
    };

    const { data, setData, post, put, processing, errors, reset } = useForm(emptyForm);

    function openCreate() {
        reset();
        setEditing(null);
        setOpen(true);
    }

    function openEdit(d: StudentFeeDiscount) {
        setData({
            student_id: String(d.student_id),
            fee_category_id: d.fee_category_id ? String(d.fee_category_id) : '',
            academic_year_id: d.academic_year_id ? String(d.academic_year_id) : '',
            title: d.title,
            type: d.type,
            value: String(d.value),
            is_active: d.is_active,
        });
        setEditing(d);
        setOpen(true);
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (editing) {
            put(`/school/fees/discounts/${editing.id}`, {
                onSuccess: () => {
                    setOpen(false);
                    setEditing(null);
                    reset();
                },
            });
        } else {
            post('/school/fees/discounts', {
                onSuccess: () => {
                    setOpen(false);
                    reset();
                },
            });
        }
    }

    function handleDelete(d: StudentFeeDiscount) {
        if (!confirm(`Remove discount "${d.title}" for ${d.student?.first_name} ${d.student?.last_name}?`)) return;
        router.delete(`/school/fees/discounts/${d.id}`);
    }

    function handleFilterChange(key: string, val: string) {
        const next = { ...filters, [key]: val === 'all' ? '' : val };
        router.get('/school/fees/discounts', next, { preserveState: true });
    }

    return (
        <AppLayout title="Fee Discounts & Concessions">
            <div className="max-w-6xl mx-auto space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between flex-wrap gap-4">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/payments" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Fees
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <div>
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Student Concessions & Discounts</h1>
                            <p className="text-xs text-slate-500">Manage individual student fee scholarships, kinship discounts, and fee concessions.</p>
                        </div>
                    </div>
                    <Button onClick={openCreate} className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-2">
                        <Plus className="w-4 h-4" /> Add Concession
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">{flash.success}</div>
                )}

                {/* Filters */}
                <div className="flex gap-4 items-center bg-white dark:bg-slate-950 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                    <Filter className="w-4 h-4 text-slate-400" />
                    <div className="w-48">
                        <Select value={filters.class_id || 'all'} onValueChange={v => handleFilterChange('class_id', v)}>
                            <SelectTrigger className="text-xs">
                                <SelectValue placeholder="All Classes" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Classes</SelectItem>
                                {classes.map(c => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="w-40">
                        <Select value={filters.status || 'all'} onValueChange={v => handleFilterChange('status', v)}>
                            <SelectTrigger className="text-xs">
                                <SelectValue placeholder="All Statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="active">Active Only</SelectItem>
                                <SelectItem value="inactive">Inactive Only</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Table */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50 dark:bg-slate-900">
                                <TableHead>Student</TableHead>
                                <TableHead>Concession Title</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Academic Year</TableHead>
                                <TableHead className="text-right">Concession Value</TableHead>
                                <TableHead className="text-center">Status</TableHead>
                                <TableHead className="w-12"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {discounts.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="text-center py-16 text-slate-400">
                                        No fee concessions found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                discounts.data.map(d => (
                                    <TableRow key={d.id}>
                                        <TableCell className="font-medium text-slate-900 dark:text-white">
                                            <div>
                                                <p>{d.student?.first_name} {d.student?.last_name}</p>
                                                <p className="text-xs font-mono text-slate-500">
                                                    Adm: {d.student?.admission_no} • {d.student?.school_class?.name ?? '—'}
                                                </p>
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-medium text-slate-800 dark:text-slate-200">
                                            {d.title}
                                        </TableCell>
                                        <TableCell className="text-xs text-slate-600 dark:text-slate-400">
                                            {d.fee_category ? d.fee_category.name : <span className="text-slate-400 italic">All Categories</span>}
                                        </TableCell>
                                        <TableCell className="text-xs text-slate-600 dark:text-slate-400">
                                            {d.academic_year ? d.academic_year.name : <span className="text-slate-400 italic">All Years</span>}
                                        </TableCell>
                                        <TableCell className="text-right font-semibold text-emerald-600">
                                            {d.type === 'percentage' ? `${d.value}%` : formatMoney(Number(d.value))}
                                        </TableCell>
                                        <TableCell className="text-center">
                                            <Badge className={`text-xs capitalize ${d.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                                                {d.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                                        <MoreHorizontal className="w-4 h-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem onClick={() => openEdit(d)} className="cursor-pointer">
                                                        <Pencil className="w-4 h-4 mr-2" /> Edit
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => handleDelete(d)} className="text-red-600 cursor-pointer">
                                                        <Trash2 className="w-4 h-4 mr-2" /> Delete
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Pagination */}
                {discounts.links && discounts.links.length > 3 && (
                    <div className="flex justify-center gap-1">
                        {discounts.links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.url || '#'}
                                className={`px-3 py-1.5 rounded text-xs border ${l.active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200'}`}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}

                {/* Create / Edit Dialog */}
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="max-w-md">
                        <DialogHeader>
                            <DialogTitle>{editing ? 'Edit Fee Concession' : 'New Fee Concession'}</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleSubmit} className="space-y-4 py-2">
                            {!editing && (
                                <div className="space-y-1">
                                    <Label htmlFor="student_id" className="text-xs">Student ID (Numeric) *</Label>
                                    <Input
                                        id="student_id"
                                        type="number"
                                        value={data.student_id}
                                        onChange={e => setData('student_id', e.target.value)}
                                        placeholder="Enter Student's database ID"
                                        required
                                        className="text-xs"
                                    />
                                    {errors.student_id && <p className="text-xs text-red-500">{errors.student_id}</p>}
                                </div>
                            )}

                            <div className="space-y-1">
                                <Label htmlFor="title" className="text-xs">Concession / Discount Title *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={e => setData('title', e.target.value)}
                                    placeholder="e.g. Kinship Discount (2nd Child), Merit Scholarship"
                                    required
                                    className="text-xs"
                                />
                                {errors.title && <p className="text-xs text-red-500">{errors.title}</p>}
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-xs">Discount Type *</Label>
                                    <Select value={data.type} onValueChange={v => setData('type', v)}>
                                        <SelectTrigger className="text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="percentage">Percentage (%)</SelectItem>
                                            <SelectItem value="fixed">Fixed Amount</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="value" className="text-xs">Discount Value *</Label>
                                    <Input
                                        id="value"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        value={data.value}
                                        onChange={e => setData('value', e.target.value)}
                                        placeholder={data.type === 'percentage' ? 'e.g. 20' : 'e.g. 1000'}
                                        required
                                        className="text-xs"
                                    />
                                    {errors.value && <p className="text-xs text-red-500">{errors.value}</p>}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label className="text-xs">Applicable Fee Category</Label>
                                <Select value={data.fee_category_id || 'all'} onValueChange={v => setData('fee_category_id', v === 'all' ? '' : v)}>
                                    <SelectTrigger className="text-xs">
                                        <SelectValue placeholder="All Categories (Entire Challan)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Categories (Entire Challan)</SelectItem>
                                        {categories.map(c => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label className="text-xs">Academic Year</Label>
                                <Select value={data.academic_year_id || 'all'} onValueChange={v => setData('academic_year_id', v === 'all' ? '' : v)}>
                                    <SelectTrigger className="text-xs">
                                        <SelectValue placeholder="All Academic Years" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Academic Years</SelectItem>
                                        {academicYears.map(y => (
                                            <SelectItem key={y.id} value={String(y.id)}>{y.name} {y.is_current ? '(Current)' : ''}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex items-center gap-2 pt-2">
                                <input
                                    type="checkbox"
                                    id="is_active"
                                    checked={data.is_active}
                                    onChange={e => setData('is_active', e.target.checked)}
                                    className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <Label htmlFor="is_active" className="text-xs font-normal">Active concession</Label>
                            </div>

                            <DialogFooter className="pt-4">
                                <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" size="sm" disabled={processing} className="bg-indigo-600 hover:bg-indigo-700 text-white">
                                    {processing ? 'Saving...' : editing ? 'Update Concession' : 'Add Concession'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
