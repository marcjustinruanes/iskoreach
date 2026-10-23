import PageHeader from '../components/PageHeader';
import WorkspaceBreadcrumbs from '../components/WorkspaceBreadcrumbs';
import SidebarNavigation, { type SidebarGroup } from '../components/SidebarNavigation';
import SidebarAccount from '../components/SidebarAccount';
import NotificationBell, { useNotificationSummary } from '../components/NotificationBell';
import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, ArrowUpRight, Award, BookOpen, Building2, CalendarClock, CalendarDays, ChartNoAxesCombined, CircleDollarSign, History, FileText, LayoutDashboard, ListChecks, Menu as MenuIcon, ShieldCheck, Users } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import SidebarToggle, { useSidebarCollapsed } from '../components/SidebarToggle';
import { buttonStyles, focusRing } from '../components/ui/DesignSystem';
import { Drawer, Modal } from '../components/ui/Overlays';

export default function AdminLayout({ title, description, actions, children }: { title: string; description?: string; actions?: ReactNode; children: ReactNode }) {
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
        { label: 'Grants and passers', href: navigation.awards, icon: Award },
        { label: 'Renewals', href: navigation.renewals, icon: CalendarClock },
        { label: 'Benefit releases', href: navigation.releases, icon: CircleDollarSign },
        { label: 'Academic terms', href: navigation.terms, icon: CalendarDays },
        { label: 'Reports', href: navigation.reports, icon: ChartNoAxesCombined },
        { label: 'Activity logs', href: navigation.activity, icon: History },
    ];
    const groups: SidebarGroup[] = [
        { label: 'Overview', items: [items[0], items[3], { label: 'Notifications', href: '/notifications', icon: Bell, count: unreadNotifications }] },
        { label: 'Verification', icon: ShieldCheck, expandable: true, items: [{ ...items[1], count: navigation.pendingProviders }, items[2], items[5]] },
        { label: 'Scholarship operations', icon: BookOpen, expandable: true, items: [items[4], { label: 'Browse scholarships', href: '/scholarships', icon: BookOpen }, { label: 'Public passers', href: '/passers', icon: Award }, items[6], items[7], items[8], items[9]] },
        { label: 'Reports & activity', icon: ChartNoAxesCombined, expandable: true, items: [...items.slice(10), { label: 'Registration policies', href: navigation.policies, icon: FileText }] },
    ];
    const links = (mobile = false) => <SidebarNavigation label="Administrative navigation" groups={groups} currentPath={url.split('?')[0]} collapsed={!mobile && collapsed} onExpand={toggle} onNavigate={() => setSidebarOpen(false)} />;
    return <div className="min-h-dvh bg-civic-canvas font-stitch text-base text-civic-ink">
        <Head title={title + ' · Administration'} />
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4">Skip to content</a>
        <aside id="admin-sidebar" data-collapsed={collapsed} className={'workspace-sidebar fixed inset-y-0 left-0 z-40 hidden w-60 flex-col overflow-hidden scrollbar-hidden border-r border-slate-700 bg-civic-navy px-3 pb-3 text-white lg:flex lg:transition-[width] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none '}>
            <div data-sidebar-brand className="flex h-20 shrink-0 items-center px-3"><Brand compact inverse /></div>
            <div data-sidebar-scroll className="min-h-0 flex-1 pt-5 pb-3 overflow-y-auto overscroll-contain scrollbar-hidden">{links()}
            <div className="mt-auto pt-6"><Link href={navigation.home} aria-label="View public site" title="View public site" data-sidebar-footer className={'flex min-h-11 items-center justify-between rounded-lg border border-white/15 px-3 text-xs font-semibold text-slate-200 hover:bg-white/10 ' + focusRing}><span>View public site</span><ArrowUpRight aria-hidden="true" className="size-4" /></Link></div>
            </div><SidebarAccount user={auth.user} />
        </aside>
        <div className={'min-w-0 transition-[padding-left] duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? 'lg:pl-20' : 'lg:pl-60')}>
            <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-2 border-b border-neutral-200 bg-white px-3 sm:px-6 lg:px-8">
                <div className="flex min-w-0 items-center gap-2"><SidebarToggle collapsed={collapsed} onToggle={toggle} controls="admin-sidebar" /><button type="button" onClick={() => setSidebarOpen(true)} aria-label="Open admin navigation" aria-expanded={sidebarOpen} className={'flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><MenuIcon aria-hidden="true" className="size-5" /></button><WorkspaceBreadcrumbs role="admin" title={title} path={url.split('?')[0]} /></div>
                <div className="flex shrink-0 items-center gap-1 sm:gap-2">
                    <NotificationBell unread={unreadNotifications} /><button type="button" onClick={() => setWorkOpen(true)} aria-label={'Pending work: ' + navigation.pendingProviders + ' providers and ' + 0 + ' external count reports awaiting review'} className={'relative flex size-11 items-center justify-center rounded-lg hover:bg-civic-panel ' + focusRing}><ListChecks aria-hidden="true" className="size-5" />{(navigation.pendingProviders > 0 || 0 > 0) && <span aria-hidden="true" className="absolute top-1 right-1 size-2 rounded-full bg-civic-teal" />}</button>

                </div>
            </header>
            <main id="main-content" className="admin-workspace workspace-content mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8 lg:py-6">
                <PageHeader title={title} description={description} actions={actions} />
                {status && <div role="status" className="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-900">{status}</div>}
                {children}
            </main>
        </div>
        <Drawer open={sidebarOpen} onClose={() => setSidebarOpen(false)} hideScrollbar footer={<div className="rounded-lg bg-civic-navy px-3 pb-2"><SidebarAccount user={auth.user} onSignOut={()=>setSidebarOpen(false)} /></div>} title="Administration" description="IskoReach platform oversight."><div className="rounded-xl bg-civic-navy p-3">{links(true)}</div><Link href={navigation.home} className={'mt-4 flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold ' + focusRing}><span>View public site</span><ArrowUpRight aria-hidden="true" className="size-4" /></Link></Drawer>
        <Modal open={workOpen} onClose={() => setWorkOpen(false)} title="Pending work" description="Provider accounts awaiting an administrator decision."><p className="text-sm leading-6 text-civic-muted">{navigation.pendingProviders > 0 ? navigation.pendingProviders + (navigation.pendingProviders === 1 ? ' provider account is awaiting review.' : ' provider accounts are awaiting review.') : 'No providers are awaiting review.'}</p><p className="mt-3 text-xs leading-5 text-civic-muted">This summary updates when you visit an admin page. Open the queue to review current provider information.</p><Link href={navigation.providers} onClick={() => setWorkOpen(false)} className={buttonStyles('secondary') + ' mt-6'}>Open provider queue</Link><p className="mt-5 text-sm leading-6 text-civic-muted">{0} external count reports await verification.</p><Link href={navigation.counts + '?pending=1'} onClick={() => setWorkOpen(false)} className={buttonStyles('outline') + ' mt-3'}>Review external counts</Link></Modal>
    </div>;
}
