import { Link } from '@inertiajs/react';
import Brand from './Brand';
import { useEffect, useRef } from 'react';
import { ArrowRight, BellRing, BookOpen, Building2, CalendarClock, CheckCircle2, ClipboardCheck, FileCheck2, GraduationCap, Landmark, Map, Search, ShieldCheck, Sparkles, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Badge, Card, buttonStyles, focusRing } from './ui/DesignSystem';

export const landingContainer = 'mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-12';
export const landingTitle = 'text-civic-heading-mobile font-bold tracking-tight text-civic-navy md:text-civic-heading';
const eyebrow = 'mb-3 block text-xs font-bold uppercase leading-5 tracking-widest text-civic-teal';

export function useLandingMotion() {
    const mainRef = useRef<HTMLElement>(null);

    useEffect(() => {
        const main = mainRef.current;
        if (!main || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const observer = new IntersectionObserver(entries => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                // Keep focused controls immediately visible during keyboard navigation.
                if (!entry.target.contains(document.activeElement)) {
                    entry.target.classList.add('motion-safe:animate-landing-reveal');
                }
                observer.unobserve(entry.target);
            }
        }, { threshold: 0.08, rootMargin: '0px 0px -32px 0px' });

        // Content stays visible before observation and when JavaScript motion is unavailable.
        main.querySelectorAll('[data-landing-reveal]').forEach(element => observer.observe(element));
        return () => observer.disconnect();
    }, []);

    return mainRef;
}

