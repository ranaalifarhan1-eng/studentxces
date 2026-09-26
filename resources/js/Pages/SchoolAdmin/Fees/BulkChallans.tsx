import React, { Fragment } from 'react';
import { Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Printer } from 'lucide-react';
import AlliedFeeChallanSlip, { FeeChallanData, SchoolDetails, BankConfig } from './Components/AlliedFeeChallanSlip';

interface Props {
    challans: FeeChallanData[];
    school: SchoolDetails;
    bankConfig?: BankConfig | null;
}

export default function BulkChallansView({ challans, school, bankConfig }: Props) {
    const copies = [
        { title: 'SCHOOL / OFFICE COPY', badge: 'OFFICE' },
        { title: 'BANK COPY', badge: 'BANK' },
        { title: 'STUDENT / PARENT COPY', badge: 'STUDENT' },
    ];

    return (
        <AppLayout title={`Bulk Challans Print (${challans.length})`}>
            <div className="space-y-6">
                {/* Print Toolbar (Hidden on Print) */}
                <div className="flex items-center justify-between no-print flex-wrap gap-4 bg-white dark:bg-slate-900 p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                    <div className="flex items-center gap-3">
                        <Link href="/school/fees/challans" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            <ArrowLeft className="w-4 h-4" /> Challans List
                        </Link>
                        <span className="text-slate-300 dark:text-slate-700">|</span>
                        <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                            Bulk Print Challans ({challans.length} challans)
                        </h1>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button onClick={() => window.print()} className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs inline-flex items-center gap-1.5">
                            <Printer className="w-4 h-4" /> Print All ({challans.length})
                        </Button>
                    </div>
                </div>

                {challans.length === 0 ? (
                    <div className="p-12 text-center text-slate-500 bg-white dark:bg-slate-950 rounded-xl border border-slate-200 dark:border-slate-800">
                        No outstanding or partial challans found for the selected class and period.
                    </div>
                ) : (
                    <div className="space-y-8 print:space-y-0">
                        {challans.map((challan, index) => (
                            <div
                                key={challan.id}
                                className="challan-print-page max-w-[287mm] mx-auto bg-transparent print:bg-white p-0"
                                style={{
                                    pageBreakAfter: index === challans.length - 1 ? 'auto' : 'always',
                                    breakAfter: index === challans.length - 1 ? 'auto' : 'page',
                                }}
                            >
                                <div className="flex flex-col md:flex-row print:flex-row items-stretch justify-between w-full gap-4 print:gap-0">
                                    {copies.map((copy, copyIdx) => (
                                        <Fragment key={copyIdx}>
                                            {copyIdx > 0 && (
                                                <div className="hidden print:flex flex-col items-center justify-between w-[2.5mm] select-none mx-0.5">
                                                    <div className="w-[1px] h-full border-r border-dashed border-neutral-400 relative flex items-center justify-center">
                                                        <span className="bg-white px-0.5 text-[8px] text-neutral-400 rotate-90 leading-none select-none">
                                                            ✂
                                                        </span>
                                                    </div>
                                                </div>
                                            )}
                                            <div className="flex-1 min-w-0">
                                                <AlliedFeeChallanSlip
                                                    challan={challan}
                                                    school={school}
                                                    bankConfig={bankConfig}
                                                    copyTitle={copy.title}
                                                    copyBadge={copy.badge}
                                                />
                                            </div>
                                        </Fragment>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
