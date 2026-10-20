import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { ApplicantCountSummary, ExternalCountReport, Paginated, SharedProps } from '../../types';
import ProviderLayout from '../../layouts/ProviderLayout';
import ApplicantCountBreakdown from '../../components/ApplicantCountBreakdown';
import ExternalCountHistory from '../../components/ExternalCountHistory';
import { Button, Card, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
interface Props extends SharedProps { scholarship: { id: number; title: string; report_url: string }; summary: ApplicantCountSummary; reports: Paginated<ExternalCountReport> }
export default function ApplicantCountDetails({ scholarship, summary, reports }: Props) {
    return <ProviderLayout title="Report external applicants" description={scholarship.title}><Link href="/provider/applicant-counts" className={buttonStyles('outline') + ' mb-6'}>Back to applicant counts</Link><ApplicantCountBreakdown summary={summary} /><ReportForm key={summary.latest_report_id ?? 'first'} url={scholarship.report_url} internalCount={summary.internal_count} /><ExternalCountHistory reports={reports} /></ProviderLayout>;
}
function ReportForm({ url, internalCount }: { url: string; internalCount: number }) {
    const [token] = useState(() => crypto.randomUUID());
    const form = useForm({ reported_count: '', source_label: '', source_as_of: '', duplicates_excluded: false, submission_token: token });
    const request = useRequestFeedback(form.cancel);
    const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); if (!request.begin()) return; form.post(url, { preserveScroll: true, onFinish: request.finish }); };
    return <Card className="mt-6 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Submit a manual count snapshot</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Report the complete external count for the source date, excluding the {internalCount} internal applicants. A new report does not overwrite earlier reports. Do not include names, email addresses, or private applicant information.</p><form onSubmit={submit} className="mt-5 space-y-5">
        <div className="grid gap-5 sm:grid-cols-2"><Input label="External applicant count" name="reported_count" type="number" min={0} max={1000000000} step={1} required value={form.data.reported_count} onChange={event => form.setData('reported_count', event.target.value)} error={form.errors.reported_count} /><Input label="Source as-of date" name="source_as_of" type="date" required value={form.data.source_as_of} onChange={event => form.setData('source_as_of', event.target.value)} error={form.errors.source_as_of} /></div>
        <Input label="Source label" name="source_label" required maxLength={200} placeholder="For example: organization application register" value={form.data.source_label} onChange={event => form.setData('source_label', event.target.value)} error={form.errors.source_label} />
        <div><label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name="duplicates_excluded" required checked={form.data.duplicates_excluded} onChange={event => form.setData('duplicates_excluded', event.target.checked)} className={'mt-1 size-5 shrink-0 accent-civic-teal ' + focusRing} />I checked the source and excluded anyone who has a counted IskoReach application, including waitlisted applicants.</label>{form.errors.duplicates_excluded && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.duplicates_excluded}</p>}</div>
        {[request.error, form.errors.submission_token].filter(Boolean).map((error, index) => <p role="alert" key={index} className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{error}</p>)}<Button type="submit" loading={form.processing}>Submit for verification</Button>
    </form></Card>;
}
