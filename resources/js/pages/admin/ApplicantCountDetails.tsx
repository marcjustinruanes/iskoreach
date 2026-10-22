import ExternalCountWorkflow from '../../components/ExternalCountWorkflow';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { ApplicantCountSummary, ExternalCountReport, Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import ApplicantCountBreakdown from '../../components/ApplicantCountBreakdown';
import ExternalCountHistory from '../../components/ExternalCountHistory';
import { Button, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';

type Decision = 'approve' | 'reject' | 'correct' | 'reverify';
interface Props extends SharedProps { scholarship: { id: number; title: string; provider: string | null; show_url: string; record_url: string }; summary: ApplicantCountSummary; reports: Paginated<ExternalCountReport> }
export default function ApplicantCountDetails({ scholarship, summary, reports, adminNavigation }: Props) {
    const [review, setReview] = useState<{ report: ExternalCountReport; decision: Decision } | null>(null);
    return <AdminLayout title="Applicant count details" description={scholarship.title + (scholarship.provider ? ' · ' + scholarship.provider : '')}>
        <div className="mb-6 flex flex-wrap gap-3">{adminNavigation && <Link href={adminNavigation.counts} className={buttonStyles('outline')}>Back to applicant counts</Link>}<Link href={scholarship.record_url} className={buttonStyles('outline')}>Scholarship information</Link></div>
        <ApplicantCountBreakdown summary={summary} /><ExternalCountWorkflow administrator /><ExternalCountHistory reports={reports} actions={report => <div className="flex flex-wrap gap-3">{report.status === 'pending' && <><Button onClick={() => setReview({ report, decision: 'approve' })}>Verify report</Button><Button variant="danger" onClick={() => setReview({ report, decision: 'reject' })}>Reject report</Button></>}{report.id === summary.latest_approved_id && <><Button variant="outline" onClick={() => setReview({ report, decision: 'correct' })}>Record correction</Button>{summary.needs_reverification && <Button onClick={() => setReview({ report, decision: 'reverify' })}>Re-verify count</Button>}</>}</div>} />
        {review && <ReviewDialog key={review.report.id + ':' + review.decision} report={review.report} decision={review.decision} summary={summary} reloadUrl={scholarship.show_url} onClose={() => setReview(null)} />}
    </AdminLayout>;
}
function ReviewDialog({ report, decision, summary, reloadUrl, onClose }: { report: ExternalCountReport; decision: Decision; summary: ApplicantCountSummary; reloadUrl: string; onClose: () => void }) {
    const form = useForm({ decision, reason: '', corrected_count: String(report.corrected_count ?? report.reported_count), duplicates_checked: false, expected_snapshot: summary.review_token, expected_approved_id: summary.latest_approved_id });
    const request = useRequestFeedback(form.cancel);
    const title = { approve: 'Verify external count?', reject: 'Reject external count?', correct: 'Record a count correction?', reverify: 'Re-verify external count?' }[decision];
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!report.review_url || !request.begin()) return;
        form.patch(report.review_url, { preserveScroll: true, onSuccess: onClose, onFinish: request.finish });
    };
    return <Modal open onClose={() => { if (!form.processing) onClose(); }} title={title} description={'Report #' + report.id + ' · ' + report.source_label + ' · as of ' + report.source_as_of}>
        <form onSubmit={submit} className="space-y-4"><p className="text-sm leading-6 text-civic-muted">{decision === 'reject' ? 'The report will remain in history and will not contribute to the count.' : 'Confirm the external number excludes all current internal applicants, including waitlisted applicants. Aggregate numbers cannot be deduplicated automatically.'}</p>
            {decision === 'correct' && <Input label="Corrected external count" name="corrected_count" type="number" min={0} max={1000000000} step={1} required value={form.data.corrected_count} onChange={event => form.setData('corrected_count', event.target.value)} error={form.errors.corrected_count} />}
            <div><label htmlFor="count-review-reason" className="mb-2 block text-sm font-semibold">{decision === 'approve' ? 'Review note (optional)' : 'Reason (required)'}</label><textarea id="count-review-reason" name="reason" rows={3} maxLength={2000} required={decision !== 'approve'} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} aria-invalid={Boolean(form.errors.reason)} className={'w-full rounded-lg border border-civic-control p-3 text-base leading-6 ' + focusRing} />{form.errors.reason && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.reason}</p>}</div>
            {decision !== 'reject' && <div><label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name="duplicates_checked" required checked={form.data.duplicates_checked} onChange={event => form.setData('duplicates_checked', event.target.checked)} className={'mt-1 size-5 shrink-0 accent-civic-teal ' + focusRing} />I checked the source and confirmed this count excludes the {summary.internal_count} internal applicants.</label>{form.errors.duplicates_checked && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.duplicates_checked}</p>}</div>}
            {[request.error, form.errors.decision, form.errors.expected_snapshot, form.errors.expected_approved_id].filter(Boolean).map((error, index) => <p role="alert" key={index} className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{error}</p>)}
            {(form.errors.decision || form.errors.expected_snapshot || form.errors.expected_approved_id || request.error) && <Link href={reloadUrl} className={'inline-flex min-h-11 items-center rounded text-sm font-semibold text-civic-teal underline ' + focusRing}>Reload count details</Link>}
            <div className="flex flex-wrap justify-end gap-3"><Button variant="outline" disabled={form.processing} onClick={onClose} data-autofocus>Cancel</Button><Button type="submit" variant={decision === 'reject' ? 'danger' : 'secondary'} loading={form.processing}>Confirm decision</Button></div>
        </form>
    </Modal>;
}