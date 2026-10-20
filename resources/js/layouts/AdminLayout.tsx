import NotificationBell, { useNotificationSummary } from '../components/NotificationBell';
import { Head, Link, usePage } from '@inertiajs/react';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { ArrowUpRight, Award, BookOpen, Building2, CalendarClock, CalendarDays, ChartNoAxesCombined, ChevronDown, CircleDollarSign, History, LayoutDashboard, ListChecks, LogOut, Menu as MenuIcon, ShieldCheck, Users } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import SidebarToggle, { useSidebarCollapsed } from '../components/SidebarToggle';
import { Avatar, buttonStyles, focusRing } from '../components/ui/DesignSystem';
import { Drawer, Modal } from '../components/ui/Overlays';

export default function AdminLayout({ title, description, children }: { title: string; description: string; children: ReactNode }) {
    const { props, url } = usePage<SharedProps>();
    const unreadNotifications = useNotificationSummary();
    const { auth, adminNavigation: navigation, status } = props;
    const { collapsed, toggle } = useSidebarCollapsed('admin');
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [workOpen, setWorkOpen] = useState(false);
    if (!auth.user || auth.user.role !== 'admin' || !navigation) return null;

    const items = [
        { label: 'Dashboard', href: navigation.dashboard, icon: LayoutDashboard },
        { label: 'Provider verification', href: navigation.providers, icon: Building2 },
        { label: 'User management', href: navigation.users, icon: Users },
        { label: 'Scholarship monitoring', href: navigation.scholarships, icon: BookOpen },
        { label: 'Applicant counts', href: navigation.counts, icon: ListChecks },
        { label: 'Awards and passers', href: navigation.awards, icon: Award },
        { label: 'Renewals', href: navigation.renewals, icon: CalendarClock },
        { label: 'Benefit releases', href: navigation.releases, icon: CircleDollarSign },
        { label: 'Academic terms', href: navigation.terms, icon: CalendarDays },
        { label: 'Reports', href: navigation.reports, icon: ChartNoAxesCombined },
        { label: 'Activity logs', href: navigation.activity, icon: History },
    ];
    const groups = [
        { label: 'Administration', items: items.slice(0, 3) },
        { label: 'Scholarship Management', items: items.slice(3, 9) },
        { label: 'Reports and Activity', items: items.slice(9) },
    ];
    const links = <nav aria-label="Administrative navigation" className="space-y-5">{groups.map(group => <div key={group.label}><p className="px-3 pb-2 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{group.label}</p><div className="space-y-1">{group.items.map(item => {
        const itemPath = new URL(item.href, window.location.origin).pathname;
        const currentPath = url.split('?')[0];
        const active = currentPath === itemPath || ([navigation.providers, navigation.users, navigation.scholarships, navigation.counts].includes(item.href) && currentPath.startsWith(itemPath + '/'));
        return <Link key={item.href} href={item.href} aria-current={active ? 'page' : undefined} onClick={() => setSidebarOpen(false)} className={'flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] font-medium leading-5 transition-colors motion-reduce:transition-none ' + focusRing + (active ? ' bg-teal-700 text-white ring-1 ring-inset ring-teal-500' : ' text-slate-200 hover:bg-white/10 hover:text-white')}><item.icon aria-hidden="true" className="size-[18px] shrink-0" /><span className="min-w-0 break-words">{item.label}</span>{item.label === 'Provider verification' && navigation.pendingProviders > 0 && <span className="ml-auto shrink-0 rounded bg-teal-100 px-1.5 text-xs font-semibold tabular-nums text-teal-950">{navigation.pendingProviders}</span>}</Link>;
    })}</div></div>)}</nav>;

    return <div className="min-h-dvh bg-civic-canvas font-stitch text-base text-civic-ink">
        <Head title={title + ' · Administration'} />
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4">Skip to content</a>
        <aside id="admin-sidebar" inert={collapsed} aria-hidden={collapsed} className={'fixed inset-y-0 left-0 z-40 hidden w-60 flex-col overflow-y-auto overscroll-contain scrollbar-hidden border-r border-slate-700 bg-[#102b3c] px-3 pb-3 text-white lg:flex lg:transition-[translate,opacity,visibility] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none ' + (collapsed ? 'lg:invisible lg:-translate-x-full lg:opacity-0' : 'lg:visible lg:translate-x-0 lg:opacity-100')}>
            <div className="flex h-16 shrink-0 items-center px-2"><Brand compact inverse /></div>
            <div className="mb-5 mt-3 flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-3"><ShieldCheck aria-hidden="true" className="size-6 shrink-0 text-teal-200" /><div className="min-w-0"><p className="text-xs font-semibold">Admin workspace</p><p className="mt-1 truncate text-[11px] text-slate-300">{auth.user.name}</p></div></div>{links}
            <div className="mt-auto pt-6"><Link href={navigation.home} className={'flex min-h-11 items-center justify-between rounded-lg border border-white/15 px-3 text-xs font-semibold text-slate-200 hover:bg-white/10 ' + focusRing}>View public site<ArrowUpRight aria-hidden="true" className="size-4" /></Link></div>
        </aside>
        <div className={'min-w-0 transition-[padding-left] duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? '' : 'lg:pl-60')}>
            <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-2 border-b border-civic-panel-strong bg-white px-3 sm:px-6 lg:px-8">
                <div className="flex min-w-0 items-center gap-2"><SidebarToggle collapsed={collapsed} onToggle={toggle} controls="admin-sidebar" /><button type="button" onClick={() => setSidebarOpen(true)} aria-label="Open admin navigation" aria-expanded={sidebarOpen} className={'flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><MenuIcon aria-hidden="true" className="size-5" /></button><nav aria-label="Breadcrumb" className="min-w-0 text-xs text-civic-muted sm:text-sm"><ol className="flex min-w-0 items-center gap-2"><li className="hidden shrink-0 sm:block"><Link href={navigation.dashboard} className={'rounded hover:text-civic-teal ' + focusRing}>Administration</Link></li><li aria-hidden="true" className="hidden shrink-0 text-civic-border sm:block">/</li><li aria-current="page" title={title} className="truncate font-semibold text-civic-navy">{title}</li></ol></nav></div>
                <div className="flex shrink-0 items-center gap-1 sm:gap-2">
                    <NotificationBell unread={unreadNotifications} /><button type="button" onClick={() => setWorkOpen(true)} aria-label={'Pending work: ' + navigation.pendingProviders + ' providers and ' + navigation.pendingCounts + ' external count reports awaiting review'} className={'relative flex size-11 items-center justify-center rounded-lg hover:bg-civic-panel ' + focusRing}><ListChecks aria-hidden="true" className="size-5" />{(navigation.pendingProviders > 0 || navigation.pendingCounts > 0) && <span aria-hidden="true" className="absolute top-1 right-1 size-2 rounded-full bg-civic-teal" />}</button>
                    <Menu><MenuButton className={'flex min-h-11 items-center gap-2 rounded-lg p-1 ' + focusRing}><Avatar name={auth.user.name} /><span className="hidden max-w-32 truncate text-sm font-medium md:block">{auth.user.name}</span><ChevronDown aria-hidden="true" className="size-4" /><span className="sr-only">Account menu</span></MenuButton><MenuItems anchor="bottom end" className="z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-civic-panel-strong bg-white p-2 font-stitch text-civic-ink shadow-civic-overlay focus:outline-none"><div className="border-b border-civic-panel-strong px-3 py-3"><p className="text-sm font-semibold">Administrator</p><p className="mt-1 break-all text-xs leading-5 text-civic-muted">{auth.user.email}</p></div><MenuItem><Link href={navigation.home} className={'mt-2 flex min-h-11 items-center rounded-lg px-3 text-sm data-focus:bg-civic-panel ' + focusRing}>Public site</Link></MenuItem><MenuItem><Link href={navigation.logout} method="post" as="button" className={'flex min-h-11 w-full items-center gap-2 rounded-lg px-3 text-sm data-focus:bg-civic-panel ' + focusRing}><LogOut aria-hidden="true" className="size-4" />Log out</Link></MenuItem></MenuItems></Menu>
                </div>
            </header>
            <main id="main-content" className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
                <div className="mb-6"><h1 className="text-2xl font-bold leading-8 tracking-tight text-civic-navy sm:text-[28px] sm:leading-9">{title}</h1><p className="mt-2 max-w-3xl text-sm leading-6 text-civic-muted">{description}</p></div>
                {status && <div role="status" className="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-900">{status}</div>}
                {children}
            </main>
        </div>
        <Drawer open={sidebarOpen} onClose={() => setSidebarOpen(false)} hideScrollbar title="Administration" description="IskoReach platform oversight."><div className="rounded-xl bg-[#102b3c] p-3">{links}</div><Link href={navigation.home} className={'mt-4 flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold ' + focusRing}>View public site<ArrowUpRight aria-hidden="true" className="size-4" /></Link></Drawer>
        <Modal open={workOpen} onClose={() => setWorkOpen(false)} title="Pending work" description="Provider accounts awaiting an administrator decision."><p className="text-sm leading-6 text-civic-muted">{navigation.pendingProviders > 0 ? navigation.pendingProviders + (navigation.pendingProviders === 1 ? ' provider account is awaiting review.' : ' provider accounts are awaiting review.') : 'No providers are awaiting review.'}</p><p className="mt-3 text-xs leading-5 text-civic-muted">This summary updates when you visit an admin page. Open the queue to review current provider information.</p><Link href={navigation.providers} onClick={() => setWorkOpen(false)} className={buttonStyles('secondary') + ' mt-6'}>Open provider queue</Link><p className="mt-5 text-sm leading-6 text-civic-muted">{navigation.pendingCounts} external count reports await verification.</p><Link href={navigation.counts + '?pending=1'} onClick={() => setWorkOpen(false)} className={buttonStyles('outline') + ' mt-3'}>Review external counts</Link></Modal>
    </div>;
}
