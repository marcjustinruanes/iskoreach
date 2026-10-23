import PageHeader from '../components/PageHeader';
import WorkspaceBreadcrumbs from '../components/WorkspaceBreadcrumbs';
import SidebarNavigation, { type SidebarGroup } from '../components/SidebarNavigation';
import SidebarAccount from '../components/SidebarAccount';
import NotificationBell, { useNotificationSummary } from '../components/NotificationBell';
import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, Megaphone, FileText, ShieldCheck, Building2, LayoutDashboard, Menu as MenuIcon, GraduationCap, UsersRound, MessagesSquare, CalendarClock, Award, PanelsTopLeft, ArrowUpRight, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import SidebarToggle, { useSidebarCollapsed } from '../components/SidebarToggle';
import VerificationNotices from '../components/VerificationNotices';
import { EmptyState, focusRing } from '../components/ui/DesignSystem';
import { Drawer, Modal } from '../components/ui/Overlays';

export default function ProviderLayout({ title, description, actions, children, dashboard = false }: { title: string; description?: string; actions?: ReactNode; children: ReactNode; dashboard?: boolean }) {
    const unreadNotifications = useNotificationSummary();
    const { auth, providerContext: context, status } = usePage<SharedProps>().props;
    const { url } = usePage();
    const { collapsed, toggle } = useSidebarCollapsed('provider');
    const [mobileOpen, setMobileOpen] = useState(false);
    const [noticesOpen, setNoticesOpen] = useState(false);
    if (!auth.user || auth.user.role !== 'provider' || !context) return null;
    const approved = context.verification_status === 'approved';
    const currentPath = url.split('?')[0];
    const groups: SidebarGroup[] = [
        { label: 'Overview', items: [{ label: approved ? 'Dashboard' : 'Account status', href: approved ? context.dashboard : context.status, icon: LayoutDashboard }] },
        ...(approved ? [
            { label: 'Scholarships & applicants', icon: GraduationCap, expandable: true, items: [
                { label: 'Browse scholarships', href: '/scholarships', icon: GraduationCap },
                { label: 'My scholarships', href: '/provider/scholarships', icon: GraduationCap },
                { label: 'Applicants', href: '/provider/applications', icon: UsersRound },
            ] },
            { label: 'Communication', icon: MessagesSquare, expandable: true, items: [
                { label: 'Messages', href: '/messages', icon: MessagesSquare },
                { label: 'Announcements', href: '/scholarship-announcements', icon: Megaphone },
            ] },
            { label: 'Grants & renewals', icon: Award, expandable: true, items: [
                { label: 'Passers, grants & benefits', href: '/benefits', icon: Award },
                { label: 'Semester renewals', href: '/renewals', icon: CalendarClock },
            ] },
        ] : []),
        { label: 'Organization', items: [
            { label: 'Notifications', href: '/notifications', icon: Bell, count: unreadNotifications },
            { label: 'Organization profile', href: context.profile, icon: Building2 },
            ...(approved ? [{ label: 'All provider tools', href: context.workspace, icon: PanelsTopLeft }] : []),
        ] },
    ];
    const links = (mobile = false) => <SidebarNavigation label="Provider navigation" groups={groups} currentPath={currentPath} collapsed={!mobile && collapsed} showGroupLabels onExpand={toggle} onNavigate={() => setMobileOpen(false)} />;
    return <div className="provider-shell min-h-dvh bg-civic-canvas font-stitch text-civic-ink"><Head title={title + ' · Provider'} />
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4">Skip to content</a>
        <aside id="provider-sidebar" data-collapsed={collapsed} className={'workspace-sidebar provider-sidebar fixed inset-y-0 left-0 z-40 hidden w-60 flex-col overflow-hidden border-r border-slate-700 bg-civic-navy px-3 pb-3 text-white scrollbar-hidden lg:flex lg:transition-[width] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none '}>
            <div data-sidebar-brand className="flex h-20 shrink-0 items-center justify-between px-3"><Brand compact /><button type="button" onClick={toggle} aria-label={collapsed ? 'Expand provider navigation' : 'Collapse provider navigation'} aria-expanded={!collapsed} className={'provider-sidebar-collapse hidden size-9 items-center justify-center rounded-lg lg:inline-flex ' + focusRing}>{collapsed ? <ChevronRight aria-hidden="true" className="size-4" /> : <ChevronLeft aria-hidden="true" className="size-4" />}</button></div>
            <div data-sidebar-scroll className="min-h-0 flex-1 pt-5 pb-3 overflow-y-auto overscroll-contain scrollbar-hidden">
            {links()}
            <div className="mt-auto pt-6"><Link href="/" aria-label="View public site" title="View public site" data-sidebar-footer className={'flex min-h-11 items-center justify-between rounded-lg border border-white/15 px-3 text-xs font-semibold text-slate-200 hover:bg-white/10 ' + focusRing}><span>View public site</span><ArrowUpRight aria-hidden="true" className="size-4" /></Link></div>
            </div><SidebarAccount user={auth.user} />
        </aside>
        <div className={'min-w-0 transition-[padding-left] duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? 'lg:pl-20' : 'lg:pl-60')}>
            <header className={'provider-topbar sticky top-0 z-30 flex h-16 items-center justify-between gap-2 border-b border-neutral-200 bg-white px-3 sm:px-6 lg:px-8 ' + (dashboard ? 'provider-dashboard-topbar' : '')}>
                <div className="flex min-w-0 items-center gap-2"><SidebarToggle collapsed={collapsed} onToggle={toggle} controls="provider-sidebar" /><button type="button" aria-label="Open provider navigation" aria-expanded={mobileOpen} onClick={() => setMobileOpen(true)} className={'flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><MenuIcon aria-hidden="true" className="size-5" /></button><WorkspaceBreadcrumbs role="provider" title={title} path={url.split('?')[0]} /></div>
                <div className="flex shrink-0 items-center gap-1 sm:gap-2"><NotificationBell unread={unreadNotifications} /><button type="button" aria-label="Recent verification decisions" onClick={() => setNoticesOpen(true)} className={'flex size-11 items-center justify-center rounded-lg hover:bg-civic-panel ' + focusRing}><ShieldCheck aria-hidden="true" className="size-5" /></button>

                </div>
            </header>
            <main id="main-content" className={'provider-workspace workspace-content mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8 lg:py-6 ' + (dashboard ? 'provider-dashboard-workspace' : '')}>{!dashboard && <PageHeader title={title} description={description} actions={actions} />}
                {status && <p role="status" className="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900">{status}</p>}{children}
            </main>
        </div>
        <Drawer open={mobileOpen} onClose={() => setMobileOpen(false)} hideScrollbar footer={<div className="rounded-lg bg-civic-navy px-3 pb-2"><SidebarAccount user={auth.user} onSignOut={()=>setMobileOpen(false)} /></div>} title="Provider navigation" description={context.organization_name || 'Your organization'}><div className="rounded-xl bg-civic-navy p-3">{links(true)}</div><Link href="/" className={'mt-4 flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold ' + focusRing}><span>View public site</span><ArrowUpRight aria-hidden="true" className="size-4" /></Link></Drawer>
        <Modal open={noticesOpen} onClose={() => setNoticesOpen(false)} title="Verification decisions" description="Recent saved account decisions. This list refreshes when you visit a page.">{context.notices.length ? <VerificationNotices notices={context.notices} headingId="provider-dialog-decisions-title" /> : <EmptyState title="No decisions recorded yet" description="Administrator decisions for your organization will appear here." />}</Modal>
    </div>;
}
