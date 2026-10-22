import { Link } from '@inertiajs/react';
import { ArrowRight, GraduationCap, Globe2, FilePenLine, UsersRound, Plus, ChartNoAxesCombined } from 'lucide-react';
import type { ProviderProfile, SharedProps, VerificationNotice, ScholarshipStatus } from '../../types';
import ProviderLayout from '../../layouts/ProviderLayout';
import { ScholarshipStatusBadge, scholarshipDate } from '../../components/ScholarshipDisplay';
import VerificationNotices from '../../components/VerificationNotices';
import { Card, EmptyState, StatusBadge, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Table } from '../../components/ui/DataDisplay';
interface Props extends SharedProps {
    profile: ProviderProfile; verificationNotices: VerificationNotice[];
    summary: { scholarships: number; published: number; draft: number; closed: number; retained: number; submitted: number };
    recentScholarships: { id: number; title: string; status: ScholarshipStatus; updated_at: string; counts_url: string }[];
    applicationActivity: { scholarship_id: number; scholarship: string; status: 'submitted' | 'under_review' | 'accepted' | 'rejected' | 'waitlisted'; total: number; last_submitted_at: string }[];
}
export default function Dashboard({ summary, recentScholarships, applicationActivity, verificationNotices, providerContext }: Props) {
    if (!providerContext) return null;
    const metrics = [
        { label: 'Scholarship records', value: summary.scholarships, icon: GraduationCap, href: '/provider/scholarships', detail: 'Current programs' },
        { label: 'Published', value: summary.published, icon: Globe2, href: '/provider/scholarships?status=published', detail: 'Published listings' },
        { label: 'Drafts', value: summary.draft, icon: FilePenLine, href: '/provider/scholarships?status=draft', detail: 'Programs in preparation' },
        { label: 'Submitted applications', value: summary.submitted, icon: UsersRound, href: '/provider/applications', detail: 'Includes retained submissions' },
    ];
    return <ProviderLayout title="Your provider account" description="Manage your programs and support scholars from one place." actions={<Link href="/provider/scholarships/create" className={buttonStyles('primary')}><Plus aria-hidden="true" className="size-4" />Create scholarship</Link>}>
        <div className="mb-6 grid grid-cols-2 gap-3 xl:grid-cols-4">{metrics.map(item => <Link key={item.label} href={item.href} className={'provider-metric group min-w-0 rounded-lg border border-neutral-200 bg-white p-4 transition-colors sm:p-5 hover:border-civic-slate motion-reduce:transition-none ' + focusRing}><div className="flex items-center justify-between gap-2"><span className="flex size-9 items-center justify-center rounded-lg bg-slate-50 text-civic-teal"><item.icon className="size-[18px]" aria-hidden="true" /></span><ArrowRight aria-hidden="true" className="size-4 text-slate-400 group-hover:text-civic-teal" /></div><h2 className="mt-4 min-h-10 text-xs font-semibold leading-5 text-civic-muted">{item.label}</h2><p className="mt-1 text-[30px] font-bold leading-10 tabular-nums tracking-tight text-civic-navy">{item.value.toLocaleString('en-PH')}</p><p className="mt-1 text-[11px] leading-5 text-civic-muted">{item.detail}</p></Link>)}</div>
        <Card className="provider-welcome mb-6 border-teal-100 bg-teal-50/40 p-5 sm:p-6"><div className="flex flex-wrap items-center justify-between gap-5"><div className="max-w-2xl"><p className="text-[10px] font-bold uppercase tracking-[0.14em] text-civic-teal">{summary.scholarships === 0 ? 'Get started' : 'Your workspace'}</p><h2 className="mt-2 text-lg font-bold text-civic-navy">{summary.scholarships === 0 ? 'Create your first scholarship' : 'Keep your scholarship programs moving'}</h2><p className="mt-2 text-sm leading-6 text-civic-muted">{summary.scholarships === 0 ? 'Start with a draft, add eligibility and requirements, then publish when your program is ready.' : 'Review submissions, prepare program updates, and keep applicant totals accurate.'}</p></div><Link href={summary.scholarships === 0 ? '/provider/scholarships/create' : '/provider/workspace'} className={buttonStyles('outline')}>{summary.scholarships === 0 ? 'Start a draft' : 'Open provider workspace'}<ArrowRight aria-hidden="true" className="size-4" /></Link></div></Card>
        <div className="mb-6 grid gap-3 md:grid-cols-3">{[
            { title: 'Review applicants', description: 'Open submitted applications and record decisions.', href: '/provider/applications', icon: UsersRound },
            { title: 'Manage programs', description: 'Edit drafts and manage your published listings.', href: '/provider/scholarships', icon: GraduationCap },
            { title: 'Check applicant totals', description: 'Report external counts for verification.', href: providerContext.counts, icon: ChartNoAxesCombined },
        ].map(item => <Link key={item.href} href={item.href} className={'group flex min-w-0 items-start gap-3 rounded-lg border border-neutral-200 bg-white p-4 transition-colors hover:border-civic-slate motion-reduce:transition-none ' + focusRing}><item.icon aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-civic-teal" /><div><h2 className="text-sm font-semibold text-civic-navy">{item.title}</h2><p className="mt-1 text-xs leading-5 text-civic-muted">{item.description}</p></div><ArrowRight aria-hidden="true" className="ml-auto mt-1 size-4 shrink-0 text-slate-400" /></Link>)}</div>
        <Card className="mb-6 min-w-0 p-5 sm:p-6"><div className="mb-5 flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-base font-bold text-civic-navy">Recently updated scholarships</h2><p className="mt-1 text-xs leading-5 text-civic-muted">Your five latest current records. {summary.closed} closed · {summary.retained} retained.</p></div><Link href="/provider/scholarships" className={'inline-flex min-h-11 items-center gap-2 rounded text-xs font-semibold text-civic-teal ' + focusRing}>View all programs<ArrowRight aria-hidden="true" className="size-4" /></Link></div>{recentScholarships.length ? <Table caption="Your recent scholarship records" rows={recentScholarships} rowKey={item => item.id} columns={[
            { key: 'title', label: 'Scholarship', render: item => <Link href={'/provider/scholarships/' + item.id + '/edit'} className={'rounded font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>{item.title}</Link> },
            { key: 'status', label: 'Status', render: item => <ScholarshipStatusBadge status={item.status} /> },
            { key: 'updated', label: 'Updated', render: item => scholarshipDate(item.updated_at) },
            { key: 'counts', label: 'Applicant totals', render: item => <Link href={item.counts_url} className={'inline-flex min-h-11 items-center rounded text-xs font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>View count breakdown</Link> },
        ]} /> : <EmptyState title="No scholarship records yet" description="Create your first draft to start building your scholarship program." />}</Card>
        <Card className="min-w-0 p-5 sm:p-6"><div className="mb-5 flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-base font-bold text-civic-navy">Recorded application activity</h2><p className="mt-1 max-w-2xl text-xs leading-5 text-civic-muted">Submitted records, including retained and waitlisted applications. Student drafts are excluded.</p></div><Link href="/provider/applications" className={'inline-flex min-h-11 items-center gap-2 rounded text-xs font-semibold text-civic-teal ' + focusRing}>Open applicants<ArrowRight aria-hidden="true" className="size-4" /></Link></div>{applicationActivity.length ? <Table caption="Your recorded application activity" rows={applicationActivity} rowKey={item => item.scholarship_id + ':' + item.status} columns={[
            { key: 'scholarship', label: 'Scholarship', render: item => item.scholarship },
            { key: 'status', label: 'Recorded status', render: item => <StatusBadge status={item.status} /> },
            { key: 'total', label: 'Submitted records', render: item => item.total.toLocaleString('en-PH') },
            { key: 'date', label: 'Latest submission', render: item => scholarshipDate(item.last_submitted_at) },
        ]} /> : <EmptyState title="No submitted application records" description="Submitted applications for your scholarships will appear here when students apply." />}</Card>
        <VerificationNotices notices={verificationNotices} />
    </ProviderLayout>;
}
