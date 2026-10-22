import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { Paginated, ProviderReview, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Button, Card, EmptyState, Input, StatusBadge, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';

interface ProviderQueueProps extends SharedProps { providers: Paginated<ProviderReview>; filters: { q: string; status: string } }

export default function Providers({ providers, filters, adminNavigation }: ProviderQueueProps) {
    const form = useForm({ q: filters.q, status: filters.status });
    if (!adminNavigation) return null;
    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.get(adminNavigation.providers, { preserveState: true, preserveScroll: true });
    };
    const reset = () => {
        form.setData({ q: '', status: '' });
        form.clearErrors();
        router.get(adminNavigation.providers, {}, { preserveScroll: true });
    };
    return <AdminLayout title="Provider reviews">
        <Card className="mb-6 rounded-xl p-5 sm:p-6"><form onSubmit={search} className="grid items-end gap-4 md:grid-cols-[minmax(0,1fr)_12rem_auto]" aria-label="Search and filter providers">
            <Input label="Search providers" type="search" name="q" value={form.data.q} onChange={event => form.setData('q', event.target.value)} placeholder="Organization, contact person, or email" maxLength={200} error={form.errors.q} />
            <div><label htmlFor="provider-status-filter" className="mb-2 block text-sm font-semibold">Verification status</label><select id="provider-status-filter" name="status" value={form.data.status} onChange={event => form.setData('status', event.target.value)} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base ' + focusRing}><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="suspended">Suspended</option></select>{form.errors.status && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.status}</p>}</div>
            <div className="flex gap-2"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={reset}>Reset</Button></div>
        </form></Card>
        <Card className="rounded-xl p-5 sm:p-6"><div className="mb-5"><h2 className="text-lg font-bold text-civic-navy">Provider accounts</h2><p aria-live="polite" className="mt-2 text-sm leading-6 text-civic-muted">{providers.total} matching {providers.total === 1 ? 'account' : 'accounts'}. Pending accounts appear first, followed by newest registrations.</p></div>
            {providers.data.length ? <Table rows={providers.data} rowKey={provider => provider.id} caption="Provider verification accounts" columns={[
                { key: 'organization', label: 'Organization', render: provider => <Link href={provider.show_url} className={'break-words rounded font-semibold text-civic-teal underline decoration-civic-teal/40 underline-offset-4 ' + focusRing}>{provider.organization_name || 'Organization details incomplete'}</Link> },
                { key: 'contact', label: 'Contact person', render: provider => <div className="break-words">{provider.name}<span className="mt-1 block break-all text-xs leading-5 text-civic-muted">{provider.email}</span></div> },
                { key: 'created', label: 'Registered', render: provider => <time className="whitespace-nowrap text-civic-muted" dateTime={provider.created_at}>{new Date(provider.created_at).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' })}</time> },
                { key: 'status', label: 'Status', render: provider => <StatusBadge status={provider.verification_status} /> },
                { key: 'action', label: 'Review', render: provider => <Link href={provider.show_url} aria-label={'View review for ' + (provider.organization_name || provider.name)} className={'inline-flex min-h-11 items-center rounded-lg px-2 font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>View review</Link> },
            ]} /> : <EmptyState title={filters.q || filters.status ? 'No matching provider accounts' : 'No provider accounts yet'} description={filters.q || filters.status ? 'Try another search or reset the filters.' : 'New organization registrations will appear here for review.'} action={filters.q || filters.status ? <Button variant="outline" onClick={reset}>Reset filters</Button> : undefined} />}
            <Pagination page={providers} label="Provider list pages" />
        </Card>
        <p className="mt-5 max-w-3xl text-sm leading-6 text-civic-muted">Approval grants provider workspace access. An administrator must check the submitted information; account approval does not automatically prove an organization's legitimacy.</p>
    </AdminLayout>;
}