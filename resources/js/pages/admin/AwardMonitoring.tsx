import { AdminCard as Card } from '../../components/admin/AdminPanel';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Badge, Button, EmptyState, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';
import type { TableColumn } from '../../components/ui/DataDisplay';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';

type Section = 'awards' | 'renewals' | 'releases';
interface Filters { q: string; status: string; provider: string; term: string; award: string; from: string; to: string; activity: string; consent: string }
interface Record {
    id: number; award_id: number; application_reference: string; scholarship: string; scholarship_url: string; provider: string;
    term: string; status: string; active_award: boolean; retained: boolean; updated_at: string;
    starts_on?: string; ends_on?: string | null; consented_at?: string | null; published_at?: string | null;
    publicly_visible?: boolean; can_publish?: boolean; publication_version?: number; publication_url?: string;
    deadline_at?: string | null; submitted_at?: string | null; reviewed_at?: string | null; overdue?: boolean;
    amount?: string | null; currency?: string; scheduled_at?: string | null; released_at?: string | null; release_confirmed?: boolean;
}
interface Props extends SharedProps { section: Section; filters: Filters; records: Paginated<Record>; statuses: string[]; providers: { id: number; name: string }[]; terms: { id: number; name: string }[] }
const titles = { awards: 'Grants and passers', renewals: 'Renewal monitoring', releases: 'Benefit releases' };
const blank: Filters = { q: '', status: '', provider: '', term: '', award: '', from: '', to: '', activity: '', consent: '' };
const selectClass = 'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2 text-base ' + focusRing;
const date = (value?: string | null) => value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'Asia/Manila' }).format(new Date(value.length === 10 ? value + 'T00:00:00+08:00' : value)) : 'Not recorded';
const label = (value: string) => value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ');

