import { Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import type { SharedProps, Paginated } from '../../types';
import type { ManagedScholarship } from '../../scholarshipTypes';
import ProviderLayout from '../../layouts/ProviderLayout';
import ScholarshipLifecycleActions from '../../components/provider/ScholarshipLifecycleActions';
import { ScholarshipStatusBadge, scholarshipDate } from '../../components/ScholarshipDisplay';
import { Badge, Button, Card, EmptyState, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';
interface Props extends SharedProps { scholarships: Paginated<ManagedScholarship>; filters: { q: string; status: string; records: string } }
export default function Scholarships({ scholarships, filters }: Props) {
    const form = useForm(filters);
    const submit = (event: FormEvent) => { event.preventDefault(); form.get('/provider/scholarships', { preserveScroll: true, preserveState: true }); };
    return <ProviderLayout title="My scholarships" description="Create program drafts, manage publication, and retain scholarship history." actions={<Link href="/provider/scholarships/create" className={buttonStyles('primary')}><Plus aria-hidden="true" className="size-4" />Create scholarship</Link>}>
        
        <Card className="mb-5 p-4 sm:p-5"><form onSubmit={submit} className="grid items-end gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(280px,1fr)_auto]" aria-label="Filter your scholarships"><Input label="Search your scholarships" name="q" type="search" maxLength={200} value={form.data.q} onChange={e => form.setData('q', e.target.value)} error={form.errors.q} /><div className="grid gap-4 sm:grid-cols-2">{(['status', 'records'] as const).map(field => <div key={field}><label htmlFor={field} className="mb-2 block text-sm font-semibold">{field === 'status' ? 'Publication status' : 'Record visibility'}</label><select id={field} name={field} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 text-base ' + focusRing} value={form.data[field]} onChange={e => form.setData(field, e.target.value)}>{(field === 'status' ? ['', 'draft', 'published', 'closed', 'archived'] : ['current', 'retained']).map(value => <option key={value} value={value}>{value ? value.charAt(0).toUpperCase() + value.slice(1) : 'All statuses'}</option>)}</select>{form.errors[field] && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors[field]}</p>}</div>)}</div><div className="flex flex-wrap gap-2"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={() => router.get('/provider/scholarships')}>Reset</Button></div></form></Card>
        <Card className="min-w-0"><h2 className="text-lg font-bold text-civic-navy">Your scholarship records</h2><p aria-live="polite" className="mt-2 mb-5 text-sm text-civic-muted">{scholarships.total} matching records.</p>{scholarships.data.length ? <Table rows={scholarships.data} rowKey={item => item.id} caption="Your scholarship records" columns={[
            { key: 'title', label: 'Scholarship', render: item => <div>{item.edit_url ? <Link className={'rounded font-semibold text-civic-teal underline ' + focusRing} href={item.edit_url}>{item.title}</Link> : item.title}{item.retained && <div className="mt-2"><Badge>Retained history</Badge></div>}{item.public_url && <Link href={item.public_url} className={'mt-2 block rounded text-xs text-civic-teal underline ' + focusRing}>View public listing</Link>}</div> },
            { key: 'status', label: 'Status', render: item => <ScholarshipStatusBadge status={item.status} /> },
            { key: 'date', label: 'Updated', render: item => scholarshipDate(item.updated_at) },
            { key: 'actions', label: 'Lifecycle actions', render: item => item.lifecycle_url ? <ScholarshipLifecycleActions key={item.id + ':' + item.version} url={item.lifecycle_url} version={item.version} status={item.status} /> : 'History retained; editing unavailable' },
        ]} /> : <EmptyState title="No matching scholarships" description="Create your first draft or adjust the filters. Retained records stay separate from current listings." />}<Pagination page={scholarships} label="Your scholarship pages" /></Card>
    </ProviderLayout>;
}
