import AppLayout from '@/Layouts/AppLayout';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Plus, Edit, Trash2, CheckSquare, Square, Sparkles, Check, ChevronDown, ChevronUp, Layers, Users, HardDrive } from 'lucide-react';

interface PkgModule {
    module_slug: string;
}

interface PkgPrice {
    id: number;
    term_months: number;
    base_monthly_price: string;
    discount_percent: string;
    total_price: string;
    currency: string;
    is_active: boolean;
    savings_amount?: number;
    effective_monthly_price?: number;
}

interface Pkg {
    id: number;
    name: string;
    slug: string;
    badge: string | null;
    description: string | null;
    currency: string;
    price_monthly: string;
    price_yearly: string;
    max_students: number;
    max_staff: number;
    storage_gb: number;
    is_active: boolean;
    subscriptions_count: number;
    modules: PkgModule[];
    prices?: PkgPrice[];
}

interface Props {
    packages: Pkg[];
    availableModules: string[];
    canonicalTerms?: Record<number, number>;
}

const moduleLabel = (s: string) => s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function PackagesIndex({ packages, availableModules }: Props) {
    const [showModal, setShowModal] = useState(false);
    const [editPkg, setEditPkg]     = useState<Pkg | null>(null);
    const [delPkg, setDelPkg]       = useState<Pkg | null>(null);
    const [expandedModules, setExpandedModules] = useState<Record<number, boolean>>({});

    const form = useForm({
        name: '',
        badge: '',
        description: '',
        base_monthly_price: '',
        currency: 'PKR',
        max_students: '0',
        max_staff: '0',
        storage_gb: '5',
        is_active: true,
        modules: [] as string[],
    });

    const parsedBasePrice = parseFloat(form.data.base_monthly_price) || 0;

    const termCalculations = [
        {
            months: 3,
            discount: 0,
            subtotal: parsedBasePrice * 3,
            savings: 0,
            total: parsedBasePrice * 3,
            effectiveRate: parsedBasePrice,
        },
        {
            months: 6,
            discount: 5,
            subtotal: parsedBasePrice * 6,
            savings: Math.round(parsedBasePrice * 6 * 0.05),
            total: Math.round(parsedBasePrice * 6 * 0.95),
            effectiveRate: Math.round((parsedBasePrice * 6 * 0.95) / 6),
        },
        {
            months: 12,
            discount: 10,
            subtotal: parsedBasePrice * 12,
            savings: Math.round(parsedBasePrice * 12 * 0.10),
            total: Math.round(parsedBasePrice * 12 * 0.90),
            effectiveRate: Math.round((parsedBasePrice * 12 * 0.90) / 12),
        },
    ];

    function toggleExpandModules(pkgId: number) {
        setExpandedModules(prev => ({ ...prev, [pkgId]: !prev[pkgId] }));
    }

    function openCreate() {
        form.reset();
        form.clearErrors();
        setEditPkg(null);
        setShowModal(true);
    }

    function openEdit(p: Pkg) {
        form.setData({
            name: p.name,
            badge: p.badge ?? '',
            description: p.description ?? '',
            base_monthly_price: String(Math.round(parseFloat(p.price_monthly) || 0)),
            currency: p.currency || 'PKR',
            max_students: String(p.max_students),
            max_staff: String(p.max_staff),
            storage_gb: String(p.storage_gb),
            is_active: p.is_active,
            modules: p.modules.map(m => m.module_slug),
        });
        form.clearErrors();
        setEditPkg(p);
        setShowModal(true);
    }

    function toggleModule(slug: string) {
        const mods = form.data.modules.includes(slug)
            ? form.data.modules.filter(m => m !== slug)
            : [...form.data.modules, slug];
        form.setData('modules', mods);
    }

    function selectAllModules() {
        form.setData('modules', [...availableModules]);
    }

    function deselectAllModules() {
        form.setData('modules', []);
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (editPkg) {
            form.put(`/super-admin/packages/${editPkg.id}`, { onSuccess: () => setShowModal(false) });
        } else {
            form.post('/super-admin/packages', { onSuccess: () => setShowModal(false) });
        }
    }

    function confirmDelete() {
        if (!delPkg) return;
        router.delete(`/super-admin/packages/${delPkg.id}`, { onSuccess: () => setDelPkg(null) });
    }

    return (
        <AppLayout title="Packages">
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Commercial Packages</h1>
                        <p className="text-sm text-slate-500 mt-0.5">
                            Canonical multi-term pricing (3, 6, 12 months) with automatic commitment discounts.
                        </p>
                    </div>
                    <Button onClick={openCreate} className="gap-2 bg-indigo-600 hover:bg-indigo-700 text-white">
                        <Plus className="w-4 h-4" /> New Package
                    </Button>
                </div>

                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {packages.length === 0 && (
                        <Card className="col-span-3">
                            <CardContent className="py-12 text-center text-slate-400">
                                No commercial packages found. Provision packages to initialize Starter, Standard, and Pro.
                            </CardContent>
                        </Card>
                    )}

                    {packages.map(p => {
                        const isPopular = p.badge && p.badge.toLowerCase().includes('popular');
                        const term3  = p.prices?.find(pr => pr.term_months === 3);
                        const term6  = p.prices?.find(pr => pr.term_months === 6);
                        const term12 = p.prices?.find(pr => pr.term_months === 12);
                        const currency = p.currency || 'PKR';
                        const isExpanded = !!expandedModules[p.id];

                        return (
                            <Card
                                key={p.id}
                                className={`relative flex flex-col justify-between transition-all border ${
                                    isPopular
                                        ? 'border-indigo-500/80 shadow-md ring-1 ring-indigo-500/30'
                                        : 'border-slate-200 dark:border-slate-800'
                                } ${!p.is_active ? 'opacity-60 bg-slate-50/50 dark:bg-slate-900/30' : 'bg-white dark:bg-slate-900'}`}
                            >
                                <div>
                                    {p.badge && (
                                        <div className="absolute -top-3 left-4">
                                            <Badge className="bg-indigo-600 text-white shadow-sm font-medium px-2.5 py-0.5 flex items-center gap-1">
                                                <Sparkles className="w-3 h-3" />
                                                {p.badge}
                                            </Badge>
                                        </div>
                                    )}

                                    <CardHeader className="pb-3 pt-5">
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <CardTitle className="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                                    {p.name}
                                                </CardTitle>
                                                <p className="text-xs text-slate-400 font-mono mt-0.5">{p.slug}</p>
                                            </div>
                                            <Badge variant={p.is_active ? 'default' : 'secondary'} className="text-xs font-normal">
                                                {p.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </div>
                                        {p.description && (
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-2 min-h-[32px] line-clamp-2">
                                                {p.description}
                                            </p>
                                        )}
                                    </CardHeader>

                                    <CardContent className="space-y-4">
                                        {/* Reference Base Monthly Rate */}
                                        <div className="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/80 border border-slate-100 dark:border-slate-700/60">
                                            <div className="flex items-baseline justify-between">
                                                <span className="text-xs font-medium text-slate-500 dark:text-slate-400">Base Reference</span>
                                                <div className="text-right">
                                                    <span className="text-xl font-extrabold text-slate-900 dark:text-white">
                                                        {currency} {Number(p.price_monthly).toLocaleString()}
                                                    </span>
                                                    <span className="text-xs text-slate-500 dark:text-slate-400">/mo</span>
                                                </div>
                                            </div>
                                            <p className="text-[11px] text-indigo-600 dark:text-indigo-400 font-medium mt-1">
                                                From {currency} {term3 ? Number(term3.total_price).toLocaleString() : (Number(p.price_monthly) * 3).toLocaleString()} / 3 months
                                            </p>
                                        </div>

                                        {/* Multi-Term Breakdown */}
                                        <div className="space-y-2">
                                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                                Purchasable Terms
                                            </p>

                                            <div className="grid grid-cols-3 gap-2">
                                                {/* 3 Months */}
                                                <div className="p-2.5 rounded-md bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-center">
                                                    <div className="text-[11px] font-semibold text-slate-600 dark:text-slate-300">3 Months</div>
                                                    <div className="text-xs font-bold text-slate-900 dark:text-white mt-1">
                                                        {term3 ? `${currency} ${Number(term3.total_price).toLocaleString()}` : `${currency} ${(Number(p.price_monthly) * 3).toLocaleString()}`}
                                                    </div>
                                                    <div className="text-[10px] text-slate-400 mt-0.5">0% discount</div>
                                                </div>

                                                {/* 6 Months */}
                                                <div className="p-2.5 rounded-md bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-center relative">
                                                    <span className="absolute -top-2 right-1 text-[9px] bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 font-bold px-1 rounded">5% OFF</span>
                                                    <div className="text-[11px] font-semibold text-slate-600 dark:text-slate-300">6 Months</div>
                                                    <div className="text-xs font-bold text-slate-900 dark:text-white mt-1">
                                                        {term6 ? `${currency} ${Number(term6.total_price).toLocaleString()}` : `${currency} ${Math.round(Number(p.price_monthly) * 6 * 0.95).toLocaleString()}`}
                                                    </div>
                                                    <div className="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium mt-0.5">
                                                        Save {currency} {term6 ? Number(term6.savings_amount || (Number(p.price_monthly) * 6 * 0.05)).toLocaleString() : Math.round(Number(p.price_monthly) * 6 * 0.05).toLocaleString()}
                                                    </div>
                                                </div>

                                                {/* 12 Months */}
                                                <div className="p-2.5 rounded-md bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-center relative">
                                                    <span className="absolute -top-2 right-1 text-[9px] bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 font-bold px-1 rounded">10% OFF</span>
                                                    <div className="text-[11px] font-semibold text-slate-600 dark:text-slate-300">12 Months</div>
                                                    <div className="text-xs font-bold text-slate-900 dark:text-white mt-1">
                                                        {term12 ? `${currency} ${Number(term12.total_price).toLocaleString()}` : `${currency} ${Math.round(Number(p.price_monthly) * 12 * 0.90).toLocaleString()}`}
                                                    </div>
                                                    <div className="text-[10px] text-indigo-600 dark:text-indigo-400 font-medium mt-0.5">
                                                        Save {currency} {term12 ? Number(term12.savings_amount || (Number(p.price_monthly) * 12 * 0.10)).toLocaleString() : Math.round(Number(p.price_monthly) * 12 * 0.10).toLocaleString()}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Capacity & Limits */}
                                        <div className="grid grid-cols-3 gap-2 py-2 border-y border-slate-100 dark:border-slate-800 text-xs text-slate-600 dark:text-slate-400">
                                            <div className="flex items-center gap-1.5">
                                                <Users className="w-3.5 h-3.5 text-slate-400" />
                                                <span>{p.max_students ? `${p.max_students} Stud.` : '∞ Stud.'}</span>
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                <Users className="w-3.5 h-3.5 text-slate-400" />
                                                <span>{p.max_staff ? `${p.max_staff} Staff` : '∞ Staff'}</span>
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                <HardDrive className="w-3.5 h-3.5 text-slate-400" />
                                                <span>{p.storage_gb} GB</span>
                                            </div>
                                        </div>

                                        {/* Entitlement Modules */}
                                        <div className="space-y-1.5">
                                            <div className="flex items-center justify-between text-xs">
                                                <span className="font-medium text-slate-700 dark:text-slate-300 flex items-center gap-1">
                                                    <Layers className="w-3.5 h-3.5 text-indigo-500" />
                                                    {p.modules.length} Included Modules
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpandModules(p.id)}
                                                    className="text-xs text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 flex items-center gap-0.5 font-medium"
                                                >
                                                    {isExpanded ? 'Hide' : 'View all'}
                                                    {isExpanded ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
                                                </button>
                                            </div>

                                            {isExpanded ? (
                                                <div className="flex flex-wrap gap-1 p-2 bg-slate-50 dark:bg-slate-800/50 rounded border border-slate-100 dark:border-slate-800 max-h-36 overflow-y-auto">
                                                    {p.modules.map(m => (
                                                        <span
                                                            key={m.module_slug}
                                                            className="text-[11px] px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 border border-indigo-100 dark:border-indigo-900/50 flex items-center gap-1"
                                                        >
                                                            <Check className="w-2.5 h-2.5 text-indigo-500" />
                                                            {moduleLabel(m.module_slug)}
                                                        </span>
                                                    ))}
                                                </div>
                                            ) : (
                                                <p className="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                                                    {p.modules.map(m => moduleLabel(m.module_slug)).slice(0, 4).join(', ')}
                                                    {p.modules.length > 4 ? ` +${p.modules.length - 4} more` : ''}
                                                </p>
                                            )}
                                        </div>
                                    </CardContent>
                                </div>

                                <CardContent className="pt-0">
                                    <div className="flex items-center justify-between text-xs text-slate-400 pt-2 border-t border-slate-100 dark:border-slate-800">
                                        <span>{p.subscriptions_count} active school{p.subscriptions_count !== 1 ? 's' : ''}</span>
                                        <div className="flex gap-1.5">
                                            <Button size="sm" variant="outline" className="h-7 px-2.5 gap-1 text-xs" onClick={() => openEdit(p)}>
                                                <Edit className="w-3 h-3" /> Edit
                                            </Button>
                                            <Button size="sm" variant="outline" className="h-7 px-2 gap-1 text-xs text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30" onClick={() => setDelPkg(p)}>
                                                <Trash2 className="w-3 h-3" />
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            </div>

            {/* Create/Edit Modal */}
            <Dialog open={showModal} onOpenChange={setShowModal}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editPkg ? `Edit Package: ${editPkg.name}` : 'New Commercial Package'}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Package Name *</Label>
                                <Input
                                    value={form.data.name}
                                    onChange={e => form.setData('name', e.target.value)}
                                    placeholder="e.g. Standard"
                                />
                                {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                            </div>
                            <div>
                                <Label>Badge / Tag (Optional)</Label>
                                <Input
                                    value={form.data.badge}
                                    onChange={e => form.setData('badge', e.target.value)}
                                    placeholder="e.g. Most Popular, Recommended"
                                />
                            </div>
                        </div>

                        <div>
                            <Label>Description</Label>
                            <Input
                                value={form.data.description}
                                onChange={e => form.setData('description', e.target.value)}
                                placeholder="Brief description for schools"
                            />
                        </div>

                        {/* Base Monthly Pricing & Multi-Term Live Calculations */}
                        <div className="p-4 rounded-lg bg-indigo-50/50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/50 space-y-3">
                            <div className="grid grid-cols-2 gap-4 items-end">
                                <div>
                                    <Label className="text-xs font-semibold uppercase text-indigo-900 dark:text-indigo-300">
                                        Base Monthly Reference Rate (PKR) *
                                    </Label>
                                    <div className="relative mt-1">
                                        <span className="absolute left-3 top-2.5 text-xs font-bold text-slate-400">PKR</span>
                                        <Input
                                            type="number"
                                            step="1"
                                            className="pl-12 bg-white dark:bg-slate-900 font-bold"
                                            value={form.data.base_monthly_price}
                                            onChange={e => form.setData('base_monthly_price', e.target.value)}
                                            placeholder="5000"
                                        />
                                    </div>
                                    {form.errors.base_monthly_price && (
                                        <p className="text-xs text-red-500 mt-1">{form.errors.base_monthly_price}</p>
                                    )}
                                </div>
                                <div className="text-xs text-slate-500 dark:text-slate-400 pb-1">
                                    <p className="font-medium text-slate-700 dark:text-slate-300">Commercial Term Rules:</p>
                                    <p>• Minimum commitment: <strong>3 months</strong> (0% off)</p>
                                    <p>• 6-month term: <strong>5% off</strong></p>
                                    <p>• 12-month term: <strong>10% off</strong></p>
                                </div>
                            </div>

                            {/* Live Term Breakdown */}
                            <div className="grid grid-cols-3 gap-2 pt-2">
                                {termCalculations.map(t => (
                                    <div key={t.months} className="bg-white dark:bg-slate-900 p-2.5 rounded border border-indigo-100 dark:border-indigo-900/60 text-center">
                                        <span className="text-[11px] font-bold text-slate-700 dark:text-slate-300">{t.months} Months</span>
                                        <div className="text-sm font-extrabold text-indigo-600 dark:text-indigo-400 mt-0.5">
                                            PKR {t.total.toLocaleString()}
                                        </div>
                                        <div className="text-[10px] text-slate-400 mt-0.5">
                                            {t.discount > 0 ? `Save PKR ${t.savings.toLocaleString()} (${t.discount}%)` : 'Standard commitment'}
                                        </div>
                                        <div className="text-[10px] text-slate-500 dark:text-slate-400 font-medium">
                                            PKR {t.effectiveRate.toLocaleString()}/mo
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Capacity Limits */}
                        <div className="grid grid-cols-3 gap-4">
                            <div>
                                <Label>Max Students</Label>
                                <Input
                                    type="number"
                                    value={form.data.max_students}
                                    onChange={e => form.setData('max_students', e.target.value)}
                                    placeholder="0 = unlimited"
                                />
                            </div>
                            <div>
                                <Label>Max Staff</Label>
                                <Input
                                    type="number"
                                    value={form.data.max_staff}
                                    onChange={e => form.setData('max_staff', e.target.value)}
                                    placeholder="0 = unlimited"
                                />
                            </div>
                            <div>
                                <Label>Storage (GB)</Label>
                                <Input
                                    type="number"
                                    value={form.data.storage_gb}
                                    onChange={e => form.setData('storage_gb', e.target.value)}
                                />
                            </div>
                        </div>

                        {/* Modules Selection */}
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Included Entitlement Modules ({form.data.modules.length}/{availableModules.length})</Label>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={selectAllModules}
                                        className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                    >
                                        Select All
                                    </button>
                                    <span className="text-xs text-slate-300">|</span>
                                    <button
                                        type="button"
                                        onClick={deselectAllModules}
                                        className="text-xs text-slate-500 hover:underline"
                                    >
                                        Deselect All
                                    </button>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                {availableModules.map(slug => {
                                    const checked = form.data.modules.includes(slug);
                                    return (
                                        <button
                                            key={slug}
                                            type="button"
                                            onClick={() => toggleModule(slug)}
                                            className={`flex items-center gap-2 px-2.5 py-1.5 rounded border text-xs text-left transition-colors ${
                                                checked
                                                    ? 'border-indigo-500 bg-indigo-50/80 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 font-medium'
                                                    : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400'
                                            }`}
                                        >
                                            {checked ? (
                                                <CheckSquare className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
                                            ) : (
                                                <Square className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                            )}
                                            <span className="truncate">{moduleLabel(slug)}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="flex items-center gap-2 pt-1">
                            <input
                                type="checkbox"
                                id="is_active"
                                checked={form.data.is_active}
                                onChange={e => form.setData('is_active', e.target.checked)}
                                className="rounded text-indigo-600 focus:ring-indigo-500"
                            />
                            <label htmlFor="is_active" className="text-sm text-slate-700 dark:text-slate-300">
                                Active (available for assignment and new subscriptions)
                            </label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setShowModal(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing} className="bg-indigo-600 hover:bg-indigo-700 text-white">
                                {editPkg ? 'Save Changes' : 'Create Package'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete Confirm */}
            <Dialog open={!!delPkg} onOpenChange={open => !open && setDelPkg(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Delete Package</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-slate-600 dark:text-slate-400">
                        Delete <strong>{delPkg?.name}</strong>? This cannot be undone and will fail if schools are subscribed.
                    </p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDelPkg(null)}>
                            Cancel
                        </Button>
                        <Button className="bg-red-600 hover:bg-red-700 text-white" onClick={confirmDelete}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