export default function AwardMonitoring({ section, filters, records, statuses, providers, terms, adminNavigation }: Props) {
    const form = useForm<Filters>(filters);
    const [selected, setSelected] = useState<Record | null>(null);
    if (!adminNavigation) return null;
    const url = adminNavigation[section];
    const reset = () => { form.setData(blank); form.clearErrors(); router.get(url); };
    const submit = (event: FormEvent) => { event.preventDefault(); form.get(url, { preserveState: true, preserveScroll: true }); };
    const columns: TableColumn<Record>[] = [
        { key: 'reference', label: 'Reference', render: record => <div><span className="font-semibold text-civic-navy">{section === 'awards' ? 'AW' : section === 'renewals' ? 'RN' : 'BR'}-{record.id}</span><p className="mt-1 text-xs text-civic-muted">{record.application_reference} · AW-{record.award_id}</p>{record.retained && <Badge>Retained history</Badge>}</div> },
        { key: 'scholarship', label: 'Scholarship / provider', render: record => <div><Link href={record.scholarship_url} className={'break-words rounded font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>{record.scholarship}</Link><p className="mt-1 break-words text-xs leading-5 text-civic-muted">{record.provider}</p><p className="mt-1 text-xs leading-5 text-civic-muted">{record.term}</p></div> },
        { key: 'status', label: 'Status', render: record => <div className="space-y-2"><Badge tone={['active', 'approved', 'released'].includes(record.status) ? 'green' : ['revoked', 'rejected'].includes(record.status) ? 'red' : 'neutral'}>{label(record.status)}</Badge>{record.overdue && <p className="text-xs font-semibold text-amber-800">Past deadline</p>}{section !== 'awards' && !record.active_award && <p className="text-xs text-civic-muted">Grant currently inactive</p>}</div> },
    ];
    if (section === 'awards') {
        columns.push(
            { key: 'dates', label: 'Grant period', render: record => <div className="text-xs leading-6"><p>From {date(record.starts_on)}</p><p>{record.ends_on ? 'Until ' + date(record.ends_on) : 'No end date recorded'}</p><p className="font-semibold">{record.active_award ? 'Currently active' : 'Currently inactive'}</p></div> },
            { key: 'publication', label: 'Public listing', render: record => <div className="space-y-2"><Badge tone={record.publicly_visible ? 'green' : 'neutral'}>{record.publicly_visible ? 'Publicly listed' : 'Hidden'}</Badge><p className="text-xs leading-5 text-civic-muted">{record.consented_at ? 'Student consent recorded' : 'Awaiting student consent'}</p>{record.published_at && <p className="text-xs leading-5 text-civic-muted">Approved {date(record.published_at)}</p>}<Button variant="outline" onClick={() => setSelected(record)}>{record.published_at ? 'Review publication' : 'Review listing'}</Button></div> },
            { key: 'related', label: 'Monitoring', render: record => <div className="flex flex-col items-start gap-2"><Link href={adminNavigation.renewals + '?award=' + record.id} className={'rounded text-sm font-semibold text-civic-teal underline ' + focusRing}>Renewals</Link><Link href={adminNavigation.releases + '?award=' + record.id} className={'rounded text-sm font-semibold text-civic-teal underline ' + focusRing}>Benefit releases</Link></div> },
        );
    } else if (section === 'renewals') {
        columns.push({ key: 'dates', label: 'Renewal dates', render: record => <div className="space-y-1 text-xs leading-6"><p>Deadline: {date(record.deadline_at)}</p><p>Submitted: {date(record.submitted_at)}</p><p>Reviewed: {date(record.reviewed_at)}</p></div> });
    } else {
        columns.push(
            { key: 'amount', label: 'Recorded benefit', render: record => <span className="whitespace-nowrap font-semibold tabular-nums">{record.amount == null ? 'Amount not specified' : record.currency + ' ' + record.amount}</span> },
            { key: 'dates', label: 'Release dates', render: record => <div className="space-y-1 text-xs leading-6"><p>Scheduled: {date(record.scheduled_at)}</p><p>Released: {date(record.released_at)}</p>{record.status === 'released' && !record.release_confirmed && <p className="font-semibold text-amber-800">Release timestamp missing</p>}</div> },
        );
    }
    return <AdminLayout title={titles[section]}>
        {section === 'awards' && <Link href="/passers" className={buttonStyles('outline') + ' mb-5'}>View public passers list</Link>}
        <Card className="mb-5 p-5 sm:p-6"><form onSubmit={submit} aria-label={'Filter ' + section} className="space-y-4">
            <Input label="Search scholarships or providers" type="search" name="q" maxLength={200} value={form.data.q} onChange={e => form.setData('q', e.target.value)} error={form.errors.q} />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Select label="Status" field="status" value={form.data.status} options={statuses.map(value => ({ value, name: label(value) }))} change={value => form.setData('status', value)} />
                <Select label="Provider" field="provider" value={form.data.provider} options={providers.map(value => ({ value: String(value.id), name: value.name }))} change={value => form.setData('provider', value)} />
                <Select label="Academic term" field="term" value={form.data.term} options={terms.map(value => ({ value: String(value.id), name: value.name }))} change={value => form.setData('term', value)} />
                {section === 'awards' && <><Select label="Current activity" field="activity" value={form.data.activity} options={[{ value: 'active', name: 'Currently active' }, { value: 'inactive', name: 'Currently inactive' }]} change={value => form.setData('activity', value)} /><Select label="Student consent" field="consent" value={form.data.consent} options={[{ value: 'given', name: 'Consent recorded' }, { value: 'missing', name: 'No consent recorded' }]} change={value => form.setData('consent', value)} /></>}
                <Input label={section === 'awards' ? 'Grant starts from' : section === 'renewals' ? 'Deadline from' : 'Scheduled release from'} name="from" type="date" value={form.data.from} onChange={e => form.setData('from', e.target.value)} error={form.errors.from} />
                <Input label="Through date (inclusive)" name="to" type="date" value={form.data.to} onChange={e => form.setData('to', e.target.value)} error={form.errors.to} />
                <Input label="Grant number" name="award" type="number" min={1} placeholder="For example: 12" value={form.data.award} onChange={e => form.setData('award', e.target.value)} error={form.errors.award} />
            </div>
            {(['status', 'provider', 'term', 'activity', 'consent'] as const).map(field => form.errors[field] && <p key={field} role="alert" className="text-sm text-civic-danger">{form.errors[field]}</p>)}
            <div className="admin-form-actions flex flex-wrap gap-3"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={reset}>Reset</Button></div>
        </form></Card>
        <Card className="min-w-0 p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Recorded {section === 'releases' ? 'benefit releases' : section}</h2><p aria-live="polite" className="mt-2 mb-5 text-sm leading-6 text-civic-muted">{records.total} matching {records.total === 1 ? 'record' : 'records'}. Dates use Asia/Manila.</p>
            {records.data.length ? <Table rows={records.data} rowKey={record => record.id} columns={columns} caption={titles[section]} /> : <EmptyState title="No matching records" description="Records will appear when grants and provider tracking entries are recorded. Try resetting any active filters." />}
            <Pagination page={records} label={titles[section] + ' pages'} />
        </Card>
        <p className="mt-5 text-sm leading-6 text-civic-muted">{section === 'awards' ? 'Public entries require a confirmed active grant, student consent, and publication approval. Accepted applications alone do not appear.' : section === 'renewals' ? 'Providers remain responsible for renewal decisions. Document checklists are not available in this monitoring view.' : 'Amounts are shown in their recorded currency. This page tracks releases; it does not transfer money.'}</p>
        {selected && <PublicationDialog key={selected.id + ':' + selected.publication_version} record={selected} close={() => setSelected(null)} />}
    </AdminLayout>;
}

function Select({ label, field, value, options, change }: { label: string; field: string; value: string; options: { value: string; name: string }[]; change: (value: string) => void }) {
    return <div><label htmlFor={'monitor-' + field} className="mb-2 block text-sm font-semibold">{label}</label><select id={'monitor-' + field} name={field} value={value} onChange={e => change(e.target.value)} className={selectClass}><option value="">All</option>{options.map(option => <option key={option.value} value={option.value}>{option.name}</option>)}</select></div>;
}
function PublicationDialog({ record, close }: { record: Record; close: () => void }) {
    const form = useForm({ action: record.published_at ? 'unpublish' : 'publish', version: record.publication_version!, reason: '', confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const publishing = form.data.action === 'publish';
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.patch(record.publication_url!, { preserveScroll: true, onSuccess: close, onFinish: request.finish }); };
    return <Modal open onClose={() => { if (!form.processing) close(); }} title={publishing ? 'Publish grant?' : 'Remove public listing?'} description={record.scholarship}>
        <form onSubmit={submit} className="space-y-5">
            {request.error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{request.error}</p>}
            <p className="text-sm leading-6 text-civic-muted">{publishing ? 'The student’s name, scholarship, and provider will be visible to everyone. Publication requires consent and a confirmed active grant.' : 'The entry will be hidden from the public passers list. The grant and its history will be retained.'}</p>
            {publishing && !record.can_publish && <p role="status" className="rounded-lg bg-amber-50 p-3 text-sm leading-6 text-amber-900">This grant is not eligible for publication. Check its activity, student consent, provider approval, and retained-record status.</p>}
            <Input label="Reason for this publication decision" name="reason" required minLength={5} maxLength={1000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} error={form.errors.reason} />
            <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name="confirmed" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 accent-civic-teal ' + focusRing} />I confirm this publication decision for AW-{record.id}.</label>
            {Object.entries(form.errors).filter(([key]) => key !== 'reason').map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}
            {form.errors.version && <Button variant="outline" onClick={() => router.reload()}>Reload latest grant</Button>}
            <div className="admin-form-actions flex flex-wrap gap-3"><Button type="submit" variant={publishing ? 'primary' : 'danger'} loading={form.processing} disabled={!form.data.confirmed || (publishing && !record.can_publish)}>{publishing ? 'Confirm publication' : 'Remove listing'}</Button><Button variant="outline" disabled={form.processing} onClick={close}>Cancel</Button></div>
        </form>
    </Modal>;
}
