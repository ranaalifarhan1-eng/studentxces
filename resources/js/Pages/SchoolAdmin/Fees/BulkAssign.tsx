import { useState, useEffect } from 'react';
import { usePage, router, Link } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, CheckCircle2, AlertTriangle, Users, FileText, Send, RefreshCw, ExternalLink, ShieldCheck, Mail } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import type { SchoolClass, PageProps } from '@/Types';

interface FeeCategory {
    id: number;
    name: string;
    type: string;
}

interface FeeStructure {
    id: number;
    class_id: number;
    fee_category_id: number;
    academic_year: string;
    amount: string;
    due_date: string | null;
    frequency: string;
    description: string | null;
    is_active: boolean;
    is_optional: boolean;
    admission_voucher_policy: string;
    school_class?: { id: number; name: string };
    fee_category?: FeeCategory;
}

interface Section {
    id: number;
    class_id: number;
    name: string;
}

interface ClassWithSections extends SchoolClass {
    sections?: Section[];
}

interface AcademicYearItem {
    id: number;
    name: string;
    is_current: boolean;
}

interface StudentCandidate {
    id: number;
    first_name: string;
    last_name: string;
    admission_no: string;
    section_id: number | null;
    status: string;
    email: string | null;
}

interface PreviewSummary {
    total_students: number;
    eligible: number;
    already_assigned: number;
    already_billed: number;
    inactive_skipped: number;
    missing_student_emails: number;
    missing_guardian_emails: number;
    new_assignments: number;
    vouchers_to_generate: number;
    estimated_billing_cents: number;
    estimated_billing_amount: string;
    estimated_billing_formatted: string;
    execution_mode: 'assign_only' | 'assign_and_bill';
    billing_label: string;
    structure_name: string;
    class_name: string;
    academic_year_name: string;
}

interface PreviewStudentRow {
    id: number;
    name: string;
    admission_no: string;
    class_name: string;
    section_name: string;
    status: string;
    is_active: boolean;
    assignment_status: 'already_assigned' | 'new';
    billing_status: 'already_billed' | 'to_bill' | 'skipped';
    has_student_email: boolean;
    student_email: string | null;
    has_guardian_email: boolean;
    guardian_email: string | null;
    amount: string;
    amount_formatted: string;
}

interface BulkExecutionResult {
    students_targeted: number;
    assignments_created: number;
    already_assigned: number;
    vouchers_generated: number;
    already_billed: number;
    notifications_queued: number;
    missing_email_count: number;
    failures: number;
    created_challans: Array<{
        challan_no: string;
        student_name: string;
        admission_no: string;
        amount: string;
    }>;
}

interface Props {
    structures: FeeStructure[];
    classes: ClassWithSections[];
    academicYears: AcademicYearItem[];
    currentYear: AcademicYearItem | null;
    preselectedStructureId?: number | null;
    mailConfig: {
        driver: string;
        is_safe: boolean;
    };
}

