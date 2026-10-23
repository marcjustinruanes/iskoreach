import { Link } from '@inertiajs/react';
import { ArrowRight, GraduationCap, UsersRound, MessagesSquare, CalendarClock, Award, ChartNoAxesCombined, Building2 } from 'lucide-react';
import ProviderLayout from '../../layouts/ProviderLayout';
import { buttonStyles, focusRing } from '../../components/ui/DesignSystem';
const tools = [
    { title: 'My scholarships', description: 'Create drafts, define requirements, and manage publication.', href: '/provider/scholarships', icon: GraduationCap, label: 'Programs' },
    { title: 'Applicants', description: 'Review submitted profiles and documents, then record a decision.', href: '/provider/applications', icon: UsersRound, label: 'Applications' },
    { title: 'Applicant counts', description: 'Review count breakdowns and report external applications.', href: '/provider/applicant-counts', icon: ChartNoAxesCombined, label: 'Reporting' },
    { title: 'Messages', description: 'Continue private conversations with your student applicants.', href: '/messages', icon: MessagesSquare, label: 'Communication' },
    { title: 'Renewals', description: 'Open renewal requests and review submitted requirements.', href: '/renewals', icon: CalendarClock, label: 'Scholar support' },
    { title: 'Grants and benefits', description: 'Confirm grants and record benefit-release history.', href: '/benefits', icon: Award, label: 'Scholar support' },
];
export default function Workspace() {
    return <ProviderLayout title="Provider workspace" actions={<Link href="/provider/scholarships/create" className={buttonStyles('primary')}>Create scholarship<ArrowRight aria-hidden="true" className="size-4" /></Link>}>
        <div className="grid gap-6 lg:grid-cols-2">{[{title:'Run your programs',description:'Publication, applications and accurate applicant totals.',items:tools.slice(0,3)},{title:'Support your scholars',description:'Communication and continuing scholarship support.',items:tools.slice(3)}].map(group=><section key={group.title} className="min-w-0 overflow-hidden rounded-xl border border-neutral-200 bg-white"><div className="border-b border-neutral-200 px-5 py-4"><h2 className="text-base font-bold">{group.title}</h2><p className="mt-1 text-xs leading-5 text-civic-muted">{group.description}</p></div><ul className="divide-y divide-neutral-200">{group.items.map(item=><li key={item.href}><Link href={item.href} className={'group flex min-w-0 items-start gap-4 p-5 transition-colors hover:bg-civic-canvas '+focusRing}><span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-civic-panel"><item.icon aria-hidden="true" className="size-5" /></span><div className="min-w-0 flex-1"><h3 className="text-sm font-semibold">{item.title}</h3><p className="mt-1 text-xs leading-5 text-civic-muted">{item.description}</p></div><ArrowRight aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-muted" /></Link></li>)}</ul></section>)}</div>
        <Link href="/provider/profile" className={'mt-6 flex flex-wrap items-center gap-3 rounded-lg border-l-2 border-civic-gold bg-civic-panel p-5 '+focusRing}><Building2 aria-hidden="true" className="size-5 shrink-0" /><div className="min-w-0 flex-1"><h2 className="text-sm font-bold">Organization profile</h2><p className="mt-1 text-xs leading-5 text-civic-muted">Keep contact details and verification information current.</p></div><ArrowRight aria-hidden="true" className="size-4 shrink-0" /></Link>
    </ProviderLayout>;
}
