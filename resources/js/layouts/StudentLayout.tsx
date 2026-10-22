import PageHeader from '../components/PageHeader';
import WorkspaceBreadcrumbs from '../components/WorkspaceBreadcrumbs';
import SidebarNavigation, { type SidebarGroup } from '../components/SidebarNavigation';
import SidebarAccount from '../components/SidebarAccount';
import { Head, Link, usePage } from '@inertiajs/react';
import { FolderOpen, FileText, Megaphone, ShieldCheck, ArrowUpRight, Award, Bell, BookOpen, CalendarClock, FileCheck2, GraduationCap, LayoutDashboard, Menu as MenuIcon, MessagesSquare, UserRound } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import SidebarToggle, { useSidebarCollapsed } from '../components/SidebarToggle';
import NotificationBell, { useNotificationSummary } from '../components/NotificationBell';
import { focusRing } from '../components/ui/DesignSystem';
import { Drawer } from '../components/ui/Overlays';

export default function StudentLayout({ title, description, actions, children }: { title: string; description?: string; actions?: ReactNode; children: ReactNode }) {
    const { props: { auth, status }, url } = usePage<SharedProps>();
    const unread = useNotificationSummary();
    const { collapsed, toggle } = useSidebarCollapsed('student');
    const [open, setOpen] = useState(false);
    if (!auth.user || auth.user.role !== 'student') return null;
    const rawPath = url.split('?')[0];
    const path = rawPath === '/scholarships' || rawPath.startsWith('/scholarships/') ? '/student'+rawPath : rawPath;
    const groups: SidebarGroup[] = [
        { label: 'Discover', items: [
            { label: 'Dashboard', href: '/student/dashboard', icon: LayoutDashboard },
            { label: 'Find Scholarships', href: '/student/scholarships', icon: GraduationCap },
        ] },
        { label: 'Applications', icon: BookOpen, expandable: true, items: [
            { label: 'My applications', href: '/student/applications', icon: BookOpen },
            { label: 'Document requirements', href: '/student/requirements', icon: FileCheck2 },
            { label: 'My document vault', href: '/student/documents', icon: FolderOpen },
            { label: 'Existing scholarships', href: '/declarations', icon: FileText },
        ] },
        { label: 'Updates', icon: Bell, expandable: true, items: [
            { label: 'Notifications', href: '/notifications', icon: Bell, count: unread },
            { label: 'Announcements', href: '/scholarship-announcements', icon: Megaphone },
        ] },
        { label: 'Awards & renewals', icon: Award, expandable: true, items: [
            { label: 'Awards & benefits', href: '/benefits', icon: Award },
            { label: 'Semester renewals', href: '/renewals', icon: CalendarClock },
            { label: 'Public passers', href: '/passers', icon: GraduationCap },
            { label: 'Public listing consent', href: '/student/awards', icon: ShieldCheck },
        ] },
        { label: 'Account', items: [
            { label: 'Messages', href: '/messages', icon: MessagesSquare },
            { label: 'My profile', href: '/student/profile', icon: UserRound },
        ] },
    ];
    const links = (mobile = false) => <SidebarNavigation label="Student navigation" groups={groups} currentPath={path} collapsed={!mobile && collapsed} onExpand={toggle} onNavigate={() => setOpen(false)} />;
    return <div className="min-h-dvh bg-civic-canvas font-stitch text-civic-ink"><Head title={title + ' · Student'} />
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4">Skip to content</a>
        <aside id="student-sidebar" data-collapsed={collapsed} className={'workspace-sidebar fixed inset-y-0 left-0 z-40 hidden w-60 flex-col overflow-hidden border-r border-slate-700 bg-civic-navy px-3 pb-3 text-white scrollbar-hidden lg:flex lg:transition-[width] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none '}>
            <div data-sidebar-brand className="flex h-20 shrink-0 items-center px-3"><Brand compact inverse /></div>
            <div data-sidebar-scroll className="min-h-0 flex-1 pt-5 pb-3 overflow-y-auto overscroll-contain scrollbar-hidden">
            {links()}<div className="mt-auto pt-6"><Link href="/" aria-label="View public site" title="View public site" data-sidebar-footer className={'flex min-h-11 items-center justify-between rounded-lg border border-white/15 px-3 text-xs font-semibold text-slate-200 hover:bg-white/10 ' + focusRing}><span>View public site</span><ArrowUpRight aria-hidden="true" className="size-4" /></Link></div>
            </div><SidebarAccount user={auth.user} />
        </aside>
        <div className={'min-w-0 transition-[padding-left] duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? 'lg:pl-20' : 'lg:pl-60')}>
            <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-2 border-b border-neutral-200 bg-white px-3 sm:px-6 lg:px-8"><div className="flex min-w-0 items-center gap-2"><SidebarToggle collapsed={collapsed} onToggle={toggle} controls="student-sidebar" /><button type="button" aria-label="Open student navigation" aria-expanded={open} onClick={() => setOpen(true)} className={'flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><MenuIcon aria-hidden="true" className="size-5" /></button><WorkspaceBreadcrumbs role="student" title={title} path={url.split('?')[0]} /></div><div className="flex shrink-0 items-center gap-1"><NotificationBell unread={unread} /></div></header>
            <main id="main-content" className="workspace-content mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8 lg:py-6"><PageHeader title={title} description={description} actions={actions} />{status && <p role="status" className="mb-6 rounded-lg border border-civic-blue-gray bg-civic-panel p-4 text-sm text-civic-navy">{status}</p>}{children}</main>
        </div>
        <Drawer open={open} onClose={() => setOpen(false)} hideScrollbar footer={<div className="rounded-lg bg-civic-navy px-3 pb-2"><SidebarAccount user={auth.user} onSignOut={()=>setOpen(false)} /></div>} title="Student navigation" description="Your scholarship activities and account."><div className="rounded-xl bg-civic-navy p-3">{links(true)}</div><Link href="/" className={'mt-4 flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold ' + focusRing}><span>View public site</span><ArrowUpRight aria-hidden="true" className="size-4" /></Link></Drawer>
    </div>;
}
