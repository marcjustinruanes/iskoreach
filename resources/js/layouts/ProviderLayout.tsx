import NotificationBell, { useNotificationSummary } from '../components/NotificationBell';
import { Head, Link, usePage } from '@inertiajs/react';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { ShieldCheck, Building2, ChevronDown, LayoutDashboard, LogOut, Menu as MenuIcon, GraduationCap, UsersRound, MessagesSquare, CalendarClock, Award, ChartNoAxesCombined, PanelsTopLeft, ArrowUpRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import SidebarToggle, { useSidebarCollapsed } from '../components/SidebarToggle';
import VerificationNotices from '../components/VerificationNotices';
import { Avatar, EmptyState, focusRing } from '../components/ui/DesignSystem';
import { Drawer, Modal } from '../components/ui/Overlays';

export default function ProviderLayout({ title, description, actions, children }: { title: string; description: string; actions?: ReactNode; children: ReactNode }) {
    const unreadNotifications = useNotificationSummary();
    const { auth, providerContext: context, status } = usePage<SharedProps>().props;
    const { url } = usePage();
    const { collapsed, toggle } = useSidebarCollapsed('provider');
    const [mobileOpen, setMobileOpen] = useState(false);
    const [noticesOpen, setNoticesOpen] = useState(false);
    if (!auth.user || auth.user.role !== 'provider' || !context) return null;
    const approved = context.verification_status === 'approved';
    const currentPath = url.split('?')[0];
    const isActive = (href: string) => {
        const path = new URL(href, window.location.origin).pathname;
        return currentPath === path || (['/provider/applicant-counts', '/provider/scholarships', '/provider/applications', '/messages', '/renewals', '/benefits'].includes(path) && currentPath.startsWith(path + '/'));
    };
    const groups = [
        { label: 'Main', items: [
            { title: approved ? 'Dashboard' : 'Account status', href: approved ? context.dashboard : context.status, icon: LayoutDashboard },
            ...(approved ? [
                { title: 'My Scholarships', href: '/provider/scholarships', icon: GraduationCap },
                { title: 'Applicants', href: '/provider/applications', icon: UsersRound },
                { title: 'Applicant Counts', href: context.counts, icon: ChartNoAxesCombined },
            ] : []),
        ] },
        ...(approved ? [{ label: 'Scholarship Management', items: [
            { title: 'Messages', href: '/messages', icon: MessagesSquare },
            { title: 'Renewals', href: '/renewals', icon: CalendarClock },
            { title: 'Awards & Benefits', href: '/benefits', icon: Award },
        ] }] : []),
        { label: 'Organization', items: [
            { title: 'Organization Profile', href: context.profile, icon: Building2 },

        ] },
    ];
    if (approved) groups.push({ label: 'Workspace', items: [{ title: 'Provider Workspace', href: context.workspace, icon: PanelsTopLeft }] });
    const activeItem = groups.flatMap(group => group.items).find(item => isActive(item.href));
    const links = <nav aria-label="Provider navigation" className="space-y-5">{groups.map(group => <div key={group.label}>{group.label && <p className="px-3 pb-2 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{group.label}</p>}<div className="space-y-1">{group.items.map(item => {
        const active = isActive(item.href);
        return <Link key={item.href} href={item.href} aria-current={active ? 'page' : undefined} onClick={() => setMobileOpen(false)} className={'flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] font-medium leading-5 transition-colors motion-reduce:transition-none ' + focusRing + (active ? ' bg-teal-700 text-white ring-1 ring-inset ring-teal-500' : ' text-slate-200 hover:bg-white/10 hover:text-white')}><item.icon aria-hidden="true" className="size-[18px] shrink-0" /><span className="min-w-0">{item.title}</span></Link>;
    })}</div></div>)}</nav>;
    return <div className="provider-shell min-h-dvh bg-civic-canvas font-stitch text-civic-ink"><Head title={title + ' · Provider'} />
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4">Skip to content</a>
        <aside id="provider-sidebar" inert={collapsed} aria-hidden={collapsed} className={'fixed inset-y-0 left-0 z-40 hidden w-60 flex-col overflow-y-auto overscroll-contain border-r border-slate-700 bg-[#102b3c] px-3 pb-3 text-white scrollbar-hidden lg:flex lg:transition-[translate,opacity,visibility] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none ' + (collapsed ? 'lg:invisible lg:-translate-x-full lg:opacity-0' : 'lg:visible lg:translate-x-0 lg:opacity-100')}>
            <div className="flex h-16 shrink-0 items-center px-2"><Brand compact inverse /></div>
            <div className="mb-5 mt-3 flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-3"><Building2 aria-hidden="true" className="size-6 shrink-0 text-teal-200" /><div className="min-w-0"><p className="text-xs font-semibold">Provider workspace</p><p className="mt-1 truncate text-[11px] text-slate-300" title={context.organization_name || undefined}>{context.organization_name || 'Organization details incomplete'}</p><p className={'mt-1 text-[11px] ' + (approved ? 'text-teal-200' : 'text-amber-200')}>{approved ? 'Approved provider' : context.verification_status === 'rejected' ? 'Not approved' : 'Pending approval'}</p></div></div>
            {links}
            <div className="mt-auto pt-6"><Link href="/" className={'flex min-h-11 items-center justify-between rounded-lg border border-white/15 px-3 text-xs font-semibold text-slate-200 hover:bg-white/10 ' + focusRing}>View public site<ArrowUpRight aria-hidden="true" className="size-4" /></Link></div>
        </aside>
        <div className={'min-w-0 transition-[padding-left] duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? '' : 'lg:pl-60')}>
            <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-2 border-b border-civic-panel-strong bg-white/95 px-3 backdrop-blur-sm sm:px-6 lg:px-8">
                <div className="flex min-w-0 items-center gap-2"><SidebarToggle collapsed={collapsed} onToggle={toggle} controls="provider-sidebar" /><button type="button" aria-label="Open provider navigation" aria-expanded={mobileOpen} onClick={() => setMobileOpen(true)} className={'flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><MenuIcon aria-hidden="true" className="size-5" /></button><nav aria-label="Breadcrumb" className="min-w-0 text-xs sm:text-sm"><ol className="flex min-w-0 items-center gap-2"><li className="hidden shrink-0 text-civic-muted sm:block">Provider</li><li aria-hidden="true" className="hidden text-civic-border sm:block">/</li><li aria-current="page" title={title} className="truncate font-semibold">{activeItem?.title || title}</li></ol></nav></div>
                <div className="flex shrink-0 items-center gap-1 sm:gap-2"><NotificationBell unread={unreadNotifications} /><button type="button" aria-label="Recent verification decisions" onClick={() => setNoticesOpen(true)} className={'flex size-11 items-center justify-center rounded-lg hover:bg-civic-panel ' + focusRing}><ShieldCheck aria-hidden="true" className="size-5" /></button>
                    <Menu><MenuButton className={'flex min-h-11 items-center gap-2 rounded-lg p-1 ' + focusRing}><Avatar name={auth.user.name} /><span className="hidden max-w-32 truncate text-sm font-medium md:block">{auth.user.name}</span><ChevronDown aria-hidden="true" className="size-4" /><span className="sr-only">Account menu</span></MenuButton><MenuItems anchor="bottom end" className="z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-civic-panel-strong bg-white p-2 font-stitch text-sm text-civic-ink shadow-civic-overlay"><p className="break-all border-b border-civic-panel-strong px-3 py-3 text-xs leading-5 text-civic-muted">{auth.user.email}</p><MenuItem><Link href={context.profile} className={'flex min-h-11 items-center rounded-lg px-3 data-focus:bg-civic-panel ' + focusRing}>Organization Profile</Link></MenuItem><MenuItem><Link href="/logout" method="post" as="button" className={'flex min-h-11 w-full items-center gap-2 rounded-lg px-3 data-focus:bg-civic-panel ' + focusRing}><LogOut aria-hidden="true" className="size-4" />Log out</Link></MenuItem></MenuItems></Menu>
                </div>
            </header>
            <main id="main-content" className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8"><div className="mb-6 flex flex-wrap items-start justify-between gap-4"><div className="min-w-0 max-w-3xl"><h1 className="text-2xl font-bold leading-8 tracking-tight text-civic-navy sm:text-[28px] sm:leading-9">{title}</h1><p className="mt-2 text-sm leading-6 text-civic-muted">{description}</p></div>{actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}</div>
                {status && <p role="status" className="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900">{status}</p>}{children}
            </main>
        </div>
        <Drawer open={mobileOpen} onClose={() => setMobileOpen(false)} hideScrollbar title="Provider navigation" description={context.organization_name || 'Your organization'}><div className="rounded-xl bg-[#102b3c] p-3">{links}</div><Link href="/" className={'mt-4 flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold ' + focusRing}>View public site<ArrowUpRight aria-hidden="true" className="size-4" /></Link></Drawer>
        <Modal open={noticesOpen} onClose={() => setNoticesOpen(false)} title="Verification decisions" description="Recent saved account decisions. This list refreshes when you visit a page.">{context.notices.length ? <VerificationNotices notices={context.notices} headingId="provider-dialog-decisions-title" /> : <EmptyState title="No decisions recorded yet" description="Administrator decisions for your organization will appear here." />}</Modal>
    </div>;
}
