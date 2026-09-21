import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft, Pencil, FileUp, Trash2, FileText, User, GraduationCap, Users,
    Shield, Key, CheckCircle2, DollarSign, Tag, CreditCard, ExternalLink, Copy, Check
} from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { useCurrency } from '@/lib/currency';
import type { PageProps, Student } from '@/Types';

interface PortalUser {
    id: number;
    name: string;
    email: string;
    status: string;
    last_login_at: string | null;
}

interface FinancialSummary {
    total_billed: string;
    total_paid: string;
    total_adjustments?: string;
    outstanding_balance: string;
    overdue_balance: string;
    is_overdue: boolean;
    active_assignments_count: number;
    active_concessions_count: number;
}

interface FeeChallanItem {
    id: number;
    fee_head_name: string;
    gross_amount: string;
    discount_amount: string;
    net_amount: string;
}

interface FeeChallanRow {
    id: number;
    challan_no: string;
    billing_period_label?: string;
    billing_period_key?: string;
    academic_year_name?: string;
    due_date?: string;
    gross_amount?: string;
    discount_amount?: string;
    adjustment_amount?: string;
    total_payable: string;
    paid_amount: string;
    derived_balance?: string;
    balance?: string;
    status: string;
    display_status?: string;
    settlement_classification?: string;
    is_overdue?: boolean;
    items?: FeeChallanItem[];
}

interface FeePaymentRow {
    id: number;
    receipt_no: string;
    amount_paid: string;
    method: string;
    payment_date: string | null;
    reference: string | null;
    note: string | null;
    fee_challan?: { id: number; challan_no: string } | null;
    collector?: { id: number; name: string } | null;
}

interface FeeAssignmentRow {
    id: number;
    fee_structure?: {
        id: number;
        amount: string;
        frequency: string;
        is_optional: boolean;
        fee_category?: { name: string };
    };
    academic_year?: { name: string };
    starts_on: string;
    ends_on: string | null;
    is_active: boolean;
}

interface FeeConcessionRow {
    id: number;
    title: string;
    type: string;
    value: string;
    fee_category?: { name: string } | null;
    academic_year?: { name: string } | null;
    is_active: boolean;
}

interface Props extends PageProps {
    student: Student;
    portalUser?: PortalUser | null;
    financialSummary?: FinancialSummary;
    challans?: FeeChallanRow[];
    payments?: FeePaymentRow[];
    assignments?: FeeAssignmentRow[];
    concessions?: FeeConcessionRow[];
}

const statusColors: Record<string, string> = {
    active:      'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400',
    alumni:      'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400',
    transferred: 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
    inactive:    'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
};

const CHALLAN_STATUS_BADGES: Record<string, string> = {
    paid:          'bg-emerald-100 text-emerald-700 border-emerald-200',
    waived:        'bg-purple-100 text-purple-700 border-purple-200',
    paid_adjusted: 'bg-teal-100 text-teal-700 border-teal-200',
    partial:       'bg-amber-100 text-amber-700 border-amber-200',
    unpaid:        'bg-slate-100 text-slate-700 border-slate-200',
    overdue:       'bg-red-100 text-red-700 border-red-200',
    void:          'bg-gray-100 text-gray-500 border-gray-200',
};

const docSchema = z.object({
    title: z.string().min(1, 'Title required'),
    file:  z.instanceof(FileList).refine((f) => f.length > 0, 'File required'),
});
type DocForm = z.infer<typeof docSchema>;

