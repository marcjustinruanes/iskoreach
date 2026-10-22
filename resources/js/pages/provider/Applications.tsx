import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { SharedProps, Paginated } from '../../types';
import type { ApplicationRecord } from '../../applicationTypes';
import { applicationLabels } from '../../applicationTypes';
import ProviderLayout from '../../layouts/ProviderLayout';
import { StatusBadge, Button, Card, EmptyState, Input, focusRing } from '../../components/ui/DesignSystem';
import { Table, Pagination } from '../../components/ui/DataDisplay';
import { scholarshipDate } from '../../components/ScholarshipDisplay';
export default function Applications({ applications, filters }: SharedProps & { applications: Paginated<ApplicationRecord>; filters: { q: string; status: string; priority: string } }) {
    const form = useForm(filters);
    const submit = (event: FormEvent) => { event.preventDefault(); form.get('/provider/applications', { preserveState: true, preserveScroll: true }); };
    return <ProviderLayout title="Applicants" description="Review submitted applications for your organization. Student drafts remain private.">
        <Card className="mb-5 p-4 sm:p-5"><form aria-label="Filter applicants" onSubmit={submit} className="grid items-end gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(280px,1fr)_auto]"><Input label="Search applicants or scholarships" name="q" value={form.data.q} onChange={e => form.setData('q', e.target.value)} error={form.errors.q} /><div className="grid gap-4 sm:grid-cols-2"><div><label htmlFor="review-status" className="mb-2 block text-sm font-semibold">Application status</label><select id="review-status" name="status" className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 text-base ' + focusRing} value={form.data.status} onChange={e => form.setData('status', e.target.value)}><option value="">All submitted statuses</option>{['submitted', 'under_review', 'accepted', 'rejected', 'waitlisted'].map(status => <option key={status} value={status}>{applicationLabels[status]}</option>)}</select></div><div><label htmlFor="priority" className="mb-2 block text-sm font-semibold">Submission priority</label><select id="priority" name="priority" value={form.data.priority} onChange={e => form.setData('priority', e.target.value)} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 text-base ' + focusRing}><option value="">All</option><option value="standard">Standard</option><option value="low">Low priority</option></select></div></div>{Object.entries(form.errors).filter(([key]) => key !== 'q').map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}<div className="flex flex-wrap gap-2"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={() => router.get('/provider/applications')}>Reset</Button></div></form></Card>
        <Card className="min-w-0"><div className="mb-5 flex flex-wrap items-center justify-between gap-2"><h2 className="text-base font-bold">Submitted applications</h2><p aria-live="polite" className="text-xs text-civic-muted">{applications.total} matching applications</p></div>{applications.data.length ? <Table rows={applications.data} rowKey={item => item.id} caption="Your organization's submitted applications" columns={[
            { key: 'student', label: 'Applicant', render: item => <Link href={'/provider/applications/' + item.id} className={'font-semibold text-civic-teal underline ' + focusRing}>{item.student_name}</Link> },
            { key: 'program', label: 'Scholarship', render: item => item.title },
            { key: 'status', label: 'Status', render: item => <div className="space-y-2"><StatusBadge status={item.status as 'submitted' | 'under_review' | 'accepted' | 'rejected' | 'waitlisted'} />{item.low_priority && <span className="block text-xs font-medium text-amber-800">Low priority</span>}</div> },
            { key: 'date', label: 'Submitted', render: item => scholarshipDate(item.submitted_at) },
        ]} /> : <EmptyState title="No matching submitted applications" description="Applications appear here after students submit. Try adjusting your filters." />}<Pagination page={applications} label="Provider applicant pages" /></Card>
    </ProviderLayout>;
}
