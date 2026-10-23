import LogoutButton from '../components/LogoutConfirmation';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ArrowRight, BookOpen, Building2, CircleHelp, ClipboardCheck, House, LayoutDashboard, Mail, Menu, UsersRound } from 'lucide-react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import { LandingFooter, landingContainer } from '../components/LandingSections';
import { buttonStyles, focusRing } from '../components/ui/DesignSystem';
import { Drawer } from '../components/ui/Overlays';
import fontUrl from '../../../public/fonts/plus-jakarta-sans-latin.woff2?url';

const guestNavigation: [string, string][] = [['Home', '/'], ['Scholarships', '/scholarships'], ['About', '/#about'], ['Contact', '/#contact']];
const navigationIcons = { Home: House, Scholarships: BookOpen, About: UsersRound, Contact: Mail, 'My applications': ClipboardCheck, Messages: Mail, 'My scholarships': BookOpen, Applicants: UsersRound, Dashboard: LayoutDashboard, Providers: Building2, Users: UsersRound, 'Account status': CircleHelp };
export const publicPageContainer = landingContainer + ' py-8 sm:py-10';

export function PublicPageHeading({ title, description, eyebrow }: { title: string; description?: string; eyebrow?: string }) {
    return <div data-page-header className="mb-6 max-w-3xl">{eyebrow && <p className="mb-3 text-xs font-bold uppercase tracking-widest text-civic-teal">{eyebrow}</p>}<h1 className="break-words text-2xl font-bold leading-8 tracking-tight text-civic-navy sm:text-3xl">{title}</h1>{description && <p className="mt-2 text-sm leading-6 text-civic-muted">{description}</p>}</div>;
}

