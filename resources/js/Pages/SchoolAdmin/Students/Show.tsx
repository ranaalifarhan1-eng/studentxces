import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft, Pencil, FileUp, Trash2, FileText, User, GraduationCap, Users,
    Shield, Key, CheckCircle2, DollarSign, Tag, CreditCard, ExternalLink, Copy, Check,
    Printer, Eye, Mail, RefreshCw, Send, Lock, UserCheck, AlertTriangle
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
    username: string;
    email: string | null;
    status: string;
    must_change_password?: boolean;
    has_active_temp_pass?: boolean;
    temp_pass_expires_at?: string | null;
    last_login_at: string | null;
}

interface GuardianPortalUser extends PortalUser {
    guardian_id: number;
    guardian_name: string;
    guardian_code: string | null;
    linked_children_count: number;
    linked_children?: Array<{ id: number; name: string; admission_no: string }>;
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
    guardianPortalUser?: GuardianPortalUser | null;
    canViewCredentials?: boolean;
    canResetCredentials?: boolean;
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
        guardianPortalUser,
        canViewCredentials = false,
        canResetCredentials = false,
        financialSummary,
        challans = [],
        payments = [],
        assignments = [],
        concessions = [],
        flash,
    } = props as any;

    const { format: formatMoney } = useCurrency();
    const [tab, setTab] = useState<'personal' | 'guardian' | 'documents' | 'fees'>('personal');
    const [docOpen, setDocOpen] = useState(false);
    const [createPortalOpen, setCreatePortalOpen] = useState(false);
    const [portalEmail, setPortalEmail] = useState(student.email || '');

    // Credentials State & Modals
    const [revealModalOpen, setRevealModalOpen] = useState(false);
    const [revealedData, setRevealedData] = useState<{
        title: string;
        username: string;
        email: string | null;
        temp_password: string;
        expires_at: string | null;
    } | null>(null);
    const [isRevealing, setIsRevealing] = useState(false);
    const [revealError, setRevealError] = useState<string | null>(null);

    const [printSlipOpen, setPrintSlipOpen] = useState(false);
    const [copiedKey, setCopiedKey] = useState<string | null>(null);

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

    const handleCreateGuardianPortal = () => {
        router.post(`/school/students/${student.id}/guardian-portal-access`, {}, {
            preserveScroll: true,
        });
    };

    const handleTogglePortalStatus = () => {
        if (confirm(`Are you sure you want to ${portalUser?.status === 'active' ? 'disable' : 'enable'} portal access for this student?`)) {
            router.patch(`/school/students/${student.id}/portal-access/status`, {}, { preserveScroll: true });
        }
    };

    const handleResetStudentPassword = () => {
        if (confirm(`Generate a new 14-day temporary password for ${student.full_name}? The student will be required to change it on next login.`)) {
            router.post(`/school/students/${student.id}/portal-access/reset-password`, {}, { preserveScroll: true });
        }
    };

    const handleResetGuardianPassword = () => {
        const linkedCount = guardianPortalUser?.linked_children_count || 1;
        let confirmText = `Generate a new 14-day temporary password for guardian ${student.guardian?.name || 'Guardian'}? The parent will be required to change it on next login.`;

        if (linkedCount > 1) {
            confirmText = `WARNING: This parent account is shared by ${linkedCount} linked students.\n\nResetting it will change the parent's login for all linked children.\n\nAre you sure you want to proceed with resetting the parent password?`;
        }

        if (confirm(confirmText)) {
            router.post(`/school/students/${student.id}/guardian-portal-access/reset-password`, {}, { preserveScroll: true });
        }
    };

    const handleRevealStudent = async () => {
        setIsRevealing(true);
        setRevealError(null);
        try {
            const res = await fetch(`/school/students/${student.id}/portal-access/reveal-password`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (res.ok) {
                setRevealedData({
                    title: `Student Portal Access (${student.full_name})`,
                    username: data.username,
                    email: data.email,
                    temp_password: data.temp_password,
                    expires_at: data.expires_at,
                });
                setRevealModalOpen(true);
            } else {
                setRevealError(data.error || 'Failed to reveal credentials.');
                setRevealModalOpen(true);
            }
        } catch (e: any) {
            setRevealError('Error fetching credentials.');
            setRevealModalOpen(true);
        } finally {
            setIsRevealing(false);
        }
    };

    const handleRevealGuardian = async () => {
        setIsRevealing(true);
        setRevealError(null);
        try {
            const res = await fetch(`/school/students/${student.id}/guardian-portal-access/reveal-password`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (res.ok) {
                setRevealedData({
                    title: `Parent Portal Access (${student.guardian?.name || 'Guardian'})`,
                    username: data.username,
                    email: data.email,
                    temp_password: data.temp_password,
                    expires_at: data.expires_at,
                });
                setRevealModalOpen(true);
            } else {
                setRevealError(data.error || 'Failed to reveal credentials.');
                setRevealModalOpen(true);
            }
        } catch (e: any) {
            setRevealError('Error fetching credentials.');
            setRevealModalOpen(true);
        } finally {
            setIsRevealing(false);
        }
    };

    const handleResendCredentials = (type: 'student' | 'guardian') => {
        const targetName = type === 'student' ? student.full_name : (student.guardian?.name || 'Guardian');
        if (confirm(`Resend portal login credentials email to ${targetName}?`)) {
            router.post(`/school/students/${student.id}/portal-access/resend-credentials`, { type }, { preserveScroll: true });
        }
    };

    const copyToClipboard = (text: string, key: string) => {
        navigator.clipboard.writeText(text);
        setCopiedKey(key);
        setTimeout(() => setCopiedKey(null), 2500);
    };

    const InfoRow = ({ label, value }: { label: string; value?: string | null }) => (
        <div>
            <p className="text-xs text-slate-400 uppercase tracking-wide">{label}</p>
            <p className="text-sm font-medium text-slate-800 dark:text-slate-200 mt-0.5">{value || '—'}</p>
        </div>
    );

    const portalAccountCreated = (flash as any)?.portal_account_created;

    return (
        <AppLayout breadcrumbs={[
            { label: 'Students', href: '/school/students' },
            { label: student.full_name },
        ]}>
            <Head title={student.full_name} />

            {/* Flash Portal Account Created Banner (Shown once after admission) */}
            {portalAccountCreated && (
                <div className="mb-6 rounded-xl border border-indigo-200 bg-indigo-50/60 dark:bg-indigo-950/30 p-4 shadow-sm">
                    <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <div className="p-2 rounded-lg bg-indigo-600 text-white shrink-0 mt-0.5">
                                <Key className="w-5 h-5" />
                            </div>
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                    Student Admitted Successfully
                                </h3>
                                <p className="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                                    Portal access accounts have been configured. Temporary passwords are not stored in session and can be securely revealed on-demand.
                                </p>

                                <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                    {/* Student Card */}
                                    <div className="bg-white dark:bg-slate-900 p-3 rounded-lg border border-indigo-100 dark:border-indigo-900/50 space-y-2">
                                        <div className="flex items-center justify-between font-semibold text-indigo-700 dark:text-indigo-300 pb-1 border-b">
                                            <span className="flex items-center gap-1.5"><GraduationCap className="w-3.5 h-3.5" /> Student portal account created ✓</span>
                                            {portalAccountCreated.student?.email_queued && (
                                                <Badge variant="outline" className="text-[10px] bg-emerald-50 text-emerald-700 border-emerald-200">Email Queued</Badge>
                                            )}
                                        </div>
                                        <div className="flex justify-between items-center pt-1">
                                            <span className="text-slate-500">Username:</span>
                                            <span className="font-mono font-bold text-slate-900 dark:text-white">{portalAccountCreated.student?.username}</span>
                                        </div>
                                        {canViewCredentials && (
                                            <div className="pt-1 flex justify-end">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 px-2.5 text-xs text-indigo-600 border-indigo-200 hover:bg-indigo-50"
                                                    onClick={() => handleRevealStudent()}
                                                >
                                                    <Eye className="w-3.5 h-3.5 mr-1" /> View Student Credentials
                                                </Button>
                                            </div>
                                        )}
                                    </div>

                                    {/* Guardian Card */}
                                    <div className="bg-white dark:bg-slate-900 p-3 rounded-lg border border-purple-100 dark:border-purple-900/50 space-y-2">
                                        <div className="flex items-center justify-between font-semibold text-purple-700 dark:text-purple-300 pb-1 border-b">
                                            <span className="flex items-center gap-1.5">
                                                <Users className="w-3.5 h-3.5" /> Parent portal account {portalAccountCreated.guardian?.is_existing ? 'linked ✓' : 'created ✓'}
                                            </span>
                                            {portalAccountCreated.guardian?.is_existing ? (
                                                <Badge variant="outline" className="text-[10px] bg-slate-100 text-slate-600">Existing Account</Badge>
                                            ) : portalAccountCreated.guardian?.email_queued ? (
                                                <Badge variant="outline" className="text-[10px] bg-emerald-50 text-emerald-700 border-emerald-200">Email Queued</Badge>
                                            ) : null}
                                        </div>
                                        <div className="flex justify-between items-center pt-1">
                                            <span className="text-slate-500">Username:</span>
                                            <span className="font-mono font-bold text-slate-900 dark:text-white">{portalAccountCreated.guardian?.username || '—'}</span>
                                        </div>
                                        {canViewCredentials && !portalAccountCreated.guardian?.is_existing && (
                                            <div className="pt-1 flex justify-end">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 px-2.5 text-xs text-purple-600 border-purple-200 hover:bg-purple-50"
                                                    onClick={() => handleRevealGuardian()}
                                                >
                                                    <Eye className="w-3.5 h-3.5 mr-1" /> View Parent Credentials
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="flex sm:flex-col gap-2 shrink-0 self-end sm:self-start">
                            <Button
                                size="sm"
                                onClick={() => setPrintSlipOpen(true)}
                                className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-1.5"
                            >
                                <Printer className="w-4 h-4" /> Print Credential Slip
                            </Button>
                        </div>
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

            {/* Quick stats row */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
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
            </div>

            {/* Portal Accounts & Security Section */}
            <div className="mb-6 space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <Shield className="w-4 h-4 text-indigo-600" />
                            Portal Accounts & Credentials
                        </h2>
                        <p className="text-xs text-slate-500">
                            Independent authentication credentials for student and parent portals.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPrintSlipOpen(true)}
                        className="text-xs inline-flex items-center gap-1.5"
                    >
                        <Printer className="w-3.5 h-3.5" /> Print Credential Slip
                    </Button>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* 1. Student Portal Card */}
                    <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                        <CardHeader className="pb-3 flex flex-row items-start justify-between space-y-0">
                            <div className="flex items-center gap-2.5">
                                <div className="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 flex items-center justify-center">
                                    <GraduationCap className="w-4 h-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-sm font-bold">Student Portal Login</CardTitle>
                                    <CardDescription className="text-xs">Student dashboard access</CardDescription>
                                </div>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className={`text-[11px] px-2 py-0.5 rounded-full font-medium ${
                                    portalUser
                                        ? portalUser.status === 'active'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-amber-100 text-amber-700'
                                        : 'bg-slate-100 text-slate-500'
                                }`}>
                                    {portalUser ? portalUser.status : 'Not Created'}
                                </span>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3 text-xs">
                            {portalUser ? (
                                <>
                                    <div className="space-y-2 bg-slate-50 dark:bg-slate-800/40 p-3 rounded-lg border border-slate-100 dark:border-slate-800">
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Username:</span>
                                            <div className="flex items-center gap-1">
                                                <span className="font-mono font-bold text-slate-900 dark:text-white">{portalUser.username}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => copyToClipboard(portalUser.username, 'student_u')}
                                                    className="p-1 hover:text-indigo-600 transition-colors"
                                                    title="Copy username"
                                                >
                                                    {copiedKey === 'student_u' ? <Check className="w-3 h-3 text-emerald-600" /> : <Copy className="w-3 h-3 text-slate-400" />}
                                                </button>
                                            </div>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Email:</span>
                                            <span className="text-slate-700 dark:text-slate-300 font-mono">
                                                {portalUser.email || <span className="italic text-slate-400">None (Username Login Only)</span>}
                                            </span>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Password Status:</span>
                                            {portalUser.must_change_password ? (
                                                <Badge variant="outline" className="text-[10px] bg-amber-50 text-amber-700 border-amber-200">
                                                    Temporary (Change on Login)
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline" className="text-[10px] bg-emerald-50 text-emerald-700 border-emerald-200">
                                                    Permanent Password Set
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Last Login:</span>
                                            <span className="text-slate-500">{portalUser.last_login_at || 'Never'}</span>
                                        </div>
                                    </div>

                                    {/* Action buttons */}
                                    <div className="flex items-center justify-between pt-1 flex-wrap gap-2">
                                        <div className="flex items-center gap-1.5 flex-wrap">
                                            {canViewCredentials && portalUser.has_active_temp_pass && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={handleRevealStudent}
                                                    disabled={isRevealing}
                                                    className="h-7 px-2.5 text-xs text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800 hover:bg-indigo-50 inline-flex items-center gap-1"
                                                >
                                                    <Eye className="w-3 h-3" /> Reveal Password
                                                </Button>
                                            )}
                                            {canResetCredentials && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={handleResetStudentPassword}
                                                    className="h-7 px-2.5 text-xs inline-flex items-center gap-1"
                                                >
                                                    <Key className="w-3 h-3" /> Reset Password
                                                </Button>
                                            )}
                                            {canViewCredentials && portalUser.email && portalUser.has_active_temp_pass && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => handleResendCredentials('student')}
                                                    className="h-7 px-2 text-xs text-slate-600 hover:text-indigo-600 inline-flex items-center gap-1"
                                                    title="Resend login credentials email"
                                                >
                                                    <Mail className="w-3 h-3" /> Resend Email
                                                </Button>
                                            )}
                                        </div>
                                        {canResetCredentials && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={handleTogglePortalStatus}
                                                className="h-7 px-2 text-xs text-slate-500 hover:text-red-600"
                                            >
                                                {portalUser.status === 'active' ? 'Disable' : 'Enable'}
                                            </Button>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <div className="text-center py-4 space-y-2">
                                    <p className="text-xs text-slate-500">No portal account created for this student yet.</p>
                                    <Button size="sm" onClick={() => setCreatePortalOpen(true)} className="text-xs bg-indigo-600 hover:bg-indigo-700 text-white">
                                        Create Student Portal Account
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* 2. Parent / Guardian Portal Card */}
                    <Card className="dark:bg-slate-900 border-slate-200 dark:border-slate-800">
                        <CardHeader className="pb-3 flex flex-row items-start justify-between space-y-0">
                            <div className="flex items-center gap-2.5">
                                <div className="w-8 h-8 rounded-lg bg-purple-50 dark:bg-purple-950/40 text-purple-600 flex items-center justify-center">
                                    <Users className="w-4 h-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-sm font-bold">Parent / Guardian Login</CardTitle>
                                    <CardDescription className="text-xs">
                                        {student.guardian ? student.guardian.name : 'Guardian'} ({student.guardian?.relation || 'Parent'})
                                    </CardDescription>
                                </div>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className={`text-[11px] px-2 py-0.5 rounded-full font-medium ${
                                    guardianPortalUser
                                        ? guardianPortalUser.status === 'active'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-amber-100 text-amber-700'
                                        : 'bg-slate-100 text-slate-500'
                                }`}>
                                    {guardianPortalUser ? guardianPortalUser.status : 'Not Created'}
                                </span>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3 text-xs">
                            {guardianPortalUser ? (
                                <>
                                    <div className="space-y-2 bg-slate-50 dark:bg-slate-800/40 p-3 rounded-lg border border-slate-100 dark:border-slate-800">
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Guardian Code:</span>
                                            <Badge variant="outline" className="font-mono text-[10px]">
                                                {guardianPortalUser.guardian_code || `PAR-${guardianPortalUser.guardian_id}`}
                                            </Badge>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Username:</span>
                                            <div className="flex items-center gap-1">
                                                <span className="font-mono font-bold text-slate-900 dark:text-white">{guardianPortalUser.username}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => copyToClipboard(guardianPortalUser.username, 'guardian_u')}
                                                    className="p-1 hover:text-purple-600 transition-colors"
                                                    title="Copy username"
                                                >
                                                    {copiedKey === 'guardian_u' ? <Check className="w-3 h-3 text-emerald-600" /> : <Copy className="w-3 h-3 text-slate-400" />}
                                                </button>
                                            </div>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Email:</span>
                                            <span className="text-slate-700 dark:text-slate-300 font-mono">
                                                {guardianPortalUser.email || <span className="italic text-slate-400">None (Username Login Only)</span>}
                                            </span>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Linked Siblings / Children:</span>
                                            <span className="font-semibold text-purple-700 dark:text-purple-300">
                                                {guardianPortalUser.linked_children_count} Student{guardianPortalUser.linked_children_count === 1 ? '' : 's'}
                                            </span>
                                        </div>
                                        {guardianPortalUser.linked_children_count > 1 && (
                                            <div className="rounded-md bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 p-2.5 text-xs text-amber-800 dark:text-amber-300">
                                                <div className="flex items-center gap-1.5 font-semibold text-amber-800 dark:text-amber-200">
                                                    <AlertTriangle className="w-3.5 h-3.5 text-amber-600 dark:text-amber-400 shrink-0" />
                                                    Shared Parent Account ({guardianPortalUser.linked_children_count} Students)
                                                </div>
                                                <p className="mt-1 text-[11px] text-amber-700 dark:text-amber-400 leading-relaxed">
                                                    This parent account is shared by {guardianPortalUser.linked_children_count} linked students. Resetting it will change the parent's login for all linked children.
                                                </p>
                                            </div>
                                        )}
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Password Status:</span>
                                            {guardianPortalUser.must_change_password ? (
                                                <Badge variant="outline" className="text-[10px] bg-amber-50 text-amber-700 border-amber-200">
                                                    Temporary (Change on Login)
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline" className="text-[10px] bg-emerald-50 text-emerald-700 border-emerald-200">
                                                    Permanent Password Set
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-slate-500">Last Login:</span>
                                            <span className="text-slate-500">{guardianPortalUser.last_login_at || 'Never'}</span>
                                        </div>
                                    </div>

                                    {/* Action buttons */}
                                    <div className="flex items-center justify-between pt-1 flex-wrap gap-2">
                                        <div className="flex items-center gap-1.5 flex-wrap">
                                            {canViewCredentials && guardianPortalUser.has_active_temp_pass && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={handleRevealGuardian}
                                                    disabled={isRevealing}
                                                    className="h-7 px-2.5 text-xs text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800 hover:bg-purple-50 inline-flex items-center gap-1"
                                                >
                                                    <Eye className="w-3 h-3" /> Reveal Password
                                                </Button>
                                            )}
                                            {canResetCredentials && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={handleResetGuardianPassword}
                                                    className="h-7 px-2.5 text-xs inline-flex items-center gap-1"
                                                >
                                                    <Key className="w-3 h-3" /> Reset Password
                                                </Button>
                                            )}
                                            {canViewCredentials && guardianPortalUser.email && guardianPortalUser.has_active_temp_pass && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => handleResendCredentials('guardian')}
                                                    className="h-7 px-2 text-xs text-slate-600 hover:text-purple-600 inline-flex items-center gap-1"
                                                    title="Resend parent login credentials email"
                                                >
                                                    <Mail className="w-3 h-3" /> Resend Email
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <div className="text-center py-4 space-y-2">
                                    <p className="text-xs text-slate-500">
                                        {student.guardian
                                            ? `Guardian record exists (${student.guardian.name}) without a portal account.`
                                            : 'No guardian linked to this student.'}
                                    </p>
                                    {student.guardian && (
                                        <Button
                                            size="sm"
                                            onClick={handleCreateGuardianPortal}
                                            className="text-xs bg-purple-600 hover:bg-purple-700 text-white"
                                        >
                                            Create Parent Portal Account
                                        </Button>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
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
            {/* Reveal Temporary Password Dialog */}
            <Dialog open={revealModalOpen} onOpenChange={setRevealModalOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Key className="w-5 h-5 text-indigo-600" />
                            {revealedData?.title || 'Active Temporary Password'}
                        </DialogTitle>
                    </DialogHeader>

                    {revealError ? (
                        <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-xs text-red-700">
                            {revealError}
                        </div>
                    ) : revealedData ? (
                        <div className="space-y-4 pt-1">
                            <p className="text-xs text-slate-500">
                                This temporary access key remains valid until the user signs in and changes it, or until expiry.
                            </p>

                            <div className="p-3 bg-slate-50 dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 space-y-2.5 text-xs">
                                <div>
                                    <span className="text-slate-400 uppercase tracking-wider text-[10px]">Login Username:</span>
                                    <div className="flex items-center justify-between font-mono font-bold text-slate-900 dark:text-white mt-0.5">
                                        <span>{revealedData.username}</span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(revealedData.username, 'modal_user')}
                                            className="p-1 hover:text-indigo-600 text-slate-400"
                                        >
                                            {copiedKey === 'modal_user' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                        </button>
                                    </div>
                                </div>
                                {revealedData.email && (
                                    <div>
                                        <span className="text-slate-400 uppercase tracking-wider text-[10px]">Associated Email:</span>
                                        <div className="font-mono text-slate-700 dark:text-slate-300 mt-0.5">
                                            {revealedData.email}
                                        </div>
                                    </div>
                                )}
                                <div className="pt-2 border-t border-slate-200 dark:border-slate-800">
                                    <span className="text-slate-400 uppercase tracking-wider text-[10px]">Temporary Password:</span>
                                    <div className="flex items-center justify-between font-mono font-bold text-indigo-600 dark:text-indigo-400 mt-1 bg-indigo-50/70 dark:bg-indigo-950/40 p-2 rounded">
                                        <span className="text-sm">{revealedData.temp_password}</span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(revealedData.temp_password, 'modal_pass')}
                                            className="p-1 hover:text-indigo-600 text-slate-400"
                                        >
                                            {copiedKey === 'modal_pass' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                        </button>
                                    </div>
                                </div>
                                {revealedData.expires_at && (
                                    <p className="text-[10px] text-slate-400 pt-1">
                                        Expires on: {revealedData.expires_at}
                                    </p>
                                )}
                            </div>

                            <DialogFooter className="flex justify-between sm:justify-between items-center pt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setRevealModalOpen(false);
                                        setPrintSlipOpen(true);
                                    }}
                                    className="text-xs inline-flex items-center gap-1.5"
                                >
                                    <Printer className="w-3.5 h-3.5" /> Print Credential Slip
                                </Button>
                                <Button size="sm" onClick={() => setRevealModalOpen(false)}>Done</Button>
                            </DialogFooter>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>

            {/* Print Credential Slip Dialog */}
            <Dialog open={printSlipOpen} onOpenChange={setPrintSlipOpen}>
                <DialogContent className="sm:max-w-xl p-0 overflow-hidden">
                    <div className="p-6 space-y-5" id="printable-credential-slip">
                        <div className="flex justify-between items-start border-b pb-4">
                            <div>
                                <h2 className="text-lg font-bold text-slate-900 dark:text-white">
                                    {props.branding?.tenant_name || props.active_school?.name || 'StudentXces'}
                                </h2>
                                <p className="text-xs text-slate-500">Student & Parent Portal Credentials</p>
                            </div>
                            <div className="text-right text-xs text-slate-400">
                                <p className="font-mono">{student.admission_no}</p>
                                <p className="text-[10px]">{new Date().toLocaleDateString()}</p>
                            </div>
                        </div>

                        {/* Student Bio */}
                        <div className="grid grid-cols-2 gap-3 bg-slate-50 dark:bg-slate-900/60 p-3 rounded-lg border border-slate-100 dark:border-slate-800 text-xs">
                            <div>
                                <span className="text-slate-500">Student:</span>{' '}
                                <strong className="text-slate-900 dark:text-white">{student.full_name}</strong>
                            </div>
                            <div>
                                <span className="text-slate-500">Admission No:</span>{' '}
                                <strong className="text-slate-900 dark:text-white font-mono">{student.admission_no}</strong>
                            </div>
                            <div>
                                <span className="text-slate-500">Class:</span>{' '}
                                <strong>{student.school_class?.name || '—'} {student.section?.name ? `(${student.section.name})` : ''}</strong>
                            </div>
                            <div>
                                <span className="text-slate-500">Guardian:</span>{' '}
                                <strong>{student.guardian?.name || '—'} ({student.guardian?.guardian_code || '—'})</strong>
                            </div>
                        </div>

                        {/* Credential Cards */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            {/* Student Box */}
                            <div className="border border-indigo-200 dark:border-indigo-900/60 rounded-lg p-3 space-y-2 text-xs bg-indigo-50/20">
                                <div className="flex items-center gap-1.5 font-bold text-indigo-700 dark:text-indigo-300 border-b pb-1">
                                    <GraduationCap className="w-4 h-4" /> Student Portal Login
                                </div>
                                <div>
                                    <span className="text-slate-500">Login Username:</span>
                                    <p className="font-mono font-bold text-slate-900 dark:text-white mt-0.5">
                                        {portalUser?.username || '—'}
                                    </p>
                                </div>
                                {portalUser?.email && (
                                    <div>
                                        <span className="text-slate-500">Email:</span>
                                        <p className="font-mono text-slate-700 dark:text-slate-300">{portalUser.email}</p>
                                    </div>
                                )}
                                <div>
                                    <span className="text-slate-500">Temporary Password:</span>
                                    <p className="font-mono font-bold text-indigo-600 dark:text-indigo-400 bg-white dark:bg-slate-900 p-1.5 rounded border border-indigo-100 dark:border-indigo-900 mt-0.5">
                                        {revealedData?.username === portalUser?.username
                                            ? revealedData.temp_password
                                            : '•••••••• (Click Reveal on card to view password)'}
                                    </p>
                                </div>
                            </div>

                            {/* Guardian Box */}
                            <div className="border border-purple-200 dark:border-purple-900/60 rounded-lg p-3 space-y-2 text-xs bg-purple-50/20">
                                <div className="flex items-center gap-1.5 font-bold text-purple-700 dark:text-purple-300 border-b pb-1">
                                    <Users className="w-4 h-4" /> Parent Portal Login
                                </div>
                                <div>
                                    <span className="text-slate-500">Login Username:</span>
                                    <p className="font-mono font-bold text-slate-900 dark:text-white mt-0.5">
                                        {guardianPortalUser?.username || '—'}
                                    </p>
                                </div>
                                {guardianPortalUser?.email && (
                                    <div>
                                        <span className="text-slate-500">Email:</span>
                                        <p className="font-mono text-slate-700 dark:text-slate-300">{guardianPortalUser.email}</p>
                                    </div>
                                )}
                                <div>
                                    <span className="text-slate-500">Temporary Password:</span>
                                    <p className="font-mono font-bold text-purple-600 dark:text-purple-400 bg-white dark:bg-slate-900 p-1.5 rounded border border-purple-100 dark:border-purple-900 mt-0.5">
                                        {revealedData?.username === guardianPortalUser?.username
                                            ? revealedData.temp_password
                                            : (guardianPortalUser ? '•••••••• (Click Reveal on card to view password)' : '—')}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Security instructions */}
                        <div className="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/50 rounded-lg text-xs text-amber-800 dark:text-amber-300 space-y-1">
                            <p className="font-semibold flex items-center gap-1">
                                <Lock className="w-3.5 h-3.5" /> First-Login Security Instructions:
                            </p>
                            <p>1. Open the portal sign-in page and enter your assigned Username and Temporary Password.</p>
                            <p>2. You will be prompted to replace your temporary password with a new personal password on your first sign-in.</p>
                            <p>3. Keep your private credentials secure. Never share your password with unauthorized persons.</p>
                        </div>
                    </div>

                    <DialogFooter className="p-4 bg-slate-50 dark:bg-slate-900 border-t flex justify-between sm:justify-between">
                        <Button type="button" variant="outline" size="sm" onClick={() => setPrintSlipOpen(false)}>
                            Close
                        </Button>
                        <Button
                            size="sm"
                            onClick={() => window.print()}
                            className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-1.5"
                        >
                            <Printer className="w-4 h-4" /> Print
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
