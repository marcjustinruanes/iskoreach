import { Link } from '@inertiajs/react';
import { ArrowRight, Building2, CheckCircle2, Clock3, GraduationCap, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import type { AdminDashboardProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Badge, Card, EmptyState, StatusBadge, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { ScholarshipStatusBadge } from '../../components/ScholarshipDisplay';
import { Table } from '../../components/ui/DataDisplay';

const date = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'Asia/Manila' }).format(new Date(value));
const dateTime = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));
const number = (value: number) => value.toLocaleString('en-PH');

export default function Dashboard({ summary, recentProviders, recentActivity, updatedAt, adminNavigation, recentScholarships }: AdminDashboardProps) {
    if (!adminNavigation) return null;
    const metrics = [
        { label: 'Scholarship records', value: summary.scholarships, detail: 'View current and retained records', href: adminNavigation.scholarships, icon: GraduationCap },
        { label: 'Published scholarships', value: summary.published_scholarships, detail: 'View current published records', href: adminNavigation.scholarships + '?status=published&records=current', icon: CheckCircle2 },
        { label: 'Registered students', value: summary.students, detail: 'View student accounts', href: adminNavigation.users + '?role=student', icon: GraduationCap },
        { label: 'Scholarship providers', value: summary.providers, detail: 'Open provider accounts', href: adminNavigation.providers, icon: Building2 },
        { label: 'Awaiting verification', value: summary.pending, detail: 'Open provider queue', href: adminNavigation.providers, icon: Clock3 },
        { label: 'Approved providers', value: summary.approved, detail: 'View verification summary', href: '#verification-summary', icon: CheckCircle2 },
    ];
    return <AdminLayout title="Dashboard" description="Monitor account registrations, provider access, and recent administrative work.">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 text-xs leading-5 text-civic-muted"><span className="inline-flex items-center gap-2"><span aria-hidden="true" className="size-2 rounded-full bg-civic-teal" />Account records</span><span>Snapshot: <time dateTime={updatedAt}>{dateTime(updatedAt)}</time> · Asia/Manila</span></div>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{metrics.map(item => <a key={item.label} href={item.href} className={'group rounded-xl border border-civic-panel-strong bg-white p-5 shadow-civic-card hover:border-civic-teal ' + focusRing}><div className="flex items-start justify-between gap-3"><span className="text-sm font-semibold text-civic-muted">{item.label}</span><item.icon aria-hidden="true" className="size-5 shrink-0 text-civic-teal" /></div><p className="mt-4 text-3xl font-bold leading-10 tabular-nums text-civic-navy">{number(item.value)}</p><span className="mt-4 flex items-center gap-2 text-xs font-semibold text-civic-teal">{item.detail}<ArrowRight aria-hidden="true" className="size-4" /></span></a>)}</div>
        <section aria-labelledby="pending-title" className="my-6 flex flex-wrap items-center justify-between gap-5 rounded-xl border border-civic-panel-strong bg-civic-panel p-5 sm:p-6"><div className="flex items-start gap-3"><Clock3 aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-civic-teal" /><div><h2 id="pending-title" className="text-base font-bold text-civic-navy">{summary.pending ? 'Provider reviews need attention' : 'Provider queue is up to date'}</h2><p className="mt-1 text-sm leading-6 text-civic-muted">{summary.pending ? number(summary.pending) + (summary.pending === 1 ? ' provider is waiting for an administrator decision.' : ' providers are waiting for an administrator decision.') : 'No provider accounts currently have pending verification.'}</p></div></div><Link href={adminNavigation.providers} className={buttonStyles('secondary')}>Review provider accounts<ArrowRight aria-hidden="true" className="size-4" /></Link></section>
        {summary.missing_provider_profiles > 0 && <p role="status" className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">{number(summary.missing_provider_profiles)} provider accounts have no organization profile. They are included in the provider total, but cannot appear in the verification queue until their profiles are restored.</p>}
        <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
            <DashboardSection title="Recent provider registrations" description="The five most recently registered organization profiles." action={<Link href={adminNavigation.providers} className={'inline-flex min-h-11 items-center gap-2 rounded text-sm font-semibold text-civic-teal ' + focusRing}>All providers<ArrowRight aria-hidden="true" className="size-4" /></Link>}>
                {recentProviders.length ? <Table rows={recentProviders} rowKey={provider => provider.id} caption="Recent provider registrations" columns={[
                    { key: 'organization', label: 'Organization', render: provider => <span className="break-words font-semibold text-civic-navy">{provider.organization_name || 'Organization details incomplete'}</span> },
                    { key: 'status', label: 'Status', render: provider => <StatusBadge status={provider.verification_status} /> },
                    { key: 'created', label: 'Registered', render: provider => <time className="whitespace-nowrap text-civic-muted" dateTime={provider.created_at}>{date(provider.created_at)}</time> },
                ]} /> : <EmptyState title="No provider registrations yet" description="Organization registrations will appear here once providers create accounts." />}
            </DashboardSection>
            <DashboardSection title="Recent administrative activity" description="The six most recent actions performed by administrators." action={<Link href={adminNavigation.activity} className={buttonStyles('outline')}>View activity logs</Link>}>
                {recentActivity.length ? <ol className="divide-y divide-civic-panel-strong">{recentActivity.map(item => <li key={item.id} className="py-4 first:pt-0 last:pb-0"><p className="text-sm font-semibold text-civic-navy">{item.action}</p><p className="mt-1 break-words text-sm leading-6 text-civic-muted">{item.actor} · {item.reference}</p><time className="mt-1 block text-xs leading-5 text-civic-muted" dateTime={item.occurred_at}>{dateTime(item.occurred_at)}</time></li>)}</ol> : <EmptyState title="No administrative activity yet" description="Recorded administrator actions will appear here. Private record contents are excluded." />}
            </DashboardSection>
            <DashboardSection id="account-summary" title="Account registrations" description="Registered accounts by role; the recent period covers the last 30 days.">
                <Table caption="Account registration summary" rows={[{ role: 'Students', total: summary.students, recent: summary.students_last_30_days }, { role: 'Providers', total: summary.providers, recent: summary.providers_last_30_days }]} rowKey={row => row.role} columns={[
                    { key: 'role', label: 'Account type', render: row => row.role },
                    { key: 'total', label: 'Total', render: row => <span className="font-semibold tabular-nums">{number(row.total)}</span> },
                    { key: 'recent', label: 'Last 30 days', render: row => <span className="tabular-nums">{number(row.recent)}</span> },
                ]} /><p className="mt-4 text-xs leading-5 text-civic-muted">Aggregate counts only. Individual student records are not exposed here.</p>
            </DashboardSection>
            <DashboardSection id="verification-summary" title="Provider verification" description="Current access decisions for registered organization profiles.">
                <dl className="space-y-3">{(['pending', 'approved', 'rejected'] as const).map(status => <div key={status} className="flex items-center justify-between gap-3 rounded-lg bg-civic-canvas px-4 py-3"><dt><StatusBadge status={status} /></dt><dd className="font-bold tabular-nums text-civic-navy">{number(summary[status])}</dd></div>)}</dl>
            </DashboardSection>
        </div>
        <div className="mt-6"><DashboardSection title="Recently updated scholarships" description="The five most recently updated records, including retained history." action={<Link href={adminNavigation.scholarships} className={buttonStyles('outline')}>All scholarships</Link>}>
            {recentScholarships.length ? <Table rows={recentScholarships} rowKey={item => item.id} caption="Recently updated scholarship records" columns={[
                { key: 'title', label: 'Scholarship', render: item => <Link href={item.show_url} className={'break-words rounded font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>{item.title}</Link> },
                { key: 'provider', label: 'Provider', render: item => item.provider },
                { key: 'status', label: 'Status', render: item => <div className="flex flex-wrap gap-2"><ScholarshipStatusBadge status={item.status} />{item.deleted_at && <Badge>Retained record</Badge>}</div> },
            ]} /> : <EmptyState title="No scholarship records yet" description="Stored scholarship records will appear here. Approved providers can create scholarship drafts in their workspace." />}
        </DashboardSection></div>
        <section aria-labelledby="operations-title" className="mt-8 rounded-xl border border-civic-panel-strong bg-white p-5 sm:p-6"><div className="flex items-start gap-3"><Info aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-civic-muted" /><div><h2 id="operations-title" className="text-lg font-bold text-civic-navy">Scholarship operations</h2><p className="mt-2 max-w-3xl text-sm leading-6 text-civic-muted">Totals reflect recorded applications, confirmed awards, and provider tracking entries.</p></div></div><div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[
            { label: 'Submitted applications', value: summary.applications, href: adminNavigation.counts },
            { label: 'Currently active awards', value: summary.active_awards, href: adminNavigation.awards + '?activity=active' },
            { label: 'Renewals awaiting review', value: summary.pending_renewals, href: adminNavigation.renewals + '?status=submitted' },
            { label: 'Pending / processing releases', value: summary.pending_releases, href: adminNavigation.releases },
        ].map(item => <Link key={item.label} href={item.href} className={'rounded-lg bg-civic-canvas p-4 hover:bg-civic-panel ' + focusRing}><span className="block text-sm leading-6 text-civic-muted">{item.label}</span><span className="mt-2 block text-2xl font-bold tabular-nums text-civic-navy">{number(item.value)}</span></Link>)}</div></section>
    </AdminLayout>;
}

function DashboardSection({ id, title, description, action, children }: { id?: string; title: string; description: string; action?: ReactNode; children: ReactNode }) {
    return <section id={id} className="min-w-0 scroll-mt-28"><Card className="h-full rounded-xl p-5 sm:p-6"><div className="mb-5 flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-lg font-bold leading-7 text-civic-navy">{title}</h2><p className="mt-1 text-sm leading-6 text-civic-muted">{description}</p></div>{action}</div>{children}</Card></section>;
}