export default function ShowStudent() {
    const { props } = usePage<Props>();
    const {
        student,
        portalUser,
        financialSummary,
        challans = [],
        payments = [],
        assignments = [],
        concessions = [],
        flash,
    } = props;

    const { format: formatMoney } = useCurrency();
    const [tab, setTab] = useState<'personal' | 'guardian' | 'documents' | 'fees'>('personal');
    const [docOpen, setDocOpen] = useState(false);
    const [createPortalOpen, setCreatePortalOpen] = useState(false);
    const [portalEmail, setPortalEmail] = useState(student.email || '');
    const [copied, setCopied] = useState(false);

    const { register, handleSubmit, reset, formState: { errors, isSubmitting } } =
        useForm<DocForm>({ resolver: zodResolver(docSchema) });

    const uploadDoc = (data: DocForm) => {
        const form = new FormData();
        form.append('title', data.title);
        form.append('file',  data.file[0]);
        router.post(`/school/students/${student.id}/documents`, form, {
            forceFormData: true,
            onSuccess: () => { setDocOpen(false); reset(); },
        });
    };

    const handleCreatePortal = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(`/school/students/${student.id}/portal-access`, { email: portalEmail }, {
            preserveScroll: true,
            onSuccess: () => setCreatePortalOpen(false),
        });
    };

    const handleTogglePortalStatus = () => {
        if (confirm(`Are you sure you want to ${portalUser?.status === 'active' ? 'disable' : 'enable'} portal access for this student?`)) {
            router.patch(`/school/students/${student.id}/portal-access/status`, {}, { preserveScroll: true });
        }
    };

    const handleResetPassword = () => {
        if (confirm('Generate a new secure temporary password for this student?')) {
            router.post(`/school/students/${student.id}/portal-access/reset-password`, {}, { preserveScroll: true });
        }
    };

    const copyCredentials = () => {
        if (!flash?.portal_credentials) return;
        const text = `Student Portal Credentials:\nEmail: ${flash.portal_credentials.email}\nTemporary Password: ${flash.portal_credentials.temp_password}`;
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2500);
    };

    const InfoRow = ({ label, value }: { label: string; value?: string | null }) => (
        <div>
            <p className="text-xs text-slate-400 uppercase tracking-wide">{label}</p>
            <p className="text-sm font-medium text-slate-800 dark:text-slate-200 mt-0.5">{value || '—'}</p>
        </div>
    );

    return (
        <AppLayout breadcrumbs={[
            { label: 'Students', href: '/school/students' },
            { label: student.full_name },
        ]}>
            <Head title={student.full_name} />

            {/* Flash Credential Banner (Shown only once) */}
            {flash?.portal_credentials && (
                <div className="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 p-4 shadow-sm">
                    <div className="flex items-start justify-between">
                        <div className="flex items-start gap-3">
                            <div className="p-2 rounded-lg bg-emerald-600 text-white shrink-0">
                                <Key className="w-5 h-5" />
                            </div>
                            <div>
                                <h3 className="text-base font-bold text-emerald-900 dark:text-emerald-100">
                                    Student Portal Credentials Generated
                                </h3>
                                <p className="text-xs text-emerald-700 dark:text-emerald-300 mt-0.5">
                                    Please copy and share these credentials now. For security, this temporary password will never be displayed again.
                                </p>
                                <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 bg-white dark:bg-slate-900 p-3 rounded-lg border border-emerald-200 dark:border-emerald-800 text-xs">
                                    <div>
                                        <span className="text-slate-400 uppercase tracking-wider text-[10px]">Login Email:</span>
                                        <p className="font-mono font-bold text-slate-900 dark:text-white mt-0.5">{flash.portal_credentials.email}</p>
                                    </div>
                                    <div>
                                        <span className="text-slate-400 uppercase tracking-wider text-[10px]">Temporary Password:</span>
                                        <p className="font-mono font-bold text-indigo-600 dark:text-indigo-400 mt-0.5">{flash.portal_credentials.temp_password}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <Button size="sm" onClick={copyCredentials} className="bg-emerald-600 hover:bg-emerald-700 text-white inline-flex items-center gap-1.5 shrink-0 ml-4">
                            {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                            {copied ? 'Copied' : 'Copy Credentials'}
                        </Button>
                    </div>
                </div>
            )}

            {/* Header */}
            <div className="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href="/school/students"><ArrowLeft className="w-4 h-4" /></Link>
                    </Button>
                    <div className="flex items-center gap-3">
                        <div className="w-12 h-12 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 flex items-center justify-center text-xl font-bold text-indigo-600 shrink-0">
                            {student.photo_url
                                ? <img src={student.photo_url} className="w-12 h-12 rounded-xl object-cover" alt="" />
                                : student.first_name[0].toUpperCase()
                            }
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-bold text-slate-900 dark:text-white">{student.full_name}</h1>
                                <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${statusColors[student.status] ?? statusColors.inactive}`}>
                                    {student.status}
                                </span>
                            </div>
                            <p className="text-sm text-slate-400 font-mono">{student.admission_no}</p>
                        </div>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button size="sm" variant="outline" asChild className="inline-flex items-center gap-2">
                        <Link href={`/school/fees/payments/collect?student_id=${student.id}`}>
                            <DollarSign className="w-4 h-4 text-emerald-600" /> Collect Fee
                        </Link>
                    </Button>
                    <Button size="sm" asChild className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-2">
                        <Link href={`/school/students/${student.id}/edit`}>
                            <Pencil className="w-4 h-4" /> Edit Profile
                        </Link>
                    </Button>
                </div>
            </div>

            {/* Quick stats + Portal Access Row */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                {[
                    { icon: GraduationCap, label: 'Class', value: student.school_class?.name ?? '—' },
                    { icon: Users,         label: 'Section', value: student.section?.name ?? '—' },
                    { icon: FileText,      label: 'Documents', value: String(student.documents?.length ?? 0) },
                ].map((s) => (
                    <Card key={s.label} className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                        <CardContent className="p-4 flex items-center gap-3">
                            <div className="w-9 h-9 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 flex items-center justify-center">
                                <s.icon className="w-4 h-4 text-indigo-500" />
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">{s.label}</p>
                                <p className="text-sm font-semibold text-slate-900 dark:text-white">{s.value}</p>
                            </div>
                        </CardContent>
                    </Card>
                ))}

                {/* Portal Access Card */}
                <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                    <CardContent className="p-4 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className={`w-9 h-9 rounded-lg flex items-center justify-center ${portalUser ? (portalUser.status === 'active' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40' : 'bg-amber-50 text-amber-600') : 'bg-slate-100 text-slate-400 dark:bg-slate-800'}`}>
                                <Shield className="w-4 h-4" />
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Portal Access</p>
                                <div className="flex items-center gap-1.5 mt-0.5">
                                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${portalUser ? (portalUser.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700') : 'bg-slate-100 text-slate-600'}`}>
                                        {portalUser ? portalUser.status : 'Not Created'}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div>
                            {!portalUser ? (
                                <Button size="sm" variant="outline" onClick={() => setCreatePortalOpen(true)} className="text-xs">
                                    Enable
                                </Button>
                            ) : (
                                <div className="flex items-center gap-1">
                                    <Button size="sm" variant="ghost" onClick={handleResetPassword} title="Reset Temporary Password" className="h-8 px-2 text-xs">
                                        <Key className="w-3.5 h-3.5 text-slate-500" />
                                    </Button>
                                    <Button size="sm" variant="outline" onClick={handleTogglePortalStatus} className="h-8 px-2 text-xs">
                                        {portalUser.status === 'active' ? 'Disable' : 'Activate'}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Tabs Navigation */}
            <div className="flex gap-1 mb-4 border-b border-slate-200 dark:border-slate-800 overflow-x-auto">
                {(['personal', 'guardian', 'fees', 'documents'] as const).map((t) => (
                    <button
                        key={t}
                        onClick={() => setTab(t)}
                        className={`px-4 py-2 text-sm font-medium capitalize border-b-2 transition-colors whitespace-nowrap ${tab === t ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'}`}
                    >
                        {t === 'fees' ? 'Fees / Financial Account' : t}
                    </button>
                ))}
            </div>

            {/* Personal tab */}
            {tab === 'personal' && (
                <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                    <CardHeader className="pb-3"><CardTitle className="text-sm">Personal Details</CardTitle></CardHeader>
                    <CardContent className="grid grid-cols-3 gap-x-6 gap-y-4">
                        <InfoRow label="Full Name"      value={student.full_name} />
                        <InfoRow label="Gender"         value={student.gender} />
                        <InfoRow label="Date of Birth"  value={student.date_of_birth ? new Date(student.date_of_birth).toLocaleDateString() : null} />
                        <InfoRow label="Blood Group"    value={student.blood_group} />
                        <InfoRow label="Religion"       value={student.religion} />
                        <InfoRow label="Nationality"    value={student.nationality} />
                        <InfoRow label="Phone"          value={student.phone} />
                        <InfoRow label="Email"          value={student.email} />
                        <InfoRow label="Category"       value={student.category} />
                        <InfoRow label="Roll No"        value={student.roll_no} />
                        <InfoRow label="Admission Date" value={student.admission_date ? new Date(student.admission_date).toLocaleDateString() : null} />
                        <InfoRow label="Previous School" value={student.previous_school} />
                        {student.address && (
                            <div className="col-span-3">
                                <p className="text-xs text-slate-400 uppercase tracking-wide">Address</p>
                                <p className="text-sm text-slate-800 dark:text-slate-200 mt-0.5">{student.address}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Guardian tab */}
            {tab === 'guardian' && (
                <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                    <CardHeader className="pb-3"><CardTitle className="text-sm">Guardian Details</CardTitle></CardHeader>
                    <CardContent className="grid grid-cols-3 gap-x-6 gap-y-4">
                        {student.guardian ? (
                            <>
                                <InfoRow label="Name"       value={student.guardian.name} />
                                <InfoRow label="Relation"   value={student.guardian.relation} />
                                <InfoRow label="Phone"      value={student.guardian.phone} />
                                <InfoRow label="Email"      value={student.guardian.email} />
                                <InfoRow label="Occupation" value={student.guardian.occupation} />
                                {student.guardian.address && (
                                    <div className="col-span-3">
                                        <p className="text-xs text-slate-400 uppercase tracking-wide">Address</p>
                                        <p className="text-sm text-slate-800 dark:text-slate-200 mt-0.5">{student.guardian.address}</p>
                                    </div>
                                )}
                            </>
                        ) : (
                            <p className="text-sm text-slate-400 col-span-3">No guardian information.</p>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Fees / Financial Account Tab */}
            {tab === 'fees' && (
                <div className="space-y-6">
                    {/* KPI Financial Overview Cards */}
                    <div className={`grid grid-cols-2 ${Number(financialSummary?.total_adjustments ?? 0) > 0 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'} gap-4`}>
                        <Card className="border-slate-200 dark:border-slate-800">
                            <CardContent className="p-4">
                                <p className="text-xs text-slate-500 uppercase tracking-wide">Total Billed</p>
                                <p className="text-xl font-bold text-slate-900 dark:text-white mt-1">
                                    {formatMoney(Number(financialSummary?.total_billed ?? 0))}
                                </p>
                            </CardContent>
                        </Card>
                        <Card className="border-slate-200 dark:border-slate-800">
                            <CardContent className="p-4">
                                <p className="text-xs text-slate-500 uppercase tracking-wide">Total Paid</p>
                                <p className="text-xl font-bold text-emerald-600 mt-1">
                                    {formatMoney(Number(financialSummary?.total_paid ?? 0))}
                                </p>
                            </CardContent>
                        </Card>
                        {Number(financialSummary?.total_adjustments ?? 0) > 0 && (
                            <Card className="border-indigo-200 dark:border-indigo-900 bg-indigo-50/20">
                                <CardContent className="p-4">
                                    <p className="text-xs text-indigo-600 dark:text-indigo-400 uppercase tracking-wide">Adjustments</p>
                                    <p className="text-xl font-bold text-indigo-700 dark:text-indigo-300 mt-1">
                                        -{formatMoney(Number(financialSummary?.total_adjustments ?? 0))}
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                        <Card className="border-slate-200 dark:border-slate-800">
                            <CardContent className="p-4">
                                <p className="text-xs text-slate-500 uppercase tracking-wide">Outstanding Balance</p>
                                <p className={`text-xl font-bold mt-1 ${Number(financialSummary?.outstanding_balance ?? 0) > 0 ? 'text-red-600' : 'text-slate-700 dark:text-slate-300'}`}>
                                    {formatMoney(Number(financialSummary?.outstanding_balance ?? 0))}
                                </p>
                            </CardContent>
                        </Card>
                        <Card className="border-slate-200 dark:border-slate-800">
                            <CardContent className="p-4">
                                <p className="text-xs text-slate-500 uppercase tracking-wide">Overdue Dues</p>
                                <p className={`text-xl font-bold mt-1 ${Number(financialSummary?.overdue_balance ?? 0) > 0 ? 'text-red-700' : 'text-slate-400'}`}>
                                    {formatMoney(Number(financialSummary?.overdue_balance ?? 0))}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Active Concessions & Active Fee Assignments */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Concessions */}
                        <Card className="border-slate-200 dark:border-slate-800">
                            <CardHeader className="pb-3 flex flex-row items-center justify-between">
                                <CardTitle className="text-sm flex items-center gap-2">
                                    <Tag className="w-4 h-4 text-emerald-600" /> Active Concessions
                                </CardTitle>
                                <Badge variant="outline" className="text-xs">
                                    {concessions.length} Concession{concessions.length === 1 ? '' : 's'}
                                </Badge>
                            </CardHeader>
                            <CardContent>
                                {concessions.length === 0 ? (
                                    <p className="text-sm text-slate-400 py-3">No active concessions assigned.</p>
                                ) : (
                                    <div className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {concessions.map((c) => (
                                            <div key={c.id} className="py-2.5 flex justify-between items-center text-sm">
                                                <div>
                                                    <p className="font-semibold text-slate-800 dark:text-slate-200">{c.title}</p>
                                                    <p className="text-xs text-slate-400">{c.fee_category?.name ?? 'All Fee Heads'} · {c.academic_year?.name ?? 'Current AY'}</p>
                                                </div>
                                                <Badge className="bg-emerald-50 text-emerald-700 border-emerald-200 font-mono">
                                                    {c.type === 'percentage' ? `${c.value}% OFF` : `-${formatMoney(Number(c.value))}`}
                                                </Badge>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Fee Assignments */}
                        <Card className="border-slate-200 dark:border-slate-800">
                            <CardHeader className="pb-3 flex flex-row items-center justify-between">
                                <CardTitle className="text-sm flex items-center gap-2">
                                    <CreditCard className="w-4 h-4 text-indigo-600" /> Active Fee Assignments
                                </CardTitle>
                                <Badge variant="outline" className="text-xs">
                                    {assignments.length} Fee{assignments.length === 1 ? '' : 's'}
                                </Badge>
                            </CardHeader>
                            <CardContent>
                                {assignments.length === 0 ? (
                                    <p className="text-sm text-slate-400 py-3">No active fee assignments configured.</p>
                                ) : (
                                    <div className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {assignments.map((a) => (
                                            <div key={a.id} className="py-2.5 flex justify-between items-center text-sm">
                                                <div>
                                                    <p className="font-semibold text-slate-800 dark:text-slate-200">
                                                        {a.fee_structure?.fee_category?.name ?? 'Fee Head'}
                                                    </p>
                                                    <p className="text-xs text-slate-400 capitalize">
                                                        {a.fee_structure?.frequency} · {a.fee_structure?.is_optional ? 'Optional Opt-in' : 'Mandatory'}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="font-mono font-bold text-slate-800 dark:text-slate-200">
                                                        {formatMoney(Number(a.fee_structure?.amount ?? 0))}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Challan Ledger Table */}
                    <Card className="border-slate-200 dark:border-slate-800">
                        <CardHeader className="pb-3 flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="text-base flex items-center gap-2">
                                    <FileText className="w-4 h-4 text-indigo-600" /> Challan Ledger
                                </CardTitle>
                                <CardDescription className="text-xs mt-0.5">
                                    Chronological voucher record including admission vouchers and monthly billings
                                </CardDescription>
                            </div>
                            <Link href={`/school/fees/payments/collect?student_id=${student.id}`}>
                                <Button size="sm" className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs inline-flex items-center gap-1.5">
                                    <DollarSign className="w-3.5 h-3.5" /> Collect Payment
                                </Button>
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {challans.length === 0 ? (
                                <div className="py-8 text-center text-slate-400 text-sm">
                                    No challans issued for this student yet.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Challan #</TableHead>
                                                <TableHead>Billing Period</TableHead>
                                                <TableHead>Due Date</TableHead>
                                                <TableHead className="text-right">Payable</TableHead>
                                                <TableHead className="text-right">Paid</TableHead>
                                                <TableHead className="text-right">Balance</TableHead>
                                                <TableHead className="text-center">Status</TableHead>
                                                <TableHead className="text-right">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {challans.map((c) => {
                                                const bal = Number(c.derived_balance ?? c.balance ?? 0);
                                                const statusKey = c.display_status ?? c.status;
                                                return (
                                                    <TableRow key={c.id}>
                                                        <TableCell className="font-mono font-semibold text-indigo-600">
                                                            <Link href={`/school/fees/challans/${c.id}`} className="hover:underline">
                                                                #{c.challan_no}
                                                            </Link>
                                                        </TableCell>
                                                        <TableCell className="text-sm text-slate-700 dark:text-slate-300">
                                                            {c.billing_period_label || c.billing_period_key || '—'}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-slate-500">
                                                            {c.due_date ? new Date(c.due_date).toLocaleDateString() : '—'}
                                                        </TableCell>
                                                        <TableCell className="text-right font-medium">
                                                            <div>{formatMoney(Number(c.total_payable))}</div>
                                                            {Number(c.adjustment_amount ?? 0) > 0 && (
                                                                <div className="text-[10px] text-amber-600 dark:text-amber-400 font-normal">
                                                                    Adj: -{formatMoney(Number(c.adjustment_amount))}
                                                                </div>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right text-emerald-600 font-medium">{formatMoney(Number(c.paid_amount))}</TableCell>
                                                        <TableCell className={`text-right font-bold ${bal > 0 ? 'text-red-600' : 'text-slate-400'}`}>
                                                            {formatMoney(bal)}
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            <Badge variant="outline" className={`text-[11px] font-medium ${CHALLAN_STATUS_BADGES[statusKey] ?? 'bg-slate-100'}`}>
                                                                {c.settlement_classification ?? (c.display_status || c.status)}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <div className="flex items-center justify-end gap-1.5">
                                                                <Button size="sm" variant="ghost" asChild className="h-8 px-2 text-xs">
                                                                    <Link href={`/school/fees/challans/${c.id}`}>
                                                                        <ExternalLink className="w-3.5 h-3.5" />
                                                                    </Link>
                                                                </Button>
                                                                {bal > 0 && (
                                                                    <Button size="sm" asChild className="h-8 px-2.5 text-xs bg-indigo-600 hover:bg-indigo-700 text-white">
                                                                        <Link href={`/school/fees/payments/collect?student_id=${student.id}&challan_id=${c.id}`}>
                                                                            Pay
                                                                        </Link>
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Payment & Receipt History Table */}
                    <Card className="border-slate-200 dark:border-slate-800">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base flex items-center gap-2">
                                <CheckCircle2 className="w-4 h-4 text-emerald-600" /> Payment & Receipt History
                            </CardTitle>
                            <CardDescription className="text-xs mt-0.5">
                                Verified transactions and money received for this student
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {payments.length === 0 ? (
                                <div className="py-8 text-center text-slate-400 text-sm">
                                    No payment receipts recorded yet.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Receipt #</TableHead>
                                                <TableHead>Payment Date</TableHead>
                                                <TableHead>Amount Received</TableHead>
                                                <TableHead>Method</TableHead>
                                                <TableHead>Challan Reference</TableHead>
                                                <TableHead>Collector</TableHead>
                                                <TableHead className="text-right">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {payments.map((p) => (
                                                <TableRow key={p.id}>
                                                    <TableCell className="font-mono font-semibold text-emerald-700">
                                                        <Link href={`/school/fees/payments/${p.id}`} className="hover:underline">
                                                            #{p.receipt_no}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell className="text-sm text-slate-600 dark:text-slate-400">
                                                        {p.payment_date ? new Date(p.payment_date).toLocaleDateString() : '—'}
                                                    </TableCell>
                                                    <TableCell className="font-bold text-emerald-600">{formatMoney(Number(p.amount_paid))}</TableCell>
                                                    <TableCell className="capitalize text-xs text-slate-600 dark:text-slate-400">{p.method}</TableCell>
                                                    <TableCell className="font-mono text-xs text-slate-500">
                                                        {p.fee_challan ? `#${p.fee_challan.challan_no}` : (p.reference || '—')}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-slate-500">{p.collector?.name ?? 'Cashier'}</TableCell>
                                                    <TableCell className="text-right">
                                                        <Button size="sm" variant="outline" asChild className="h-8 px-2.5 text-xs inline-flex items-center gap-1.5">
                                                            <Link href={`/school/fees/payments/${p.id}`}>
                                                                <FileText className="w-3.5 h-3.5" /> View Receipt
                                                            </Link>
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* Documents tab */}
            {tab === 'documents' && (
                <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                    <CardHeader className="pb-3 flex-row items-center justify-between">
                        <CardTitle className="text-sm">Documents</CardTitle>
                        <Button size="sm" variant="outline" onClick={() => setDocOpen(true)} className="inline-flex items-center gap-2">
                            <FileUp className="w-3.5 h-3.5" /> Upload
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {!student.documents?.length ? (
                            <p className="text-sm text-slate-400">No documents uploaded yet.</p>
                        ) : (
                            <div className="space-y-2">
                                {student.documents.map((doc) => (
                                    <div key={doc.id} className="flex items-center justify-between p-3 rounded-lg bg-slate-50 dark:bg-slate-800">
                                        <div className="flex items-center gap-2">
                                            <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                                            <div>
                                                <p className="text-sm font-medium text-slate-700 dark:text-slate-300">{doc.title}</p>
                                                {doc.file_size && <p className="text-xs text-slate-400">{(doc.file_size / 1024).toFixed(1)} KB</p>}
                                            </div>
                                        </div>
                                        <Button variant="ghost" size="icon" className="h-8 w-8 text-red-500 hover:text-red-600"
                                            onClick={() => { if (confirm('Delete this document?')) router.delete(`/school/students/documents/${doc.id}`); }}>
                                            <Trash2 className="w-3.5 h-3.5" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Upload dialog */}
            <Dialog open={docOpen} onOpenChange={setDocOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>Upload Document</DialogTitle></DialogHeader>
                    <form onSubmit={handleSubmit(uploadDoc)} className="space-y-4 pt-2">
                        <div className="space-y-1.5">
                            <Label>Document Title <span className="text-red-500">*</span></Label>
                            <Input placeholder="e.g. Birth Certificate" {...register('title')} />
                            {errors.title && <p className="text-xs text-red-500">{errors.title.message}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>File <span className="text-red-500">*</span></Label>
                            <Input type="file" accept=".pdf,.jpg,.jpeg,.png" {...register('file')} />
                            {errors.file && <p className="text-xs text-red-500">{errors.file.message as string}</p>}
                            <p className="text-xs text-slate-400">PDF, JPG, PNG · max 5 MB</p>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDocOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={isSubmitting} className="bg-indigo-600 hover:bg-indigo-700 text-white">Upload</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Create Portal Access Dialog */}
            <Dialog open={createPortalOpen} onOpenChange={setCreatePortalOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Shield className="w-5 h-5 text-indigo-600" /> Create Student Portal Access
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleCreatePortal} className="space-y-4 pt-2">
                        <p className="text-xs text-slate-500">
                            Create a student portal account for <span className="font-semibold text-slate-800 dark:text-slate-200">{student.full_name}</span>. A secure 1-time temporary password will be generated for the student.
                        </p>
                        <div className="space-y-1.5">
                            <Label>Login Email</Label>
                            <Input
                                type="email"
                                placeholder={student.email || `${student.admission_no.toLowerCase().replace(/[^a-z0-9]/g, '')}@student.school.local`}
                                value={portalEmail}
                                onChange={e => setPortalEmail(e.target.value)}
                            />
                            <p className="text-[11px] text-slate-400">If left blank, student email or admission-derived email will be used.</p>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCreatePortalOpen(false)}>Cancel</Button>
                            <Button type="submit" className="bg-indigo-600 hover:bg-indigo-700 text-white">Create Account</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
