import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ArrowRight, Search, ClipboardCheck, BellRing, MapPin, FileCheck2, TrendingUp, CalendarClock, ShieldCheck, Sun, Landmark, GraduationCap, School, Building2, FileText, UsersRound, RefreshCw, MessageCircle } from 'lucide-react';
import Brand from './Brand';
import { buttonStyles, focusRing } from './ui/DesignSystem';

export const landingContainer = 'mx-auto w-full max-w-[1440px] px-4 sm:px-6 lg:px-12';
export const landingTitle = 'text-civic-heading-mobile font-bold tracking-tight text-civic-navy md:text-civic-heading';

export function LandingHero({ stats, children }: { stats: { programs: number; open: number; passers: number }; children: ReactNode }) {
    return <section className="landing-hero" aria-labelledby="hero-title">
        <div className={landingContainer + ' landing-hero-grid'}>
            <div className="landing-hero-copy">
                <p className="landing-eyebrow"><Sun aria-hidden="true" className="size-4" />Scholarships for a brighter Philippines</p>
                <h1 id="hero-title" className="max-w-xl text-[32px] font-extrabold leading-10 tracking-tight text-civic-navy sm:text-civic-display sm:leading-[1.08]">Find scholarships.<br />Build your <span className="landing-highlight">future.</span></h1>
                <p className="mt-5 max-w-lg text-base leading-7 text-civic-muted sm:text-lg">Explore opportunities across the Philippines and keep your scholarship applications in one place.</p>
                <div className="mt-7 flex flex-wrap items-center gap-x-5 gap-y-2"><Link href="/scholarships" className={buttonStyles('primary', 'lg')}>Explore Scholarships<ArrowRight aria-hidden="true" className="size-4" /></Link></div>
                <div className="landing-benefits" aria-label="IskoReach benefits">
                    <div><span><Search aria-hidden="true" /></span><p><strong>{stats.programs > 0 ? stats.programs.toLocaleString('en-PH') : 'More'}</strong><small>scholarship opportunities</small></p></div>
                    <div><span><Landmark aria-hidden="true" /></span><p><strong>Top providers</strong><small>schools, NGOs and more</small></p></div>
                    <div><span><ClipboardCheck aria-hidden="true" /></span><p><strong>All in one place</strong><small>search, save and apply</small></p></div>
                </div>
            </div>
            <div className="landing-hero-art" aria-hidden="true">
                <span className="landing-hero-sun" />
                <span className="landing-hero-dots" />
                <span className="landing-hero-rays">✦</span>
                <img src="/images/landing-students.png" alt="" className="landing-hero-students" fetchPriority="high" />
            </div>
            <div className="landing-hero-search">
                <div className="landing-search-panel rounded-2xl border border-civic-border/60 border-t-4 border-t-civic-gold bg-white p-5 shadow-civic-raised sm:p-7">
                    <h2 className="text-lg font-bold text-civic-navy">Start your search</h2>
                    <p className="mt-2 mb-5 text-sm leading-6 text-civic-muted">Search a program, provider, or location.</p>
                    {children}
                    <p className="landing-search-note mt-4 flex items-start gap-2 text-xs leading-5 text-civic-muted"><ShieldCheck aria-hidden="true" className="mt-0.5 size-4 shrink-0" />Explore first. Prepare your documents when you choose a program.</p>
                </div>
            </div>
        </div>
    </section>;
}

export function LandingTrust() {
    return <section className="landing-trust" aria-label="Who IskoReach serves">
        <div className={landingContainer + ' landing-trust-inner'}>
            <p className="landing-trust-intro">Trusted by students across<br className="hidden sm:block" /> the Philippines</p>
            <div><span><GraduationCap aria-hidden="true" /></span><p>Students</p></div>
            <div><span><School aria-hidden="true" /></span><p>Schools</p></div>
            <div><span><Building2 aria-hidden="true" /></span><p>Organizations</p></div>
            <div><span><FileText aria-hidden="true" /></span><p>Government Programs</p></div>
        </div>
    </section>;
}

export function LandingJourney() {
    return <section id="how-it-works" className="landing-journey scroll-mt-24" aria-labelledby="journey-title"><div className={landingContainer}>
        <div id="about" className="max-w-3xl scroll-mt-28"><p className="text-xs font-bold uppercase tracking-widest text-civic-muted">About IskoReach</p><h2 id="journey-title" className={landingTitle + ' mt-2'}>One place for your <span className="landing-highlight">scholarship journey.</span></h2><p id="student-title" className="mt-3 scroll-mt-28 text-sm leading-6 text-civic-muted sm:text-base">IskoReach connects students with scholarship programs and helps providers manage applications. Each provider sets its own requirements and makes its grant decisions.</p></div>
        <ol className="landing-journey-cards">{[
            ['Discover', 'Compare locations, deadlines, and eligibility before choosing a program.', Search, '/scholarships'],
            ['Prepare and apply', 'Follow the program checklist and submit the documents it requires.', ClipboardCheck, '/register'],
            ['Stay updated', 'Track applications, provider messages, and semester renewal requirements.', BellRing, '/login'],
        ].map(([title, description, Icon, href], index) => { const StepIcon = Icon as typeof Search; return <li key={String(title)}><Link href={String(href)} className="landing-journey-card"><div className="landing-journey-card-top"><span className="landing-journey-icon"><StepIcon aria-hidden="true" className="size-5" /></span><span className="landing-journey-number">0{index + 1}</span></div><div className="landing-journey-card-copy"><h3>{String(title)}</h3><p>{String(description)}</p></div><span aria-hidden="true" className="landing-journey-arrow"><ArrowRight className="size-5" /></span></Link></li>; })}</ol>
    </div></section>;
}

