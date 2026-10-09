import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { router, usePage, Head } from '@inertiajs/react';
import { toast } from 'sonner';
import { useEffect } from 'react';
import { ShieldCheck, LogOut, Lock } from 'lucide-react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { PageProps } from '@/Types';

const passwordSchema = z.object({
    password: z.string().min(8, 'Password must be at least 8 characters'),
    password_confirmation: z.string().min(1, 'Please confirm your password'),
}).refine((data) => data.password === data.password_confirmation, {
    message: "Passwords do not match",
    path: ['password_confirmation'],
});

type PasswordFormData = z.infer<typeof passwordSchema>;

interface FirstChangePasswordProps extends PageProps {
    username: string;
    role: string;
}

export default function FirstChangePassword() {
    const { flash, errors: serverErrors, username, role, branding, active_school } = usePage<FirstChangePasswordProps>().props;

    const brandName = branding?.tenant_name || active_school?.name || branding?.platform_name || branding?.app_name || 'StudentXces';
    const logoUrl = branding?.logo_url || active_school?.logo_url || branding?.platform_logo_url || null;

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<PasswordFormData>({
        resolver: zodResolver(passwordSchema),
        defaultValues: { password: '', password_confirmation: '' },
    });

    useEffect(() => {
        if (flash?.error) toast.error(flash.error);
        if (flash?.success) toast.success(flash.success);
    }, [flash]);

    useEffect(() => {
        if (serverErrors?.password) setError('password', { message: serverErrors.password });
    }, [serverErrors, setError]);

    const onSubmit = (data: PasswordFormData) => {
        router.post('/password/first-change', data, {
            onError: (errs) => {
                if (errs.password) setError('password', { message: errs.password });
                if (errs.message) toast.error(errs.message);
            },
        });
    };

    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <AuthLayout>
            <Head title="Set New Password" />

            <div className="w-full max-w-md">
                {/* Logo / Branding */}
                <div className="text-center mb-8">
                    {logoUrl ? (
                        <div className="inline-flex items-center justify-center mb-4">
                            <img src={logoUrl} alt={brandName} className="h-12 w-auto max-w-[200px] object-contain" />
                        </div>
                    ) : (
                        <div className="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-indigo-600 text-white shadow-lg mb-4">
                            <ShieldCheck className="w-6 h-6" />
                        </div>
                    )}
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {brandName}
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Portal Security Setup
                    </p>
                </div>

                <Card className="shadow-xl border-0 dark:bg-slate-800/60 dark:backdrop-blur">
                    <CardHeader className="space-y-1 pb-4">
                        <div className="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 font-medium text-xs uppercase tracking-wider">
                            <Lock className="w-4 h-4" />
                            First-Time Login Verification
                        </div>
                        <CardTitle className="text-xl font-semibold text-slate-900 dark:text-white">
                            Set Your Personal Password
                        </CardTitle>
                        <CardDescription className="text-slate-500 dark:text-slate-400 text-xs leading-relaxed">
                            Welcome, <strong className="text-slate-700 dark:text-slate-200">{username || 'User'}</strong> ({role}).
                            You have signed in using a temporary access key. Please choose a secure permanent password to continue.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
                            {/* New Password */}
                            <div className="space-y-1.5">
                                <Label htmlFor="password" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    New Password
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    autoFocus
                                    placeholder="Minimum 8 characters"
                                    className="h-10"
                                    {...register('password')}
                                />
                                {errors.password && (
                                    <p className="text-xs text-red-500 mt-1">{errors.password.message}</p>
                                )}
                            </div>

                            {/* Confirm Password */}
                            <div className="space-y-1.5">
                                <Label htmlFor="password_confirmation" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Confirm New Password
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    placeholder="Re-type new password"
                                    className="h-10"
                                    {...register('password_confirmation')}
                                />
                                {errors.password_confirmation && (
                                    <p className="text-xs text-red-500 mt-1">{errors.password_confirmation.message}</p>
                                )}
                            </div>

                            <Button
                                type="submit"
                                disabled={isSubmitting}
                                className="w-auto min-w-[140px] h-10 font-medium"
                            >
                                {isSubmitting ? 'Saving...' : 'Set Password & Enter'}
                            </Button>

                            <div className="pt-2 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center text-xs">
                                <span className="text-slate-400">Signed in as {username}</span>
                                <button
                                    type="button"
                                    onClick={handleLogout}
                                    className="inline-flex items-center gap-1 text-slate-500 hover:text-red-600 transition-colors"
                                >
                                    <LogOut className="w-3.5 h-3.5" />
                                    Sign out
                                </button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AuthLayout>
    );
}
