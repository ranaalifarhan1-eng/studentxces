import AppLayout from '@/Layouts/AppLayout';
import { useForm, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Globe, CheckCircle2, AlertCircle, Trash2, Star, ShieldCheck, RefreshCw, Plus, Zap } from 'lucide-react';

interface ProvisioningStatus {
    is_provisioning: boolean;
    request_status: string | null;
    safe_error: string | null;
    can_activate: boolean;
    can_retry: boolean;
}

interface SchoolDomainItem {
    id: number;
    school_id: number;
    hostname: string;
    type: 'default' | 'custom';
    is_primary: boolean;
    status: 'pending' | 'verified' | 'active' | 'failed' | 'disabled';
    verification_token: string | null;
    verified_at: string | null;
    ssl_status: 'pending' | 'active' | 'failed';
    created_at: string;
    provisioning?: ProvisioningStatus;
}

interface Props {
    domains: SchoolDomainItem[];
    cname_target: string;
    tenant_base_domain: string;
    is_super_admin?: boolean;
}

export default function Domains({ domains, cname_target, tenant_base_domain, is_super_admin = false }: Props) {
    const [verifyingId, setVerifyingId] = useState<number | null>(null);
    const [activatingId, setActivatingId] = useState<number | null>(null);

    // 5-second polling only while any domain has an active provisioning request
    useEffect(() => {
        const hasActiveProvisioning = domains.some(d => d.provisioning?.is_provisioning);
        if (!hasActiveProvisioning) return;

        const interval = setInterval(() => {
            router.reload({ only: ['domains'] });
        }, 5000);

        return () => clearInterval(interval);
    }, [domains]);

    const form = useForm({
        hostname: '',
    });

    function handleAddDomain(e: React.FormEvent) {
        e.preventDefault();
        form.post('/school/settings/domains', {
            onSuccess: () => form.reset(),
        });
    }

    function handleVerify(domain: SchoolDomainItem) {
        setVerifyingId(domain.id);
        router.post(`/school/settings/domains/${domain.id}/verify`, {}, {
            onFinish: () => setVerifyingId(null),
        });
    }

    function handleActivate(domain: SchoolDomainItem) {
        setActivatingId(domain.id);
        router.post(`/school/settings/domains/${domain.id}/activate`, {}, {
            onFinish: () => setActivatingId(null),
        });
    }

    function handleMakePrimary(domain: SchoolDomainItem) {
        router.patch(`/school/settings/domains/${domain.id}/primary`);
    }

    function handleDelete(domain: SchoolDomainItem) {
        if (confirm(`Are you sure you want to remove '${domain.hostname}'?`)) {
            router.delete(`/school/settings/domains/${domain.id}`);
        }
    }

    return (
        <AppLayout title="Domain Management">
            <div className="space-y-6 max-w-6xl">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <Globe className="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        Domain Management
                    </h1>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Configure custom domains and platform URLs for your school portal.
                    </p>
                </div>

                {/* Add Custom Domain */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">Add Custom Domain</CardTitle>
                        <CardDescription>
                            Connect your own custom subdomain (e.g. <code>app.yourschool.com</code> or <code>portal.yourschool.edu.pk</code>).
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleAddDomain} className="flex flex-col sm:flex-row gap-3">
                            <div className="flex-1">
                                <Label htmlFor="hostname" className="sr-only">Hostname</Label>
                                <Input
                                    id="hostname"
                                    placeholder="app.yourschool.com"
                                    value={form.data.hostname}
                                    onChange={e => form.setData('hostname', e.target.value)}
                                    className={form.errors.hostname ? 'border-red-500' : ''}
                                />
                                {form.errors.hostname && (
                                    <p className="text-xs text-red-500 mt-1">{form.errors.hostname}</p>
                                )}
                            </div>
                            <Button type="submit" disabled={form.processing} className="gap-2 shrink-0">
                                <Plus className="w-4 h-4" /> Add Domain
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Configured Domains List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">Configured Domains</CardTitle>
                        <CardDescription>
                            Active and pending hostnames routed to your school instance.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm text-left">
                                <thead className="bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 uppercase text-xs">
                                    <tr>
                                        <th className="px-6 py-3">Domain</th>
                                        <th className="px-6 py-3">Type</th>
                                        <th className="px-6 py-3">Verification</th>
                                        <th className="px-6 py-3">SSL / Routing</th>
                                        <th className="px-6 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                    {domains.map(domain => (
                                        <tr key={domain.id} className="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">
                                            <td className="px-6 py-4 font-medium text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                                <span>{domain.hostname}</span>
                                                {domain.is_primary && (
                                                    <span className="inline-flex items-center gap-1 text-xs bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 font-semibold px-2 py-0.5 rounded-full">
                                                        <Star className="w-3 h-3 fill-amber-500 text-amber-500" /> Primary
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                <span className={`text-xs px-2.5 py-1 rounded-full font-medium ${
                                                    domain.type === 'default'
                                                        ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300'
                                                        : 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300'
                                                }`}>
                                                    {domain.type === 'default' ? 'Platform Default' : 'Custom'}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4">
                                                {domain.status === 'active' ? (
                                                    <span className="inline-flex items-center gap-1.5 text-xs text-green-700 dark:text-green-400 font-medium">
                                                        <CheckCircle2 className="w-4 h-4 text-green-600" /> Active
                                                    </span>
                                                ) : domain.status === 'verified' ? (
                                                    <span className="inline-flex items-center gap-1.5 text-xs text-sky-700 dark:text-sky-400 font-medium">
                                                        <CheckCircle2 className="w-4 h-4 text-sky-600" /> DNS Verified
                                                    </span>
                                                ) : domain.status === 'failed' ? (
                                                    <span className="inline-flex items-center gap-1.5 text-xs text-red-700 dark:text-red-400 font-medium">
                                                        <AlertCircle className="w-4 h-4 text-red-600" /> DNS Unverified
                                                    </span>
                                                ) : domain.status === 'disabled' ? (
                                                    <span className="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 font-medium">
                                                        Disabled
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400 font-medium">
                                                        <RefreshCw className="w-4 h-4 text-amber-600" /> Pending DNS
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                {domain.provisioning?.is_provisioning ? (
                                                    <span className="inline-flex items-center gap-1 text-xs text-indigo-700 dark:text-indigo-400 font-medium animate-pulse">
                                                        <RefreshCw className="w-4 h-4 animate-spin text-indigo-600" /> Provisioning...
                                                    </span>
                                                ) : domain.ssl_status === 'active' ? (
                                                    <span className="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400 font-medium">
                                                        <ShieldCheck className="w-4 h-4 text-green-600" /> Secure
                                                    </span>
                                                ) : domain.ssl_status === 'failed' ? (
                                                    <span className="inline-flex items-center gap-1 text-xs text-red-700 dark:text-red-400 font-medium">
                                                        <AlertCircle className="w-4 h-4 text-red-600" /> Provisioning Failed
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400 font-medium">
                                                        <RefreshCw className="w-4 h-4 text-amber-600" /> Pending SSL
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-right space-x-2">
                                                {domain.type === 'custom' && domain.status !== 'active' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleVerify(domain)}
                                                        disabled={verifyingId === domain.id || domain.provisioning?.is_provisioning}
                                                        className="text-xs"
                                                    >
                                                        <RefreshCw className={`w-3.5 h-3.5 mr-1 ${verifyingId === domain.id ? 'animate-spin' : ''}`} />
                                                        Verify DNS
                                                    </Button>
                                                )}
                                                {is_super_admin && domain.provisioning?.can_activate && (
                                                    <Button
                                                        variant="default"
                                                        size="sm"
                                                        onClick={() => handleActivate(domain)}
                                                        disabled={activatingId === domain.id}
                                                        className="text-xs bg-indigo-600 hover:bg-indigo-700 text-white gap-1"
                                                    >
                                                        <Zap className="w-3.5 h-3.5" />
                                                        Activate Domain
                                                    </Button>
                                                )}
                                                {is_super_admin && domain.provisioning?.can_retry && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleActivate(domain)}
                                                        disabled={activatingId === domain.id}
                                                        className="text-xs text-amber-700 border-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/30 gap-1"
                                                    >
                                                        <RefreshCw className="w-3.5 h-3.5" />
                                                        Retry Activation
                                                    </Button>
                                                )}
                                                {!domain.is_primary && domain.status === 'active' && domain.ssl_status === 'active' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleMakePrimary(domain)}
                                                        className="text-xs"
                                                    >
                                                        Make Primary
                                                    </Button>
                                                )}
                                                {(domain.type === 'custom' || domains.length > 1) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleDelete(domain)}
                                                        disabled={domain.provisioning?.is_provisioning}
                                                        className="text-xs text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30"
                                                    >
                                                        <Trash2 className="w-3.5 h-3.5" />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* DNS Setup Guide */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">DNS Setup Instructions</CardTitle>
                        <CardDescription>
                            To route your custom domain to your school portal, add the following DNS record at your domain registrar.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4 text-sm">
                        <div className="grid sm:grid-cols-3 gap-3 p-3 bg-gray-50 dark:bg-gray-800/60 rounded-md border border-gray-200 dark:border-gray-700 font-mono text-xs">
                            <div>
                                <span className="text-gray-500 block">Type</span>
                                <span className="font-semibold text-gray-900 dark:text-gray-100">CNAME</span>
                            </div>
                            <div>
                                <span className="text-gray-500 block">Host / Name</span>
                                <span className="font-semibold text-gray-900 dark:text-gray-100">app (or your subdomain)</span>
                            </div>
                            <div>
                                <span className="text-gray-500 block">Target / Destination</span>
                                <span className="font-semibold text-indigo-600 dark:text-indigo-400">{cname_target}</span>
                            </div>
                        </div>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            DNS propagation typically takes 5–30 minutes. Once configured, click <strong>Verify DNS</strong>.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