export function LandingStudentTools() {
    return <section className="landing-tools" aria-labelledby="student-tools-title"><div className={landingContainer}><div className="mb-5 max-w-2xl"><h2 id="student-tools-title" className={landingTitle}>Stay organized at every step.</h2><p className="mt-2 text-sm leading-6 text-civic-muted sm:text-base">Keep your opportunities, documents, and updates together in your student workspace.</p></div><div className="landing-tool-grid">{[
        { icon: MapPin, title: 'Explore any location', text: 'Search beyond your hometown for programs that fit your plans.' },
        { icon: FileCheck2, title: 'Prepare your requirements', text: 'Know which documents each scholarship needs before you submit.' },
        { icon: TrendingUp, title: 'Track each application', text: 'Follow separate statuses and provider decisions for every program.' },
        { icon: CalendarClock, title: 'Keep up with renewals', text: 'Stay on top of semester requirements and scholarship updates.' },
    ].map(({ icon: Icon, title, text }) => <div className="landing-tool" key={title}><span><Icon aria-hidden="true" /></span><div><h3>{title}</h3><p>{text}</p></div></div>)}</div></div></section>;
}

export function LandingProviders({ accountHref, onReview }: { accountHref: string; onReview: () => void }) {
    const features = [
        { icon: FileCheck2, label: 'Publish scholarships' },
        { icon: UsersRound, label: 'Review applications' },
        { icon: RefreshCw, label: 'Manage renewals' },
    ];
    return <section id="for-providers" className="landing-provider-section scroll-mt-24"><div className={landingContainer + ' landing-provider-container'}><div className="landing-provider-card">
        <span className="landing-provider-dots" aria-hidden="true" />
        <div className="landing-provider-copy"><h2 id="provider-title"><Sun aria-hidden="true" className="landing-provider-spark" />Make scholarship management<br /><span>more organized.</span></h2><p>Easily manage your programs, applicant records, and semester requirements with a provider account.</p>
            <ul className="landing-provider-features">{features.map(({ icon: Icon, label }) => <li key={label}><span><Icon aria-hidden="true" /></span>{label}</li>)}</ul>
        </div>
        <div className="landing-provider-actions"><Link href={accountHref} className={buttonStyles('secondary', 'lg') + ' landing-provider-cta'}>Register as a provider<ArrowRight aria-hidden="true" className="size-5" /></Link><button type="button" onClick={onReview} className={'landing-provider-review ' + focusRing}>How provider approval works<ArrowRight aria-hidden="true" className="size-5" /></button></div>
    </div></div></section>;
}

export function LandingFooter({ accountHref, providerHref, loginHref, hideAccount = false }: { hideAccount?: boolean; accountHref: string; providerHref: string; loginHref: string }) {
    const links: [string, string][] = [['Scholarships', '/scholarships'], ['Public passers', '/passers'], ['How it works', '/#how-it-works'], ...(hideAccount ? [] : accountHref === loginHref ? [['My account', accountHref] as [string, string]] : [['Student account', accountHref], ['Provider account', providerHref], ['Log in', loginHref]] as [string, string][]), ['Provider messaging', '/messages']];
    return <footer id="contact" className="landing-footer scroll-mt-24"><div className={landingContainer + ' landing-footer-container'}>
        <div className="landing-footer-main"><div className="landing-footer-brand"><Brand compact /><p>Discover scholarships. Keep your next steps clear.</p></div><nav aria-label="Footer navigation" className="landing-footer-links">{links.map(([label, href]) => href.includes('#') ? <a key={label} href={href} className={focusRing}>{label}</a> : <Link key={label} href={href} className={focusRing}>{label}</Link>)}</nav></div>
        <div className="landing-footer-meta"><p>Copyright {new Date().getFullYear()} IskoReach PH.</p><p className="landing-footer-message"><MessageCircle aria-hidden="true" />Scholarship questions? Sign in to message the provider.</p><div className="landing-footer-legal"><Link href="/terms" className={focusRing}>Terms of Use</Link><Link href="/privacy" className={focusRing}>Privacy Policy</Link></div></div>
    </div></footer>;
}