export default function BulkAssign({
    structures,
    classes,
    academicYears,
    currentYear,
    preselectedStructureId,
    mailConfig,
}: Props) {
    const { flash } = usePage<PageProps & { bulkResult?: BulkExecutionResult }>().props;
    const { format: formatMoney } = useCurrency();

    // 1. Structure Selection
    const [selectedStructureId, setSelectedStructureId] = useState<string>(
        preselectedStructureId ? String(preselectedStructureId) : structures[0] ? String(structures[0].id) : ''
    );

    const activeStructure = structures.find(s => String(s.id) === String(selectedStructureId));

    // 2. Academic Year
    const [selectedAyId, setSelectedAyId] = useState<string>(() => {
        if (activeStructure) {
            const match = academicYears.find(ay => ay.name === activeStructure.academic_year);
            if (match) return String(match.id);
        }
        return currentYear ? String(currentYear.id) : (academicYears[0] ? String(academicYears[0].id) : '');
    });

    // 3. Target Parameters
    const [targetMode, setTargetMode] = useState<'entire_class' | 'selected_sections' | 'selected_students'>('entire_class');
    const [selectedSectionIds, setSelectedSectionIds] = useState<number[]>([]);
    const [selectedStudentIds, setSelectedStudentIds] = useState<number[]>([]);
    const [activeOnly, setActiveOnly] = useState<boolean>(true);

    // Class students cache for selected_students mode
    const [classStudents, setClassStudents] = useState<StudentCandidate[]>([]);
    const [loadingStudents, setLoadingStudents] = useState<boolean>(false);

    // 4. Execution Mode & Billing Options
    const [executionMode, setExecutionMode] = useState<'assign_only' | 'assign_and_bill'>('assign_and_bill');
    const [billingLabel, setBillingLabel] = useState<string>('');
    const [dueDate, setDueDate] = useState<string>('');
    const [issueDate, setIssueDate] = useState<string>(() => new Date().toISOString().split('T')[0]);
    const [notifyStudent, setNotifyStudent] = useState<boolean>(false);
    const [notifyGuardian, setNotifyGuardian] = useState<boolean>(false);

    // 5. Preview & Execution State
    const [previewLoading, setPreviewLoading] = useState<boolean>(false);
    const [previewSummary, setPreviewSummary] = useState<PreviewSummary | null>(null);
    const [previewStudents, setPreviewStudents] = useState<PreviewStudentRow[]>([]);
    const [previewError, setPreviewError] = useState<string | null>(null);

    const [executing, setExecuting] = useState<boolean>(false);
    const [result, setResult] = useState<BulkExecutionResult | null>(null);

    // Sync structure changes with academic year, billing label and due date
    useEffect(() => {
        if (activeStructure) {
            const matchAy = academicYears.find(ay => ay.name === activeStructure.academic_year);
            if (matchAy) {
                setSelectedAyId(String(matchAy.id));
            }
            if (!billingLabel || billingLabel.trim() === '') {
                setBillingLabel(activeStructure.fee_category?.name ?? 'Fee Voucher');
            }
            if (!dueDate) {
                if (activeStructure.due_date) {
                    setDueDate(activeStructure.due_date);
                } else {
                    const defaultDue = new Date();
                    defaultDue.setDate(defaultDue.getDate() + 15);
                    setDueDate(defaultDue.toISOString().split('T')[0]);
                }
            }
            // Reset preview when structure changes
            setPreviewSummary(null);
            setPreviewStudents([]);
        }
    }, [selectedStructureId]);

    // Load students when targetMode is selected_students
    useEffect(() => {
        if (targetMode === 'selected_students' && activeStructure) {
            setLoadingStudents(true);
            axios.get(`/school/fees/structures/classes/${activeStructure.class_id}/students`)
                .then(res => setClassStudents(res.data))
                .catch(() => setClassStudents([]))
                .finally(() => setLoadingStudents(false));
        }
    }, [targetMode, activeStructure?.class_id]);

    const activeClass = classes.find(c => c.id === activeStructure?.class_id);
    const sections = activeClass?.sections || [];

    // Helper: Compute Preview
    async function handleFetchPreview() {
        if (!activeStructure) return;
        setPreviewLoading(true);
        setPreviewError(null);

        try {
            const res = await axios.post('/school/fees/structures/bulk-assign/preview', {
                fee_structure_id: activeStructure.id,
                academic_year_id: Number(selectedAyId),
                class_id: activeStructure.class_id,
                target_mode: targetMode,
                section_ids: selectedSectionIds,
                student_ids: selectedStudentIds,
                active_only: activeOnly,
                execution_mode: executionMode,
                billing_label: billingLabel.trim() || activeStructure.fee_category?.name || 'Fee Voucher',
            });

            setPreviewSummary(res.data.summary);
            setPreviewStudents(res.data.students);
        } catch (err: any) {
            const msg = err.response?.data?.message || 'Failed to calculate preview. Please review your parameters.';
            setPreviewError(msg);
        } finally {
            setPreviewLoading(false);
        }
    }

    // Helper: Execute Bulk Operation
    async function handleExecute() {
        if (!activeStructure || !previewSummary) return;
        if (!confirm(`Are you sure you want to execute bulk fee processing for ${previewSummary.eligible} student(s)? This action will commit financial records.`)) {
            return;
        }

        setExecuting(true);
        setPreviewError(null);

        try {
            const res = await axios.post('/school/fees/structures/bulk-assign/execute', {
                fee_structure_id: activeStructure.id,
                academic_year_id: Number(selectedAyId),
                class_id: activeStructure.class_id,
                target_mode: targetMode,
                section_ids: selectedSectionIds,
                student_ids: selectedStudentIds,
                active_only: activeOnly,
                execution_mode: executionMode,
                billing_label: billingLabel.trim() || activeStructure.fee_category?.name || 'Fee Voucher',
                due_date: dueDate || null,
                issue_date: issueDate || null,
                notify_student: notifyStudent,
                notify_guardian: notifyGuardian,
            });

            setResult(res.data);
            setPreviewSummary(null);
            setPreviewStudents([]);
        } catch (err: any) {
            const msg = err.response?.data?.message || 'Bulk execution failed. Please check server logs.';
            setPreviewError(msg);
        } finally {
            setExecuting(false);
        }
    }

    // Result Summary View
    if (result) {
        return (
            <AppLayout title="Bulk Fee Result Summary">
                <div className="max-w-4xl mx-auto space-y-6">
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50/50 dark:bg-emerald-950/20 p-6">
                        <div className="flex items-center gap-3">
                            <CheckCircle2 className="w-8 h-8 text-emerald-600 dark:text-emerald-400" />
                            <div>
                                <h1 className="text-xl font-bold text-slate-900 dark:text-white">Bulk Fee Operation Completed</h1>
                                <p className="text-sm text-slate-600 dark:text-slate-400">Financial records and vouchers processed authoritatively.</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6">
                            <div className="rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 text-center">
                                <span className="text-xs text-slate-500 font-medium">Students Targeted</span>
                                <p className="text-2xl font-bold text-slate-900 dark:text-white mt-1">{result.students_targeted}</p>
                            </div>
                            <div className="rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 text-center">
                                <span className="text-xs text-slate-500 font-medium">Assignments Created</span>
                                <p className="text-2xl font-bold text-emerald-600 mt-1">{result.assignments_created}</p>
                                <span className="text-[11px] text-slate-400">{result.already_assigned} already assigned</span>
                            </div>
                            <div className="rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 text-center">
                                <span className="text-xs text-slate-500 font-medium">Vouchers Generated</span>
                                <p className="text-2xl font-bold text-indigo-600 mt-1">{result.vouchers_generated}</p>
                                <span className="text-[11px] text-slate-400">{result.already_billed} already billed</span>
                            </div>
                            <div className="rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 text-center">
                                <span className="text-xs text-slate-500 font-medium">Notifications Queued</span>
                                <p className="text-2xl font-bold text-teal-600 mt-1">{result.notifications_queued}</p>
                                <span className="text-[11px] text-slate-400">{result.missing_email_count} missing email</span>
                            </div>
                        </div>

                        {result.created_challans && result.created_challans.length > 0 && (
                            <div className="mt-6">
                                <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-2">Generated Challans ({result.created_challans.length})</h3>
                                <div className="max-h-56 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Challan No</TableHead>
                                                <TableHead>Student Name</TableHead>
                                                <TableHead>Admission No</TableHead>
                                                <TableHead className="text-right">Payable</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {result.created_challans.map((c, idx) => (
                                                <TableRow key={idx}>
                                                    <TableCell className="font-mono font-medium text-indigo-600">{c.challan_no}</TableCell>
                                                    <TableCell>{c.student_name}</TableCell>
                                                    <TableCell>{c.admission_no}</TableCell>
                                                    <TableCell className="text-right font-semibold">{formatMoney(c.amount)}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>
                        )}

                        <div className="flex flex-wrap items-center gap-3 mt-6 pt-4 border-t border-emerald-200 dark:border-emerald-900/40">
                            <Link href="/school/fees/payments?tab=challans">
                                <Button className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-2">
                                    <FileText className="w-4 h-4" /> View Challans Hub
                                </Button>
                            </Link>
                            <Link href="/school/fees/outstanding">
                                <Button variant="outline" className="inline-flex items-center gap-2">
                                    <ExternalLink className="w-4 h-4" /> View Outstanding Fees
                                </Button>
                            </Link>
                            <Link href="/school/fees/structures">
                                <Button variant="ghost" className="inline-flex items-center gap-2">
                                    Back to Fee Structures
                                </Button>
                            </Link>
                        </div>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout title="Bulk Fee Assignment & Voucher Generation">
            <div className="max-w-5xl mx-auto space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/structures" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Fee Structures
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <div>
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Assign to Existing Students</h1>
                            <p className="text-sm text-slate-500">Bulk fee assignment, whole-class event billing, and voucher generation</p>
                        </div>
                    </div>
                </div>

                {previewError && (
                    <div className="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700 flex items-start gap-2">
                        <AlertTriangle className="w-5 h-5 shrink-0 text-red-500" />
                        <div>{previewError}</div>
                    </div>
                )}

                {/* Step 1: Select Fee Structure */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-6 space-y-4">
                    <div className="flex items-center gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span className="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center">1</span>
                        <h2 className="text-base font-semibold text-slate-900 dark:text-white">Select Fee Structure</h2>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label>Active Fee Structure <span className="text-red-500">*</span></Label>
                            <Select value={selectedStructureId} onValueChange={setSelectedStructureId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select fee structure">
                                        {activeStructure ? `${activeStructure.fee_category?.name} — ${activeStructure.school_class?.name} (${formatMoney(activeStructure.amount)})` : 'Select structure'}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    {structures.map(s => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.fee_category?.name} — {s.school_class?.name} ({formatMoney(s.amount)} • {s.frequency})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Academic Year <span className="text-red-500">*</span></Label>
                            <Select value={selectedAyId} onValueChange={setSelectedAyId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select academic year">
                                        {academicYears.find(ay => String(ay.id) === selectedAyId)?.name || 'Select academic year'}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    {academicYears.map(ay => (
                                        <SelectItem key={ay.id} value={String(ay.id)}>{ay.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {activeStructure && (
                        <div className="rounded-lg bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 p-4 text-xs grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <span className="text-slate-400">Class Scope:</span>
                                <p className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5">{activeStructure.school_class?.name}</p>
                            </div>
                            <div>
                                <span className="text-slate-400">Amount / Frequency:</span>
                                <p className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5">{formatMoney(activeStructure.amount)} ({activeStructure.frequency})</p>
                            </div>
                            <div>
                                <span className="text-slate-400">Category Type:</span>
                                <p className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5 capitalize">{activeStructure.fee_category?.type || 'general'}</p>
                            </div>
                            <div>
                                <span className="text-slate-400">Assignment Policy:</span>
                                <p className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5">{activeStructure.is_optional ? 'Optional' : 'Required'}</p>
                            </div>
                        </div>
                    )}
                </div>

                {/* Step 2: Target Selection */}
                {activeStructure && (
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-6 space-y-4">
                        <div className="flex items-center gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                            <span className="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center">2</span>
                            <h2 className="text-base font-semibold text-slate-900 dark:text-white">Target Students ({activeStructure.school_class?.name})</h2>
                        </div>

                        <div className="space-y-3">
                            <Label className="text-sm font-medium">Targeting Scope</Label>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label className={`border rounded-lg p-3 cursor-pointer flex flex-col gap-1 transition ${targetMode === 'entire_class' ? 'border-indigo-600 bg-indigo-50/40 dark:bg-indigo-950/20' : 'border-slate-200'}`}>
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="target_mode"
                                            checked={targetMode === 'entire_class'}
                                            onChange={() => setTargetMode('entire_class')}
                                            className="text-indigo-600"
                                        />
                                        <span className="text-sm font-semibold">Entire Class</span>
                                    </div>
                                    <p className="text-xs text-slate-500 pl-5">All students enrolled in {activeStructure.school_class?.name}.</p>
                                </label>

                                <label className={`border rounded-lg p-3 cursor-pointer flex flex-col gap-1 transition ${targetMode === 'selected_sections' ? 'border-indigo-600 bg-indigo-50/40 dark:bg-indigo-950/20' : 'border-slate-200'}`}>
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="target_mode"
                                            checked={targetMode === 'selected_sections'}
                                            onChange={() => setTargetMode('selected_sections')}
                                            className="text-indigo-600"
                                        />
                                        <span className="text-sm font-semibold">Selected Sections</span>
                                    </div>
                                    <p className="text-xs text-slate-500 pl-5">Filter by specific section(s) in this class.</p>
                                </label>

                                <label className={`border rounded-lg p-3 cursor-pointer flex flex-col gap-1 transition ${targetMode === 'selected_students' ? 'border-indigo-600 bg-indigo-50/40 dark:bg-indigo-950/20' : 'border-slate-200'}`}>
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="target_mode"
                                            checked={targetMode === 'selected_students'}
                                            onChange={() => setTargetMode('selected_students')}
                                            className="text-indigo-600"
                                        />
                                        <span className="text-sm font-semibold">Selected Students</span>
                                    </div>
                                    <p className="text-xs text-slate-500 pl-5">Hand-pick individual students from a list.</p>
                                </label>
                            </div>
                        </div>

                        {/* Section Selection */}
                        {targetMode === 'selected_sections' && (
                            <div className="space-y-2 p-3 bg-slate-50 dark:bg-slate-900 rounded-lg border border-slate-200">
                                <Label className="text-xs font-semibold">Choose Sections:</Label>
                                <div className="flex flex-wrap gap-3">
                                    {sections.map(sec => (
                                        <label key={sec.id} className="flex items-center gap-1.5 text-sm cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={selectedSectionIds.includes(sec.id)}
                                                onChange={e => {
                                                    if (e.target.checked) setSelectedSectionIds([...selectedSectionIds, sec.id]);
                                                    else setSelectedSectionIds(selectedSectionIds.filter(id => id !== sec.id));
                                                }}
                                                className="rounded text-indigo-600"
                                            />
                                            <span>Section {sec.name}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Selected Students Table */}
                        {targetMode === 'selected_students' && (
                            <div className="space-y-2 p-3 bg-slate-50 dark:bg-slate-900 rounded-lg border border-slate-200">
                                <Label className="text-xs font-semibold">Choose Specific Students ({selectedStudentIds.length} selected):</Label>
                                {loadingStudents ? (
                                    <p className="text-xs text-slate-400 py-2">Loading students...</p>
                                ) : (
                                    <div className="max-h-48 overflow-y-auto border border-slate-200 rounded-md bg-white dark:bg-slate-950 p-2 space-y-1">
                                        {classStudents.map(st => (
                                            <label key={st.id} className="flex items-center justify-between p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-800 text-xs cursor-pointer">
                                                <div className="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedStudentIds.includes(st.id)}
                                                        onChange={e => {
                                                            if (e.target.checked) setSelectedStudentIds([...selectedStudentIds, st.id]);
                                                            else setSelectedStudentIds(selectedStudentIds.filter(id => id !== st.id));
                                                        }}
                                                        className="rounded text-indigo-600"
                                                    />
                                                    <span className="font-medium text-slate-800 dark:text-slate-200">{st.first_name} {st.last_name}</span>
                                                    <span className="text-slate-400 font-mono">({st.admission_no})</span>
                                                </div>
                                                <Badge variant="outline" className="text-[10px]">{st.status}</Badge>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-2 pt-2">
                            <input
                                type="checkbox"
                                id="active_only"
                                checked={activeOnly}
                                onChange={e => setActiveOnly(e.target.checked)}
                                className="w-4 h-4 rounded text-indigo-600"
                            />
                            <Label htmlFor="active_only" className="cursor-pointer text-sm">
                                Active Students Only <span className="text-xs text-slate-400 font-normal">(Exclude withdrawn / inactive students)</span>
                            </Label>
                        </div>
                    </div>
                )}

                {/* Step 3: Execution Mode & Billing Settings */}
                {activeStructure && (
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-6 space-y-4">
                        <div className="flex items-center gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                            <span className="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center">3</span>
                            <h2 className="text-base font-semibold text-slate-900 dark:text-white">Execution Mode & Billing Setup</h2>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <label className={`border rounded-lg p-4 cursor-pointer flex flex-col gap-1 transition ${executionMode === 'assign_only' ? 'border-indigo-600 bg-indigo-50/40 dark:bg-indigo-950/20' : 'border-slate-200'}`}>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="exec_mode"
                                        checked={executionMode === 'assign_only'}
                                        onChange={() => setExecutionMode('assign_only')}
                                        className="text-indigo-600"
                                    />
                                    <span className="text-sm font-semibold">Assign Fee Only</span>
                                </div>
                                <p className="text-xs text-slate-500 pl-5">
                                    Creates student fee assignments without generating vouchers immediately.
                                </p>
                            </label>

                            <label className={`border rounded-lg p-4 cursor-pointer flex flex-col gap-1 transition ${executionMode === 'assign_and_bill' ? 'border-indigo-600 bg-indigo-50/40 dark:bg-indigo-950/20' : 'border-slate-200'}`}>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="exec_mode"
                                        checked={executionMode === 'assign_and_bill'}
                                        onChange={() => setExecutionMode('assign_and_bill')}
                                        className="text-indigo-600"
                                    />
                                    <span className="text-sm font-semibold">Assign Fee + Generate Vouchers</span>
                                </div>
                                <p className="text-xs text-slate-500 pl-5">
                                    Creates fee assignments and immediately issues one-time fee challans.
                                </p>
                            </label>
                        </div>

                        {executionMode === 'assign_and_bill' && (
                            <div className="space-y-4 pt-2 border-t border-slate-100 dark:border-slate-800">
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div className="space-y-1.5">
                                        <Label>Billing Label <span className="text-red-500">*</span></Label>
                                        <Input
                                            value={billingLabel}
                                            onChange={e => setBillingLabel(e.target.value)}
                                            placeholder="e.g. Test Session 2026, Annual Exam 2026"
                                        />
                                        <p className="text-[11px] text-slate-400">Appears on vouchers, receipts, and ledgers.</p>
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label>Issue Date</Label>
                                        <Input
                                            type="date"
                                            value={issueDate}
                                            onChange={e => setIssueDate(e.target.value)}
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label>Due Date <span className="text-red-500">*</span></Label>
                                        <Input
                                            type="date"
                                            value={dueDate}
                                            onChange={e => setDueDate(e.target.value)}
                                        />
                                    </div>
                                </div>

                                {/* Notifications */}
                                <div className="rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 space-y-2">
                                    <div className="flex items-center justify-between">
                                        <Label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Voucher Email Notifications</Label>
                                        <Badge variant="outline" className="text-[10px] gap-1">
                                            <Mail className="w-3 h-3" /> Driver: {mailConfig.driver} ({mailConfig.is_safe ? 'Safe / Simulated' : 'Live'})
                                        </Badge>
                                    </div>
                                    <div className="flex flex-wrap gap-6 pt-1">
                                        <label className="flex items-center gap-2 cursor-pointer text-xs font-medium">
                                            <input
                                                type="checkbox"
                                                checked={notifyStudent}
                                                onChange={e => setNotifyStudent(e.target.checked)}
                                                className="w-4 h-4 rounded text-indigo-600"
                                            />
                                            <span>Notify Student Email</span>
                                        </label>
                                        <label className="flex items-center gap-2 cursor-pointer text-xs font-medium">
                                            <input
                                                type="checkbox"
                                                checked={notifyGuardian}
                                                onChange={e => setNotifyGuardian(e.target.checked)}
                                                className="w-4 h-4 rounded text-indigo-600"
                                            />
                                            <span>Notify Guardian Email</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="pt-2">
                            <Button
                                type="button"
                                onClick={handleFetchPreview}
                                disabled={previewLoading || !activeStructure}
                                className="bg-indigo-600 hover:bg-indigo-700 text-white inline-flex items-center gap-2"
                            >
                                {previewLoading ? (
                                    <>
                                        <RefreshCw className="w-4 h-4 animate-spin" /> Calculating Preview...
                                    </>
                                ) : (
                                    <>
                                        <FileText className="w-4 h-4" /> Preview Bulk Assignment
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                )}

                {/* Step 4: Mandatory Preview & Confirmation */}
                {previewSummary && (
                    <div className="rounded-xl border border-indigo-200 dark:border-indigo-900/60 bg-white dark:bg-slate-950 p-6 space-y-6">
                        <div className="flex items-center justify-between pb-3 border-b border-indigo-100 dark:border-indigo-900/40">
                            <div className="flex items-center gap-2">
                                <span className="w-6 h-6 rounded-full bg-indigo-600 text-white text-xs font-bold flex items-center justify-center">4</span>
                                <div>
                                    <h2 className="text-base font-semibold text-slate-900 dark:text-white">Preview & Validation Summary</h2>
                                    <p className="text-xs text-slate-500">Review metrics before committing. Nothing is written to the database yet.</p>
                                </div>
                            </div>
                            <Badge className="bg-indigo-100 text-indigo-800 border-0">{previewSummary.billing_label}</Badge>
                        </div>

                        {/* KPI Metrics */}
                        <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                            <div className="rounded-lg bg-slate-50 dark:bg-slate-900 p-3 text-center border border-slate-200 dark:border-slate-800">
                                <span className="text-[11px] text-slate-400">Total Scoped</span>
                                <p className="text-xl font-bold text-slate-800 dark:text-slate-200">{previewSummary.total_students}</p>
                            </div>
                            <div className="rounded-lg bg-slate-50 dark:bg-slate-900 p-3 text-center border border-slate-200 dark:border-slate-800">
                                <span className="text-[11px] text-slate-400">Eligible</span>
                                <p className="text-xl font-bold text-emerald-600">{previewSummary.eligible}</p>
                            </div>
                            <div className="rounded-lg bg-slate-50 dark:bg-slate-900 p-3 text-center border border-slate-200 dark:border-slate-800">
                                <span className="text-[11px] text-slate-400">Already Assigned</span>
                                <p className="text-xl font-bold text-slate-600">{previewSummary.already_assigned}</p>
                            </div>
                            <div className="rounded-lg bg-slate-50 dark:bg-slate-900 p-3 text-center border border-slate-200 dark:border-slate-800">
                                <span className="text-[11px] text-slate-400">Already Billed</span>
                                <p className="text-xl font-bold text-amber-600">{previewSummary.already_billed}</p>
                            </div>
                            <div className="rounded-lg bg-slate-50 dark:bg-slate-900 p-3 text-center border border-slate-200 dark:border-slate-800">
                                <span className="text-[11px] text-slate-400">Inactive / Skipped</span>
                                <p className="text-xl font-bold text-slate-400">{previewSummary.inactive_skipped}</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-indigo-50/50 dark:bg-indigo-950/20 rounded-lg border border-indigo-100 dark:border-indigo-900/40">
                            <div>
                                <span className="text-xs text-indigo-700 dark:text-indigo-400">New Assignments:</span>
                                <p className="text-lg font-bold text-indigo-900 dark:text-indigo-200">{previewSummary.new_assignments}</p>
                            </div>
                            <div>
                                <span className="text-xs text-indigo-700 dark:text-indigo-400">Vouchers To Generate:</span>
                                <p className="text-lg font-bold text-indigo-900 dark:text-indigo-200">{previewSummary.vouchers_to_generate}</p>
                            </div>
                            <div>
                                <span className="text-xs text-indigo-700 dark:text-indigo-400">Estimated Billing:</span>
                                <p className="text-lg font-bold text-indigo-900 dark:text-indigo-200">{previewSummary.estimated_billing_formatted}</p>
                            </div>
                        </div>

                        {/* Affected Students Table */}
                        <div className="space-y-2">
                            <h3 className="text-xs font-semibold text-slate-700 dark:text-slate-300">Affected Students ({previewStudents.length})</h3>
                            <div className="max-h-64 overflow-y-auto border border-slate-200 dark:border-slate-800 rounded-lg">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-slate-50 dark:bg-slate-900 text-xs">
                                            <TableHead>Student</TableHead>
                                            <TableHead>Admission No</TableHead>
                                            <TableHead>Class / Sec</TableHead>
                                            <TableHead>Assignment Status</TableHead>
                                            <TableHead>Billing Status</TableHead>
                                            <TableHead>Email Availability</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody className="text-xs">
                                        {previewStudents.map(st => (
                                            <TableRow key={st.id}>
                                                <TableCell className="font-medium text-slate-900 dark:text-white">{st.name}</TableCell>
                                                <TableCell className="font-mono text-slate-500">{st.admission_no}</TableCell>
                                                <TableCell>{st.class_name} {st.section_name ? `(${st.section_name})` : ''}</TableCell>
                                                <TableCell>
                                                    {st.assignment_status === 'already_assigned' ? (
                                                        <Badge variant="outline" className="text-[10px] bg-slate-50 text-slate-600">Already Assigned</Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-[10px] bg-emerald-50 text-emerald-700 border-emerald-200">New Assignment</Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {st.billing_status === 'already_billed' && (
                                                        <Badge variant="outline" className="text-[10px] bg-amber-50 text-amber-800 border-amber-200">Already Billed</Badge>
                                                    )}
                                                    {st.billing_status === 'to_bill' && (
                                                        <Badge variant="outline" className="text-[10px] bg-indigo-50 text-indigo-700 border-indigo-200">Will Generate</Badge>
                                                    )}
                                                    {st.billing_status === 'skipped' && (
                                                        <Badge variant="outline" className="text-[10px] bg-slate-100 text-slate-500">Skipped</Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex gap-1.5">
                                                        <span className={`px-1.5 py-0.5 rounded text-[10px] ${st.has_student_email ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400'}`}>
                                                            Student: {st.has_student_email ? 'Yes' : 'No'}
                                                        </span>
                                                        <span className={`px-1.5 py-0.5 rounded text-[10px] ${st.has_guardian_email ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400'}`}>
                                                            Guardian: {st.has_guardian_email ? 'Yes' : 'No'}
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right font-semibold">{st.amount_formatted}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Confirmation Bar */}
                        <div className="flex items-center justify-between pt-4 border-t border-slate-200 dark:border-slate-800">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setPreviewSummary(null)}
                            >
                                Back to Edit
                            </Button>

                            <Button
                                type="button"
                                onClick={handleExecute}
                                disabled={executing || previewSummary.eligible === 0}
                                className="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold inline-flex items-center gap-2"
                            >
                                {executing ? (
                                    <>
                                        <RefreshCw className="w-4 h-4 animate-spin" /> Processing Bulk Fee...
                                    </>
                                ) : (
                                    <>
                                        <ShieldCheck className="w-4 h-4" /> Confirm Bulk Assignment
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
