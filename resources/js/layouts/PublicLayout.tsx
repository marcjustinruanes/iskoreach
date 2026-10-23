import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Menu, House, BookOpen, Info, Mail } from 'lucide-react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import { LandingFooter, landingWideContainer, landingContainer } from '../components/LandingSections';
import { buttonStyles, focusRing } from '../components/ui/DesignSystem';
import { Drawer } from '../components/ui/Overlays';
import fontUrl from '../../../public/fonts/plus-jakarta-sans-latin.woff2?url';

const navigation = [
    { label: 'Home', href: '/', icon: House },
    { label: 'Scholarships', href: '/scholarships', icon: BookOpen },
    { label: 'About', href: '/#about', icon: Info },
    { label: 'Contact', href: '/#contact', icon: Mail },
];
export const publicPageContainer = landingContainer + ' py-8 sm:py-10';

export function PublicPageHeading({ title, description, eyebrow }: { title: string; description?: string; eyebrow?: string }) {
    return <div className="mb-6 max-w-3xl">{eyebrow && <p className="mb-3 text-xs font-bold uppercase tracking-widest text-civic-teal">{eyebrow}</p>}<h1 className="break-words text-2xl font-bold leading-8 tracking-tight text-civic-navy sm:text-3xl">{title}{title === 'Find a scholarship' && <svg aria-hidden="true" viewBox="0 0 36 36" className="scholarship-heading-rays"><path d="M4 18 15 22M17 4l4 11M30 9l-6 9" /></svg>}</h1>{description && <p className="mt-2 text-sm leading-6 text-civic-muted">{description}</p>}</div>;
}

export default function PublicLayout({ children }: { children: ReactNode }) {
    const { props: { auth }, url } = usePage<SharedProps>();
    const path = url.split('?')[0].split('#')[0];
    const [hash, setHash] = useState('');
    const [menuOpen, setMenuOpen] = useState(false);
    useEffect(() => {
        const update = () => setHash(window.location.hash);
        update();
        window.addEventListener('hashchange', update);
        return () => window.removeEventListener('hashchange', update);
    }, [url]);
    const isActive = (href: string) => href.includes('#') ? path === '/' && hash === href.slice(1) : href === '/' ? path === '/' && !hash : path === href || path.startsWith(href + '/');
    const links = (mobile = false) => navigation.map(({ label, href, icon: Icon }) => {
        const active = isActive(href);
        const className = 'inline-flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 font-semibold ' + (mobile ? 'text-sm ' : 'text-xs ') + focusRing + (active ? ' bg-civic-navy text-white' : ' text-civic-muted hover:bg-civic-panel');
        const current = active ? 'page' as const : undefined;
        const content = <><Icon aria-hidden="true" className="size-4" />{label}</>;
        return href.includes('#') ? <a key={href} href={href} aria-current={current} onClick={() => setMenuOpen(false)} className={className}>{content}</a> : <Link key={href} href={href} aria-current={current} onClick={() => setMenuOpen(false)} className={className}>{content}</Link>;
    });
    const accountHref = auth.user ? '/dashboard' : '/register';
    const providerHref = auth.user ? '/dashboard' : '/register?account=provider';
    const loginHref = auth.user ? '/dashboard' : '/login';
    return <div id="home" className="flex min-h-dvh flex-col bg-[#f8fbfc] font-stitch text-civic-ink selection:bg-civic-mint selection:text-civic-navy">
        <Head><link rel="preload" href={fontUrl} as="font" type="font/woff2" crossOrigin="anonymous" /></Head>
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4 focus:text-civic-navy">Skip to content</a>
        <header className="sticky top-0 z-40 border-b border-civic-panel-strong/60 bg-[#f8fbfc]"><div className={landingWideContainer + ' flex min-h-[76px] items-center justify-between gap-3 py-3'}>
            <Brand />
            <nav aria-label="Main navigation" className="hidden items-center gap-1 lg:flex">{links()}</nav>
            <div className="flex shrink-0 items-center gap-2">{!auth.user && <Link href="/login" className={'hidden min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-civic-muted sm:inline-flex ' + focusRing}>Log in</Link>}<Link href={accountHref} className="inline-flex min-h-11 items-center justify-center rounded-lg border border-civic-gold bg-civic-gold px-4 py-2 text-sm font-bold text-civic-navy shadow-sm transition hover:brightness-105 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-civic-teal">{auth.user ? 'My account' : 'Get started'}</Link><button type="button" onClick={() => setMenuOpen(true)} aria-label="Open navigation menu" aria-expanded={menuOpen} className={'flex size-11 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><Menu aria-hidden="true" className="size-6" /></button></div>
        </div></header>
        <Drawer open={menuOpen} onClose={() => setMenuOpen(false)} title="Explore IskoReach" description="Scholarships, public passers, and information about IskoReach."><nav aria-label="Mobile navigation" className="flex flex-col gap-2">{links(true)}<Link href={loginHref} onClick={() => setMenuOpen(false)} className={buttonStyles('primary')}>{auth.user ? 'My account' : 'Log in'}</Link></nav></Drawer>
        {children}
        <div className="mt-auto"><LandingFooter accountHref={accountHref} providerHref={providerHref} loginHref={loginHref} /></div>
    </div>;
}
