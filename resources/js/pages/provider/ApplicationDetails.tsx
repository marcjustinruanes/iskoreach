import StartConversation from '../../components/StartConversation';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { SharedProps } from '../../types';
import type { ApplicationDetails as Details } from '../../applicationTypes';
import { applicationLabels } from '../../applicationTypes';
import ProviderLayout from '../../layouts/ProviderLayout';
import ApplicationRecordDetails from '../../components/ApplicationRecordDetails';
import { Button, Card, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
export default function ApplicationDetails({ application }: SharedProps & { application: Details }) {
    return <ApplicationReview key={application.id + ':' + application.version} application={application} />;
}
function ApplicationReview({ application: item }: { application: Details }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ version: item.version, status: '', reason: '', confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const available = item.program_retained ? [] : ({ submitted: ['under_review', 'accepted', 'rejected', 'waitlisted'], under_review: ['accepted', 'rejected', 'waitlisted'], waitlisted: ['under_review', 'accepted', 'rejected'] } as Record<string, string[]>)[item.status] ?? [];
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.patch('/provider/applications/' + item.id, { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: request.finish }); };
    return <ProviderLayout title="Applicant review" description="Review the submitted profile and record a reason for each status change.">
        <Link href="/provider/applications" className={buttonStyles('outline') + ' mb-6'}>Back to applicants</Link>
        <ApplicationRecordDetails application={item} />{item.submitted_at && !item.retained && !item.program_retained && <StartConversation applicationId={item.id} />}
        {item.status === 'accepted' && !item.retained && !item.program_retained && <Link href={'/benefits/applications/' + item.id + '/create'} className={buttonStyles('primary') + ' mt-5'}>Manage confirmed award</Link>}
        <Card className="mt-6"><h2 className="text-lg font-bold">Review actions</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Accepted and rejected decisions are final in this stage. Acceptance does not create an award or publish a passer. Conflict flags describe the submission-time check.</p>
            {available.length ? <div className="mt-5 flex flex-wrap gap-3">{available.map(status => <Button key={status} variant="outline" onClick={() => { form.clearErrors(); form.setData({ version: item.version, status, reason: '', confirmed: false }); setOpen(true); }}>{applicationLabels[status]}</Button>)}</div> : <p role="status" className="mt-4 text-sm leading-6 text-civic-muted">No further review actions are available for this record.</p>}
        </Card>
        <Modal open={open} onClose={() => { if (!form.processing) setOpen(false); }} title={'Mark as ' + (applicationLabels[form.data.status] ?? 'reviewed') + '?'} description="The student will see this status and explanation in their application tracker.">
            <form onSubmit={submit} className="space-y-4"><div><label htmlFor="decision-reason" className="mb-2 block text-sm font-semibold">Explanation for the student</label><textarea id="decision-reason" name="reason" required minLength={5} maxLength={2000} rows={4} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} className={'w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base leading-6 ' + focusRing} /></div>
                <label className="flex items-start gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I confirm this status and explanation.</label>
                {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}{Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm leading-6 text-civic-danger">{error}</p>)}
                {form.errors.version && <Button variant="outline" onClick={() => router.reload()}>Reload latest record</Button>}
                <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed}>Confirm decision</Button><Button variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>Cancel</Button></div>
            </form>
        </Modal>
    </ProviderLayout>;
}
