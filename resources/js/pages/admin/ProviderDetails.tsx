import { FileText, Eye } from 'lucide-react';
import { AdminCard as Card, AdminRecordFields } from '../../components/admin/AdminPanel';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { Paginated, ProviderDetails as Provider, SharedProps, VerificationHistory } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Button, EmptyState, StatusBadge, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { Modal } from '../../components/ui/Overlays';

interface ProviderDetailsProps extends SharedProps { provider: Provider; history: Paginated<VerificationHistory> }
const date = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));
const rejectionReasons = ['Incomplete verification documents', 'Unreadable or invalid documents', 'Organization details do not match the documents', 'Representative authorization could not be verified', 'Organization eligibility could not be verified', 'Other'];

export default function ProviderDetails({ provider, history, adminNavigation }: ProviderDetailsProps) {
    const [preview, setPreview] = useState<Provider['documents'][number] | null>(null);
    const [previewError, setPreviewError] = useState(false);
    const representativeKeys = ['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email'];
    const registrationRows = (representative: boolean) => Object.entries(provider.registration_details)
        .filter(([key]) => representativeKeys.includes(key) === representative && !['organization_name', 'organization_type'].includes(key))
        .map(([key, value]) => ({ label: key.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase()), value: typeof value === 'boolean' ? value ? 'Yes' : 'No' : value || 'Not provided' }));
    return <AdminLayout title="Provider verification" actions={adminNavigation && <Link href={adminNavigation.providers} className={buttonStyles('outline', 'sm')}>Back to provider accounts</Link>}>
        <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <Card><div className="flex flex-wrap items-start justify-between gap-3 border-b border-civic-blue-gray pb-4"><h2 className="min-w-0 flex-1 break-words text-lg font-bold leading-7">{provider.organization_name || 'Organization details incomplete'}</h2><StatusBadge status={provider.verification_status} /></div>
                <AdminRecordFields rows={[
                    {label: 'Contact person', value: provider.name}, {label: 'Account email', value: provider.email},
                    {label: 'Organization email', value: provider.contact_email || 'Missing - required before approval'},
                    {label: 'Organization type', value: String(provider.registration_details.organization_type || 'Not collected for this legacy account')},
                    {label: 'Registered', value: date(provider.created_at)},
                    {label: 'Last decision', value: provider.reviewed_at ? date(provider.reviewed_at) : 'Not reviewed yet'}, {label: 'Reviewed by', value: provider.reviewer || 'No current reviewer'},
                ]} />
                {provider.registration_version > 0 && <details className="mt-5 border-t border-civic-blue-gray pt-3"><summary className={'cursor-pointer rounded py-2 text-sm font-semibold ' + focusRing}>Representative and organization details</summary><div className="mt-3 space-y-5"><section aria-labelledby="representative-details"><h3 id="representative-details" className="text-sm font-bold">Authorized representative</h3><AdminRecordFields rows={registrationRows(true)} /></section><section aria-labelledby="organization-details"><h3 id="organization-details" className="text-sm font-bold">Organization and address</h3><AdminRecordFields rows={registrationRows(false)} /></section></div></details>}
                <section aria-labelledby="provider-documents" className="mt-5 border-t border-civic-blue-gray pt-4"><h3 id="provider-documents" className="text-sm font-bold">Private verification documents</h3>{provider.documents.length ? <ul className="mt-3 divide-y divide-civic-blue-gray">{provider.documents.map(document => <li key={document.kind} className="flex min-w-0 flex-wrap items-center justify-between gap-2 py-3"><span className="min-w-0 break-words text-sm capitalize">{document.kind.replaceAll('_', ' ')}</span><div className="flex flex-wrap gap-2"><Button variant="outline" size="sm" aria-label={'Preview ' + document.kind.replaceAll('_', ' ')} onClick={() => { setPreviewError(false); setPreview(document); }}><Eye aria-hidden="true" className="size-4" />Preview</Button><a href={document.url} className={buttonStyles('outline', 'sm')}><FileText aria-hidden="true" className="size-4" />Download<span className="sr-only"> {document.kind.replaceAll('_', ' ')}</span></a></div></li>)}</ul> : <p className="mt-3 rounded-lg border border-dashed border-civic-blue-gray bg-civic-canvas p-3 text-sm leading-6 text-civic-muted">No verification documents were collected for this legacy account.</p>}{!provider.documents_available && <p role="alert" className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-civic-danger">Required documents are missing or failed an integrity check. Approval is blocked.</p>}</section>
                {provider.rejection_reason && <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4"><h3 className="text-sm font-bold text-red-900">Current decision reason</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-red-900">{provider.rejection_reason}</p></div>}
            </Card>
            <ReviewActions key={provider.id + ':' + provider.verification_version} provider={provider} />
        </div>
        <Card className="mt-5 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Verification history</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Decisions and organization changes are retained. Times are shown in Asia/Manila.</p>
            <div className="mt-5">{history.data.length ? <ol className="divide-y divide-civic-panel-strong">{history.data.map(item => <li key={item.id} className="py-5 first:pt-0"><div className="flex flex-wrap items-start justify-between gap-2"><h3 className="text-sm font-bold text-civic-navy">{item.action}</h3><time dateTime={item.occurred_at} className="text-xs leading-5 text-civic-muted">{date(item.occurred_at)}</time></div><p className="mt-2 break-words text-sm leading-6 text-civic-muted">{item.actor} · {item.from || 'Unknown'} → {item.to || 'Unknown'}</p>{item.reason ? <p className="mt-3 whitespace-pre-wrap break-words rounded-lg bg-civic-panel p-3 text-sm leading-6">{item.reason}</p> : item.to === 'rejected' && <p className="mt-2 text-xs leading-5 text-civic-muted">A reason was not retained in this older history entry.</p>}<div>{item.previous_reason && <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-civic-muted"><strong>Previous rejection reason:</strong> {item.previous_reason}</p>}</div></li>)}</ol> : <EmptyState title="No verification history yet" description="Decisions and changes to this organization's details will appear here." />}</div>
            <Pagination page={history} label="Verification history pages" />
        </Card>
        <Modal open={preview !== null} onClose={() => setPreview(null)} title={preview ? preview.kind.replaceAll('_', ' ').replace(/^./, value => value.toUpperCase()) : 'Document preview'} description={preview?.name} panelClassName="!max-w-5xl">
            {preview && <div className="space-y-4">{previewError ? <p role="alert" className="rounded-lg bg-red-50 p-4 text-sm leading-6 text-civic-danger">This preview could not be loaded. The file may have been replaced or failed its integrity check. Reload the provider record or use Download.</p> : ['image/jpeg', 'image/png'].includes(preview.mime) ? <img src={preview.preview_url} alt={preview.kind.replaceAll('_', ' ') + ' preview'} className="max-h-[65dvh] w-full rounded-lg object-contain" onError={() => setPreviewError(true)} /> : preview.mime === 'application/pdf' ? <iframe src={preview.preview_url} title={preview.kind.replaceAll('_', ' ') + ' PDF preview'} className="h-[65dvh] w-full rounded-lg border border-civic-border" onError={() => setPreviewError(true)} /> : <p className="text-sm text-civic-muted">Preview is available for PDF, JPG and PNG documents.</p>}<div className="flex flex-wrap items-center justify-between gap-3"><p className="text-xs leading-6 text-civic-muted">If your browser cannot display the document, download it to review.</p><a href={preview.url} className={buttonStyles('outline', 'sm')}><FileText aria-hidden="true" className="size-4" />Download document</a></div></div>}
        </Modal>
    </AdminLayout>;
}

function ReviewActions({ provider }: { provider: Provider }) {
    const [decision, setDecision] = useState<'approved' | 'rejected' | 'suspended' | null>(null);
    const [selectedReason, setSelectedReason] = useState('');
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
    const open = (value: 'approved' | 'rejected' | 'suspended') => { form.clearErrors(); setRequestError(''); form.setData(current => ({ ...current, verification_status: value, rejection_reason: '', documents_reviewed: false })); setSelectedReason(''); setDecision(value); };
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (pending.current) return;
        if (decision === 'rejected' && !selectedReason) { form.setError('rejection_reason', 'Select a rejection reason.'); return; }
        if (decision === 'rejected' && selectedReason === 'Other' && !form.data.rejection_reason.trim()) { form.setError('rejection_reason', 'Describe the rejection reason in Additional information.'); return; }
        if (!navigator.onLine) { setRequestError('You are offline. Reconnect before confirming a decision.'); return; }
        pending.current = true;
        form.transform(data => ({ ...data, rejection_reason: decision === 'rejected' ? [selectedReason, data.rejection_reason.trim()].filter(Boolean).join('\n\n') : data.rejection_reason.trim() }));
        form.patch(provider.review_url, { preserveScroll: true, onSuccess: () => setDecision(null), onFinish: () => { pending.current = false; } });
    };
    return <Card className="xl:sticky xl:top-20"><h2 className="text-lg font-bold text-civic-navy">Review decision</h2><p className="mt-3 text-sm leading-6 text-civic-muted">Review the evidence before deciding. Access changes and your reason are recorded.</p><div className="mt-4 grid gap-2 border-t border-civic-blue-gray pt-4">{provider.verification_status !== 'approved' && <Button disabled={provider.registration_version > 0 && !provider.documents_available} onClick={() => open('approved')}>Approve provider</Button>}{provider.verification_status !== 'rejected' && <Button variant="outline" className="!border-red-200 !text-civic-danger hover:!bg-red-50" onClick={() => open('rejected')}>Reject provider</Button>}{provider.verification_status === 'approved' && <Button variant="danger" onClick={() => open('suspended')}>Suspend provider</Button>}</div>
        <Modal open={decision !== null} onClose={() => { if (!form.processing) setDecision(null); }} title={decision === 'suspended' ? 'Suspend provider' : decision === 'rejected' ? 'Reject provider' : 'Approve provider'} description={decision !== 'approved' ? 'This decision blocks provider workspace access. The reason is shared with the provider in their status and email notification.' : 'Approval grants provider workspace access to this organization.'} panelClassName="!max-w-lg">
            <form onSubmit={submit} className="space-y-5">
                {requestError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{requestError}</p>}
                {decision === 'rejected' && <fieldset disabled={form.processing} aria-describedby="rejection-help"><legend className="mb-3 text-sm font-semibold">Rejection reason</legend><div className="space-y-3">{rejectionReasons.map(reason => <label key={reason} className="flex cursor-pointer items-start gap-3 text-sm leading-6"><input type="radio" name="rejection_category" value={reason} checked={selectedReason === reason} onChange={() => { setSelectedReason(reason); form.clearErrors('rejection_reason'); }} className={'mt-1 size-4 shrink-0 ' + focusRing} /><span>{reason}</span></label>)}</div></fieldset>}
                {decision !== 'approved' && <div><label htmlFor="rejection-reason" className="mb-2 block text-sm font-semibold">{decision === 'rejected' ? 'Additional information' + (selectedReason === 'Other' ? ' (required)' : ' (optional)') : 'Decision reason'}</label><textarea id="rejection-reason" name="rejection_reason" required={decision === 'suspended'} disabled={form.processing} maxLength={decision === 'rejected' ? 1800 : 2000} rows={4} value={form.data.rejection_reason} onChange={event => { form.setData('rejection_reason', event.target.value); form.clearErrors('rejection_reason'); }} aria-invalid={Boolean(form.errors.rejection_reason)} aria-describedby="rejection-help" className={'w-full rounded-lg border border-civic-control bg-white px-3 py-3 text-base leading-6 ' + focusRing} /><p id="rejection-help" role={form.errors.rejection_reason ? 'alert' : undefined} className={'mt-2 text-sm leading-6 ' + (form.errors.rejection_reason ? 'text-civic-danger' : 'text-civic-muted')}>{form.errors.rejection_reason || 'Explain what the provider needs to correct. Your reason and additional information are saved together.'}</p></div>}
                {decision === 'approved' && provider.registration_version > 0 && <label className="flex items-start gap-3 text-sm"><input type="checkbox" required checked={form.data.documents_reviewed} onChange={event => form.setData('documents_reviewed', event.target.checked)} />I previewed or downloaded and reviewed the identity, organization or individual administration evidence, and any required authorization.</label>}
                {form.errors.verification_status && <p role="alert" className="text-sm leading-6 text-civic-danger">{form.errors.verification_status}</p>}
                {form.errors.expected_version && <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{form.errors.expected_version}<Link href={provider.show_url} className={'mt-2 block rounded font-bold underline ' + focusRing}>Reload provider details</Link></div>}
                <div className="flex flex-wrap justify-end gap-3"><Button variant="outline" disabled={form.processing} onClick={() => setDecision(null)} data-autofocus>Cancel</Button><Button type="submit" variant={decision !== 'approved' ? 'danger' : 'primary'} loading={form.processing} disabled={Boolean(form.errors.expected_version)}>{decision === 'suspended' ? 'Confirm suspension' : decision === 'rejected' ? 'Confirm rejection' : 'Confirm approval'}</Button></div>
            </form>
        </Modal>
    </Card>;
}
