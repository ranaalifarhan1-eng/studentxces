import AppLayout from '@/Layouts/AppLayout';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { CreditCard, Plus, Edit, Trash2, ChevronLeft, ChevronRight, Activity, Clock, AlertTriangle, TrendingUp } from 'lucide-react';

interface School   { id: number; name: string; }
interface PkgPrice { id: number; term_months: number; base_monthly_price: string; discount_percent: string; total_price: string; currency: string; }
interface Pkg      { id: number; name: string; price_monthly: string; price_yearly: string; currency?: string; prices?: PkgPrice[]; }
interface Coupon   { id: number; code: string; type: string; value: string; }
interface Sub {
    id: number; status: string; is_trial: boolean; start_date: string; end_date: string;
    billing_term_months?: number | null; base_monthly_price?: string | null; discount_percent?: string | null;
    billed_amount?: string | null; currency?: string | null;
    amount_paid: string; payment_method: string | null; notes: string | null;
    school: School; package: Pkg; coupon: Coupon | null;
}
interface Meta { total: number; per_page: number; current_page: number; last_page: number; }
interface Kpi  { total: number; active: number; trial: number; expired: number; }
interface Props {
    subscriptions: { data: Sub[]; meta: Meta };
    schools: School[]; packages: Pkg[]; coupons: Coupon[];
    kpi: Kpi; filters: { school_id?: string; status?: string };
    canonicalTerms?: Record<number, number>;
}