export function LandingMark() {
    return <Brand />;
}
function IconTile({ icon: Icon }: { icon: LucideIcon }) {
    return <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-civic-mint text-civic-teal"><Icon aria-hidden="true" className="size-6" /></span>;
}
export function LandingHero({ stats }: { stats: { programs: number; open: number; passers: number } }) {
    return <section className={landingContainer + ' relative grid gap-10 py-12 lg:grid-cols-2 lg:items-center lg:gap-14 lg:py-20'} aria-labelledby="hero-title">
        <div aria-hidden="true" className="pointer-events-none absolute top-0 left-0 size-64 rounded-full bg-civic-mint/25 blur-3xl" />
        <div className="relative motion-safe:animate-landing-reveal">
            <div className="mb-6 inline-flex items-center gap-2 rounded-full bg-civic-panel-strong px-3 py-2 text-xs font-bold uppercase leading-4 tracking-wide text-civic-navy"><span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-civic-teal" />Built for Filipino students</div>
            <h1 id="hero-title" className="max-w-xl text-civic-display-mobile font-extrabold text-civic-navy sm:text-civic-display">Your Next <span className="text-civic-teal">Opportunity</span> Starts Here.</h1>
            <p className="mt-5 max-w-xl text-civic-body text-civic-muted">Discover civic grants, understand eligibility, prepare requirements, and track multi-stage applications—all in one clear workspace.</p>
            <div className="mt-8 flex flex-wrap gap-3"><Link href="/scholarships" className={buttonStyles('secondary', 'lg')}>Explore Scholarships <ArrowRight aria-hidden="true" className="size-5" /></Link><a href="#how-it-works" className={buttonStyles('outline', 'lg')}><ClipboardCheck aria-hidden="true" className="size-5 text-civic-teal" />How It Works</a></div>
            <div className="mt-9 rounded-xl bg-civic-panel p-4"><p className="mb-3 text-xs font-medium text-civic-muted">Current public directory</p><div className="grid grid-cols-3 gap-2">{[[stats.programs.toLocaleString('en-PH'), 'Published programs'], [stats.open.toLocaleString('en-PH'), 'Open now'], [stats.passers.toLocaleString('en-PH'), 'Public passers']].map(([value, label]) => <div key={label}><span className="block text-xl font-extrabold tabular-nums text-civic-navy">{value}</span><span className="mt-1 block text-xs leading-4 text-civic-muted">{label}</span></div>)}</div></div>
        </div>
        <figure className="relative mx-auto flex w-full max-w-xl flex-col gap-4 motion-safe:animate-landing-reveal motion-safe:[animation-delay:140ms] lg:min-h-[480px] lg:justify-center lg:pb-10 lg:pl-4" aria-label="Your scholarship journey">
            <div className="relative z-10 w-full rounded-xl bg-white p-5 shadow-civic-overlay sm:p-7"><div className="mb-6 flex items-center gap-3"><IconTile icon={GraduationCap} /><div><p className="font-bold text-civic-navy">Your next step, made clearer</p><p className="mt-1 text-xs leading-5 text-civic-muted">From discovery to your provider’s decision</p></div></div><ol className="space-y-4">{[['Explore programs', 'Choose any location and compare opening dates, benefits, and recorded criteria.'], ['Prepare your requirements', 'Upload private files and attach the required documents to your application checklist.'], ['Review and submit', 'Confirm the information you share, then track provider decisions in your account.']].map(([title, text], index) => <li key={title} className="flex gap-3 rounded-xl bg-civic-panel p-4"><span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-civic-navy text-xs font-bold text-white">{index + 1}</span><div><p className="text-sm font-semibold text-civic-navy">{title}</p><p className="mt-1 text-xs leading-5 text-civic-muted">{text}</p></div></li>)}</ol></div>
            <div className="relative z-20 flex items-center gap-3 self-end rounded-xl bg-civic-navy p-4 text-white shadow-civic-raised"><ShieldCheck aria-hidden="true" className="size-7 shrink-0 text-civic-mint" /><div><p className="text-xs font-semibold">Your files stay private</p><p className="mt-1 text-xs leading-5 text-blue-100">Shared through authorized application workflows</p></div></div>
            <figcaption className="text-center text-xs leading-5 text-civic-muted">Program eligibility and award decisions belong to the provider.</figcaption>
        </figure>
    </section>;
}
export function LandingJourney() {
    return <section data-landing-reveal id="how-it-works" className={landingContainer + ' scroll-mt-24 py-14 lg:py-20'} aria-labelledby="journey-title">
        <div className="mx-auto mb-10 max-w-2xl text-center"><span className={eyebrow}>Simplicity first</span><h2 id="journey-title" className={landingTitle}>Your Scholarship Journey, Made Simpler.</h2><p className="mt-3 text-civic-small text-civic-muted">Find an opportunity, prepare your requirements, and follow your application in one place.</p></div>
        <div className="grid gap-6 md:grid-cols-3">{[
            { title: 'Discover Opportunities', icon: Search, text: 'Browse by location, application dates, and scholarship type, then review the provider’s eligibility criteria.', tag: 'Location and application-date filters' },
            { title: 'Prepare & Apply', icon: ClipboardCheck, text: 'Use scholarship-specific requirement checklists with instructions for the documents you need to prepare.', tag: 'Document requirements checklist' },
            { title: 'Track & Stay Updated', icon: BellRing, text: 'Follow your application stages, review updates, and semester renewal deadlines in one workspace.', tag: 'Application and renewal updates' },
        ].map((item, index) => <Card key={item.title} className="rounded-2xl border-0 p-7"><div className="mb-6 flex items-center justify-between"><IconTile icon={item.icon} /><span className="text-3xl font-extrabold tabular-nums text-civic-panel-strong" aria-hidden="true">0{index + 1}</span></div><h3 className="text-lg font-bold text-civic-navy">{item.title}</h3><p className="mt-3 min-h-24 text-sm leading-6 text-civic-muted">{item.text}</p><div className="mt-5 flex items-start gap-2 rounded-lg bg-civic-panel p-3 text-xs font-medium leading-5"><CheckCircle2 aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-civic-teal" />{item.tag}</div></Card>)}</div>
    </section>;
}
export function LandingStudentTools() {
    return <section className="bg-civic-panel py-14 lg:py-20" aria-labelledby="student-title"><div data-landing-reveal className={landingContainer}>
        <div className="mb-9 flex flex-col justify-between gap-4 md:flex-row md:items-end"><div><span className={eyebrow}>Student focus</span><h2 id="student-title" className={landingTitle}>Stay Organized at Every Step.</h2></div><p className="max-w-md text-sm leading-6 text-civic-muted">Keep your files, application updates, and renewal checklists together in your student workspace.</p></div>
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">{[
            { icon: Map, title: 'Discover More Opportunities', text: 'Find local, provincial, and private grants. Choose any location you want to explore.', tag: 'Browse by location' },
            { icon: FileCheck2, title: 'Know What to Prepare', text: 'Keep a clear list of the documents required by each scholarship provider.', tag: 'Requirements checklist' },
            { icon: TrendingUp, title: 'Track Your Applications', text: 'Follow each application from document preparation to the provider’s decision.', tag: 'Application status history' },
            { icon: CalendarClock, title: 'Keep Up With Renewals', text: 'Keep semester requirements and renewal deadlines together with your active scholarships.', tag: 'Renewal reminders' },
        ].map(item => <Card key={item.title} className="flex flex-col rounded-2xl border-0"><IconTile icon={item.icon} /><h3 className="mt-5 text-lg font-bold leading-6 text-civic-navy">{item.title}</h3><p className="mt-3 text-sm leading-6 text-civic-muted">{item.text}</p><p className="mt-auto flex items-center gap-2 pt-6 text-xs font-semibold text-civic-teal">{item.tag}<ArrowRight aria-hidden="true" className="size-4" /></p></Card>)}</div>
    </div></section>;
}
export function LandingProviders({ accountHref, onReview }: { accountHref: string; onReview: () => void }) {
    return <section data-landing-reveal id="for-providers" className={landingContainer + ' scroll-mt-24 py-14 lg:py-20'} aria-labelledby="provider-title">
        <div className="relative grid gap-10 overflow-hidden rounded-3xl bg-linear-to-br from-civic-navy via-civic-navy-soft to-[#002a44] px-6 py-10 text-white shadow-civic-raised sm:p-10 lg:grid-cols-[1.2fr_0.8fr] lg:items-center lg:p-14">
            <div><span className="mb-5 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-civic-mint"><Landmark aria-hidden="true" className="size-4" />LGUs, foundations & universities</span><h2 id="provider-title" className="text-civic-heading-mobile font-bold tracking-tight md:text-civic-heading">Make Scholarship Management More Organized.</h2><p className="mt-4 max-w-xl text-base leading-7 text-blue-100">Bring scholarship criteria, applicant records, and semester requirements into one organized workspace.</p>
                <ul className="mt-7 grid gap-4 text-sm leading-6 text-blue-100 sm:grid-cols-2">{['Student profiles and application records', 'Provider-defined eligibility rules', 'Semester renewal tracking', 'Administrator approval for providers'].map(text => <li key={text} className="flex gap-2"><CheckCircle2 aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-mint" />{text}</li>)}</ul>
                <div className="mt-8 flex flex-wrap gap-3"><Link href={accountHref} className={buttonStyles('secondary')}>Partner as a Provider <GraduationCap aria-hidden="true" className="size-4" /></Link><button type="button" onClick={onReview} className={'min-h-11 rounded-lg px-3 py-2 text-sm font-semibold text-blue-100 ' + focusRing}>View Approval Process <ArrowRight aria-hidden="true" className="ml-1 inline size-4" /></button></div>
            </div>
            <div className="mx-auto w-full max-w-sm rounded-2xl bg-white p-6 text-civic-ink shadow-civic-overlay"><p className="text-xs font-bold uppercase tracking-widest text-civic-teal">Your provider workspace</p><h3 className="mt-3 text-xl font-bold text-civic-navy">Support every stage</h3><ul className="mt-6 space-y-4">{['Publish scholarship details and requirements', 'Review submitted applications', 'Request renewals for eligible awards', 'Record benefits and release history'].map(text => <li key={text} className="flex items-start gap-3 rounded-lg bg-civic-panel p-3 text-sm leading-6"><CheckCircle2 aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-teal" />{text}</li>)}</ul><p className="mt-5 text-xs leading-5 text-civic-muted">Provider approval is required. Benefit records track releases; IskoReach does not transfer money.</p></div>
        </div>
    </section>;
}
export function LandingCallToAction({ accountHref, signedIn }: { accountHref: string; signedIn: boolean }) {
    return <section data-landing-reveal id="about" className={landingContainer + ' scroll-mt-24 py-12 text-center lg:py-20'} aria-labelledby="cta-title">
        <span className="mb-5 inline-flex items-center gap-2 rounded-full bg-civic-mint px-3 py-2 text-xs font-bold uppercase tracking-wide text-civic-teal"><GraduationCap aria-hidden="true" className="size-4" />Iskolar ng bayan para sa bayan</span>
        <h2 id="cta-title" className="mx-auto max-w-4xl text-civic-display-mobile font-extrabold text-civic-navy md:text-civic-display">Take the Next Step Toward Your Education.</h2><p className="mx-auto mt-5 max-w-2xl text-base leading-7 text-civic-muted">Explore published programs, prepare your student profile, or register your scholarship organization to get started.</p>
        <div className="mt-8 flex flex-wrap justify-center gap-3"><Link href="/scholarships" className={buttonStyles('secondary', 'lg')}>Explore Scholarships <Search aria-hidden="true" className="size-4" /></Link><Link href={accountHref} className={buttonStyles('primary', 'lg')}>{signedIn ? 'Open My Account' : 'Create Account'}<Sparkles aria-hidden="true" className="size-4" /></Link></div>
        <div className="mt-7 flex flex-wrap justify-center gap-5 text-xs leading-5 text-civic-muted"><span className="flex items-center gap-1.5"><CheckCircle2 aria-hidden="true" className="size-4 text-civic-teal" />Student registration: no admin approval</span><span className="flex items-center gap-1.5"><ShieldCheck aria-hidden="true" className="size-4 text-civic-teal" />Providers: administrator approval required</span></div>
    </section>;
}
export function LandingFooter({ accountHref, providerHref, loginHref }: { accountHref: string; providerHref: string; loginHref: string }) {
    return <footer className="mt-14 bg-civic-panel pt-12 pb-7"><div className={landingContainer}>
        <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr]">
            <div><LandingMark /><p className="mt-5 max-w-sm text-sm leading-6 text-civic-muted">A community-based scholarship discovery, application, and monitoring platform for Filipino learners.</p><div className="mt-4 flex flex-wrap gap-2"><span className="rounded-full bg-civic-panel-strong px-3 py-1.5 text-xs font-semibold"><BookOpen aria-hidden="true" className="mr-1 inline size-3" />SDG 4 · Quality Education</span><span className="rounded-full bg-civic-panel-strong px-3 py-1.5 text-xs font-semibold">SDG 10 · Reduced Inequalities</span></div></div>
            <FooterLinks title="Explore" links={[["Find scholarships", '/scholarships'], ["Public passers", '/passers'], ['How it works', '/#how-it-works'], ['For students', '/#student-title']]} />
            <FooterLinks title="For Providers" links={[["Partner with us", '/#for-providers'], ['Register organization', providerHref], ['Account access', loginHref]]} />
            <FooterLinks title="IskoReach" links={[["About the platform", '/#about'], ['Create an account', accountHref], ['Log in', loginHref]]} />
        </div><div className="mt-10 flex flex-col justify-between gap-4 border-t border-civic-border/50 pt-6 text-xs leading-6 text-civic-muted md:flex-row"><p className="max-w-2xl">Review each program’s recorded requirements and deadlines. Provider decisions determine eligibility and awards.</p><p className="shrink-0">© {new Date().getFullYear()} IskoReach PH.</p></div>
    </div></footer>;
}
function FooterLinks({ title, links }: { title: string; links: [string, string][] }) {
    return <div><h2 className="mb-4 text-sm font-bold text-civic-navy">{title}</h2><ul className="space-y-1">{links.map(([label, href]) => <li key={label}>{href.includes('#') ? <a href={href} className={'inline-flex min-h-11 items-center rounded text-xs leading-5 text-civic-muted hover:text-civic-teal ' + focusRing}>{label}</a> : <Link href={href} className={'inline-flex min-h-11 items-center rounded text-xs leading-5 text-civic-muted hover:text-civic-teal ' + focusRing}>{label}</Link>}</li>)}</ul></div>;
}
