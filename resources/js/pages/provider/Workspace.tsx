import { Link } from '@inertiajs/react';
import { ArrowRight, GraduationCap, UsersRound, MessagesSquare, CalendarClock, Award, ChartNoAxesCombined, Building2 } from 'lucide-react';
import ProviderLayout from '../../layouts/ProviderLayout';
import { focusRing } from '../../components/ui/DesignSystem';
const tools = [
    { title: 'My scholarships', description: 'Create drafts, define requirements, and manage publication.', href: '/provider/scholarships', icon: GraduationCap, label: 'Programs' },
    { title: 'Applicants', description: 'Review submitted profiles and documents, then record a decision.', href: '/provider/applications', icon: UsersRound, label: 'Applications' },
    { title: 'Applicant counts', description: 'Review count breakdowns and report external applications.', href: '/provider/applicant-counts', icon: ChartNoAxesCombined, label: 'Reporting' },
    { title: 'Messages', description: 'Continue private conversations with your student applicants.', href: '/messages', icon: MessagesSquare, label: 'Communication' },
    { title: 'Renewals', description: 'Open renewal requests and review submitted requirements.', href: '/renewals', icon: CalendarClock, label: 'Scholar support' },
    { title: 'Awards and benefits', description: 'Confirm awards and record benefit-release history.', href: '/benefits', icon: Award, label: 'Scholar support' },
];
export default function Workspace() {
    return <ProviderLayout title="Provider workspace" description="Choose a tool to manage programs, review applications, or support your scholars.">
        <div className="mb-6 rounded-xl border border-neutral-200 bg-civic-panel px-5 py-4"><p className="text-sm font-semibold text-civic-navy">Your organization is approved</p><p className="mt-1 text-xs leading-5 text-civic-muted">You can use the provider tools below. Start in My scholarships to prepare a new program.</p></div>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{tools.map(item => <Link key={item.href} href={item.href} className={'provider-tool group flex min-w-0 flex-col rounded-lg border border-neutral-200 bg-white p-5 transition-colors hover:border-civic-slate motion-reduce:transition-none ' + focusRing}><div className="flex items-center justify-between"><span className="flex size-10 items-center justify-center rounded-xl bg-slate-50 text-civic-teal"><item.icon aria-hidden="true" className="size-5" /></span><span className="text-[10px] font-semibold uppercase tracking-wider text-civic-muted">{item.label}</span></div><h2 className="mt-5 text-base font-bold text-civic-navy">{item.title}</h2><p className="mt-2 flex-1 text-sm leading-6 text-civic-muted">{item.description}</p><span className="mt-5 flex items-center gap-2 text-xs font-semibold text-civic-teal">Open {item.title.toLowerCase()}<ArrowRight aria-hidden="true" className="size-4 transition-transform group-hover:translate-x-1 motion-reduce:transform-none" /></span></Link>)}</div>
        <Link href="/provider/profile" className={'mt-6 flex flex-wrap items-center gap-3 rounded-xl border border-civic-panel-strong bg-white p-5 ' + focusRing}><Building2 aria-hidden="true" className="size-5 shrink-0 text-civic-teal" /><div className="min-w-0 flex-1"><h2 className="text-sm font-bold">Organization profile</h2><p className="mt-1 text-xs leading-5 text-civic-muted">Review your organization details and verification status.</p></div><ArrowRight aria-hidden="true" className="size-4 shrink-0 text-civic-muted" /></Link>
    </ProviderLayout>;
}
