import { Head, router } from '@inertiajs/react';
import { AlertTriangle, LogOut, Mail, Building2, ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

interface Props {
    schoolName: string;
    subscriptionStatus: string;
    supportEmail: string;
}

export default function SubscriptionNotice({
    schoolName,
    subscriptionStatus,
    supportEmail,
}: Props) {
    function handleLogout() {
        router.post('/logout');
    }

    const isExpired = subscriptionStatus === 'expired';

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
            <Head title="Subscription Access Suspended" />

            <div className="sm:mx-auto sm:w-full sm:max-w-md">
                <div className="flex justify-center mb-4">
                    <div className="w-16 h-16 rounded-2xl bg-amber-100 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 flex items-center justify-center text-amber-600 dark:text-amber-400 shadow-sm">
                        <ShieldAlert className="w-9 h-9" />
                    </div>
                </div>

                <h2 className="text-center text-2xl font-extrabold text-slate-900 dark:text-white">
                    {isExpired ? 'Subscription Expired' : 'Subscription Access Suspended'}
                </h2>
                <p className="mt-1 text-center text-sm text-slate-500 dark:text-slate-400">
                    Operational access for this school tenant is temporarily on hold.
                </p>
            </div>

            <div className="mt-6 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
                <Card className="border-slate-200 dark:border-slate-800 shadow-sm bg-white dark:bg-slate-900">
                    <CardHeader className="pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Building2 className="w-4 h-4 text-slate-400" />
                                <CardTitle className="text-sm font-bold text-slate-900 dark:text-white">
                                    {schoolName}
                                </CardTitle>
                            </div>
                            <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 capitalize text-xs">
                                {subscriptionStatus}
                            </Badge>
                        </div>
                    </CardHeader>

                    <CardContent className="space-y-5 pt-4">
                        <div className="p-3.5 bg-amber-50 dark:bg-amber-950/30 rounded-lg border border-amber-200 dark:border-amber-900/60 text-xs text-amber-900 dark:text-amber-200 space-y-1.5 leading-relaxed">
                            <div className="flex items-center gap-1.5 font-semibold text-amber-800 dark:text-amber-300">
                                <AlertTriangle className="w-3.5 h-3.5 shrink-0" /> Notice
                            </div>
                            <p>
                                Access to school operational modules (Students, Staff, Attendance, Fees, Exams, Reports) requires an active commercial subscription.
                            </p>
                            <p>
                                Please contact your school administration or platform billing support to restore active access.
                            </p>
                        </div>

                        {supportEmail && (
                            <div className="flex items-center justify-between text-xs text-slate-500 pt-1">
                                <span className="flex items-center gap-1.5">
                                    <Mail className="w-3.5 h-3.5 text-slate-400" /> Billing Support:
                                </span>
                                <a href={`mailto:${supportEmail}`} className="font-semibold text-indigo-600 hover:underline">
                                    {supportEmail}
                                </a>
                            </div>
                        )}

                        <div className="pt-2 border-t border-slate-100 dark:border-slate-800">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleLogout}
                                className="w-full gap-2 text-slate-700 dark:text-slate-200"
                            >
                                <LogOut className="w-4 h-4" /> Sign Out
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
