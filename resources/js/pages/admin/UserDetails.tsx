import { AdminCard as Card, AdminRecordFields } from '../../components/admin/AdminPanel';
import { Link, useForm } from '@inertiajs/react';
import type { AccountActivity, ManagedAccount, Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import AccountStatusBadge from '../../components/AccountStatusBadge';
import { Button, EmptyState, buttonStyles } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';

interface UserDetailsProps extends SharedProps {
    account: ManagedAccount & { profile_present: boolean; verification_url: string | null; registration_details: Record<string, string | number | null> | null; review_url: string | null; review_reason: string | null };
    activity: Paginated<AccountActivity>;
}
const date = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));

export default function UserDetails({ account, activity, adminNavigation }: UserDetailsProps) {
    const review = useForm({ registration_status: 'approved', registration_review_reason: account.review_reason ?? '' });
    return <AdminLayout title="Account details" actions={adminNavigation && <Link href={adminNavigation.users} className={buttonStyles('outline', 'sm')}>Back to user management</Link>}>

        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <Card className="min-w-0 rounded-xl p-5 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-3"><h2 className="min-w-0 break-words text-xl font-bold leading-8 text-civic-navy">{account.name}</h2><AccountStatusBadge status={account.status} /></div>
                <AdminRecordFields className="mt-3" rows={[
                    ['Account reference', '#' + account.id], ['Account type', account.role === 'student' ? 'Student' : 'Scholarship provider'],
                    ['Account email', account.email], ['Registration date', account.created_at ? date(account.created_at) : 'Not recorded'],
                    ['Profile record', account.profile_present ? 'Available' : 'Missing'],
                    ...(account.role === 'provider' ? [['Organization', account.organization_name || 'Not provided']] : []),
                ].map(([label, value]) => ({label, value}))} />
                {!account.profile_present && <p role="status" className="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">This account has no profile record. Features that require a profile will remain unavailable until its profile is restored.</p>}
            </Card>
            <Card className="rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Account access</h2><p className="mt-3 text-sm leading-6 text-civic-muted">{account.role === 'student' ? 'Student workspace access requires administrator approval.' : account.status === 'active' ? 'This provider has administrator approval for provider workspace access.' : 'Provider workspace access requires an approved organization profile.'}</p>{account.verification_url && <Link href={account.verification_url} className={buttonStyles('primary') + ' mt-5'}>Open provider verification</Link>}{account.review_url && <form className="mt-5 space-y-4" onSubmit={event => { event.preventDefault(); review.patch(account.review_url!); }}><label className="block text-sm font-semibold">Registration decision<select className="mt-2 block min-h-11 w-full rounded-lg border p-2 text-base" value={review.data.registration_status} onChange={event => review.setData('registration_status', event.target.value)}><option value="approved">Approve</option><option value="rejected">Reject</option></select></label><label className="block text-sm font-semibold">Reason {review.data.registration_status === 'rejected' ? '(required)' : '(optional)'}<textarea className="mt-2 block w-full rounded-lg border p-2 text-base" value={review.data.registration_review_reason} maxLength={2000} onChange={event => review.setData('registration_review_reason', event.target.value)} /></label>{Object.values(review.errors).map(error => <p key={error} role="alert" className="text-sm text-civic-danger">{error}</p>)}<Button type="submit" loading={review.processing}>Save registration decision</Button></form>}</Card>
        </div>
        {account.registration_details && <Card className="mt-5 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold">Student registration details</h2><AdminRecordFields className="mt-4" rows={Object.entries(account.registration_details).filter(([, value]) => value !== null && value !== '').map(([key, value]) => ({ label: key.replaceAll('_', ' '), value: String(value) }))} /></Card>}
        <Card className="mt-5 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Recorded account activity</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Registration review decisions and organization changes for this account. Sign-ins and student profile edits are not included. Times are shown in Asia/Manila.</p>
            <div className="mt-5">{activity.data.length ? <ol className="divide-y divide-civic-panel-strong">{activity.data.map(item => <li key={item.id} className="py-4 first:pt-0"><div className="flex flex-wrap justify-between gap-2"><h3 className="text-sm font-semibold text-civic-navy">{item.action}</h3><time dateTime={item.occurred_at} className="text-xs leading-5 text-civic-muted">{date(item.occurred_at)}</time></div><p className="mt-2 break-words text-sm leading-6 text-civic-muted">{item.actor}</p></li>)}</ol> : <EmptyState title="No recorded account activity" description="Recorded organization changes and administrator review decisions will appear here. Registration details are shown above." />}</div>
            <Pagination page={activity} label="Account activity pages" />
        </Card>
    </AdminLayout>;
}
