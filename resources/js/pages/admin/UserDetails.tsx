import { Link } from '@inertiajs/react';
import type { AccountActivity, ManagedAccount, Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import AccountStatusBadge from '../../components/AccountStatusBadge';
import { Card, EmptyState, buttonStyles } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';

interface UserDetailsProps extends SharedProps {
    account: ManagedAccount & { profile_present: boolean; verification_url: string | null };
    activity: Paginated<AccountActivity>;
}
const date = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));

export default function UserDetails({ account, activity, adminNavigation }: UserDetailsProps) {
    return <AdminLayout title="Account details" description="Review account identity, current access, and recorded administrative activity.">
        {adminNavigation && <Link href={adminNavigation.users} className={buttonStyles('outline') + ' mb-6'}>Back to user management</Link>}
        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <Card className="min-w-0 rounded-xl p-5 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-3"><h2 className="min-w-0 break-words text-xl font-bold leading-8 text-civic-navy">{account.name}</h2><AccountStatusBadge status={account.status} /></div>
                <dl className="mt-6 grid gap-5 text-sm sm:grid-cols-2">{[
                    ['Account reference', '#' + account.id], ['Account type', account.role === 'student' ? 'Student' : 'Scholarship provider'],
                    ['Account email', account.email], ['Registration date', account.created_at ? date(account.created_at) : 'Not recorded'],
                    ['Profile record', account.profile_present ? 'Available' : 'Missing'],
                    ...(account.role === 'provider' ? [['Organization', account.organization_name || 'Not provided']] : []),
                ].map(([label, value]) => <div key={label} className="min-w-0"><dt className="font-semibold text-civic-navy">{label}</dt><dd className="mt-1 break-words leading-6 text-civic-muted [overflow-wrap:anywhere]">{value}</dd></div>)}</dl>
                {!account.profile_present && <p role="status" className="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">This account has no profile record. Features that require a profile will remain unavailable until its profile is restored.</p>}
            </Card>
            <Card className="rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Account access</h2><p className="mt-3 text-sm leading-6 text-civic-muted">{account.role === 'student' ? 'Student accounts can access their own workspace immediately after registration.' : account.status === 'active' ? 'This provider has administrator approval for provider workspace access.' : 'Provider workspace access requires an approved organization profile.'}</p>{account.verification_url && <Link href={account.verification_url} className={buttonStyles('secondary') + ' mt-5'}>Open provider verification</Link>}<p className="mt-5 text-sm leading-6 text-civic-muted">This page provides account oversight. Suspension, role changes, and password changes are not available here.</p></Card>
        </div>
        <Card className="mt-6 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Recorded account activity</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Organization changes and provider review decisions for this account. Sign-ins and student profile changes are not included. Times are shown in Asia/Manila.</p>
            <div className="mt-5">{activity.data.length ? <ol className="divide-y divide-civic-panel-strong">{activity.data.map(item => <li key={item.id} className="py-4 first:pt-0"><div className="flex flex-wrap justify-between gap-2"><h3 className="text-sm font-semibold text-civic-navy">{item.action}</h3><time dateTime={item.occurred_at} className="text-xs leading-5 text-civic-muted">{date(item.occurred_at)}</time></div><p className="mt-2 break-words text-sm leading-6 text-civic-muted">{item.actor}</p></li>)}</ol> : <EmptyState title="No recorded account activity" description="Recorded organization changes and administrator review decisions will appear here. Registration details are shown above." />}</div>
            <Pagination page={activity} label="Account activity pages" />
        </Card>
    </AdminLayout>;
}