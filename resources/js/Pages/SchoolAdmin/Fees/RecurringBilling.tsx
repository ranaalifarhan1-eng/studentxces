import { useState } from 'react';
import { usePage, useForm, router, Link } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    ArrowLeft, Calendar, Play, Eye, CheckCircle2, AlertTriangle,
    ShieldCheck, Clock, RefreshCw, AlertCircle
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/Types';

interface BillingSettings {
    auto_billing_enabled: boolean;
    auto_billing_day: number;
    recurring_fee_due_day: number;
}

interface SchoolInfo {
    id: number;
    name: string;
    timezone: string;
}

interface AcademicYearInfo {
    id: number;
    name: string;
    is_current: boolean;
}

interface BillingRun {
    id: number;
    target_month: string;
    billing_period_key: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    triggered_by: 'scheduler' | 'manual';
    started_at: string | null;
    completed_at: string | null;
    last_executed_at?: string | null;
    execution_count?: number;
    students_scanned: number;
    challans_created: number;
    last_challans_created?: number;
    vouchers_updated?: number;
    last_vouchers_updated?: number;
    charges_appended?: number;
    last_charges_appended?: number;
    challans_skipped: number;
    total_amount: string;
    error_summary: string | null;
}

interface Props extends PageProps {
    settings: BillingSettings;
    school: SchoolInfo;
    currentYear: AcademicYearInfo | null;
    recentRuns: BillingRun[];
    currentMonth: string;
}

interface PreviewResult {
    school_id: number;
    school_name: string;
    target_month: string;
    academic_year_name: string;
    due_date: string;
    eligible_students_count: number;
    expected_challans_count: number;
    skipped_duplicates_count: number;
    expected_gross_cents: number;
    expected_gross_amount: string;
    error?: string;
}

function Toggle({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <button
            type="button"
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2',
                checked ? 'bg-indigo-600' : 'bg-slate-300 dark:bg-slate-700'
            )}
        >
            <span
                className={cn(
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                    checked ? 'translate-x-5' : 'translate-x-0'
                )}
            />
        </button>
    );
}