const STATUS_COLORS: Record<string, string> = {
    active:    'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    trial:     'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    expired:   'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    suspended: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};

export default function SubscriptionsIndex({ subscriptions, schools, packages, coupons, kpi, filters }: Props) {
    const [showModal, setShowModal] = useState(false);
    const [editSub, setEditSub]     = useState<Sub | null>(null);
    const [delSub, setDelSub]       = useState<Sub | null>(null);
    const [schoolFilter, setSchoolFilter] = useState(filters.school_id ?? '');
    const [statusFilter, setStatusFilter] = useState(filters.status ?? '');

    const form = useForm({
        school_id: '',
        package_id: '',
        billing_term_months: '3',
        coupon_id: '',
        start_date: new Date().toISOString().split('T')[0],
        end_date: '',
        status: 'active',
        is_trial: false,
        trial_ends_at: '',
        amount_paid: '0',
        payment_method: '',
        notes: '',
    });

    function calculateEndDate(startDateStr: string, months: number): string {
        if (!startDateStr) return '';
        const d = new Date(startDateStr);
        d.setMonth(d.getMonth() + months);
        return d.toISOString().split('T')[0];
    }

    function handlePackageOrTermChange(pkgId: string, termMonthsStr: string, couponIdStr: string, startDateStr: string) {
        const pkg = packages.find(p => String(p.id) === pkgId);
        const term = parseInt(termMonthsStr, 10) || 3;
        const newEndDate = calculateEndDate(startDateStr || form.data.start_date, term);

        let calculatedAmount = 0;
        if (pkg) {
            const priceTerm = pkg.prices?.find(pr => pr.term_months === term);
            if (priceTerm) {
                calculatedAmount = parseFloat(priceTerm.total_price) || 0;
            } else {
                const base = parseFloat(pkg.price_monthly) || 0;
                const discount = term === 12 ? 0.10 : (term === 6 ? 0.05 : 0);
                calculatedAmount = Math.round(base * term * (1 - discount));
            }
        }

        // Apply coupon if selected
        if (couponIdStr && couponIdStr !== '_none') {
            const c = coupons.find(cp => String(cp.id) === couponIdStr);
            if (c) {
                if (c.type === 'percent') {
                    calculatedAmount = Math.round(calculatedAmount * (1 - parseFloat(c.value) / 100));
                } else {
                    calculatedAmount = Math.max(0, calculatedAmount - parseFloat(c.value));
                }
            }
        }

        form.setData(data => ({
            ...data,
            package_id: pkgId,
            billing_term_months: termMonthsStr,
            end_date: newEndDate,
            amount_paid: String(calculatedAmount),
            coupon_id: couponIdStr === '_none' ? '' : couponIdStr,
        }));
    }

    function applyFilters(overrides: Record<string, string> = {}) {
        router.get('/super-admin/subscriptions', { school_id: schoolFilter, status: statusFilter, ...overrides }, { preserveState: true, replace: true });
    }

    function openCreate() {
        form.reset();
        form.clearErrors();
        const today = new Date().toISOString().split('T')[0];
        const initialPkg = packages[0];
        const initialPkgId = initialPkg ? String(initialPkg.id) : '';
        const initialEnd = calculateEndDate(today, 3);
        
        let initialAmount = '0';
        if (initialPkg) {
            const p3 = initialPkg.prices?.find(pr => pr.term_months === 3);
            initialAmount = p3 ? String(Math.round(parseFloat(p3.total_price))) : String(Math.round((parseFloat(initialPkg.price_monthly) || 0) * 3));
        }

        form.setData({
            school_id: '',
            package_id: initialPkgId,
            billing_term_months: '3',
            coupon_id: '',
            start_date: today,
            end_date: initialEnd,
            status: 'active',
            is_trial: false,
            trial_ends_at: '',
            amount_paid: initialAmount,
            payment_method: '',
            notes: '',
        });
        setEditSub(null);
        setShowModal(true);
    }

    function openEdit(s: Sub) {
        form.setData({
            school_id: String(s.school.id),
            package_id: String(s.package.id),
            billing_term_months: s.billing_term_months ? String(s.billing_term_months) : '3',
            coupon_id: s.coupon ? String(s.coupon.id) : '',
            start_date: s.start_date,
            end_date: s.end_date,
            status: s.status,
            is_trial: s.is_trial,
            trial_ends_at: '',
            amount_paid: s.amount_paid,
            payment_method: s.payment_method ?? '',
            notes: s.notes ?? '',
        });
        form.clearErrors();
        setEditSub(s);
        setShowModal(true);
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (editSub) {
            form.put(`/super-admin/subscriptions/${editSub.id}`, { onSuccess: () => setShowModal(false) });
        } else {
            form.post('/super-admin/subscriptions', { onSuccess: () => setShowModal(false) });
        }
    }

    function confirmDelete() {
        if (!delSub) return;
        router.delete(`/super-admin/subscriptions/${delSub.id}`, { onSuccess: () => setDelSub(null) });
    }

    return (
        <AppLayout title="Subscriptions">
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Subscriptions</h1>
                        <p className="text-sm text-slate-500 mt-0.5">Manage school subscriptions and billing</p>
                    </div>
                    <Button onClick={openCreate} className="gap-2"><Plus className="w-4 h-4" /> Assign Subscription</Button>
                </div>

                {/* KPI */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    {[
                        { label: 'Total', value: kpi.total,   icon: TrendingUp,     color: 'text-indigo-500' },
                        { label: 'Active', value: kpi.active,  icon: Activity,       color: 'text-green-500' },
                        { label: 'Trial',  value: kpi.trial,   icon: Clock,          color: 'text-blue-500' },
                        { label: 'Expired',value: kpi.expired, icon: AlertTriangle,  color: 'text-red-500' },
                    ].map(k => (
                        <Card key={k.label}>
                            <CardContent className="pt-4 pb-4 flex items-center gap-3">
                                <k.icon className={`w-8 h-8 ${k.color} shrink-0`} />
                                <div>
                                    <p className="text-2xl font-bold text-slate-900 dark:text-white">{k.value}</p>
                                    <p className="text-xs text-slate-500">{k.label}</p>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-4 flex flex-wrap gap-3">
                        <Select value={schoolFilter || '_all'} onValueChange={v => { setSchoolFilter(v === '_all' ? '' : v); applyFilters({ school_id: v === '_all' ? '' : v }); }}>
                            <SelectTrigger className="w-52"><SelectValue placeholder="All Schools" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="_all">All Schools</SelectItem>
                                {schools.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <Select value={statusFilter || '_all'} onValueChange={v => { setStatusFilter(v === '_all' ? '' : v); applyFilters({ status: v === '_all' ? '' : v }); }}>
                            <SelectTrigger className="w-40"><SelectValue placeholder="All Status" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="_all">All Status</SelectItem>
                                {['active','trial','expired','suspended'].map(s => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CreditCard className="w-4 h-4 text-indigo-500" /> Subscriptions ({subscriptions.meta.total})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 dark:border-slate-700 text-xs uppercase text-slate-500">
                                        <th className="text-left py-3 px-4 font-medium">School</th>
                                        <th className="text-left py-3 px-4 font-medium">Package</th>
                                        <th className="text-left py-3 px-4 font-medium">Period</th>
                                        <th className="text-left py-3 px-4 font-medium">Status</th>
                                        <th className="text-left py-3 px-4 font-medium">Paid</th>
                                        <th className="text-right py-3 px-4 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {subscriptions.data.length === 0 && (
                                        <tr><td colSpan={6} className="text-center py-10 text-slate-400">No subscriptions yet.</td></tr>
                                    )}
                                    {subscriptions.data.map(s => (
                                        <tr key={s.id} className="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                            <td className="py-3 px-4 font-medium text-slate-900 dark:text-white">{s.school.name}</td>
                                            <td className="py-3 px-4 text-slate-600 dark:text-slate-300 font-medium">
                                                <span>{s.package.name}</span>
                                                {s.billing_term_months && (
                                                    <span className="ml-1.5 px-1.5 py-0.5 rounded text-[11px] bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-normal">
                                                        {s.billing_term_months} mo
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-3 px-4 text-slate-500 text-xs">
                                                {new Date(s.start_date).toLocaleDateString()} → {new Date(s.end_date).toLocaleDateString()}
                                            </td>
                                            <td className="py-3 px-4">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium capitalize ${STATUS_COLORS[s.status] ?? ''}`}>{s.status}</span>
                                                {s.is_trial && <span className="ml-1 text-xs text-blue-500">(trial)</span>}
                                            </td>
                                            <td className="py-3 px-4 font-semibold text-slate-900 dark:text-white">
                                                {s.currency || 'PKR'} {Number(s.amount_paid).toLocaleString()}
                                            </td>
                                            <td className="py-3 px-4">
                                                <div className="flex items-center justify-end gap-1">
                                                    <button onClick={() => openEdit(s)} className="p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-400 hover:text-indigo-600">
                                                        <Edit className="w-3.5 h-3.5" />
                                                    </button>
                                                    <button onClick={() => setDelSub(s)} className="p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-400 hover:text-red-600">
                                                        <Trash2 className="w-3.5 h-3.5" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {subscriptions.meta.last_page > 1 && (
                            <div className="flex items-center justify-between mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                                <p className="text-sm text-slate-500">Page {subscriptions.meta.current_page} of {subscriptions.meta.last_page}</p>
                                <div className="flex gap-2">
                                    <Button variant="outline" size="sm" disabled={subscriptions.meta.current_page <= 1} onClick={() => applyFilters({ page: String(subscriptions.meta.current_page - 1) })}><ChevronLeft className="w-4 h-4" /></Button>
                                    <Button variant="outline" size="sm" disabled={subscriptions.meta.current_page >= subscriptions.meta.last_page} onClick={() => applyFilters({ page: String(subscriptions.meta.current_page + 1) })}><ChevronRight className="w-4 h-4" /></Button>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Create/Edit Modal */}
            <Dialog open={showModal} onOpenChange={setShowModal}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>{editSub ? 'Edit Subscription' : 'Assign Commercial Subscription'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {!editSub && (
                            <div>
                                <Label>School *</Label>
                                <Select value={form.data.school_id} onValueChange={v => form.setData('school_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select school" /></SelectTrigger>
                                    <SelectContent>{schools.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.school_id && <p className="text-xs text-red-500 mt-1">{form.errors.school_id}</p>}
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Commercial Package *</Label>
                                <Select
                                    value={form.data.package_id}
                                    onValueChange={v => handlePackageOrTermChange(v, form.data.billing_term_months, form.data.coupon_id, form.data.start_date)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select package" /></SelectTrigger>
                                    <SelectContent>
                                        {packages.map(p => (
                                            <SelectItem key={p.id} value={String(p.id)}>
                                                {p.name} (PKR {Number(p.price_monthly).toLocaleString()}/mo)
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.package_id && <p className="text-xs text-red-500 mt-1">{form.errors.package_id}</p>}
                            </div>

                            <div>
                                <Label>Billing Term Commitment *</Label>
                                <Select
                                    value={form.data.billing_term_months}
                                    onValueChange={v => handlePackageOrTermChange(form.data.package_id, v, form.data.coupon_id, form.data.start_date)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select term" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="3">3 Months (0% off)</SelectItem>
                                        <SelectItem value="6">6 Months (5% off)</SelectItem>
                                        <SelectItem value="12">12 Months (10% off)</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-[11px] text-slate-400 mt-1">Minimum commitment: 3 months</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Start Date *</Label>
                                <Input
                                    type="date"
                                    value={form.data.start_date}
                                    onChange={e => handlePackageOrTermChange(form.data.package_id, form.data.billing_term_months, form.data.coupon_id, e.target.value)}
                                />
                                {form.errors.start_date && <p className="text-xs text-red-500 mt-1">{form.errors.start_date}</p>}
                            </div>
                            <div>
                                <Label>End Date *</Label>
                                <Input
                                    type="date"
                                    value={form.data.end_date}
                                    onChange={e => form.setData('end_date', e.target.value)}
                                />
                                {form.errors.end_date && <p className="text-xs text-red-500 mt-1">{form.errors.end_date}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Status *</Label>
                                <Select value={form.data.status} onValueChange={v => form.setData('status', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['active','trial','expired','suspended'].map(s => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Amount Paid (PKR) *</Label>
                                <Input
                                    type="number"
                                    step="1"
                                    value={form.data.amount_paid}
                                    onChange={e => form.setData('amount_paid', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Payment Method</Label>
                                <Input value={form.data.payment_method} onChange={e => form.setData('payment_method', e.target.value)} placeholder="bank transfer, jazzcash, easypaisa..." />
                            </div>
                            <div>
                                <Label>Promotional Coupon</Label>
                                <Select
                                    value={form.data.coupon_id || '_none'}
                                    onValueChange={v => handlePackageOrTermChange(form.data.package_id, form.data.billing_term_months, v, form.data.start_date)}
                                >
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="_none">None</SelectItem>
                                        {coupons.map(c => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {c.code} ({c.type === 'percent' ? `${c.value}% off` : `PKR ${c.value} off`})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <input type="checkbox" id="is_trial" checked={form.data.is_trial}
                                onChange={e => form.setData('is_trial', e.target.checked)} className="rounded" />
                            <label htmlFor="is_trial" className="text-sm">This is a trial subscription</label>
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Input value={form.data.notes} onChange={e => form.setData('notes', e.target.value)} placeholder="Optional notes" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing} className="bg-indigo-600 hover:bg-indigo-700 text-white">
                                {editSub ? 'Save Changes' : 'Assign Subscription'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete Confirm */}
            <Dialog open={!!delSub} onOpenChange={open => !open && setDelSub(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader><DialogTitle>Remove Subscription</DialogTitle></DialogHeader>
                    <p className="text-sm text-slate-600 dark:text-slate-400">Remove subscription for <strong>{delSub?.school.name}</strong>?</p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDelSub(null)}>Cancel</Button>
                        <Button className="bg-red-600 hover:bg-red-700 text-white" onClick={confirmDelete}>Remove</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
