import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { ApplicantCountSummary, Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Badge, Button, Card, EmptyState, Input, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';

interface Props extends SharedProps { filters: { q: string; pending: string }; scholarships: Paginated<{ id: number; title: string; provider: string | null; summary: ApplicantCountSummary; show_url: string }> }
export default function ApplicantCounts({ filters, scholarships, adminNavigation }: Props) {
    const form = useForm(filters);
    if (!adminNavigation) return null;
    const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); form.get(adminNavigation.counts, { preserveState: true, preserveScroll: true }); };
    return <AdminLayout title="Applicant counts" description="Inspect internal submissions, provider-reported external snapshots, and verified totals."><Card className="mb-6 rounded-xl p-5"><form onSubmit={submit} aria-label="Search applicant counts" className="space-y-4"><Input label="Search scholarships" name="q" type="search" maxLength={200} value={form.data.q} onChange={event => form.setData('q', event.target.value)} error={form.errors.q} /><label className="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" name="pending" checked={form.data.pending === '1'} onChange={event => form.setData('pending', event.target.checked ? '1' : '')} className={'size-5 accent-civic-teal ' + focusRing} />Only scholarships with reports awaiting review</label><div className="flex gap-3"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={() => router.get(adminNavigation.counts)}>Reset</Button></div></form></Card>
        <Card className="min-w-0 rounded-xl p-5"><h2 className="mb-5 text-lg font-bold text-civic-navy">{scholarships.total} scholarship count records</h2>{scholarships.data.length ? <Table rows={scholarships.data} rowKey={item => item.id} caption="Applicant count monitoring" columns={[
            { key: 'title', label: 'Scholarship', render: item => <div className="min-w-40"><Link href={item.show_url} className={'break-words rounded font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>{item.title}</Link><p className="mt-1 text-xs leading-5 text-civic-muted">{item.provider || 'Organization not recorded'}</p></div> },
            { key: 'internal', label: 'Internal / waitlisted', render: item => item.summary.internal_count + ' / ' + item.summary.waitlisted_count },
            { key: 'reported', label: 'Latest external report', render: item => <span>{item.summary.reported_external_count === null ? 'No report' : item.summary.reported_external_count}<span className="mt-1 block break-words text-xs text-civic-muted [overflow-wrap:anywhere]">{item.summary.source_label}{item.summary.source_as_of && ' · ' + item.summary.source_as_of}</span></span> },
            { key: 'verified', label: 'Verified external included', render: item => item.summary.verified_external_count },
            { key: 'total', label: 'Total preview', render: item => <strong className="tabular-nums">{item.summary.public_count}</strong> },
            { key: 'status', label: 'Review status', render: item => <div className="space-y-2"><Badge tone={item.summary.needs_reverification || item.summary.pending_reports ? 'amber' : 'neutral'}>{item.summary.needs_reverification ? 'Re-verification needed' : item.summary.latest_status.replace('_', ' ')}</Badge>{item.summary.pending_reports > 0 && <span className="block text-xs leading-5 text-civic-muted">{item.summary.pending_reports} pending</span>}</div> },
        ]} /> : <EmptyState title="No matching scholarship records" description="Try another search or clear the pending-review filter. New scholarship records will appear here when available." />}<Pagination page={scholarships} label="Applicant count list pages" /></Card><p className="mt-5 text-sm leading-6 text-civic-muted">Manual reporting only. Google Forms and Google Sheets synchronization are not connected. The public landing page remains a labelled design preview.</p>
    </AdminLayout>;
}