export default function PublicLayout({ children, reviewMode = false }: { children: ReactNode; reviewMode?: boolean }) {
    const { props: { auth }, url } = usePage<SharedProps>();
    const path = url.split('?')[0].split('#')[0];
    const pendingProvider = auth.user?.role === 'provider' && auth.provider_status !== 'approved';
    const pendingStudent = auth.user?.role === 'student' && path === '/student/registration-status';
    const accountHref = pendingProvider ? '/provider/status' : pendingStudent ? '/student/registration-status' : auth.user ? '/dashboard' : '/register';
    const navigation: [string, string][] = !auth.user ? guestNavigation
        : pendingProvider || pendingStudent ? [...guestNavigation, ['Account status', accountHref]]
        : auth.user.role === 'student' ? [['Home', '/'], ['Scholarships', '/student/scholarships'], ['My applications', '/student/applications'], ['Messages', '/messages']]
        : auth.user.role === 'provider' ? [['Home', '/'], ['My scholarships', '/provider/scholarships'], ['Applicants', '/provider/applications'], ['Messages', '/messages']]
        : [['Home', '/'], ['Dashboard', '/admin/dashboard'], ['Providers', '/admin/providers'], ['Users', '/admin/users']];
    const [activeSection, setActiveSection] = useState('home');
    const [menuOpen, setMenuOpen] = useState(false);
    useEffect(() => {
        if (path !== '/') return;
        let frame = 0;
        const update = () => {
            frame = 0;
            const headerHeight = document.querySelector('header')?.getBoundingClientRect().height ?? 80;
            const threshold = headerHeight + Math.min(120, window.innerHeight * 0.2);
            let current = 'home';
            for (const id of ['discover', 'about', 'contact']) {
                const section = document.getElementById(id);
                if (section && section.getBoundingClientRect().top <= threshold) current = id;
            }
            if (window.scrollY > 0 && window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2) current = 'contact';
            setActiveSection(current);
        };
        const schedule = () => { if (!frame) frame = window.requestAnimationFrame(update); };
        schedule();
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        window.addEventListener('hashchange', schedule);
        const observer = new ResizeObserver(schedule);
        observer.observe(document.body);
        return () => {
            window.cancelAnimationFrame(frame);
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            window.removeEventListener('hashchange', schedule);
            observer.disconnect();
        };
    }, [url]);
    const isActive = (href: string) => path === '/'
        ? activeSection === ({ '/': 'home', '/scholarships': 'discover', '/student/scholarships': 'discover', '/#about': 'about', '/#contact': 'contact' } as Record<string, string>)[href]
        : !href.includes('#') && href !== '/' && (path === href || path.startsWith(href + '/'));
    const links = (mobile = false) => navigation.map(([label, href]) => {
        const active = isActive(href);
        const Icon = navigationIcons[label as keyof typeof navigationIcons];
        const className = 'public-nav-link inline-flex min-h-11 items-center gap-2 rounded-full px-3 py-2 font-semibold ' + (mobile ? 'text-sm ' : 'text-xs ') + focusRing + (active ? ' bg-civic-navy text-white' : ' text-civic-muted hover:bg-civic-panel');
        const current = active ? 'page' as const : undefined;
        const content = <>{Icon && <Icon aria-hidden="true" className="size-4" />}{label}</>;
        return href.includes('#') ? <a key={href} href={href} aria-current={current} onClick={() => setMenuOpen(false)} className={className}>{content}</a> : <Link key={href} href={href} aria-current={current} onClick={() => setMenuOpen(false)} className={className}>{content}</Link>;
    });
    const providerHref = auth.user ? accountHref : '/register?account=provider';
    const loginHref = auth.user ? accountHref : '/login';
    return <div id="home" className="flex min-h-dvh flex-col bg-civic-canvas font-stitch text-civic-ink selection:bg-civic-mint selection:text-civic-navy">
        <Head><link rel="preload" href={fontUrl} as="font" type="font/woff2" crossOrigin="anonymous" /></Head>
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4 focus:text-civic-navy">Skip to content</a>
        <header className="public-header sticky top-0 z-40">
            <div className={landingContainer + ' public-header-inner flex min-h-[4.75rem] items-center justify-between gap-3 py-2'}>
                <Brand />
                <nav aria-label="Main navigation" className="hidden items-center gap-1.5 lg:flex">{links()}</nav>
                <div className="flex shrink-0 items-center gap-2">{!auth.user && !reviewMode && <Link href="/login" className={'hidden min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-civic-muted sm:inline-flex ' + focusRing}>Log in</Link>}{reviewMode ? <LogoutButton className={buttonStyles('secondary', 'sm')}>Log out</LogoutButton> : <Link href={accountHref} className={buttonStyles('secondary', 'sm') + ' public-header-cta'}>{auth.user ? 'My account' : <>Get started<ArrowRight aria-hidden="true" className="size-4" /></>}</Link>}<button type="button" onClick={() => setMenuOpen(true)} aria-label="Open navigation menu" aria-expanded={menuOpen} className={'flex size-11 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><Menu aria-hidden="true" className="size-6" /></button></div>
        </div></header>
        <Drawer open={menuOpen} onClose={() => setMenuOpen(false)} title="Explore IskoReach" description="Scholarships, public passers, and information about IskoReach."><nav aria-label="Mobile navigation" className="flex flex-col gap-2">{links(true)}{reviewMode ? <LogoutButton className={buttonStyles('primary')}>Log out</LogoutButton> : auth.user ? <Link href={accountHref} onClick={() => setMenuOpen(false)} className={buttonStyles('primary')}>My account</Link> : <><Link href={loginHref} onClick={() => setMenuOpen(false)} className={buttonStyles('outline')}>Log in</Link><Link href={accountHref} onClick={() => setMenuOpen(false)} className={buttonStyles('primary')}>Get started</Link></>}</nav></Drawer>
        {children}
        <div className="mt-auto"><LandingFooter hideAccount={reviewMode} accountHref={accountHref} providerHref={providerHref} loginHref={loginHref} /></div>
    </div>;
}
