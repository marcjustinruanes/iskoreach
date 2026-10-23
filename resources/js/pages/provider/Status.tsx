import { useState } from 'react';
import { Modal } from '../../components/ui/Overlays';
import VerificationDocuments from '../../components/provider/VerificationDocuments';
import type { VerificationDocumentProps } from '../../components/provider/VerificationDocuments';
import { Head, Link } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Circle, Clock3, RefreshCw } from 'lucide-react';
import type { ProviderProfile, SharedProps, VerificationNotice } from '../../types';
import OrganizationProfileForm from '../../components/provider/OrganizationProfileForm';
import VerificationNotices from '../../components/VerificationNotices';
import PublicLayout from '../../layouts/PublicLayout';
import { Badge, Button } from '../../components/ui/DesignSystem';

interface ProviderStatusProps extends SharedProps, VerificationDocumentProps { profile: ProviderProfile; verificationNotices: VerificationNotice[] }

export default function Status({ profile, auth, status, verificationNotices, verificationDocuments, verificationUpload }: ProviderStatusProps) {
    const [statusOpen, setStatusOpen] = useState(false);
    const [checking, setChecking] = useState(false);
    const [checkError, setCheckError] = useState('');
    const [latest, setLatest] = useState<{ verification_status: string; rejection_reason: string | null; reviewed_at?: string | null }>({ verification_status: profile.verification_status, rejection_reason: profile.rejection_reason });
    const checkStatus = async () => {
        setStatusOpen(true); setChecking(true); setCheckError('');
        try {
            const response = await fetch('/provider/status', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Status could not be checked. Please try again.');
            setLatest(await response.json());
        } catch (error) { setCheckError(error instanceof Error ? error.message : 'Status could not be checked. Please try again.'); }
        finally { setChecking(false); }
    };
    const suspended = profile.verification_status === 'suspended';
    const rejected = profile.verification_status === 'rejected';
    const title = suspended ? 'Provider account suspended' : rejected ? 'Organization not approved' : 'Organization review pending';
    const StatusIcon = rejected ? AlertCircle : Clock3;
    return <PublicLayout reviewMode>
        <Head title={rejected ? 'Provider review' : 'Pending approval'} />
        <main id="main-content" className="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6 sm:py-12">
            <div className="mb-7 flex flex-wrap items-center justify-between gap-4"><p className="text-xs font-bold uppercase tracking-widest text-civic-teal">Provider account</p></div>
            <div className="rounded-xl border border-neutral-200 bg-white px-5 py-7 shadow-civic-card sm:p-9">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start"><span className={'flex size-12 shrink-0 items-center justify-center rounded-xl ' + (rejected ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-900')}><StatusIcon aria-hidden="true" className="size-6" /></span><div className="min-w-0"><Badge tone={rejected ? 'red' : 'amber'} icon={StatusIcon}>{suspended ? 'Suspended' : rejected ? 'Not approved' : 'Pending approval'}</Badge><h1 className="mt-3 text-2xl font-bold leading-8 tracking-tight text-civic-navy">{title}</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-civic-muted">{suspended ? 'Your provider workspace is restricted. Review the administrator decision below. Only an administrator can restore access.' : rejected ? 'Review the administrator’s reason and correct your organization details before another review.' : 'Your organization details have been submitted. An administrator must approve your account before you can access provider workspace features.'}</p></div></div>
                {profile.rejection_reason && <div role="status" className="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-900"><h2 className="font-bold">Administrator’s reason</h2><p className="mt-2 whitespace-pre-wrap break-words">{profile.rejection_reason}</p></div>}
                {status && <p role="status" className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900">{status}</p>}
                <div className="mt-8 grid gap-8 border-t border-neutral-200 pt-7 lg:grid-cols-[minmax(0,1fr)_16rem]">
                    <section aria-labelledby="organization-title"><h2 id="organization-title" className="text-xl font-bold leading-7 text-civic-navy">Organization details</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Check that your organization name and contact email are correct. Saving a change returns the account to pending review.</p>
                        <dl className="mt-5 grid gap-3 border-b border-neutral-200 pb-5 text-sm sm:grid-cols-2"><div><dt className="text-civic-muted">Contact person</dt><dd className="mt-1 break-words font-semibold">{auth.user?.name}</dd></div><div><dt className="text-civic-muted">Account email</dt><dd className="mt-1 break-all font-semibold">{auth.user?.email}</dd></div></dl>
                        <div className="mt-5"><OrganizationProfileForm profile={profile} /></div>
                    </section>
                    <aside aria-labelledby="next-title"><h2 id="next-title" className="text-base font-bold text-civic-navy">What happens next</h2><ol className="mt-5 space-y-5 text-sm leading-6"><li className="flex gap-2"><CheckCircle2 aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-teal" /><div><p className="font-semibold">Account created</p><p className="text-civic-muted">You can review your details here.</p></div></li><li className="flex gap-2"><StatusIcon aria-hidden="true" className="mt-1 size-4 shrink-0 text-amber-800" /><div><p className="font-semibold">{rejected ? 'Correction needed' : 'Administrator review'}</p><p className="text-civic-muted">{rejected ? 'Update the details mentioned in the review.' : 'Your workspace stays restricted during review.'}</p></div></li><li className="flex gap-2"><Circle aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-muted" /><div><p className="font-semibold">Provider access</p><p className="text-civic-muted">Available after approval.</p></div></li></ol><Button variant="outline" className="mt-6" onClick={() => void checkStatus()}><RefreshCw aria-hidden="true" className="size-4" />Check status</Button></aside>
                </div>
            </div>
            <VerificationDocuments key={verificationUpload?.version} verificationDocuments={verificationDocuments} verificationUpload={verificationUpload} /><VerificationNotices notices={verificationNotices} />
        </main>
        <Modal open={statusOpen} onClose={() => setStatusOpen(false)} title="Provider review status"><div className="space-y-4">{checking ? <p role="status" className="text-sm leading-6">Checking the latest administrator decision...</p> : checkError ? <><p role="alert" className="text-sm text-civic-danger">{checkError}</p><Button onClick={() => void checkStatus()}>Try again</Button></> : <><h2 className="text-lg font-bold">{latest.verification_status === 'approved' ? 'Registration approved' : latest.verification_status === 'rejected' ? 'Registration rejected' : latest.verification_status === 'suspended' ? 'Account suspended' : 'Still under admin review'}</h2><p className="text-sm leading-7">{latest.verification_status === 'approved' ? 'The administrator approved your provider account. You can now access your workspace.' : latest.verification_status === 'pending' ? 'Your information is waiting for administrator review. No decision has been made yet.' : 'Review the administrator?s note, correct your information, and submit it again for review.'}</p>{latest.rejection_reason && <div className="rounded-lg bg-civic-panel p-4"><h3 className="text-sm font-bold">Administrator?s note</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-7">{latest.rejection_reason}</p></div>}{latest.verification_status === 'approved' && <Link href="/provider/dashboard" className="inline-flex min-h-11 items-center text-sm font-semibold underline">Open provider workspace</Link>}<Button variant="outline" onClick={() => setStatusOpen(false)}>Close</Button></>}</div></Modal>
    </PublicLayout>;
}
