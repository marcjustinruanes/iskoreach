import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { ManagedAccount, Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import AccountStatusBadge from '../../components/AccountStatusBadge';
import { Button, Card, EmptyState, Input, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';

interface AccountFilters { q: string; role: string; status: string; sort: string }
interface UsersProps extends SharedProps { accounts: Paginated<ManagedAccount>; filters: AccountFilters }
const selectClass = 'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base ' + focusRing;

export default function Users({ accounts, filters, adminNavigation }: UsersProps) {
    const form = useForm<AccountFilters>(filters);
    if (!adminNavigation) return null;
    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.get(adminNavigation.users, { preserveState: true, preserveScroll: true });
    };
    const reset = () => {
        form.setData({ q: '', role: '', status: '', sort: 'newest' });
        form.clearErrors();
        router.get(adminNavigation.users, {}, { preserveScroll: true });
    };
    return <AdminLayout title="User management">
        <Card className="mb-6 rounded-xl p-5 sm:p-6"><form onSubmit={search} aria-label="Search and filter accounts" className="space-y-4">
            <Input label="Search accounts" type="search" name="q" placeholder="Name, email, or provider organization" value={form.data.q} onChange={event => form.setData('q', event.target.value)} maxLength={200} error={form.errors.q} />
            <div className="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_auto]">
                <div><label htmlFor="account-role" className="mb-2 block text-sm font-semibold">Account type</label><select id="account-role" name="role" value={form.data.role} onChange={event => form.setData('role', event.target.value)} className={selectClass}><option value="">Students and providers</option><option value="student">Students</option><option value="provider">Providers</option></select>{form.errors.role && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.role}</p>}</div>
                <div><label htmlFor="account-status" className="mb-2 block text-sm font-semibold">Account status</label><select id="account-status" name="status" value={form.data.status} onChange={event => form.setData('status', event.target.value)} className={selectClass}><option value="">All statuses</option><option value="active">Active</option><option value="pending">Pending provider</option><option value="rejected">Rejected provider</option><option value="profile_missing">Provider profile missing</option></select>{form.errors.status && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.status}</p>}</div>
                <div><label htmlFor="account-sort" className="mb-2 block text-sm font-semibold">Sort by</label><select id="account-sort" name="sort" value={form.data.sort} onChange={event => form.setData('sort', event.target.value)} className={selectClass}><option value="newest">Newest registrations</option><option value="oldest">Oldest registrations</option><option value="name_asc">Name: A–Z</option><option value="name_desc">Name: Z–A</option></select>{form.errors.sort && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.sort}</p>}</div>
                <div className="flex gap-2"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={reset}>Reset</Button></div>
            </div>
        </form></Card>
        <Card className="rounded-xl p-5 sm:p-6"><div className="mb-5"><h2 className="text-lg font-bold text-civic-navy">Registered accounts</h2><p aria-live="polite" className="mt-2 text-sm leading-6 text-civic-muted">{accounts.total} matching {accounts.total === 1 ? 'account' : 'accounts'}. Administrator accounts are managed through the protected provisioning process.</p></div>
            {accounts.data.length ? <Table rows={accounts.data} rowKey={account => account.id} caption="Student and provider accounts" columns={[
                { key: 'identity', label: 'Account', render: account => <div className="min-w-0"><Link href={account.show_url} className={'break-words rounded font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>{account.name}</Link><span className="mt-1 block break-all text-xs leading-5 text-civic-muted">{account.email}</span>{account.organization_name && <span className="mt-1 block break-words text-xs leading-5 text-civic-muted">{account.organization_name}</span>}</div> },
                { key: 'role', label: 'Type', render: account => account.role === 'student' ? 'Student' : 'Provider' },
                { key: 'status', label: 'Status', render: account => <AccountStatusBadge status={account.status} /> },
                { key: 'registered', label: 'Registered', render: account => account.created_at ? <time className="whitespace-nowrap text-civic-muted" dateTime={account.created_at}>{new Date(account.created_at).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' })}</time> : 'Not recorded' },
                { key: 'details', label: 'Details', render: account => <Link href={account.show_url} aria-label={'View account for ' + account.name} className={'inline-flex min-h-11 items-center rounded-lg px-2 font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>View account</Link> },
            ]} /> : <EmptyState title={filters.q || filters.role || filters.status ? 'No matching accounts' : 'No student or provider accounts yet'} description={filters.q || filters.role || filters.status ? 'Try another search or reset the filters.' : 'Student and provider registrations will appear here.'} action={filters.q || filters.role || filters.status ? <Button variant="outline" onClick={reset}>Reset filters</Button> : undefined} />}
            <Pagination page={accounts} label="Account list pages" />
        </Card>
        <p className="mt-5 text-sm leading-6 text-civic-muted">Students have immediate access. Provider access depends on administrator approval. Suspension and reactivation controls are not available.</p>
    </AdminLayout>;
}