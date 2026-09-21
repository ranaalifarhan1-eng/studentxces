import { useState, useMemo } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
    ArrowLeft,
    ChevronRight,
    Check,
    AlertCircle,
    Receipt,
    Percent,
    Banknote,
    ShieldCheck,
    CheckCircle2,
    Lock,
    Plus,
    X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { useCurrency } from '@/lib/currency';
import type { PageProps, SchoolClass, Section, AcademicYear } from '@/Types';

interface FeeCategory {
    id: number;
    name: string;
    type: string;
}

interface FeeStructure {
    id: number;
    school_id?: number;
    class_id: number;
    fee_category_id: number;
    academic_year: string;
    amount: string | number;
    frequency: string;
    is_optional: boolean;
    admission_voucher_policy?: 'required' | 'optional' | 'excluded';
    due_date?: string | null;
    description?: string | null;
    fee_category?: FeeCategory;
}

interface Props extends PageProps {
    classes: Pick<SchoolClass, 'id' | 'name'>[];
    sections: (Pick<Section, 'id' | 'name'> & { class_id: number })[];
    academicYears?: AcademicYear[];
    currentAcademicYear?: AcademicYear | null;
    feeStructures?: FeeStructure[];
    canAssignFees?: boolean;
    canDiscountFees?: boolean;
}

const schema = z.object({
    // Personal
    first_name:      z.string().min(1, 'First name is required'),
    last_name:       z.string().optional(),
    gender:          z.enum(['male', 'female', 'other']),
    date_of_birth:   z.string().optional(),
    blood_group:     z.string().optional(),
    religion:        z.string().optional(),
    nationality:     z.string().optional(),
    phone:           z.string().optional(),
    email:           z.string().email().optional().or(z.literal('')),
    address:         z.string().optional(),
    category:        z.enum(['general', 'disabled', 'quota']),
    status:          z.enum(['active', 'alumni', 'transferred', 'inactive']),
    admission_date:  z.string().optional(),
    previous_school: z.string().optional(),
    roll_no:         z.string().optional(),
    // Class
    class_id:   z.coerce.number().int().positive('Select a class'),
    section_id: z.coerce.number().int().positive().nullable().optional(),
    // Guardian
    guardian: z.object({
        name:       z.string().min(1, 'Guardian name is required'),
        relation:   z.string().min(1, 'Relation is required'),
        phone:      z.string().optional(),
        email:      z.string().email().optional().or(z.literal('')),
        occupation: z.string().optional(),
        address:    z.string().optional(),
    }),
});

type FormData = z.infer<typeof schema>;

const STEPS = [
    'Personal Info',
    'Class & Roll',
    'Guardian Info',
    'Fee Setup',
    'Review & Admit',
];

const FREQ_LABELS: Record<string, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    annual: 'Annual',
    one_time: 'One-Time',
};

const FREQ_COLORS: Record<string, string> = {
    monthly: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    quarterly: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    annual: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
    one_time: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
};

