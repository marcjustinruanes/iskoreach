import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { MonitoredScholarship, Paginated, ScholarshipFilters, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { ScholarshipStatusBadge, locationLabel, scholarshipDate } from '../../components/ScholarshipDisplay';
import { Badge, Button, Card, EmptyState, Input, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';

interface ScholarshipsProps extends SharedProps { scholarships: Paginated<MonitoredScholarship>; filters: ScholarshipFilters; providers: { id: number; name: string }[]; categories: string[] }
const selectClass = 'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base ' + focusRing;

export default function Scholarships({ scholarships, filters, providers, categories, adminNavigation }: ScholarshipsProps) {
    const form = useForm<ScholarshipFilters>(filters);
    if (!adminNavigation) return null;
    const search = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); form.get(adminNavigation.scholarships, { preserveState: true, preserveScroll: true }); };
    const reset = () => router.get(adminNavigation.scholarships, {}, { preserveScroll: true });
    const choices = [
        { key: 'status' as const, label: 'Publication status', options: [['draft', 'Draft'], ['published', 'Published'], ['closed', 'Closed'], ['archived', 'Archived']] },
        { key: 'provider' as const, label: 'Provider', options: providers.map(provider => [String(provider.id), provider.name]) },
        { key: 'category' as const, label: 'Category', options: categories.map(category => [category, category]) },
        { key: 'records' as const, label: 'Record visibility', options: [['current', 'Current records'], ['retained', 'Retained after removal']] },
    ];
    return <AdminLayout title="Scholarship monitoring">
        <Card className="mb-6 rounded-xl p-5 sm:p-6"><form onSubmit={search} aria-label="Search and filter scholarships" className="space-y-5">
            <Input label="Search scholarships" name="q" type="search" maxLength={200} placeholder="Scholarship title or provider organization" value={form.data.q} onChange={event => form.setData('q', event.target.value)} error={form.errors.q} />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{choices.map(choice => <div key={choice.key}><label htmlFor={'scholarship-' + choice.key} className="mb-2 block text-sm font-semibold">{choice.label}</label><select id={'scholarship-' + choice.key} name={choice.key} value={form.data[choice.key]} onChange={event => form.setData(choice.key, event.target.value)} className={selectClass}><option value="">{choice.key === 'records' ? 'Current and retained records' : 'All'}</option>{choice.options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>{form.errors[choice.key] && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors[choice.key]}</p>}</div>)}<Input label="Location name or code" name="location" maxLength={100} placeholder="Nationwide, region, or city / municipality" value={form.data.location} onChange={event => form.setData('location', event.target.value)} error={form.errors.location} /></div>
            <details open={Boolean(filters.opens_from || filters.opens_to || filters.deadline_from || filters.deadline_to)} className="rounded-lg border border-civic-panel-strong p-4"><summary className={'cursor-pointer rounded text-sm font-semibold text-civic-teal ' + focusRing}>Opening and deadline dates</summary><p className="mt-3 text-xs leading-5 text-civic-muted">Date ranges include the selected days in Asia/Manila.</p><div className="mt-4 grid gap-4 sm:grid-cols-2">{(['opens_from', 'opens_to', 'deadline_from', 'deadline_to'] as const).map(key => <Input key={key} name={key} label={{ opens_from: 'Opens on or after', opens_to: 'Opens on or before', deadline_from: 'Deadline on or after', deadline_to: 'Deadline on or before' }[key]} type="date" value={form.data[key]} onChange={event => form.setData(key, event.target.value)} error={form.errors[key]} />)}</div></details>
            <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={reset}>Reset</Button></div>
        </form></Card>
        <Card className="min-w-0 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Scholarship records</h2><p aria-live="polite" className="mt-2 mb-5 text-sm leading-6 text-civic-muted">{scholarships.total} matching {scholarships.total === 1 ? 'record' : 'records'}. Most recently updated first. Dates use Asia/Manila.</p>
            {scholarships.data.length ? <Table rows={scholarships.data} rowKey={item => item.id} caption="Scholarship monitoring records" columns={[
                { key: 'title', label: 'Scholarship', render: item => <div className="min-w-48"><Link href={item.show_url} className={'break-words rounded font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>{item.title}</Link><span className="mt-1 block text-xs leading-5 text-civic-muted">{item.provider}</span><span className="mt-1 block text-xs text-civic-muted">{item.scholarship_type}</span></div> },
                { key: 'locations', label: 'Locations', render: item => <span className="block min-w-32 text-xs leading-6">{item.locations.length ? item.locations.map(locationLabel).join('; ') : 'Not specified'}</span> },
                { key: 'dates', label: 'Application dates', render: item => <div className="min-w-44 text-xs leading-6"><p>Opens: {scholarshipDate(item.opens_at)}</p><p>Deadline: {scholarshipDate(item.deadline_at)}</p></div> },
                { key: 'status', label: 'Status', render: item => <div className="space-y-2"><ScholarshipStatusBadge status={item.status} />{item.deleted_at && <Badge>Retained record</Badge>}</div> },
                { key: 'eligibility', label: 'Existing scholars', render: item => <span className="block min-w-24 text-xs leading-6">{item.accepts_existing_scholars ? 'Allowed' : 'Not allowed'}</span> },
                { key: 'count', label: 'Count preview', render: item => <Link href={item.counts_url} className={'inline-flex min-h-11 items-center rounded text-sm font-semibold text-civic-teal underline ' + focusRing}>{item.applicant_count.toLocaleString('en-PH')}</Link> },
                { key: 'updated', label: 'Updated', render: item => <span className="block min-w-36 text-xs leading-6 text-civic-muted">{scholarshipDate(item.updated_at)}</span> },
            ]} /> : <EmptyState title={Object.values(filters).some(Boolean) ? 'No matching scholarship records' : 'No scholarship records yet'} description="Monitoring displays stored scholarship records. Provider scholarship creation tools are not available yet." action={Object.values(filters).some(Boolean) ? <Button variant="outline" onClick={reset}>Reset filters</Button> : undefined} />}
            <Pagination page={scholarships} label="Scholarship list pages" />
        </Card>
        <p className="mt-5 text-sm leading-6 text-civic-muted">Count previews include internal submitted applications and verified external snapshots only. Scholarship approval and moderation actions are not defined.</p>
    </AdminLayout>;
}
