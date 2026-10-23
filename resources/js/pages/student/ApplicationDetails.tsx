import ApplicationRequirement from '../../components/ApplicationRequirement';
import StartConversation from '../../components/StartConversation';
import ApplicationDocumentChecklist from '../../components/ApplicationDocumentChecklist';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { SharedProps } from '../../types';
import type { ApplicationContext, ApplicationDetails as Details } from '../../applicationTypes';
import AppLayout from '../../layouts/AppLayout';
import ApplicationRecordDetails, { SubmissionProfile } from '../../components/ApplicationRecordDetails';
import { Button, Card, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
interface Props extends SharedProps { application: Details; context: ApplicationContext; checklist: import('../../documentTypes').DocumentRequirement[]; vault: import('../../documentTypes').VaultDocument[] }
export default function ApplicationDetails(props: Props) {
    return <ApplicationEditor key={props.application.id + ':' + props.application.version + ':' + props.context.context_token} {...props} />;
}
function ApplicationEditor({ application: item, context, checklist, vault }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ version: item.version, context_token: context.context_token, confirmed: false, conflict_confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const blocked = !context.open || context.conflict_blocked || context.missing_documents > 0 || item.retained;
    const draft = !item.submitted_at && ['not_started', 'requirements_pending'].includes(item.status);
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.patch('/student/applications/' + item.id + '/submit', { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: request.finish }); };
    const documentsPanel = item.submitted_at && !item.retained && !item.program_retained && <Card className="mt-6"><h2 className="text-lg font-bold">Document review and corrections</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Review provider feedback below. Replace a rejected document here while your application awaits a decision. Corrections retain the previous version and do not create another application.</p><div className="mt-5 space-y-4">{checklist.length ? checklist.map(row => <ApplicationRequirement key={row.id+':'+item.version} application={{id:item.id,version:item.version,status:item.status,submitted:true}} row={row} />) : <p className="text-sm text-civic-muted">No document requirements are configured for this application.</p>}</div></Card>;
    return <AppLayout title="Application details">
        <Link href="/student/applications" className={buttonStyles('outline') + ' mb-6'}>Back to my applications</Link>
        <ApplicationRecordDetails application={item} documentsPanel={documentsPanel || undefined} />{item.submitted_at && !item.retained && !item.program_retained && <StartConversation applicationId={item.id} />}{draft && <ApplicationDocumentChecklist applicationId={item.id} version={item.version} checklist={checklist} vault={vault} editable={!item.retained && !item.program_retained} />}

        {draft && <Card className="mt-6"><h2 className="text-lg font-bold">Review before submitting</h2><p className="mt-2 mb-5 text-sm leading-6 text-civic-muted">Submission shares the following profile details with this scholarship provider. The saved snapshot stays with your application.</p><SubmissionProfile profile={context.profile} /><Link href="/student/profile" className={'mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-civic-teal underline ' + focusRing}>Update my school details</Link>
            {!context.open && <p role="status" className="mt-4 rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">This program is not accepting applications now. Your draft remains available.</p>}
            {context.conflict_blocked && <p role="status" className="mt-4 rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">You have an active scholarship grant. This program does not accept existing scholars, so submission is blocked.</p>}
            {context.low_priority && <p role="status" className="mt-4 rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">You have an active scholarship grant. This program allows existing scholars; your application will be marked low priority.</p>}
            {context.missing_documents > 0 && <p role="status" className="mt-4 rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">Complete {context.missing_documents} missing or incompatible document attachment(s) in the checklist before submitting.</p>}
            <Button disabled={blocked} onClick={() => setOpen(true)} className="mt-5">Review and submit</Button>
        </Card>}
        <Modal open={open} onClose={() => { if (!form.processing) setOpen(false); }} title="Submit this application?" description="Check the program criteria and profile details. Submitted applications cannot be edited or resubmitted in this stage.">
            <form onSubmit={submit} className="space-y-4">
                {context.low_priority && <label className="flex items-start gap-3 text-sm leading-6"><input name="conflict_confirmed" type="checkbox" checked={form.data.conflict_confirmed} onChange={e => form.setData('conflict_confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I understand that my active grant places this application in the low-priority group.</label>}
                <label className="flex items-start gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I reviewed the criteria and confirm submission and sharing my displayed profile details and attached documents with this provider.</label>
                {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}{Object.entries(form.errors).map(([key, value]) => <p key={key} role="alert" className="text-sm leading-6 text-civic-danger">{value}</p>)}
                {(form.errors.version || form.errors.context_token) && <Button variant="outline" onClick={() => router.reload()}>Reload latest information</Button>}
                <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed || (context.low_priority && !form.data.conflict_confirmed)}>Confirm submission</Button><Button variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>Cancel</Button></div>
            </form>
        </Modal>
    </AppLayout>;
}
