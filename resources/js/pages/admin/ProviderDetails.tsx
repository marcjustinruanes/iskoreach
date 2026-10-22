import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { Paginated, ProviderDetails as Provider, SharedProps, VerificationHistory } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Button, Card, EmptyState, StatusBadge, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { Modal } from '../../components/ui/Overlays';

interface ProviderDetailsProps extends SharedProps { provider: Provider; history: Paginated<VerificationHistory> }
const date = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));

export default function ProviderDetails({ provider, history, adminNavigation }: ProviderDetailsProps) {
    return <AdminLayout title="Provider verification" description="Check the registered information, review history, and decide on account access.">
        {adminNavigation && <Link href={adminNavigation.providers} className={buttonStyles('outline') + ' mb-6'}>Back to provider accounts</Link>}
        <div className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <Card className="min-w-0 rounded-xl p-5 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-4"><h2 className="min-w-0 break-words text-xl font-bold leading-8 text-civic-navy">{provider.organization_name || 'Organization details incomplete'}</h2><StatusBadge status={provider.verification_status} /></div>
                <dl className="mt-6 grid gap-5 text-sm sm:grid-cols-2">{[
                    ['Organization name', provider.organization_name || 'Missing — required before approval'],
                    ['Contact person', provider.name], ['Account email', provider.email],
                    ['Organization contact email', provider.contact_email || 'Missing — required before approval'],
                    ['Registration date', date(provider.created_at)], ['Organization type', String(provider.registration_details.organization_type || 'Not collected for this legacy account')],
                    ['Last decision', provider.reviewed_at ? date(provider.reviewed_at) : 'Not reviewed yet'], ['Reviewed by', provider.reviewer || 'No current reviewer'],
                ].map(([label, value]) => <div key={label} className="min-w-0"><dt className="font-semibold text-civic-navy">{label}</dt><dd className="mt-1 break-words leading-6 text-civic-muted [overflow-wrap:anywhere]">{value}</dd></div>)}</dl>
                {provider.registration_version > 0 && <dl className="mt-6 grid gap-4 text-sm sm:grid-cols-2">{Object.entries(provider.registration_details).map(([key, value]) => <div key={key}><dt className="font-semibold capitalize">{key.replaceAll('_', ' ')}</dt><dd className="break-words">{typeof value === 'boolean' ? value ? 'Yes' : 'No' : value || 'Not provided'}</dd></div>)}<div><dt>Email verification</dt><dd>{provider.email_verified ? 'Verified' : 'Pending'}</dd></div></dl>}
                <div className="mt-6 rounded-lg bg-civic-panel p-4"><h3 className="text-sm font-bold">Private verification documents</h3>{provider.documents.length ? <ul className="mt-3 space-y-3">{provider.documents.map(document => <li key={document.kind}><a href={document.url} className="font-semibold text-civic-teal underline">Download {document.kind.replaceAll('_', ' ')}</a></li>)}</ul> : <p className="mt-2 text-sm">No verification documents were collected for this legacy account.</p>}{!provider.documents_available && <p role="alert" className="mt-3 text-sm text-civic-danger">Required documents are missing or failed an integrity check. Approval is blocked.</p>}</div>
                {provider.rejection_reason && <div className="mt-5 rounded-lg border border-red-200 bg-red-50 p-4"><h3 className="text-sm font-bold text-red-900">Current decision reason</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-red-900">{provider.rejection_reason}</p></div>}
            </Card>
            <ReviewActions key={provider.id + ':' + provider.verification_version} provider={provider} />
        </div>
        <Card className="mt-6 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Verification history</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Decisions and organization changes are retained. Times are shown in Asia/Manila.</p>
            <div className="mt-6">{history.data.length ? <ol className="divide-y divide-civic-panel-strong">{history.data.map(item => <li key={item.id} className="py-5 first:pt-0"><div className="flex flex-wrap items-start justify-between gap-2"><h3 className="text-sm font-bold text-civic-navy">{item.action}</h3><time dateTime={item.occurred_at} className="text-xs leading-5 text-civic-muted">{date(item.occurred_at)}</time></div><p className="mt-2 break-words text-sm leading-6 text-civic-muted">{item.actor} · {item.from || 'Unknown'} → {item.to || 'Unknown'}</p>{item.reason ? <p className="mt-3 whitespace-pre-wrap break-words rounded-lg bg-civic-panel p-3 text-sm leading-6">{item.reason}</p> : item.to === 'rejected' && <p className="mt-2 text-xs leading-5 text-civic-muted">A reason was not retained in this older history entry.</p>}<div>{item.previous_reason && <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-civic-muted"><strong>Previous rejection reason:</strong> {item.previous_reason}</p>}</div></li>)}</ol> : <EmptyState title="No verification history yet" description="Decisions and changes to this organization's details will appear here." />}</div>
            <Pagination page={history} label="Verification history pages" />
        </Card>
    </AdminLayout>;
}

function ReviewActions({ provider }: { provider: Provider }) {
    const [decision, setDecision] = useState<'approved' | 'rejected' | 'suspended' | null>(null);
    const [requestError, setRequestError] = useState('');
    const pending = useRef(false);
    const form = useForm({ verification_status: 'approved' as 'approved' | 'rejected' | 'suspended', rejection_reason: '', expected_version: provider.verification_version, documents_reviewed: false });
    const cancel = form.cancel;
    useEffect(() => {
        const stopException = router.on('exception', event => {
            if (!pending.current) return;
            event.preventDefault(); pending.current = false; cancel();
            setRequestError('The result could not be confirmed. Check your connection and reload the provider details before trying again.');
        });
        const stopInvalid = router.on('invalid', event => {
            if (!pending.current) return;
            event.preventDefault(); pending.current = false; cancel();
            setRequestError(event.detail.response.status === 419 ? 'Your session expired. Reload the page before continuing.' : 'The result could not be confirmed. Reload the provider details to check the latest status.');
        });
        return () => { stopException(); stopInvalid(); };
    }, [cancel]);
    const open = (value: 'approved' | 'rejected' | 'suspended') => { form.clearErrors(); setRequestError(''); form.setData('verification_status', value); setDecision(value); };
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (pending.current) return;
        if (!navigator.onLine) { setRequestError('You are offline. Reconnect before confirming a decision.'); return; }
        pending.current = true;
        form.patch(provider.review_url, { preserveScroll: true, onSuccess: () => setDecision(null), onFinish: () => { pending.current = false; } });
    };
    return <Card className="rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Review decision</h2><p className="mt-3 text-sm leading-6 text-civic-muted">Confirm the organization and contact information before deciding. Approval enables provider workspace access; rejection blocks it.</p><div className="mt-5 flex flex-wrap gap-3">{provider.verification_status !== 'approved' && <Button disabled={provider.registration_version > 0 && (!provider.email_verified || !provider.documents_available)} onClick={() => open('approved')}>Approve provider</Button>}{provider.verification_status !== 'rejected' && <Button variant="danger" onClick={() => open('rejected')}>Reject provider</Button>}{provider.verification_status === 'approved' && <Button variant="danger" onClick={() => open('suspended')}>Suspend provider</Button>}</div>
        <Modal open={decision !== null} onClose={() => { if (!form.processing) setDecision(null); }} title={decision === 'suspended' ? 'Suspend provider?' : decision === 'rejected' ? 'Reject provider?' : 'Approve provider?'} description={decision !== 'approved' ? 'This will block provider workspace access. Give the organization a clear reason.' : 'This will grant provider workspace access to this organization.'}>
            <form onSubmit={submit} className="space-y-5"><p className="break-words text-sm font-semibold text-civic-navy">{provider.organization_name || provider.name}</p>
                {requestError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{requestError}</p>}
                {decision !== 'approved' && <div><label htmlFor="rejection-reason" className="mb-2 block text-sm font-semibold">Decision reason</label><textarea id="rejection-reason" name="rejection_reason" required maxLength={2000} rows={4} value={form.data.rejection_reason} onChange={event => form.setData('rejection_reason', event.target.value)} aria-invalid={Boolean(form.errors.rejection_reason)} aria-describedby="rejection-help" className={'w-full rounded-lg border border-civic-control bg-white px-3 py-3 text-base leading-6 ' + focusRing} /><p id="rejection-help" role={form.errors.rejection_reason ? 'alert' : undefined} className={'mt-2 text-sm leading-6 ' + (form.errors.rejection_reason ? 'text-civic-danger' : 'text-civic-muted')}>{form.errors.rejection_reason || 'This reason is retained in the history and shared with the provider.'}</p></div>}
                {decision === 'approved' && provider.registration_version > 0 && <label className="flex items-start gap-3 text-sm"><input type="checkbox" required checked={form.data.documents_reviewed} onChange={event => form.setData('documents_reviewed', event.target.checked)} />I downloaded and reviewed the identity, organization or individual administration evidence, and any required authorization.</label>}
                {form.errors.verification_status && <p role="alert" className="text-sm leading-6 text-civic-danger">{form.errors.verification_status}</p>}
                {form.errors.expected_version && <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{form.errors.expected_version}<Link href={provider.show_url} className={'mt-2 block rounded font-bold underline ' + focusRing}>Reload provider details</Link></div>}
                <div className="flex flex-wrap justify-end gap-3"><Button variant="outline" disabled={form.processing} onClick={() => setDecision(null)} data-autofocus>Cancel</Button><Button type="submit" variant={decision !== 'approved' ? 'danger' : 'primary'} loading={form.processing} disabled={Boolean(form.errors.expected_version)}>{decision === 'suspended' ? 'Confirm suspension' : decision === 'rejected' ? 'Confirm rejection' : 'Confirm approval'}</Button></div>
            </form>
        </Modal>
    </Card>;
}
