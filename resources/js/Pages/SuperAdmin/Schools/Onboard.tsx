import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, useMemo, useEffect } from 'react';
import {
    School as SchoolIcon, UserCheck, Package as PackageIcon, CreditCard,
    Calendar, Globe, CheckCircle2, ChevronRight, ChevronLeft,
    Sparkles, Check, ArrowLeft, Shield, Eye, EyeOff, RefreshCw,
    Layers, Users, HardDrive, Tag, AlertCircle, ExternalLink
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';

interface PkgModule {
    module_slug: string;
}

interface PkgPrice {
    id: number;
    package_id: number;
    term_months: number;
    base_monthly_price: string;
    discount_percent: string;
    total_price: string;
    currency: string;
    is_active: boolean;
}

interface PackageItem {
    id: number;
    name: string;
    slug: string;
    badge: string | null;
    description: string | null;
    currency: string;
    price_monthly: string;
    max_students: number;
    max_staff: number;
    storage_gb: number;
    is_active: boolean;
    is_internal?: boolean;
    modules: PkgModule[];
    prices: PkgPrice[];
}

interface CouponItem {
    id: number;
    code: string;
    type: 'percent' | 'fixed';
    value: string;
    description?: string;
}

interface OnboardingSuccessData {
    school_id: number;
    school_name: string;
    school_code: string;
    school_slug: string;
    admin_name: string;
    admin_email: string;
    package_name: string;
    term_months: number;
    billed_amount: number;
    amount_paid: number;
    balance_due: number;
    currency: string;
    start_date: string;
    end_date: string;
    academic_year?: string;
    domain_hostname?: string;
    domain_status?: string;
}

interface Props {
    packages: PackageItem[];
    coupons: CouponItem[];
    canonicalTerms: Record<number, number>;
    defaults: {
        country: string;
        city: string;
        state: string;
        timezone: string;
        currency: string;
        language: string;
        status: string;
        start_date: string;
        academic_year_name: string;
        academic_start: string;
        academic_end: string;
    };
    onboardingSuccess?: OnboardingSuccessData | null;
}

const STEPS = [
    { id: 1, name: 'School', icon: SchoolIcon, desc: 'Identity & Locale' },
    { id: 2, name: 'Admin', icon: UserCheck, desc: 'School Admin' },
    { id: 3, name: 'Package', icon: PackageIcon, desc: 'Commercial Tier' },
    { id: 4, name: 'Billing', icon: CreditCard, desc: 'Term & Payment' },
    { id: 5, name: 'Academic', icon: Calendar, desc: 'Academic Year' },
    { id: 6, name: 'Domain', icon: Globe, desc: 'Custom Hostname' },
    { id: 7, name: 'Review', icon: CheckCircle2, desc: 'Confirmation' },
];

export default function SchoolOnboard({
    packages = [],
    coupons = [],
    defaults,
    onboardingSuccess,
}: Props) {
    const [currentStep, setCurrentStep] = useState(1);
    const [showPassword, setShowPassword] = useState(false);
    const [hasCustomDomain, setHasCustomDomain] = useState(false);
    const [createAcademicYear, setCreateAcademicYear] = useState(true);

    // Initial default package: Standard or first available
    const initialPackage = useMemo(() => {
        return packages.find(p => p.slug === 'standard') || packages[0] || null;
    }, [packages]);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        // 1. School
        name: '',
        code: '',
        slug: '',
        email: '',
        phone: '',
        address: '',
        city: defaults.city || 'Lahore',
        state: defaults.state || 'Punjab',
        country: defaults.country || 'PK',
        timezone: defaults.timezone || 'Asia/Karachi',
        currency: defaults.currency || 'PKR',
        language: defaults.language || 'en',
        status: defaults.status || 'active',

        // 2. Admin
        admin_name: '',
        admin_email: '',
        admin_phone: '',
        admin_password: '',

        // 3 & 4. Package & Billing
        package_id: initialPackage ? initialPackage.id : 0,
        billing_term_months: 6, // default 6mo (5% off)
        coupon_id: '' as string | number,
        start_date: defaults.start_date || new Date().toISOString().split('T')[0],
        amount_paid: '0',
        payment_method: 'manual',
        notes: 'Commercial guided onboarding',

        // 5. Academic Year
        academic_year_name: defaults.academic_year_name || 'Academic Year 2026-27',
        academic_start: defaults.academic_start || '',
        academic_end: defaults.academic_end || '',
        set_academic_current: true,

        // 6. Domain
        custom_domain: '',
    });

    // Auto-generate Slug & Code from School Name if empty
    function handleNameChange(val: string) {
        const generatedSlug = val.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, '');
        const words = val.trim().split(/\s+/);
        const generatedCode = words.length > 1
            ? words.map(w => w[0]).join('').toUpperCase() + '01'
            : (val.slice(0, 4).toUpperCase() + '01');

        setData(prev => ({
            ...prev,
            name: val,
            slug: prev.slug === '' || prev.slug === prev.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, '') ? generatedSlug : prev.slug,
            code: prev.code === '' ? generatedCode : prev.code,
        }));
    }

    // Password generator helper
    function generateSecurePassword() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*';
        let pass = '';
        for (let i = 0; i < 12; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        setData('admin_password', pass);
        setShowPassword(true);
    }

    // Selected Package & Price lookup
    const selectedPackage = useMemo(() => {
        return packages.find(p => p.id === Number(data.package_id)) || initialPackage;
    }, [packages, data.package_id, initialPackage]);

    const selectedPriceRow = useMemo(() => {
        if (!selectedPackage) return null;
        return selectedPackage.prices.find(pr => pr.term_months === Number(data.billing_term_months)) || null;
    }, [selectedPackage, data.billing_term_months]);

    // Financial calculations
    const pricingBreakdown = useMemo(() => {
        const baseMonthly = selectedPackage ? parseFloat(selectedPackage.price_monthly) || 0 : 0;
        const months = Number(data.billing_term_months) || 3;
        const subtotal = baseMonthly * months;

        let autoDiscountPercent = 0;
        if (months === 6) autoDiscountPercent = 5;
        if (months === 12) autoDiscountPercent = 10;

        const termPrice = selectedPriceRow
            ? parseFloat(selectedPriceRow.total_price)
            : Math.round(subtotal * (1 - autoDiscountPercent / 100));

        const termSavings = Math.round(subtotal - termPrice);
        const effectiveMonthly = Math.round(termPrice / months);

        // Coupon calculation
        let couponDiscount = 0;
        const selectedCoupon = coupons.find(c => String(c.id) === String(data.coupon_id));
        if (selectedCoupon) {
            if (selectedCoupon.type === 'percent') {
                couponDiscount = Math.round(termPrice * (parseFloat(selectedCoupon.value) / 100));
            } else {
                couponDiscount = Math.min(termPrice, parseFloat(selectedCoupon.value));
            }
        }

        const billedAmount = Math.max(0, termPrice - couponDiscount);
        const amountReceived = parseFloat(data.amount_paid) || 0;
        const balanceDue = Math.max(0, billedAmount - amountReceived);

        return {
            baseMonthly,
            months,
            subtotal,
            autoDiscountPercent,
            termPrice,
            termSavings,
            effectiveMonthly,
            selectedCoupon,
            couponDiscount,
            billedAmount,
            amountReceived,
            balanceDue,
        };
    }, [selectedPackage, selectedPriceRow, data.billing_term_months, data.coupon_id, data.amount_paid, coupons]);

    // Server authoritative end date preview
    const calculatedEndDate = useMemo(() => {
        if (!data.start_date) return '';
        const d = new Date(data.start_date);
        if (isNaN(d.getTime())) return '';
        d.setMonth(d.getMonth() + Number(data.billing_term_months));
        return d.toISOString().split('T')[0];
    }, [data.start_date, data.billing_term_months]);

    // Validate current step before advancing
    function validateStep(step: number): boolean {
        clearErrors();
        if (step === 1) {
            if (!data.name.trim()) {
                // @ts-ignore
                errors.name = 'School name is required';
                return false;
            }
            if (!data.code.trim()) {
                // @ts-ignore
                errors.code = 'School code is required';
                return false;
            }
            if (!data.slug.trim()) {
                // @ts-ignore
                errors.slug = 'Slug is required';
                return false;
            }
        }
        if (step === 2) {
            if (!data.admin_name.trim()) {
                // @ts-ignore
                errors.admin_name = 'Admin full name is required';
                return false;
            }
            if (!data.admin_email.trim() || !data.admin_email.includes('@')) {
                // @ts-ignore
                errors.admin_email = 'A valid admin email is required';
                return false;
            }
            if (!data.admin_password || data.admin_password.length < 8) {
                // @ts-ignore
                errors.admin_password = 'Password must be at least 8 characters';
                return false;
            }
        }
        if (step === 3) {
            if (!data.package_id) {
                // @ts-ignore
                errors.package_id = 'Please select a commercial package';
                return false;
            }
        }
        if (step === 4) {
            if (!data.start_date) {
                // @ts-ignore
                errors.start_date = 'Start date is required';
                return false;
            }
            const received = parseFloat(data.amount_paid) || 0;
            if (received < 0) {
                // @ts-ignore
                errors.amount_paid = 'Amount received cannot be negative';
                return false;
            }
            if (received > pricingBreakdown.billedAmount) {
                // @ts-ignore
                errors.amount_paid = `Amount received cannot exceed billed amount (${pricingBreakdown.billedAmount})`;
                return false;
            }
        }
        return true;
    }

    function nextStep() {
        if (validateStep(currentStep)) {
            setCurrentStep(prev => Math.min(STEPS.length, prev + 1));
        }
    }

    function prevStep() {
        clearErrors();
        setCurrentStep(prev => Math.max(1, prev - 1));
    }

    function handleFinalSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!validateStep(currentStep)) return;

        const payload = {
            ...data,
            custom_domain: hasCustomDomain ? data.custom_domain.trim() : null,
            academic_year_name: createAcademicYear ? data.academic_year_name.trim() : null,
            academic_start: createAcademicYear ? data.academic_start : null,
            academic_end: createAcademicYear ? data.academic_end : null,
        };

        post('/super-admin/schools/onboard', {
            // @ts-ignore
            data: payload,
            preserveScroll: true,
        });
    }

    // Success Screen View
    if (onboardingSuccess) {
        return (
            <AppLayout breadcrumbs={[{ label: 'Super Admin' }, { label: 'Schools', href: '/super-admin/schools' }, { label: 'Onboard Success' }]}>
                <Head title="School Onboarded Successfully" />

                <div className="max-w-3xl mx-auto py-8 space-y-6">
                    <Card className="border-emerald-500/30 bg-emerald-50/40 dark:bg-emerald-950/20 shadow-md">
                        <CardHeader className="text-center pb-4">
                            <div className="mx-auto w-14 h-14 rounded-full bg-emerald-100 dark:bg-emerald-900/60 flex items-center justify-center text-emerald-600 dark:text-emerald-300 mb-2">
                                <Check className="w-8 h-8 stroke-[3]" />
                            </div>
                            <CardTitle className="text-2xl font-extrabold text-slate-900 dark:text-white">
                                Commercial School Successfully Onboarded
                            </CardTitle>
                            <CardDescription className="text-slate-600 dark:text-slate-400">
                                Foundation records, administrator credentials, commercial subscription, and academic calendar are live.
                            </CardDescription>
                        </CardHeader>

                        <CardContent className="space-y-6">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 bg-white dark:bg-slate-900 p-5 rounded-xl border border-slate-200 dark:border-slate-800 text-sm">
                                <div>
                                    <span className="text-xs text-slate-400 font-medium uppercase tracking-wider">School Identity</span>
                                    <p className="font-bold text-slate-900 dark:text-white text-base mt-0.5">{onboardingSuccess.school_name}</p>
                                    <p className="text-xs text-slate-500 font-mono mt-0.5">Code: {onboardingSuccess.school_code} &bull; Slug: {onboardingSuccess.school_slug}</p>
                                </div>

                                <div>
                                    <span className="text-xs text-slate-400 font-medium uppercase tracking-wider">School Administrator</span>
                                    <p className="font-bold text-slate-900 dark:text-white text-base mt-0.5">{onboardingSuccess.admin_name}</p>
                                    <p className="text-xs text-slate-500 font-mono mt-0.5">{onboardingSuccess.admin_email}</p>
                                </div>

                                <div className="border-t border-slate-100 dark:border-slate-800 pt-3">
                                    <span className="text-xs text-slate-400 font-medium uppercase tracking-wider">Commercial Subscription</span>
                                    <p className="font-bold text-slate-900 dark:text-white mt-0.5">
                                        {onboardingSuccess.package_name} ({onboardingSuccess.term_months} Months Commitment)
                                    </p>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        {onboardingSuccess.start_date} to {onboardingSuccess.end_date}
                                    </p>
                                </div>

                                <div className="border-t border-slate-100 dark:border-slate-800 pt-3">
                                    <span className="text-xs text-slate-400 font-medium uppercase tracking-wider">Payment Snapshot</span>
                                    <p className="font-bold text-slate-900 dark:text-white mt-0.5">
                                        Billed: {onboardingSuccess.currency} {Number(onboardingSuccess.billed_amount).toLocaleString()} &bull; Paid: {onboardingSuccess.currency} {Number(onboardingSuccess.amount_paid).toLocaleString()}
                                    </p>
                                    <p className="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-0.5">
                                        Balance Due: {onboardingSuccess.currency} {Number(onboardingSuccess.balance_due).toLocaleString()}
                                    </p>
                                </div>

                                {onboardingSuccess.academic_year && (
                                    <div className="border-t border-slate-100 dark:border-slate-800 pt-3">
                                        <span className="text-xs text-slate-400 font-medium uppercase tracking-wider">Academic Year</span>
                                        <p className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5">{onboardingSuccess.academic_year} (Active)</p>
                                    </div>
                                )}

                                {onboardingSuccess.domain_hostname && (
                                    <div className="border-t border-slate-100 dark:border-slate-800 pt-3">
                                        <span className="text-xs text-slate-400 font-medium uppercase tracking-wider">Custom Domain</span>
                                        <p className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5">{onboardingSuccess.domain_hostname}</p>
                                        <span className="inline-flex items-center text-[10px] bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 font-bold px-1.5 py-0.5 rounded mt-0.5">
                                            Status: {onboardingSuccess.domain_status || 'pending'} (Awaiting DNS verification)
                                        </span>
                                    </div>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
                                <Button variant="outline" asChild>
                                    <Link href="/super-admin/schools" className="gap-2">
                                        <ArrowLeft className="w-4 h-4" /> Return to Schools
                                    </Link>
                                </Button>

                                <div className="flex items-center gap-2">
                                    <Button variant="secondary" asChild>
                                        <Link href={`/super-admin/subscriptions?school_id=${onboardingSuccess.school_id}`}>
                                            View Subscription
                                        </Link>
                                    </Button>
                                    <Button className="bg-indigo-600 hover:bg-indigo-700 text-white" asChild>
                                        <Link href={`/super-admin/schools/${onboardingSuccess.school_id}`}>
                                            Open School Details
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Super Admin' }, { label: 'Schools', href: '/super-admin/schools' }, { label: 'Commercial Onboarding' }]}>
            <Head title="Onboard Commercial School" />

            <div className="max-w-5xl mx-auto space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                            <Sparkles className="w-6 h-6 text-indigo-600" />
                            Commercial School Onboarding
                        </h1>
                        <p className="text-sm text-slate-500 mt-0.5">
                            Guided multi-step setup for new paying school tenants, administrators, and multi-term commitments.
                        </p>
                    </div>
                    <Button variant="ghost" asChild>
                        <Link href="/super-admin/schools" className="gap-1.5 text-xs text-slate-500">
                            <ArrowLeft className="w-3.5 h-3.5" /> Back to Schools
                        </Link>
                    </Button>
                </div>

                {/* Stepper Navigation */}
                <div className="bg-white dark:bg-slate-900 p-3.5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-x-auto">
                    <nav className="flex items-center justify-between min-w-[680px]">
                        {STEPS.map((step, idx) => {
                            const Icon = step.icon;
                            const isDone = currentStep > step.id;
                            const isCurrent = currentStep === step.id;

                            return (
                                <div key={step.id} className="flex items-center flex-1 last:flex-none">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (step.id < currentStep) setCurrentStep(step.id);
                                        }}
                                        disabled={step.id > currentStep}
                                        className={`flex items-center gap-2.5 text-left transition-all ${
                                            isCurrent
                                                ? 'text-indigo-600 dark:text-indigo-400 font-bold'
                                                : isDone
                                                ? 'text-slate-700 dark:text-slate-300 font-medium hover:text-indigo-600'
                                                : 'text-slate-400 opacity-60 cursor-not-allowed'
                                        }`}
                                    >
                                        <div
                                            className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold shrink-0 transition-colors ${
                                                isCurrent
                                                    ? 'bg-indigo-600 text-white shadow'
                                                    : isDone
                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                                    : 'bg-slate-100 dark:bg-slate-800 text-slate-400'
                                            }`}
                                        >
                                            {isDone ? <Check className="w-4 h-4 stroke-[3]" /> : step.id}
                                        </div>
                                        <div className="hidden sm:block">
                                            <p className="text-xs leading-none">{step.name}</p>
                                            <p className="text-[10px] text-slate-400 font-normal mt-0.5">{step.desc}</p>
                                        </div>
                                    </button>

                                    {idx < STEPS.length - 1 && (
                                        <div className={`flex-1 h-[2px] mx-3 ${currentStep > step.id ? 'bg-emerald-400 dark:bg-emerald-600' : 'bg-slate-200 dark:bg-slate-800'}`} />
                                    )}
                                </div>
                            );
                        })}
                    </nav>
                </div>

                {/* Form Container */}
                <form onSubmit={handleFinalSubmit}>
                    <Card className="border-slate-200 dark:border-slate-800 shadow-sm bg-white dark:bg-slate-900">
                        {/* STEP 1: School Information */}
                        {currentStep === 1 && (
                            <>
                                <CardHeader className="border-b border-slate-100 dark:border-slate-800 pb-4">
                                    <CardTitle className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <SchoolIcon className="w-5 h-5 text-indigo-600" /> Step 1: School Identity & Localization
                                    </CardTitle>
                                    <CardDescription>
                                        Enter core institution information and operational localization settings.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4 pt-5">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div className="md:col-span-2 space-y-1.5">
                                            <Label htmlFor="name" className="text-xs font-semibold">School Name *</Label>
                                            <Input
                                                id="name"
                                                placeholder="e.g. Beaconhouse Palm Campus"
                                                value={data.name}
                                                onChange={e => handleNameChange(e.target.value)}
                                                className={errors.name ? 'border-red-500' : ''}
                                            />
                                            {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="code" className="text-xs font-semibold">School Code *</Label>
                                            <Input
                                                id="code"
                                                placeholder="e.g. BPC01"
                                                value={data.code}
                                                onChange={e => setData('code', e.target.value.toUpperCase())}
                                                className={errors.code ? 'border-red-500' : ''}
                                            />
                                            {errors.code && <p className="text-xs text-red-500">{errors.code}</p>}
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="slug" className="text-xs font-semibold">URL Slug *</Label>
                                            <Input
                                                id="slug"
                                                placeholder="e.g. beaconhouse-palm-campus"
                                                value={data.slug}
                                                onChange={e => setData('slug', e.target.value.toLowerCase())}
                                                className={errors.slug ? 'border-red-500' : ''}
                                            />
                                            {errors.slug && <p className="text-xs text-red-500">{errors.slug}</p>}
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="email" className="text-xs font-semibold">School Email</Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                placeholder="info@school.edu.pk"
                                                value={data.email}
                                                onChange={e => setData('email', e.target.value)}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="phone" className="text-xs font-semibold">Phone</Label>
                                            <Input
                                                id="phone"
                                                placeholder="+92 42 35890000"
                                                value={data.phone}
                                                onChange={e => setData('phone', e.target.value)}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="city" className="text-xs font-semibold">City</Label>
                                            <Input
                                                id="city"
                                                value={data.city}
                                                onChange={e => setData('city', e.target.value)}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="state" className="text-xs font-semibold">State / Province</Label>
                                            <Input
                                                id="state"
                                                value={data.state}
                                                onChange={e => setData('state', e.target.value)}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="country" className="text-xs font-semibold">Country</Label>
                                            <Input
                                                id="country"
                                                value={data.country}
                                                onChange={e => setData('country', e.target.value.toUpperCase())}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="timezone" className="text-xs font-semibold">Timezone</Label>
                                            <Input
                                                id="timezone"
                                                value={data.timezone}
                                                onChange={e => setData('timezone', e.target.value)}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="currency" className="text-xs font-semibold">Currency</Label>
                                            <Input
                                                id="currency"
                                                value={data.currency}
                                                onChange={e => setData('currency', e.target.value.toUpperCase())}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="language" className="text-xs font-semibold">Language</Label>
                                            <Input
                                                id="language"
                                                value={data.language}
                                                onChange={e => setData('language', e.target.value.toLowerCase())}
                                            />
                                        </div>
                                    </div>
                                </CardContent>
                            </>
                        )}

                        {/* STEP 2: School Administrator */}
                        {currentStep === 2 && (
                            <>
                                <CardHeader className="border-b border-slate-100 dark:border-slate-800 pb-4">
                                    <CardTitle className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <UserCheck className="w-5 h-5 text-indigo-600" /> Step 2: School Administrator Account
                                    </CardTitle>
                                    <CardDescription>
                                        Configure the primary school administrator login credentials.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4 pt-5 max-w-xl">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="admin_name" className="text-xs font-semibold">Admin Full Name *</Label>
                                        <Input
                                            id="admin_name"
                                            placeholder="e.g. Dr. Ayesha Khan"
                                            value={data.admin_name}
                                            onChange={e => setData('admin_name', e.target.value)}
                                            className={errors.admin_name ? 'border-red-500' : ''}
                                        />
                                        {errors.admin_name && <p className="text-xs text-red-500">{errors.admin_name}</p>}
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor="admin_email" className="text-xs font-semibold">Admin Email Address *</Label>
                                        <Input
                                            id="admin_email"
                                            type="email"
                                            placeholder="admin@school.edu.pk"
                                            value={data.admin_email}
                                            onChange={e => setData('admin_email', e.target.value)}
                                            className={errors.admin_email ? 'border-red-500' : ''}
                                        />
                                        {errors.admin_email && <p className="text-xs text-red-500">{errors.admin_email}</p>}
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor="admin_phone" className="text-xs font-semibold">Admin Phone Number</Label>
                                        <Input
                                            id="admin_phone"
                                            placeholder="+92 300 1234567"
                                            value={data.admin_phone}
                                            onChange={e => setData('admin_phone', e.target.value)}
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="admin_password" className="text-xs font-semibold">Initial Password *</Label>
                                            <button
                                                type="button"
                                                onClick={generateSecurePassword}
                                                className="text-[11px] text-indigo-600 hover:text-indigo-700 font-semibold flex items-center gap-1"
                                            >
                                                <RefreshCw className="w-3 h-3" /> Generate Secure Password
                                            </button>
                                        </div>
                                        <div className="relative">
                                            <Input
                                                id="admin_password"
                                                type={showPassword ? 'text' : 'password'}
                                                placeholder="Min 8 characters"
                                                value={data.admin_password}
                                                onChange={e => setData('admin_password', e.target.value)}
                                                className={`pr-10 ${errors.admin_password ? 'border-red-500' : ''}`}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowPassword(p => !p)}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                            >
                                                {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                            </button>
                                        </div>
                                        {errors.admin_password && <p className="text-xs text-red-500">{errors.admin_password}</p>}
                                        <p className="text-[11px] text-slate-400">
                                            Password will be securely hashed upon creation.
                                        </p>
                                    </div>

                                    <div className="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                                        <Shield className="w-4 h-4 text-indigo-600 shrink-0" />
                                        <span>User will be assigned the <strong>school-admin</strong> role automatically scoped to this school.</span>
                                    </div>
                                </CardContent>
                            </>
                        )}

                        {/* STEP 3: Commercial Package Selection */}
                        {currentStep === 3 && (
                            <>
                                <CardHeader className="border-b border-slate-100 dark:border-slate-800 pb-4">
                                    <CardTitle className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <PackageIcon className="w-5 h-5 text-indigo-600" /> Step 3: Select Commercial Package Tier
                                    </CardTitle>
                                    <CardDescription>
                                        Choose the appropriate commercial plan for this school. Internal tiers are excluded.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="pt-6">
                                    <div className="grid gap-6 md:grid-cols-3">
                                        {packages.map(p => {
                                            const isSelected = Number(data.package_id) === p.id;
                                            const isPopular = p.badge && p.badge.toLowerCase().includes('popular');

                                            return (
                                                <div
                                                    key={p.id}
                                                    onClick={() => setData('package_id', p.id)}
                                                    className={`relative rounded-xl border p-5 cursor-pointer transition-all flex flex-col justify-between ${
                                                        isSelected
                                                            ? 'border-indigo-600 ring-2 ring-indigo-600/30 bg-indigo-50/20 dark:bg-indigo-950/20'
                                                            : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700'
                                                    }`}
                                                >
                                                    {isPopular && (
                                                        <div className="absolute -top-3 left-4">
                                                            <Badge className="bg-indigo-600 text-white font-semibold text-[10px] px-2 py-0.5 shadow">
                                                                <Sparkles className="w-3 h-3 mr-1 inline" /> Most Popular
                                                            </Badge>
                                                        </div>
                                                    )}

                                                    <div className="space-y-3">
                                                        <div className="flex items-center justify-between">
                                                            <h3 className="font-bold text-slate-900 dark:text-white text-lg">{p.name}</h3>
                                                            <div className={`w-5 h-5 rounded-full border flex items-center justify-center ${isSelected ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300'}`}>
                                                                {isSelected && <Check className="w-3 h-3 stroke-[3]" />}
                                                            </div>
                                                        </div>

                                                        <div className="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700">
                                                            <span className="text-xs text-slate-500">Base Reference</span>
                                                            <div className="text-xl font-extrabold text-slate-900 dark:text-white">
                                                                {p.currency} {Number(p.price_monthly).toLocaleString()} <span className="text-xs font-normal text-slate-400">/mo</span>
                                                            </div>
                                                        </div>

                                                        <p className="text-xs text-slate-500 line-clamp-2">{p.description}</p>

                                                        <div className="grid grid-cols-3 gap-1 py-2 border-y border-slate-100 dark:border-slate-800 text-[11px] text-slate-600 dark:text-slate-400">
                                                            <div><strong>{p.max_students}</strong> Stud.</div>
                                                            <div><strong>{p.max_staff}</strong> Staff</div>
                                                            <div><strong>{p.storage_gb}</strong> GB</div>
                                                        </div>

                                                        <div className="text-xs font-medium text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
                                                            <Layers className="w-3.5 h-3.5" /> {p.modules.length} Included Modules
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                    {errors.package_id && <p className="text-xs text-red-500 mt-3">{errors.package_id}</p>}
                                </CardContent>
                            </>
                        )}

                        {/* STEP 4: Billing Term, Coupon & Payment */}
                        {currentStep === 4 && (
                            <>
                                <CardHeader className="border-b border-slate-100 dark:border-slate-800 pb-4">
                                    <CardTitle className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <CreditCard className="w-5 h-5 text-indigo-600" /> Step 4: Multi-Term Billing & Payment Received
                                    </CardTitle>
                                    <CardDescription>
                                        Select commitment term (3, 6, 12 months), apply optional coupons, and record payments.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6 pt-5">
                                    {/* Term Selection */}
                                    <div>
                                        <Label className="text-xs font-semibold uppercase tracking-wider text-slate-500 block mb-2.5">
                                            Commitment Term *
                                        </Label>
                                        <div className="grid grid-cols-3 gap-4">
                                            {[
                                                { months: 3, discount: 0, label: '3 Months', badge: 'Standard' },
                                                { months: 6, discount: 5, label: '6 Months', badge: '5% OFF' },
                                                { months: 12, discount: 10, label: '12 Months', badge: '10% OFF' },
                                            ].map(term => {
                                                const isSelected = Number(data.billing_term_months) === term.months;
                                                const base = selectedPackage ? parseFloat(selectedPackage.price_monthly) : 0;
                                                const subtotal = base * term.months;
                                                const total = Math.round(subtotal * (1 - term.discount / 100));

                                                return (
                                                    <div
                                                        key={term.months}
                                                        onClick={() => setData('billing_term_months', term.months)}
                                                        className={`p-4 rounded-xl border cursor-pointer transition-all relative text-center ${
                                                            isSelected
                                                                ? 'border-indigo-600 bg-indigo-50/30 dark:bg-indigo-950/20 ring-2 ring-indigo-600/30'
                                                                : 'border-slate-200 dark:border-slate-800 hover:border-slate-300'
                                                        }`}
                                                    >
                                                        <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${term.discount > 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'}`}>
                                                            {term.badge}
                                                        </span>
                                                        <h4 className="font-bold text-slate-900 dark:text-white mt-1">{term.label}</h4>
                                                        <p className="text-lg font-extrabold text-slate-900 dark:text-white mt-1">
                                                            {selectedPackage?.currency || 'PKR'} {total.toLocaleString()}
                                                        </p>
                                                        <p className="text-[11px] text-slate-400 mt-0.5">
                                                            Effective {selectedPackage?.currency || 'PKR'} {Math.round(total / term.months).toLocaleString()} /mo
                                                        </p>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    {/* Dates & Optional Coupon */}
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                                        <div className="space-y-1.5">
                                            <Label htmlFor="start_date" className="text-xs font-semibold">Subscription Start Date *</Label>
                                            <Input
                                                id="start_date"
                                                type="date"
                                                value={data.start_date}
                                                onChange={e => setData('start_date', e.target.value)}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <div className="flex items-center justify-between">
                                                <Label className="text-xs font-semibold">Calculated End Date</Label>
                                                <span className="text-[10px] bg-slate-100 dark:bg-slate-800 text-slate-500 px-1.5 py-0.5 rounded">Server Authoritative</span>
                                            </div>
                                            <Input
                                                value={calculatedEndDate}
                                                readOnly
                                                className="bg-slate-50 dark:bg-slate-800/50 text-slate-600 font-mono"
                                            />
                                        </div>

                                        <div className="md:col-span-2 space-y-1.5">
                                            <Label htmlFor="coupon_id" className="text-xs font-semibold">Promotional Coupon (Optional)</Label>
                                            <Select
                                                value={String(data.coupon_id || 'none')}
                                                onValueChange={v => setData('coupon_id', v === 'none' ? '' : Number(v))}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select coupon code (optional)" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">None (No coupon applied)</SelectItem>
                                                    {coupons.map(c => (
                                                        <SelectItem key={c.id} value={String(c.id)}>
                                                            {c.code} &mdash; {c.type === 'percent' ? `${c.value}% OFF` : `PKR ${c.value} OFF`}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>

                                    {/* Pricing & Payment Snapshot Box */}
                                    <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-4">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">Commercial Summary Breakdown</h4>
                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                                            <div className="bg-white dark:bg-slate-900 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                                                <span className="text-xs text-slate-400">Term Price</span>
                                                <p className="text-base font-bold text-slate-900 dark:text-white mt-0.5">
                                                    {selectedPackage?.currency} {pricingBreakdown.termPrice.toLocaleString()}
                                                </p>
                                                {pricingBreakdown.termSavings > 0 && (
                                                    <span className="text-[10px] text-emerald-600 font-semibold">Save {selectedPackage?.currency} {pricingBreakdown.termSavings.toLocaleString()}</span>
                                                )}
                                            </div>

                                            <div className="bg-white dark:bg-slate-900 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                                                <span className="text-xs text-slate-400">Coupon Discount</span>
                                                <p className="text-base font-bold text-slate-900 dark:text-white mt-0.5">
                                                    - {selectedPackage?.currency} {pricingBreakdown.couponDiscount.toLocaleString()}
                                                </p>
                                                <span className="text-[10px] text-slate-400">{pricingBreakdown.selectedCoupon ? pricingBreakdown.selectedCoupon.code : 'No coupon'}</span>
                                            </div>

                                            <div className="bg-white dark:bg-slate-900 p-3 rounded-lg border border-indigo-200 dark:border-indigo-900/60 bg-indigo-50/30">
                                                <span className="text-xs text-indigo-700 dark:text-indigo-300 font-semibold">Final Billed Amount</span>
                                                <p className="text-lg font-extrabold text-indigo-950 dark:text-indigo-100 mt-0.5">
                                                    {selectedPackage?.currency} {pricingBreakdown.billedAmount.toLocaleString()}
                                                </p>
                                                <span className="text-[10px] text-slate-400">Total payable</span>
                                            </div>

                                            <div className="bg-white dark:bg-slate-900 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                                                <span className="text-xs text-slate-400">Balance Due</span>
                                                <p className={`text-base font-bold mt-0.5 ${pricingBreakdown.balanceDue > 0 ? 'text-amber-600' : 'text-emerald-600'}`}>
                                                    {selectedPackage?.currency} {pricingBreakdown.balanceDue.toLocaleString()}
                                                </p>
                                                <span className="text-[10px] text-slate-400">{pricingBreakdown.balanceDue === 0 ? 'Fully Paid' : 'Pending balance'}</span>
                                            </div>
                                        </div>

                                        {/* Amount Received Input */}
                                        <div className="pt-2 border-t border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                                            <div className="space-y-1.5">
                                                <Label htmlFor="amount_paid" className="text-xs font-semibold">Amount Received Now *</Label>
                                                <Input
                                                    id="amount_paid"
                                                    type="number"
                                                    step="0.01"
                                                    value={data.amount_paid}
                                                    onChange={e => setData('amount_paid', e.target.value)}
                                                    className={errors.amount_paid ? 'border-red-500' : ''}
                                                />
                                                {errors.amount_paid && <p className="text-xs text-red-500">{errors.amount_paid}</p>}
                                            </div>

                                            <div className="flex items-center gap-2 pb-0.5">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => setData('amount_paid', '0')}
                                                    className="text-xs"
                                                >
                                                    Unpaid (0)
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => setData('amount_paid', String(pricingBreakdown.billedAmount))}
                                                    className="text-xs"
                                                >
                                                    Full Payment (100%)
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </>
                        )}

                        {/* STEP 5: Academic Year */}
                        {currentStep === 5 && (
                            <>
                                <CardHeader className="border-b border-slate-100 dark:border-slate-800 pb-4">
                                    <CardTitle className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <Calendar className="w-5 h-5 text-indigo-600" /> Step 5: Academic Year Calendar
                                    </CardTitle>
                                    <CardDescription>
                                        Set up the initial academic calendar session for this school tenant.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4 pt-5 max-w-xl">
                                    <div className="flex items-center space-x-2 pb-2">
                                        <Checkbox
                                            id="create_ay"
                                            checked={createAcademicYear}
                                            onCheckedChange={(chk) => setCreateAcademicYear(!!chk)}
                                        />
                                        <Label htmlFor="create_ay" className="text-sm font-medium cursor-pointer">
                                            Create initial academic year calendar now
                                        </Label>
                                    </div>

                                    {createAcademicYear && (
                                        <div className="space-y-4 pt-2 border-t border-slate-100 dark:border-slate-800">
                                            <div className="space-y-1.5">
                                                <Label htmlFor="ay_name" className="text-xs font-semibold">Academic Year Name *</Label>
                                                <Input
                                                    id="ay_name"
                                                    placeholder="e.g. Academic Year 2026-2027"
                                                    value={data.academic_year_name}
                                                    onChange={e => setData('academic_year_name', e.target.value)}
                                                />
                                            </div>

                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="ay_start" className="text-xs font-semibold">Session Start Date *</Label>
                                                    <Input
                                                        id="ay_start"
                                                        type="date"
                                                        value={data.academic_start}
                                                        onChange={e => setData('academic_start', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="ay_end" className="text-xs font-semibold">Session End Date *</Label>
                                                    <Input
                                                        id="ay_end"
                                                        type="date"
                                                        value={data.academic_end}
                                                        onChange={e => setData('academic_end', e.target.value)}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex items-center space-x-2 pt-2">
                                                <Checkbox
                                                    id="ay_curr"
                                                    checked={data.set_academic_current}
                                                    onCheckedChange={(chk) => setData('set_academic_current', !!chk)}
                                                />
                                                <Label htmlFor="ay_curr" className="text-xs text-slate-600 dark:text-slate-400 cursor-pointer">
                                                    Set as active / current academic session
                                                </Label>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </>
                        )}

                        {/* STEP 6: Custom Domain */}
                        {currentStep === 6 && (
                            <>
                                <CardHeader className="border-b border-slate-100 dark:border-slate-800 pb-4">
                                    <CardTitle className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <Globe className="w-5 h-5 text-indigo-600" /> Step 6: Custom Domain Setup (Optional)
                                    </CardTitle>
                                    <CardDescription>
                                        Optionally register a custom domain in Pending state. DNS verification occurs separately.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4 pt-5 max-w-xl">
                                    <div className="space-y-3">
                                        <div
                                            onClick={() => setHasCustomDomain(false)}
                                            className={`p-4 rounded-xl border cursor-pointer transition-all ${
                                                !hasCustomDomain
                                                    ? 'border-indigo-600 bg-indigo-50/30 dark:bg-indigo-950/20 ring-1 ring-indigo-600'
                                                    : 'border-slate-200 dark:border-slate-800'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <h4 className="font-bold text-sm text-slate-900 dark:text-white">Skip for now</h4>
                                                    <p className="text-xs text-slate-500 mt-0.5">Use platform subdomain and configure custom domain later.</p>
                                                </div>
                                                <div className={`w-4 h-4 rounded-full border flex items-center justify-center ${!hasCustomDomain ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300'}`}>
                                                    {!hasCustomDomain && <Check className="w-2.5 h-2.5 stroke-[3]" />}
                                                </div>
                                            </div>
                                        </div>

                                        <div
                                            onClick={() => setHasCustomDomain(true)}
                                            className={`p-4 rounded-xl border cursor-pointer transition-all ${
                                                hasCustomDomain
                                                    ? 'border-indigo-600 bg-indigo-50/30 dark:bg-indigo-950/20 ring-1 ring-indigo-600'
                                                    : 'border-slate-200 dark:border-slate-800'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <h4 className="font-bold text-sm text-slate-900 dark:text-white">Add Custom Domain</h4>
                                                    <p className="text-xs text-slate-500 mt-0.5">Initialize pending custom domain entry for DNS verification.</p>
                                                </div>
                                                <div className={`w-4 h-4 rounded-full border flex items-center justify-center ${hasCustomDomain ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300'}`}>
                                                    {hasCustomDomain && <Check className="w-2.5 h-2.5 stroke-[3]" />}
                                                </div>
                                            </div>

                                            {hasCustomDomain && (
                                                <div className="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 space-y-2">
                                                    <Label htmlFor="custom_domain" className="text-xs font-semibold">Custom Hostname *</Label>
                                                    <Input
                                                        id="custom_domain"
                                                        placeholder="e.g. app.greenschool.edu.pk"
                                                        value={data.custom_domain}
                                                        onChange={e => setData('custom_domain', e.target.value.toLowerCase())}
                                                    />
                                                    <p className="text-[11px] text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 p-2 rounded border border-amber-200 dark:border-amber-800">
                                                        Domain will be created with status <strong>Pending</strong>. Operator will need to verify DNS CNAME pointing to <code>tenants.edusystem.store</code> before activation.
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </>
                        )}

                        {/* STEP 7: Final Review & Confirmation */}
                        {currentStep === 7 && (
                            <>
                                <CardHeader className="border-b border-slate-100 dark:border-slate-800 pb-4">
                                    <CardTitle className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <CheckCircle2 className="w-5 h-5 text-indigo-600" /> Step 7: Final Review & Confirmation
                                    </CardTitle>
                                    <CardDescription>
                                        Verify all configuration details before executing atomic school and subscription creation.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6 pt-5">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        {/* School Summary */}
                                        <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                                            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                                <SchoolIcon className="w-3.5 h-3.5" /> School Tenant
                                            </h4>
                                            <p className="font-bold text-slate-900 dark:text-white text-base">{data.name}</p>
                                            <p className="text-xs text-slate-600 dark:text-slate-400">
                                                Code: <strong className="text-slate-900 dark:text-white">{data.code}</strong> &bull; Slug: <code>{data.slug}</code>
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {data.city}, {data.state}, {data.country} ({data.timezone})
                                            </p>
                                        </div>

                                        {/* Admin Summary */}
                                        <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                                            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                                <UserCheck className="w-3.5 h-3.5" /> Administrator
                                            </h4>
                                            <p className="font-bold text-slate-900 dark:text-white text-base">{data.admin_name}</p>
                                            <p className="text-xs text-slate-600 dark:text-slate-400 font-mono">{data.admin_email}</p>
                                            <Badge variant="outline" className="text-[10px]">Role: school-admin</Badge>
                                        </div>

                                        {/* Commercial Package & Billing Summary */}
                                        <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2 md:col-span-2">
                                            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                                <CreditCard className="w-3.5 h-3.5" /> Commercial Subscription & Payment
                                            </h4>
                                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-1">
                                                <div>
                                                    <span className="text-[11px] text-slate-400">Package Tier</span>
                                                    <p className="font-bold text-slate-900 dark:text-white">{selectedPackage?.name}</p>
                                                    <span className="text-[10px] text-slate-500">{selectedPackage?.modules.length} Modules</span>
                                                </div>
                                                <div>
                                                    <span className="text-[11px] text-slate-400">Commitment Term</span>
                                                    <p className="font-bold text-slate-900 dark:text-white">{data.billing_term_months} Months</p>
                                                    <span className="text-[10px] text-emerald-600 font-semibold">{pricingBreakdown.autoDiscountPercent}% Auto Discount</span>
                                                </div>
                                                <div>
                                                    <span className="text-[11px] text-slate-400">Validity Period</span>
                                                    <p className="font-bold text-slate-900 dark:text-white">{data.start_date}</p>
                                                    <span className="text-[10px] text-slate-500">to {calculatedEndDate}</span>
                                                </div>
                                                <div>
                                                    <span className="text-[11px] text-indigo-600 dark:text-indigo-400 font-semibold">Payable Billed</span>
                                                    <p className="text-base font-extrabold text-indigo-900 dark:text-indigo-100">
                                                        {selectedPackage?.currency} {pricingBreakdown.billedAmount.toLocaleString()}
                                                    </p>
                                                    <span className="text-[10px] text-slate-500">Paid: {pricingBreakdown.amountReceived.toLocaleString()}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </>
                        )}

                        {/* Footer Controls */}
                        <div className="flex items-center justify-between p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 rounded-b-xl">
                            {currentStep > 1 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={prevStep}
                                    className="gap-1.5 text-xs"
                                >
                                    <ChevronLeft className="w-4 h-4" /> Back
                                </Button>
                            ) : (
                                <div />
                            )}

                            {currentStep < STEPS.length ? (
                                <Button
                                    type="button"
                                    onClick={nextStep}
                                    className="bg-indigo-600 hover:bg-indigo-700 text-white gap-1.5 text-xs"
                                >
                                    Next: {STEPS[currentStep].name} <ChevronRight className="w-4 h-4" />
                                </Button>
                            ) : (
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-emerald-600 hover:bg-emerald-700 text-white gap-2 font-bold px-6"
                                >
                                    {processing ? (
                                        <>Creating School...</>
                                    ) : (
                                        <>
                                            <Check className="w-4 h-4 stroke-[3]" /> Create School &amp; Subscription
                                        </>
                                    )}
                                </Button>
                            )}
                        </div>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