export default function RecurringBilling({ settings, school, currentYear, recentRuns, currentMonth }: Props) {
    const { flash } = usePage<any>().props;

    const form = useForm({
        auto_billing_enabled: settings.auto_billing_enabled,
        auto_billing_day: settings.auto_billing_day,
        recurring_fee_due_day: settings.recurring_fee_due_day,
    });

    const [selectedMonth, setSelectedMonth] = useState(currentMonth);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewResult | null>(null);
    const [runLoading, setRunLoading] = useState(false);

    const handleSaveSettings = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/school/fees/recurring-billing/settings');
    };

    const handlePreview = async () => {
        setPreviewLoading(true);
        try {
            const resp = await axios.post('/school/fees/recurring-billing/preview', {
                month: selectedMonth,
            });
            setPreviewData(resp.data);
        } catch (err: any) {
            console.error('Preview error:', err);
            alert(err.response?.data?.message || 'Failed to calculate preview.');
        } finally {
            setPreviewLoading(false);
        }
    };

    const handleRunNow = () => {
        if (!confirm(`Are you sure you want to run billing for ${selectedMonth} now? This will generate fee vouchers for eligible students.`)) {
            return;
        }

        setRunLoading(true);
        router.post(
            '/school/fees/recurring-billing/run-now',
            { month: selectedMonth },
            {
                onFinish: () => {
                    setRunLoading(false);
                    setPreviewData(null);
                },
            }
        );
    };

    return (
        <AppLayout title="Automatic Fee Billing">
            <div className="space-y-6 max-w-6xl mx-auto pb-12">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 mb-1">
                            <Link
                                href="/school/fees/challans"
                                className="text-slate-500 hover:text-slate-700 text-sm flex items-center gap-1"
                            >
                                <ArrowLeft className="w-4 h-4" /> Back to Challans
                            </Link>
                        </div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100 flex items-center gap-2">
                            <Calendar className="w-7 h-7 text-indigo-600" />
                            Automatic Monthly Fee Billing
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Configure automatic recurring monthly fee generation, review preview metrics, and monitor cycle history.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link href="/school/fees/structures/bulk-assign">
                            <Button variant="outline" size="sm">
                                Manual Bulk Assign
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Flash Success Message */}
                {flash?.success && (
                    <div className="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-4 flex items-center gap-3 text-sm">
                        <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
                        <span>{flash.success}</span>
                    </div>
                )}

                {/* Configuration Card */}
                <Card className="border-slate-200 shadow-sm">
                    <CardHeader>
                        <CardTitle className="text-lg">Recurring Billing Configuration</CardTitle>
                        <CardDescription>
                            Control whether StudentXces automatically generates fee vouchers each month or requires manual bulk billing.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSaveSettings} className="space-y-6">
                            {/* Enable Toggle */}
                            <div className="flex items-start justify-between p-4 bg-slate-50 dark:bg-slate-900/40 rounded-xl border border-slate-200 dark:border-slate-800">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2 font-medium text-slate-900 dark:text-slate-100">
                                        <span>Automatically generate recurring fee vouchers</span>
                                        {form.data.auto_billing_enabled ? (
                                            <Badge variant="default" className="bg-emerald-600 text-white hover:bg-emerald-600">Active</Badge>
                                        ) : (
                                            <Badge variant="outline" className="text-slate-500">Disabled (Manual Only)</Badge>
                                        )}
                                    </div>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 max-w-xl">
                                        When enabled, the background scheduler automatically generates vouchers for students with active recurring fee assignments on the specified billing day.
                                    </p>
                                </div>
                                <Toggle
                                    checked={form.data.auto_billing_enabled}
                                    onChange={(val) => form.setData('auto_billing_enabled', val)}
                                />
                            </div>

                            {/* Cycle Days */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div className="space-y-2">
                                    <Label htmlFor="auto_billing_day" className="text-sm font-medium">
                                        Generate vouchers on day of month (1–28)
                                    </Label>
                                    <Input
                                        id="auto_billing_day"
                                        type="number"
                                        min={1}
                                        max={28}
                                        value={form.data.auto_billing_day}
                                        onChange={(e) => form.setData('auto_billing_day', parseInt(e.target.value) || 1)}
                                        className="w-full sm:w-48"
                                    />
                                    <p className="text-xs text-slate-500">
                                        Day 1 runs on the first day of every month in school timezone ({school.timezone}).
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="recurring_fee_due_day" className="text-sm font-medium">
                                        Payment due on day of billing month (1–28)
                                    </Label>
                                    <Input
                                        id="recurring_fee_due_day"
                                        type="number"
                                        min={1}
                                        max={28}
                                        value={form.data.recurring_fee_due_day}
                                        onChange={(e) => form.setData('recurring_fee_due_day', parseInt(e.target.value) || 10)}
                                        className="w-full sm:w-48"
                                    />
                                    <p className="text-xs text-slate-500">
                                        For example, Day 10 sets the due date to the 10th of the billing month.
                                    </p>
                                </div>
                            </div>

                            {/* Explanatory notice */}
                            <div className="p-4 bg-indigo-50/60 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-lg text-xs text-indigo-900 dark:text-indigo-200 space-y-1">
                                <div className="font-semibold flex items-center gap-1.5">
                                    <ShieldCheck className="w-4 h-4 text-indigo-600" />
                                    How Recurring Billing Operates
                                </div>
                                <ul className="list-disc pl-5 space-y-0.5 text-indigo-800 dark:text-indigo-300">
                                    <li>Student fee assignments remain active across months.</li>
                                    <li>The system automatically generates the next eligible recurring voucher for active students.</li>
                                    <li>Existing duplicate protection guarantees no student is billed twice for the same month.</li>
                                    <li>One-time admission fees and one-off adjustments are never automatically re-billed.</li>
                                    <li>If disabled, current manual bulk billing behavior remains completely unchanged.</li>
                                </ul>
                            </div>

                            {form.data.auto_billing_enabled && (
                                <div className="p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-xs flex items-center gap-2">
                                    <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0" />
                                    <span>
                                        <strong>Notice:</strong> Turning this on will automatically create fee vouchers for students with active recurring fee assignments on Day {form.data.auto_billing_day} of each month.
                                    </span>
                                </div>
                            )}

                            <div className="flex justify-end">
                                <Button type="submit" disabled={form.processing} className="bg-indigo-600 hover:bg-indigo-700">
                                    {form.processing ? 'Saving...' : 'Save Configuration'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* Preview & Manual Execution Card */}
                <Card className="border-slate-200 shadow-sm">
                    <CardHeader>
                        <CardTitle className="text-lg">Preview & Manual Run</CardTitle>
                        <CardDescription>
                            Test what a billing cycle would generate without mutating data, or execute an immediate cycle for any month.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="flex flex-col sm:flex-row items-start sm:items-end gap-4 p-4 bg-slate-50 dark:bg-slate-900/40 rounded-xl border border-slate-200 dark:border-slate-800">
                            <div className="space-y-2 w-full sm:w-64">
                                <Label htmlFor="selected_month" className="text-sm font-medium">
                                    Target Billing Month
                                </Label>
                                <Input
                                    id="selected_month"
                                    type="month"
                                    value={selectedMonth}
                                    onChange={(e) => {
                                        setSelectedMonth(e.target.value);
                                        setPreviewData(null);
                                    }}
                                />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={handlePreview}
                                    disabled={previewLoading || runLoading}
                                    className="flex items-center gap-2"
                                >
                                    <Eye className="w-4 h-4" />
                                    {previewLoading ? 'Calculating...' : 'Preview Billing Cycle'}
                                </Button>

                                <Button
                                    type="button"
                                    onClick={handleRunNow}
                                    disabled={runLoading || previewLoading}
                                    className="bg-indigo-600 hover:bg-indigo-700 flex items-center gap-2"
                                >
                                    <Play className="w-4 h-4" />
                                    {runLoading ? 'Running...' : 'Run Billing Now'}
                                </Button>
                            </div>
                        </div>

                        {/* Preview Metrics Display */}
                        {previewData && (
                            <div className="border border-indigo-100 bg-indigo-50/40 dark:bg-slate-900/60 rounded-xl p-5 space-y-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="font-semibold text-sm text-slate-900 dark:text-slate-100 flex items-center gap-2">
                                        <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                                        Preview Results for {previewData.target_month}
                                    </h3>
                                    <Badge variant="outline" className="text-xs">
                                        Calculated Due Date: {previewData.due_date}
                                    </Badge>
                                </div>

                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                    <div className="bg-white dark:bg-slate-800 p-3.5 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div className="text-xs text-slate-500">Eligible Students</div>
                                        <div className="text-xl font-bold text-slate-900 dark:text-slate-100 mt-1">
                                            {previewData.eligible_students_count}
                                        </div>
                                    </div>

                                    <div className="bg-white dark:bg-slate-800 p-3.5 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div className="text-xs text-slate-500">Expected Vouchers</div>
                                        <div className="text-xl font-bold text-emerald-600 mt-1">
                                            {previewData.expected_challans_count}
                                        </div>
                                    </div>

                                    <div className="bg-white dark:bg-slate-800 p-3.5 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div className="text-xs text-slate-500">Duplicates Skipped</div>
                                        <div className="text-xl font-bold text-amber-600 mt-1">
                                            {previewData.skipped_duplicates_count}
                                        </div>
                                    </div>

                                    <div className="bg-white dark:bg-slate-800 p-3.5 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div className="text-xs text-slate-500">Estimated Total Gross</div>
                                        <div className="text-xl font-bold text-indigo-600 mt-1">
                                            PKR {Number(previewData.expected_gross_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </div>
                                    </div>
                                </div>

                                <p className="text-xs text-slate-500">
                                    Preview calculation verified against active student fee assignments, standing concessions, and existing active challans. Zero database records were modified.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Cycle History / Audit Table */}
                <Card className="border-slate-200 shadow-sm">
                    <CardHeader>
                        <CardTitle className="text-lg">Recent Billing Cycles</CardTitle>
                        <CardDescription>
                            Audit trail of automated scheduler and manual recurring billing runs for this school.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {recentRuns.length === 0 ? (
                            <div className="text-center py-8 text-slate-500 text-sm">
                                <Clock className="w-8 h-8 mx-auto text-slate-400 mb-2" />
                                No billing cycles have been executed yet for this school.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Target Month</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Triggered By</TableHead>
                                            <TableHead className="text-right" title="Active students evaluated in latest run execution">Students Scanned</TableHead>
                                            <TableHead className="text-right" title="Genuinely new vouchers created across all executions for this month">New Vouchers</TableHead>
                                            <TableHead className="text-right" title="Existing vouchers updated with appended fee heads">Existing Vouchers Updated</TableHead>
                                            <TableHead className="text-right">Skipped</TableHead>
                                            <TableHead className="text-right">Amount Added</TableHead>
                                            <TableHead>Executed At</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentRuns.map((run) => (
                                            <TableRow key={run.id}>
                                                <TableCell className="font-medium text-slate-900 dark:text-slate-100">
                                                    <div className="flex items-center gap-1.5">
                                                        <span>{run.target_month}</span>
                                                        {run.execution_count && run.execution_count > 1 ? (
                                                            <Badge variant="outline" className="text-[10px] py-0 px-1 font-normal text-indigo-700 bg-indigo-50 border-indigo-200">
                                                                Catch-up #{run.execution_count}
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                    <span className="block text-xs text-slate-400">{run.billing_period_key}</span>
                                                </TableCell>
                                                <TableCell>
                                                    {run.status === 'completed' && (
                                                        <Badge variant="default" className="bg-emerald-600 text-white">Completed</Badge>
                                                    )}
                                                    {run.status === 'running' && (
                                                        <Badge variant="secondary" className="bg-amber-100 text-amber-800">In Progress</Badge>
                                                    )}
                                                    {run.status === 'queued' && (
                                                        <Badge variant="secondary" className="bg-slate-100 text-slate-700">Queued</Badge>
                                                    )}
                                                    {run.status === 'failed' && (
                                                        <Badge variant="destructive">Failed</Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-slate-600 dark:text-slate-300 capitalize text-sm">
                                                    {run.triggered_by === 'scheduler' ? 'Automatic (Cron)' : 'Manual'}
                                                </TableCell>
                                                <TableCell className="text-right">{run.students_scanned}</TableCell>
                                                <TableCell className="text-right font-medium text-emerald-600">{run.challans_created}</TableCell>
                                                <TableCell className="text-right font-medium text-indigo-600">
                                                    {run.vouchers_updated ?? 0}
                                                    {run.charges_appended && run.charges_appended > 0 ? (
                                                        <span className="block text-[11px] text-slate-400 font-normal">({run.charges_appended} charges)</span>
                                                    ) : null}
                                                </TableCell>
                                                <TableCell className="text-right text-slate-500">{run.challans_skipped}</TableCell>
                                                <TableCell className="text-right font-medium text-slate-900 dark:text-slate-100">
                                                    PKR {Number(run.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </TableCell>
                                                <TableCell className="text-xs text-slate-500">
                                                    {run.last_executed_at || run.completed_at || run.started_at || '—'}
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
        </AppLayout>
    );
}