export default function CreateStudent() {
    const {
        classes = [],
        sections = [],
        academicYears = [],
        currentAcademicYear = null,
        feeStructures = [],
        canAssignFees = false,
        canDiscountFees = false,
    } = usePage<Props>().props;

    const { format: formatMoney } = useCurrency();
    const [step, setStep] = useState(0);

    // Initial dates
    const todayStr = new Date().toISOString().slice(0, 10);
    const initialMonthStr = todayStr.slice(0, 7); // e.g. "2026-09"
    const initialDueDate = `${initialMonthStr}-25`;

    const {
        register,
        handleSubmit,
        setValue,
        watch,
        trigger,
        setError,
        clearErrors,
        formState: { errors, isSubmitting },
    } = useForm<FormData>({
        resolver: zodResolver(schema),
        defaultValues: {
            gender: 'male',
            category: 'general',
            status: 'active',
            nationality: 'Pakistani',
            admission_date: todayStr,
            guardian: { relation: 'Father' },
        },
    });

    const watchedClassId = watch('class_id');
    const watchedAdmissionDate = watch('admission_date');
    const visibleSections = watchedClassId
        ? sections.filter((s) => s.class_id === Number(watchedClassId))
        : [];

    // Financial Step State
    const defaultAy = currentAcademicYear ?? academicYears[0] ?? null;
    const [selectedAyId, setSelectedAyId] = useState<number | null>(defaultAy?.id ?? null);
    const [billingStartMonth, setBillingStartMonth] = useState<string>(
        watchedAdmissionDate ? watchedAdmissionDate.slice(0, 7) : initialMonthStr
    );
    const [selectedOptionalIds, setSelectedOptionalIds] = useState<number[]>([]);
    const [selectedVoucherStructureIds, setSelectedVoucherStructureIds] = useState<number[]>([]);

    // Concession State
    const [showConcession, setShowConcession] = useState<boolean>(false);
    const [concessionTitle, setConcessionTitle] = useState<string>('Sibling Concession');
    const [concessionCategoryId, setConcessionCategoryId] = useState<string>(''); // '' = all / general
    const [concessionType, setConcessionType] = useState<'percentage' | 'fixed'>('percentage');
    const [concessionValue, setConcessionValue] = useState<string>('20');
    const [concessionError, setConcessionError] = useState<string | null>(null);

    // First Challan State
    const [generateFirstChallan, setGenerateFirstChallan] = useState<boolean>(true);
    const [dueDate, setDueDate] = useState<string>(initialDueDate);
    const [dueDateError, setDueDateError] = useState<string | null>(null);
    const [financialNotes, setFinancialNotes] = useState<string>('');

    // Active Academic Year object
    const selectedAcademicYear = useMemo(() => {
        if (!selectedAyId) return defaultAy;
        return academicYears.find((ay) => ay.id === selectedAyId) ?? defaultAy;
    }, [selectedAyId, academicYears, defaultAy]);

    // Active Fee Structures for this School, Class & Academic Year
    const classStructures = useMemo(() => {
        if (!watchedClassId) return [];
        return feeStructures.filter((s) => {
            const matchesClass = s.class_id === Number(watchedClassId);
            const matchesAy = selectedAcademicYear ? s.academic_year === selectedAcademicYear.name : true;
            return matchesClass && matchesAy;
        });
    }, [feeStructures, watchedClassId, selectedAcademicYear]);

    const mandatoryStructures = useMemo(
        () => classStructures.filter((s) => !s.is_optional),
        [classStructures]
    );
    const optionalStructures = useMemo(
        () => classStructures.filter((s) => s.is_optional),
        [classStructures]
    );

    // Active assigned structures (all mandatory + selected optional)
    const assignedStructures = useMemo(() => {
        const optionalAssigned = optionalStructures.filter((s) => selectedOptionalIds.includes(s.id));
        return [...mandatoryStructures, ...optionalAssigned];
    }, [mandatoryStructures, optionalStructures, selectedOptionalIds]);

    // Determine which structures are included on the first admission voucher
    // Enforces:
    // - Structure must be assigned
    // - admission_voucher_policy === 'required' -> always included
    // - admission_voucher_policy === 'excluded' -> never included
    // - admission_voucher_policy === 'optional' -> included if operator checked it
    const firstVoucherStructures = useMemo(() => {
        if (!generateFirstChallan) return [];
        return assignedStructures.filter((st) => {
            const policy = st.admission_voucher_policy || 'optional';
            if (policy === 'excluded') return false;
            if (policy === 'required') return true;
            return selectedVoucherStructureIds.includes(st.id);
        });
    }, [generateFirstChallan, assignedStructures, selectedVoucherStructureIds]);

    // Assigned structures deferred to later billing
    const laterBillingStructures = useMemo(() => {
        const voucherIds = new Set(firstVoucherStructures.map((s) => s.id));
        return assignedStructures.filter((st) => !voucherIds.has(st.id));
    }, [assignedStructures, firstVoucherStructures]);

    // Sync voucher structure selection defaults whenever class structures change
    useMemo(() => {
        const defaultVoucherIds = classStructures
            .filter((s) => (!s.is_optional || selectedOptionalIds.includes(s.id)) && s.admission_voucher_policy !== 'excluded')
            .map((s) => s.id);
        setSelectedVoucherStructureIds(defaultVoucherIds);
    }, [watchedClassId, selectedAyId]);

    // Unique Categories in active structures for Concession "Applies To"
    const applicableCategories = useMemo(() => {
        const map = new Map<number, string>();
        classStructures.forEach((st) => {
            if (st.fee_category) {
                map.set(st.fee_category.id, st.fee_category.name);
            }
        });
        return Array.from(map.entries()).map(([id, name]) => ({ id, name }));
    }, [classStructures]);

    // Financial Calculation for First Voucher Live Preview
    const firstVoucherSummary = useMemo(() => {
        let grossCents = 0;
        firstVoucherStructures.forEach((st) => {
            grossCents += Math.round(Number(st.amount) * 100);
        });

        let concessionCents = 0;
        let applicableCategoryName = '';

        if (showConcession && concessionTitle.trim() && Number(concessionValue) > 0) {
            let applicableGrossCents = grossCents;
            if (concessionCategoryId !== '') {
                const targetCatId = Number(concessionCategoryId);
                const targetCat = applicableCategories.find((c) => c.id === targetCatId);
                applicableCategoryName = targetCat ? targetCat.name : '';

                applicableGrossCents = firstVoucherStructures
                    .filter((st) => st.fee_category_id === targetCatId)
                    .reduce((acc, st) => acc + Math.round(Number(st.amount) * 100), 0);
            }

            if (applicableGrossCents > 0) {
                if (concessionType === 'percentage') {
                    const pct = Math.min(100, Math.max(0, Number(concessionValue)));
                    concessionCents = Math.min(applicableGrossCents, Math.round((applicableGrossCents * pct) / 100));
                } else {
                    const fixedCents = Math.round(Number(concessionValue) * 100);
                    concessionCents = Math.min(applicableGrossCents, fixedCents);
                }
            }
        }

        const netCents = Math.max(0, grossCents - concessionCents);

        return {
            grossCents,
            concessionCents,
            netCents,
            grossFormatted: formatMoney(grossCents / 100),
            concessionFormatted: formatMoney(concessionCents / 100),
            netFormatted: formatMoney(netCents / 100),
            applicableCategoryName,
        };
    }, [firstVoucherStructures, showConcession, concessionTitle, concessionCategoryId, concessionType, concessionValue, applicableCategories, formatMoney]);

    const handleToggleOptional = (id: number) => {
        setSelectedOptionalIds((prev) => {
            const exists = prev.includes(id);
            if (exists) {
                // Removing assignment: also remove from voucher selection
                setSelectedVoucherStructureIds((vPrev) => vPrev.filter((vId) => vId !== id));
                return prev.filter((item) => item !== id);
            } else {
                // Adding assignment: if not excluded, add to voucher selection by default
                const st = classStructures.find((s) => s.id === id);
                if (st && st.admission_voucher_policy !== 'excluded') {
                    setSelectedVoucherStructureIds((vPrev) => Array.from(new Set([...vPrev, id])));
                }
                return [...prev, id];
            }
        });
    };

    const handleToggleVoucher = (id: number) => {
        const st = classStructures.find((s) => s.id === id);
        if (!st || st.admission_voucher_policy === 'required' || st.admission_voucher_policy === 'excluded') {
            return;
        }
        setSelectedVoucherStructureIds((prev) =>
            prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
        );
    };

    // Step Transition Validation
    const handleNextStep = async () => {
        clearErrors();
        if (step === 0) {
            const valid = await trigger(['first_name', 'gender']);
            if (!valid) return;
            setStep(1);
        } else if (step === 1) {
            const valid = await trigger(['class_id']);
            if (!valid || !watchedClassId) {
                setError('class_id', { message: 'Please select a class before configuring fees.' });
                return;
            }
            setStep(2);
        } else if (step === 2) {
            const valid = await trigger(['guardian.name', 'guardian.relation']);
            if (!valid) return;
            setStep(3);
        } else if (step === 3) {
            let hasError = false;
            setConcessionError(null);
            setDueDateError(null);

            if (!billingStartMonth) {
                setError('class_id' as any, { message: 'Billing start month is required.' });
                hasError = true;
            }

            if (canAssignFees && canDiscountFees && showConcession) {
                if (!concessionTitle.trim()) {
                    setConcessionError('Concession title is required.');
                    hasError = true;
                } else if (Number(concessionValue) <= 0 || isNaN(Number(concessionValue))) {
                    setConcessionError('Concession value must be greater than 0.');
                    hasError = true;
                } else if (concessionType === 'percentage') {
                    if (Number(concessionValue) > 100) {
                        setConcessionError('Discount cannot exceed 100%.');
                        hasError = true;
                    }
                } else if (concessionType === 'fixed') {
                    let applicableGross = 0;
                    if (concessionCategoryId !== '') {
                        applicableGross = assignedStructures
                            .filter((st) => st.fee_category_id === Number(concessionCategoryId))
                            .reduce((acc, st) => acc + Number(st.amount), 0);
                    } else {
                        applicableGross = assignedStructures.reduce((acc, st) => acc + Number(st.amount), 0);
                    }
                    if (applicableGross > 0 && Number(concessionValue) > applicableGross) {
                        setConcessionError('Fixed concession cannot exceed the applicable fee.');
                        hasError = true;
                    }
                }
            }

            if (canAssignFees && generateFirstChallan) {
                if (!dueDate) {
                    setDueDateError('Due date is required when generating a fee voucher.');
                    hasError = true;
                } else {
                    const startMonthPrefix = billingStartMonth ? `${billingStartMonth}-01` : todayStr;
                    if (dueDate < startMonthPrefix) {
                        setDueDateError('Due date cannot be before the voucher issue period.');
                        hasError = true;
                    }
                }
            }

            if (hasError) return;
            setStep(4);
        }
    };

    const onSubmit = (formData: FormData) => {
        const payload: Record<string, any> = { ...formData };

        if (!canAssignFees) {
            // Operator without finance permissions: strip financial fields
            delete payload.fee_structure_ids;
            delete payload.first_voucher_structure_ids;
            delete payload.initialize_fees;
            delete payload.generate_first_challan;
            delete payload.concession;
            delete payload.academic_year_id;
            delete payload.billing_start_month;
            delete payload.due_date;
            delete payload.financial_notes;
        } else {
            // Operator with finance permissions
            const mandatoryIds = mandatoryStructures.map((s) => s.id);
            const combinedIds = Array.from(new Set([...mandatoryIds, ...selectedOptionalIds]));

            payload.academic_year_id = selectedAcademicYear?.id ?? null;
            payload.fee_structure_ids = combinedIds;
            payload.first_voucher_structure_ids = generateFirstChallan
                ? firstVoucherStructures.map((s) => s.id)
                : [];
            payload.billing_start_month = billingStartMonth ? `${billingStartMonth}-01` : null;
            payload.initialize_fees = true;
            payload.generate_first_challan = generateFirstChallan;
            payload.financial_notes = financialNotes || null;

            if (generateFirstChallan && dueDate) {
                payload.due_date = dueDate;
            } else {
                delete payload.due_date;
            }

            if (canDiscountFees && showConcession && concessionTitle.trim() && Number(concessionValue) > 0) {
                payload.concession = {
                    title: concessionTitle.trim(),
                    fee_category_id: concessionCategoryId ? Number(concessionCategoryId) : null,
                    type: concessionType,
                    value: Number(concessionValue),
                };
            } else {
                delete payload.concession;
            }
        }

        router.post('/school/students', payload, {
            onError: (errs) => {
                Object.entries(errs).forEach(([f, m]) => {
                    setError(f as keyof FormData, { message: m });
                });
            },
        });
    };

    const Field = ({
        name,
        label,
        placeholder,
        type = 'text',
        required = false,
    }: {
        name: string;
        label: string;
        placeholder?: string;
        type?: string;
        required?: boolean;
    }) => {
        const keys = name.split('.');
        const err =
            keys.length === 2
                ? (errors as Record<string, Record<string, { message?: string }>>)[keys[0]]?.[keys[1]]
                : (errors as Record<string, { message?: string }>)[name];
        return (
            <div className="space-y-1.5">
                <Label className="text-sm font-medium">
                    {label}
                    {required && <span className="text-red-500 ml-1">*</span>}
                </Label>
                <Input
                    type={type}
                    placeholder={placeholder}
                    className="h-9"
                    {...register(name as keyof FormData)}
                />
                {err && <p className="text-xs text-red-500">{err.message as string}</p>}
            </div>
        );
    };

    const selectedClassName = classes.find((c) => c.id === Number(watchedClassId))?.name || '—';
    const selectedSectionName = visibleSections.find((s) => s.id === Number(watch('section_id')))?.name || '—';

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'Students', href: '/school/students' },
                { label: 'Admit Student' },
            ]}
        >
            <Head title="Admit Student" />

            <div className="max-w-3xl">
                {/* Header */}
                <div className="flex items-center gap-3 mb-6">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href="/school/students">
                            <ArrowLeft className="w-4 h-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-xl font-bold text-slate-900 dark:text-white">Admit Student</h1>
                        <p className="text-sm text-slate-500">
                            Step {step + 1} of {STEPS.length} — {STEPS[step]}
                        </p>
                    </div>
                </div>

                {/* Step Indicators */}
                <div className="flex items-center gap-2 mb-6 overflow-x-auto pb-1">
                    {STEPS.map((s, i) => (
                        <div key={s} className="flex items-center gap-2 shrink-0">
                            <button
                                type="button"
                                onClick={() => i < step && setStep(i)}
                                className={`flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold transition-colors ${
                                    i === step
                                        ? 'bg-indigo-600 text-white'
                                        : i < step
                                        ? 'bg-emerald-500 text-white cursor-pointer'
                                        : 'bg-slate-200 text-slate-400 dark:bg-slate-800'
                                }`}
                            >
                                {i < step ? <Check className="w-3.5 h-3.5" /> : i + 1}
                            </button>
                            <span
                                className={`text-xs hidden sm:block ${
                                    i === step
                                        ? 'text-slate-900 dark:text-white font-medium'
                                        : 'text-slate-400'
                                }`}
                            >
                                {s}
                            </span>
                            {i < STEPS.length - 1 && (
                                <ChevronRight className="w-3.5 h-3.5 text-slate-300 dark:text-slate-700" />
                            )}
                        </div>
                    ))}
                </div>

                {/* Server Error Notice */}
                {(errors as any).fee_setup && (
                    <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 flex items-center gap-2">
                        <AlertCircle className="w-4 h-4 shrink-0" />
                        <span>{(errors as any).fee_setup}</span>
                    </div>
                )}

                <form onSubmit={handleSubmit(onSubmit)}>
                    {/* Step 0 — Personal Info */}
                    {step === 0 && (
                        <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm">Personal Information</CardTitle>
                                <CardDescription className="text-xs">
                                    Basic bio and demographic records of the student.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <Field name="first_name" label="First Name" placeholder="John" required />
                                <Field name="last_name" label="Last Name" placeholder="Doe" />
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">
                                        Gender <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        defaultValue="male"
                                        onValueChange={(v) => setValue('gender', v as 'male' | 'female' | 'other')}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="male">Male</SelectItem>
                                            <SelectItem value="female">Female</SelectItem>
                                            <SelectItem value="other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <Field name="date_of_birth" label="Date of Birth" type="date" />
                                <Field name="blood_group" label="Blood Group" placeholder="A+" />
                                <Field name="religion" label="Religion" placeholder="Islam" />
                                <Field name="nationality" label="Nationality" placeholder="Pakistani" />
                                <Field name="phone" label="Phone" placeholder="+923000000000" />
                                <Field name="email" label="Email" placeholder="student@email.com" type="email" />
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Category</Label>
                                    <Select
                                        defaultValue="general"
                                        onValueChange={(v) =>
                                            setValue('category', v as 'general' | 'disabled' | 'quota')
                                        }
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="general">General</SelectItem>
                                            <SelectItem value="disabled">Disabled</SelectItem>
                                            <SelectItem value="quota">Quota</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Status</Label>
                                    <Select
                                        defaultValue="active"
                                        onValueChange={(v) =>
                                            setValue('status', v as 'active' | 'alumni' | 'transferred' | 'inactive')
                                        }
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="active">Active</SelectItem>
                                            <SelectItem value="inactive">Inactive</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="sm:col-span-2 space-y-1.5">
                                    <Label className="text-sm font-medium">Address</Label>
                                    <Textarea
                                        rows={2}
                                        className="resize-none"
                                        placeholder="House, Street, Area…"
                                        {...register('address')}
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Step 1 — Class & Roll */}
                    {step === 1 && (
                        <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm">Class Assignment</CardTitle>
                                <CardDescription className="text-xs">
                                    Assign student to class, section, and record admission metadata.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">
                                        Class <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={watchedClassId ? String(watchedClassId) : ''}
                                        onValueChange={(v) => {
                                            setValue('class_id', Number(v));
                                            setValue('section_id', null);
                                            setSelectedOptionalIds([]);
                                        }}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue placeholder="Select class" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {classes.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.class_id && (
                                        <p className="text-xs text-red-500">{errors.class_id.message}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Section</Label>
                                    <Select
                                        value={watch('section_id') ? String(watch('section_id')) : ''}
                                        onValueChange={(v) => setValue('section_id', Number(v))}
                                        disabled={visibleSections.length === 0}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue
                                                placeholder={
                                                    visibleSections.length === 0
                                                        ? 'Select class first'
                                                        : 'Select section'
                                                }
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {visibleSections.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <Field name="roll_no" label="Roll No" placeholder="01" />
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Admission Date</Label>
                                    <Input
                                        type="date"
                                        className="h-9"
                                        {...register('admission_date', {
                                            onChange: (e) => {
                                                const val = e.target.value;
                                                if (val && val.length >= 7) {
                                                    const m = val.slice(0, 7);
                                                    setBillingStartMonth(m);
                                                    setDueDate(`${m}-25`);
                                                }
                                            },
                                        })}
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <Field
                                        name="previous_school"
                                        label="Previous School"
                                        placeholder="Previous School Name"
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Step 2 — Guardian Info */}
                    {step === 2 && (
                        <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm">Guardian Information</CardTitle>
                                <CardDescription className="text-xs">
                                    Emergency contact and guardian relationship details.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <Field
                                    name="guardian.name"
                                    label="Guardian Name"
                                    placeholder="Mr. John Doe"
                                    required
                                />
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">
                                        Relation <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        defaultValue="Father"
                                        onValueChange={(v) => setValue('guardian.relation', v)}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {['Father', 'Mother', 'Guardian', 'Uncle', 'Aunt', 'Sibling'].map(
                                                (r) => (
                                                    <SelectItem key={r} value={r}>
                                                        {r}
                                                    </SelectItem>
                                                )
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <Field
                                    name="guardian.phone"
                                    label="Phone"
                                    placeholder="+923000000000"
                                />
                                <Field
                                    name="guardian.email"
                                    label="Email"
                                    type="email"
                                    placeholder="guardian@email.com"
                                />
                                <Field
                                    name="guardian.occupation"
                                    label="Occupation"
                                    placeholder="Business / Service"
                                />
                                <div className="sm:col-span-2 space-y-1.5">
                                    <Label className="text-sm font-medium">Address</Label>
                                    <Textarea
                                        rows={2}
                                        className="resize-none"
                                        {...register('guardian.address')}
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Step 3 — Fee Setup */}
                    {step === 3 && (
                        <div className="space-y-4">
                            {!canAssignFees ? (
                                <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                                    <CardHeader className="pb-3">
                                        <div className="flex items-center gap-2">
                                            <ShieldCheck className="w-5 h-5 text-slate-500" />
                                            <CardTitle className="text-sm">Standard Class Fees (Informational)</CardTitle>
                                        </div>
                                        <CardDescription className="text-xs">
                                            Fee assignment and voucher generation are managed by staff with finance permissions (<code>fees.assign</code>). Standard class fees will apply automatically upon enrollment.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="rounded-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
                                            <table className="w-full text-sm">
                                                <thead className="bg-slate-50 dark:bg-slate-900 text-slate-600 dark:text-slate-400 text-xs">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left">Fee Head</th>
                                                        <th className="px-3 py-2 text-left">Frequency</th>
                                                        <th className="px-3 py-2 text-right">Amount</th>
                                                        <th className="px-3 py-2 text-center">Requirement</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                                    {mandatoryStructures.length === 0 ? (
                                                        <tr>
                                                            <td colSpan={4} className="px-3 py-4 text-center text-slate-400 text-xs">
                                                                No mandatory fee structures found for this class.
                                                            </td>
                                                        </tr>
                                                    ) : (
                                                        mandatoryStructures.map((st) => (
                                                            <tr key={st.id}>
                                                                <td className="px-3 py-2 font-medium text-slate-900 dark:text-white">
                                                                    {st.fee_category?.name || 'General Fee'}
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    <Badge className={`border-0 text-xs ${FREQ_COLORS[st.frequency] || ''}`}>
                                                                        {FREQ_LABELS[st.frequency] || st.frequency}
                                                                    </Badge>
                                                                </td>
                                                                <td className="px-3 py-2 text-right font-semibold text-slate-900 dark:text-white">
                                                                    {formatMoney(st.amount)}
                                                                </td>
                                                                <td className="px-3 py-2 text-center">
                                                                    <Badge variant="outline" className="border-indigo-300 bg-indigo-50 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300 text-xs">
                                                                        Required
                                                                    </Badge>
                                                                </td>
                                                            </tr>
                                                        ))
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </CardContent>
                                </Card>
                            ) : (
                                <>
                                    {/* Main Fee Setup Card */}
                                    <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                                        <CardHeader className="pb-3">
                                            <div className="flex items-center justify-between flex-wrap gap-2">
                                                <div>
                                                    <CardTitle className="text-sm">Fee Setup</CardTitle>
                                                    <CardDescription className="text-xs">
                                                        Configure the student's recurring and one-time school charges.
                                                    </CardDescription>
                                                </div>
                                                {selectedAcademicYear && (
                                                    <Badge variant="outline" className="text-xs font-semibold border-slate-300">
                                                        AY: {selectedAcademicYear.name}
                                                    </Badge>
                                                )}
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {/* AY and Billing Starts From Controls */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-100 dark:border-slate-800">
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                        Academic Year
                                                    </Label>
                                                    <Select
                                                        value={selectedAyId ? String(selectedAyId) : ''}
                                                        onValueChange={(v) => setSelectedAyId(Number(v))}
                                                    >
                                                        <SelectTrigger className="h-9 bg-white dark:bg-slate-900">
                                                            <SelectValue placeholder="Select Year">
                                                                {selectedAcademicYear ? `${selectedAcademicYear.name}${selectedAcademicYear.is_current ? ' (Current)' : ''}` : 'Select Year'}
                                                            </SelectValue>
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {academicYears.map((ay) => (
                                                                <SelectItem key={ay.id} value={String(ay.id)}>
                                                                    {ay.name} {ay.is_current ? '(Current)' : ''}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                        Billing Starts From <span className="text-red-500">*</span>
                                                    </Label>
                                                    <Input
                                                        type="month"
                                                        value={billingStartMonth}
                                                        onChange={(e) => {
                                                            setBillingStartMonth(e.target.value);
                                                            if (e.target.value) {
                                                                setDueDate(`${e.target.value}-25`);
                                                            }
                                                        }}
                                                        className="h-9 bg-white dark:bg-slate-900"
                                                    />
                                                    <p className="text-[11px] text-slate-500">
                                                        Full monthly fee applies for the selected starting month. (No proration in V1).
                                                    </p>
                                                </div>
                                            </div>

                                            {/* Fee Structures Table */}
                                            <div className="space-y-2">
                                                <Label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                    Applicable Fee Heads for {selectedClassName}
                                                </Label>

                                                {classStructures.length === 0 ? (
                                                    <div className="rounded-lg border border-dashed border-amber-200 dark:border-amber-900/50 bg-amber-50/50 dark:bg-amber-950/20 p-6 text-center space-y-2">
                                                        <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
                                                            No fee structures configured for {selectedClassName} in {selectedAcademicYear?.name || 'this academic year'}.
                                                        </p>
                                                        <p className="text-xs text-amber-700 dark:text-amber-400">
                                                            You can still admit the student and assign fees later, or configure fee structures now:
                                                        </p>
                                                        <div>
                                                            <a
                                                                href="/school/fees/structures"
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 underline underline-offset-2"
                                                            >
                                                                Configure Fee Structures &rarr;
                                                            </a>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="rounded-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
                                                        <table className="w-full text-sm">
                                                            <thead className="bg-slate-50 dark:bg-slate-900 text-slate-600 dark:text-slate-400 text-xs">
                                                                <tr>
                                                                    <th className="px-3 py-2.5 text-left">Fee Head</th>
                                                                    <th className="px-3 py-2.5 text-left">Frequency</th>
                                                                    <th className="px-3 py-2.5 text-right">Standard Amount</th>
                                                                    <th className="px-3 py-2.5 text-center">Student Assignment</th>
                                                                    <th className="px-3 py-2.5 text-center">First Voucher</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                                                {classStructures.map((st) => {
                                                                    const isAssigned = !st.is_optional || selectedOptionalIds.includes(st.id);
                                                                    const policy = st.admission_voucher_policy || 'optional';
                                                                    const isVoucherSelected = selectedVoucherStructureIds.includes(st.id);

                                                                    return (
                                                                        <tr
                                                                            key={st.id}
                                                                            className={
                                                                                isAssigned
                                                                                    ? 'bg-white dark:bg-slate-950'
                                                                                    : 'bg-slate-50/50 dark:bg-slate-900/30 opacity-75'
                                                                            }
                                                                        >
                                                                            <td className="px-3 py-2.5">
                                                                                <div className="flex items-center gap-1.5">
                                                                                    {!st.is_optional && <Lock className="w-3.5 h-3.5 text-indigo-600 shrink-0" />}
                                                                                    <span className="font-medium text-slate-900 dark:text-white">
                                                                                        {st.fee_category?.name || 'General Fee'}
                                                                                    </span>
                                                                                </div>
                                                                                <span className="text-[11px] text-slate-400">
                                                                                    {!st.is_optional ? 'Mandatory class charge' : 'Optional charge'}
                                                                                </span>
                                                                            </td>
                                                                            <td className="px-3 py-2.5">
                                                                                <Badge className={`border-0 text-xs ${FREQ_COLORS[st.frequency] || ''}`}>
                                                                                    {FREQ_LABELS[st.frequency] || st.frequency}
                                                                                </Badge>
                                                                            </td>
                                                                            <td className="px-3 py-2.5 text-right font-semibold text-slate-900 dark:text-white">
                                                                                {formatMoney(st.amount)}
                                                                            </td>
                                                                            <td className="px-3 py-2.5 text-center">
                                                                                {!st.is_optional ? (
                                                                                    <Badge className="bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border-0 text-xs font-semibold">
                                                                                        Required
                                                                                    </Badge>
                                                                                ) : (
                                                                                    <label className="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                                                                        <input
                                                                                            type="checkbox"
                                                                                            checked={selectedOptionalIds.includes(st.id)}
                                                                                            onChange={() => handleToggleOptional(st.id)}
                                                                                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4"
                                                                                        />
                                                                                        <span className="text-xs font-medium text-slate-700 dark:text-slate-300">
                                                                                            {selectedOptionalIds.includes(st.id) ? 'Assigned' : 'Add to Student'}
                                                                                        </span>
                                                                                    </label>
                                                                                )}
                                                                            </td>
                                                                            <td className="px-3 py-2.5 text-center">
                                                                                {policy === 'required' ? (
                                                                                    <Badge className="bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border-0 text-xs font-semibold">
                                                                                        <Lock className="w-3 h-3 mr-1" /> Required
                                                                                    </Badge>
                                                                                ) : policy === 'excluded' ? (
                                                                                    <div className="inline-flex flex-col items-center">
                                                                                        <Badge variant="outline" className="text-slate-500 bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-[11px] font-medium">
                                                                                            Later Billing
                                                                                        </Badge>
                                                                                        <span className="text-[10px] text-slate-400">Regular billing cycle</span>
                                                                                    </div>
                                                                                ) : (
                                                                                    !isAssigned ? (
                                                                                        <span className="text-xs text-slate-300 dark:text-slate-600 select-none" title="Must be assigned to student first">
                                                                                            —
                                                                                        </span>
                                                                                    ) : (
                                                                                        <label className="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                                                                            <input
                                                                                                type="checkbox"
                                                                                                checked={isVoucherSelected}
                                                                                                onChange={() => handleToggleVoucher(st.id)}
                                                                                                className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4"
                                                                                            />
                                                                                            <span className="text-xs font-medium text-slate-700 dark:text-slate-300">
                                                                                                {isVoucherSelected ? 'Included' : 'Defer to Later'}
                                                                                            </span>
                                                                                        </label>
                                                                                    )
                                                                                )}
                                                                            </td>
                                                                        </tr>
                                                                    );
                                                                })}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* Concession / Discount Card */}
                                    <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                                        <CardHeader className="pb-3">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <CardTitle className="text-sm flex items-center gap-1.5">
                                                        <Percent className="w-4 h-4 text-emerald-600" />
                                                        Concession (Optional)
                                                    </CardTitle>
                                                    <CardDescription className="text-xs">
                                                        Apply an authorized discount or scholarship to this student's fees.
                                                    </CardDescription>
                                                </div>
                                                {!canDiscountFees ? (
                                                    <Badge variant="outline" className="text-xs text-slate-400">
                                                        No concession permission
                                                    </Badge>
                                                ) : !showConcession ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setShowConcession(true)}
                                                        className="text-xs inline-flex items-center gap-1"
                                                    >
                                                        <Plus className="w-3.5 h-3.5" /> Add Concession
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => {
                                                            setShowConcession(false);
                                                            setConcessionError(null);
                                                        }}
                                                        className="text-xs text-red-600 hover:text-red-700 inline-flex items-center gap-1"
                                                    >
                                                        <X className="w-3.5 h-3.5" /> Remove
                                                    </Button>
                                                )}
                                            </div>
                                        </CardHeader>
                                        <CardContent>
                                            {!canDiscountFees ? (
                                                <p className="text-xs text-slate-500 italic">
                                                    You do not have permission ('fees.discount') to grant concessions. Existing standard class fees apply.
                                                </p>
                                            ) : !showConcession ? (
                                                <p className="text-xs text-slate-500">
                                                    No concession configured for this student.
                                                </p>
                                            ) : (
                                                <div className="space-y-3 pt-1">
                                                    {concessionError && (
                                                        <p className="text-xs text-red-600 font-medium">{concessionError}</p>
                                                    )}
                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                        <div className="space-y-1.5">
                                                            <Label className="text-xs font-semibold">Title / Reason <span className="text-red-500">*</span></Label>
                                                            <Input
                                                                value={concessionTitle}
                                                                onChange={(e) => {
                                                                    setConcessionTitle(e.target.value);
                                                                    setConcessionError(null);
                                                                }}
                                                                placeholder="e.g. Sibling Concession, Need-Based Aid"
                                                                className="h-9"
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label className="text-xs font-semibold">Applies To</Label>
                                                            <Select
                                                                value={concessionCategoryId}
                                                                onValueChange={(v) => {
                                                                    setConcessionCategoryId(v);
                                                                    setConcessionError(null);
                                                                }}
                                                            >
                                                                <SelectTrigger className="h-9">
                                                                    <SelectValue placeholder="All Applicable Fee Heads" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="">All Applicable Fee Heads (General)</SelectItem>
                                                                    {applicableCategories.map((c) => (
                                                                        <SelectItem key={c.id} value={String(c.id)}>
                                                                            {c.name}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label className="text-xs font-semibold">Type</Label>
                                                            <Select
                                                                value={concessionType}
                                                                onValueChange={(v) => {
                                                                    setConcessionType(v as 'percentage' | 'fixed');
                                                                    setConcessionError(null);
                                                                }}
                                                            >
                                                                <SelectTrigger className="h-9">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="percentage">Percentage (%)</SelectItem>
                                                                    <SelectItem value="fixed">Fixed Amount</SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label className="text-xs font-semibold">
                                                                {concessionType === 'percentage' ? 'Percentage (1–100%)' : 'Fixed Amount'} <span className="text-red-500">*</span>
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                step={concessionType === 'percentage' ? '1' : '0.01'}
                                                                min="0"
                                                                max={concessionType === 'percentage' ? '100' : undefined}
                                                                value={concessionValue}
                                                                onChange={(e) => {
                                                                    setConcessionValue(e.target.value);
                                                                    setConcessionError(null);
                                                                }}
                                                                placeholder={concessionType === 'percentage' ? '20' : '1000'}
                                                                className="h-9"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>

                                    {/* Live Financial Preview Card */}
                                    <Card className="dark:bg-slate-900 border-indigo-100 dark:border-indigo-950/60 bg-gradient-to-b from-indigo-50/20 to-transparent">
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm flex items-center gap-1.5 text-indigo-900 dark:text-indigo-200">
                                                <Banknote className="w-4 h-4 text-indigo-600" />
                                                Financial Breakdown (Live Preview)
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {/* Part 1: First Fee Voucher (Immediate) */}
                                            <div className="rounded-lg border border-indigo-100 dark:border-indigo-950 bg-white/70 dark:bg-slate-900/70 p-3 space-y-2">
                                                <div className="flex items-center justify-between border-b border-indigo-50 dark:border-indigo-950/80 pb-1.5">
                                                    <span className="text-xs font-semibold text-indigo-900 dark:text-indigo-200 flex items-center gap-1.5">
                                                        <Receipt className="w-3.5 h-3.5 text-indigo-600" />
                                                        First Fee Voucher (Immediate Charges)
                                                    </span>
                                                    {generateFirstChallan && dueDate && (
                                                        <span className="text-[11px] text-indigo-600 dark:text-indigo-400 font-medium">
                                                            Due: {dueDate}
                                                        </span>
                                                    )}
                                                </div>

                                                {!generateFirstChallan ? (
                                                    <p className="text-xs text-slate-500 italic py-1">
                                                        No initial voucher selected. All assigned charges will be billed in scheduled monthly billing.
                                                    </p>
                                                ) : firstVoucherStructures.length === 0 ? (
                                                    <p className="text-xs text-amber-600 dark:text-amber-400 italic py-1">
                                                        No charges selected for the first voucher. (Voucher will be 0.00 or skipped).
                                                    </p>
                                                ) : (
                                                    <div className="space-y-1 text-xs">
                                                        {firstVoucherStructures.map((st) => (
                                                            <div key={st.id} className="flex justify-between py-0.5 text-slate-700 dark:text-slate-300">
                                                                <span>
                                                                    {st.fee_category?.name || 'Fee Head'} ({FREQ_LABELS[st.frequency] || st.frequency})
                                                                </span>
                                                                <span className="font-medium text-slate-900 dark:text-white">
                                                                    {formatMoney(st.amount)}
                                                                </span>
                                                            </div>
                                                        ))}

                                                        <div className="flex justify-between pt-1 border-t border-slate-100 dark:border-slate-800 text-slate-600 dark:text-slate-400">
                                                            <span>Gross Voucher Total</span>
                                                            <span className="font-medium">{firstVoucherSummary.grossFormatted}</span>
                                                        </div>

                                                        {firstVoucherSummary.concessionCents > 0 && (
                                                            <div className="flex justify-between text-emerald-600 dark:text-emerald-400 font-medium">
                                                                <span>
                                                                    Concession: {concessionTitle} ({concessionType === 'percentage' ? `${concessionValue}%` : 'Fixed'}{firstVoucherSummary.applicableCategoryName ? ` on ${firstVoucherSummary.applicableCategoryName}` : ''})
                                                                </span>
                                                                <span>-{firstVoucherSummary.concessionFormatted}</span>
                                                            </div>
                                                        )}

                                                        <div className="flex justify-between pt-1.5 border-t border-slate-200 dark:border-slate-700 font-bold text-sm text-indigo-900 dark:text-indigo-200">
                                                            <span>Total Due on Admission Voucher</span>
                                                            <span>{firstVoucherSummary.netFormatted}</span>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Part 2: Ongoing Account Commitments (Later Billing) */}
                                            <div className="rounded-lg border border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/40 p-3 space-y-2">
                                                <div className="flex items-center justify-between border-b border-slate-200/60 dark:border-slate-800 pb-1.5">
                                                    <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                        Ongoing Account Commitments (Later Billing)
                                                    </span>
                                                    <span className="text-[11px] text-slate-400">
                                                        Billed in scheduled cycles
                                                    </span>
                                                </div>

                                                {laterBillingStructures.length === 0 ? (
                                                    <p className="text-xs text-slate-500 italic py-0.5">
                                                        All assigned fee heads are included on the first admission voucher.
                                                    </p>
                                                ) : (
                                                    <div className="space-y-1 text-xs">
                                                        {laterBillingStructures.map((st) => (
                                                            <div key={st.id} className="flex justify-between py-0.5 text-slate-600 dark:text-slate-400">
                                                                <span>
                                                                    {st.fee_category?.name || 'Fee Head'} ({FREQ_LABELS[st.frequency] || st.frequency})
                                                                </span>
                                                                <span className="font-medium text-slate-700 dark:text-slate-300">
                                                                    {formatMoney(st.amount)}
                                                                </span>
                                                            </div>
                                                        ))}
                                                        <p className="text-[11px] text-slate-400 pt-1 italic">
                                                            Will be billed in scheduled monthly/academic billing cycles starting from {billingStartMonth || 'selected period'}.
                                                        </p>
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* Generate First Fee Voucher (Challan) */}
                                    <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                                        <CardHeader className="pb-3">
                                            <div className="flex items-center gap-2">
                                                <Receipt className="w-4 h-4 text-indigo-600" />
                                                <CardTitle className="text-sm">Fee Voucher / Challan</CardTitle>
                                            </div>
                                            <CardDescription className="text-xs">
                                                Optionally generate the student's first fee voucher immediately after admission.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <label className="flex items-center gap-2.5 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={generateFirstChallan}
                                                    onChange={(e) => setGenerateFirstChallan(e.target.checked)}
                                                    className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4"
                                                />
                                                <span className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                    Generate first fee voucher after admission
                                                </span>
                                            </label>

                                            {generateFirstChallan && (
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                                    <div className="space-y-1.5">
                                                        <Label className="text-xs font-semibold">Billing Period</Label>
                                                        <Input
                                                            value={billingStartMonth}
                                                            disabled
                                                            className="h-9 bg-slate-50 dark:bg-slate-800 cursor-not-allowed"
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label className="text-xs font-semibold">
                                                            Due Date <span className="text-red-500">*</span>
                                                        </Label>
                                                        <Input
                                                            type="date"
                                                            value={dueDate}
                                                            onChange={(e) => setDueDate(e.target.value)}
                                                            className="h-9"
                                                        />
                                                        {dueDateError && (
                                                            <p className="text-xs text-red-500">{dueDateError}</p>
                                                        )}
                                                    </div>
                                                    <div className="sm:col-span-2 space-y-1.5">
                                                        <Label className="text-xs font-semibold">Financial Notes (Optional)</Label>
                                                        <Input
                                                            value={financialNotes}
                                                            onChange={(e) => setFinancialNotes(e.target.value)}
                                                            placeholder="e.g. Approved admission fee installment or special notes"
                                                            className="h-9"
                                                        />
                                                    </div>
                                                    <p className="sm:col-span-2 text-[11px] text-slate-500">
                                                        The voucher will be created with status Unpaid. Payments are recorded separately when money is collected.
                                                    </p>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </>
                            )}
                        </div>
                    )}

                    {/* Step 4 — Review & Admit */}
                    {step === 4 && (
                        <div className="space-y-4">
                            <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                                <CardHeader className="pb-3">
                                    <div className="flex items-center gap-2">
                                        <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                                        <CardTitle className="text-sm">Review & Admit Student</CardTitle>
                                    </div>
                                    <CardDescription className="text-xs">
                                        Verify all academic and financial parameters before finalizing admission.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {/* 2-column overview */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        {/* Left: Bio & Academic */}
                                        <div className="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-100 dark:border-slate-800 space-y-2 text-xs">
                                            <p className="font-semibold text-slate-800 dark:text-slate-200 text-sm border-b pb-1">
                                                Student & Academic
                                            </p>
                                            <div>
                                                <span className="text-slate-500">Full Name:</span>{' '}
                                                <span className="font-medium text-slate-900 dark:text-white">
                                                    {watch('first_name')} {watch('last_name') || ''}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500">Gender / Status:</span>{' '}
                                                <span className="font-medium capitalize text-slate-900 dark:text-white">
                                                    {watch('gender')} / {watch('status')}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500">Class & Section:</span>{' '}
                                                <span className="font-medium text-slate-900 dark:text-white">
                                                    {selectedClassName} ({selectedSectionName})
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500">Roll No:</span>{' '}
                                                <span className="font-medium text-slate-900 dark:text-white">
                                                    {watch('roll_no') || '—'}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500">Admission Date:</span>{' '}
                                                <span className="font-medium text-slate-900 dark:text-white">
                                                    {watch('admission_date') || '—'}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500">Guardian:</span>{' '}
                                                <span className="font-medium text-slate-900 dark:text-white">
                                                    {watch('guardian.name')} ({watch('guardian.relation')})
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500">Contact:</span>{' '}
                                                <span className="font-medium text-slate-900 dark:text-white">
                                                    {watch('phone') || watch('guardian.phone') || '—'}
                                                </span>
                                            </div>
                                        </div>

                                        {/* Right: Financial Setup */}
                                        <div className="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-100 dark:border-slate-800 space-y-3 text-xs">
                                            <p className="font-semibold text-slate-800 dark:text-slate-200 text-sm border-b pb-1">
                                                Financial Configuration
                                            </p>

                                            {!canAssignFees ? (
                                                <p className="text-slate-500 italic">
                                                    Fee setup is managed by the Finance department.
                                                </p>
                                            ) : (
                                                <>
                                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                                        <div>
                                                            <span className="text-slate-500">Academic Year:</span>{' '}
                                                            <span className="font-medium text-slate-900 dark:text-white">
                                                                {selectedAcademicYear?.name || '—'}
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span className="text-slate-500">Billing Starts From:</span>{' '}
                                                            <span className="font-medium text-slate-900 dark:text-white">
                                                                {billingStartMonth}
                                                            </span>
                                                        </div>
                                                    </div>

                                                    {/* 1. Account-Level Assigned Fees */}
                                                    <div className="pt-2 border-t border-slate-200 dark:border-slate-700">
                                                        <span className="text-slate-700 dark:text-slate-300 font-semibold">
                                                            1. Assigned Student Fees (Account Level):
                                                        </span>
                                                        <ul className="mt-1 space-y-1 pl-2">
                                                            {assignedStructures.length === 0 ? (
                                                                <li className="text-slate-400 italic">No fee structures assigned</li>
                                                            ) : (
                                                                assignedStructures.map((st) => (
                                                                    <li key={st.id} className="flex justify-between">
                                                                        <span className="text-slate-600 dark:text-slate-400">
                                                                            {st.fee_category?.name} ({FREQ_LABELS[st.frequency] || st.frequency})
                                                                        </span>
                                                                        <span className="font-medium text-slate-900 dark:text-white">
                                                                            {formatMoney(st.amount)}
                                                                        </span>
                                                                    </li>
                                                                ))
                                                            )}
                                                        </ul>
                                                    </div>

                                                    {/* Concession Note if configured */}
                                                    {showConcession && concessionTitle.trim() && (
                                                        <div className="pt-1.5 border-t border-slate-200 dark:border-slate-700 flex justify-between">
                                                            <span className="text-slate-500">Registered Concession:</span>
                                                            <span className="font-medium text-emerald-700 dark:text-emerald-300">
                                                                {concessionTitle} ({concessionType === 'percentage' ? `${concessionValue}%` : formatMoney(concessionValue)})
                                                            </span>
                                                        </div>
                                                    )}

                                                    {/* 2. First Fee Voucher (Immediate) */}
                                                    <div className="pt-2 border-t border-slate-200 dark:border-slate-700 space-y-1">
                                                        <div className="flex justify-between items-center">
                                                            <span className="text-slate-700 dark:text-slate-300 font-semibold">
                                                                2. First Fee Voucher (Immediate):
                                                            </span>
                                                            <span className="text-[11px] text-slate-500">
                                                                {generateFirstChallan ? `Due: ${dueDate || '—'}` : 'Not Requested'}
                                                            </span>
                                                        </div>

                                                        {!generateFirstChallan ? (
                                                            <p className="text-slate-400 italic pl-2">
                                                                No initial voucher requested. Fees will be billed in standard billing cycle.
                                                            </p>
                                                        ) : firstVoucherStructures.length === 0 ? (
                                                            <p className="text-amber-500 italic pl-2">
                                                                No charges selected for first voucher.
                                                            </p>
                                                        ) : (
                                                            <div className="pl-2 space-y-1">
                                                                {firstVoucherStructures.map((st) => (
                                                                    <div key={st.id} className="flex justify-between text-slate-600 dark:text-slate-400">
                                                                        <span>{st.fee_category?.name}</span>
                                                                        <span className="font-medium text-slate-800 dark:text-slate-200">{formatMoney(st.amount)}</span>
                                                                    </div>
                                                                ))}
                                                                {firstVoucherSummary.concessionCents > 0 && (
                                                                    <div className="flex justify-between text-emerald-600 dark:text-emerald-400">
                                                                        <span>Concession Discount</span>
                                                                        <span>-{firstVoucherSummary.concessionFormatted}</span>
                                                                    </div>
                                                                )}
                                                                <div className="flex justify-between pt-1 border-t border-slate-200 dark:border-slate-700 font-bold text-slate-900 dark:text-white">
                                                                    <span>Voucher Total</span>
                                                                    <span>{firstVoucherSummary.netFormatted}</span>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>

                                                    {/* 3. Deferred / Later Billing */}
                                                    <div className="pt-2 border-t border-slate-200 dark:border-slate-700 space-y-1">
                                                        <span className="text-slate-700 dark:text-slate-300 font-semibold">
                                                            3. Deferred / Later Billing:
                                                        </span>
                                                        {laterBillingStructures.length === 0 ? (
                                                            <p className="text-slate-400 italic pl-2">
                                                                None — all assigned charges included on first voucher.
                                                            </p>
                                                        ) : (
                                                            <div className="pl-2 space-y-1">
                                                                {laterBillingStructures.map((st) => (
                                                                    <div key={st.id} className="flex justify-between text-slate-500">
                                                                        <span>{st.fee_category?.name} ({FREQ_LABELS[st.frequency] || st.frequency})</span>
                                                                        <span>{formatMoney(st.amount)}</span>
                                                                    </div>
                                                                ))}
                                                                <p className="text-[10px] text-slate-400 italic pt-0.5">
                                                                    Charges will be billed in scheduled billing cycles.
                                                                </p>
                                                            </div>
                                                        )}
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    )}

                    {/* Navigation Buttons */}
                    <div className="flex gap-3 justify-end mt-6">
                        {step > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {step < STEPS.length - 1 ? (
                            <Button
                                type="button"
                                className="bg-indigo-600 hover:bg-indigo-700 text-white"
                                onClick={handleNextStep}
                            >
                                Next — {STEPS[step + 1]}
                            </Button>
                        ) : (
                            <Button
                                type="submit"
                                disabled={isSubmitting}
                                className="bg-indigo-600 hover:bg-indigo-700 text-white min-w-[130px]"
                            >
                                {isSubmitting ? 'Admitting…' : 'Admit Student'}
                            </Button>
                        )}
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
