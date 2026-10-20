import { Head, Link, usePage } from '@inertiajs/react';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { ArrowUpRight, Award, Bell, BookOpen, CalendarClock, ChevronDown, FileCheck2, GraduationCap, LayoutDashboard, LogOut, Menu as MenuIcon, MessagesSquare, UserRound } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';
import Brand from '../components/Brand';
import SidebarToggle, { useSidebarCollapsed } from '../components/SidebarToggle';
import NotificationBell, { useNotificationSummary } from '../components/NotificationBell';
import { Avatar, focusRing } from '../components/ui/DesignSystem';
import { Drawer } from '../components/ui/Overlays';

export default function StudentLayout({ title, description, children }: { title: string; description: string; children: ReactNode }) {
    const { props: { auth, status }, url } = usePage<SharedProps>();
    const unread = useNotificationSummary();
    const { collapsed, toggle } = useSidebarCollapsed('student');
    const [open, setOpen] = useState(false);
    if (!auth.user || auth.user.role !== 'student') return null;
    const path = url.split('?')[0];
    const groups = [
        { label: 'Main', items: [
            { label: 'Dashboard', href: '/student/dashboard', icon: LayoutDashboard },
            { label: 'Find Scholarships', href: '/scholarships', icon: GraduationCap },
            { label: 'My Applications', href: '/student/applications', icon: BookOpen },
            { label: 'Requirements', href: '/student/documents', icon: FileCheck2 },
        ] },
        { label: 'Student Services', items: [
            { label: 'Messages', href: '/messages', icon: MessagesSquare },
            { label: 'Notifications', href: '/notifications', icon: Bell },
            { label: 'Renewals', href: '/renewals', icon: CalendarClock },
            { label: 'My Awards and Benefits', href: '/benefits', icon: Award },
            { label: 'Award Publication Consent', href: '/student/awards', icon: Award },
        ] },
        { label: 'Account', items: [{ label: 'My Profile', href: '/student/profile', icon: UserRound }] },
    ];
    const links = <nav aria-label="Student navigation" className="space-y-5">{groups.map(group => <div key={group.label}><p className="px-3 pb-2 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{group.label}</p><div className="space-y-1">{group.items.map(item => {
        const active = path === item.href || path.startsWith(item.href + '/');
        return <Link key={item.href} href={item.href} aria-current={active ? 'page' : undefined} onClick={() => setOpen(false)} className={'flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] font-medium leading-5 transition-colors motion-reduce:transition-none ' + focusRing + (active ? ' bg-teal-700 text-white ring-1 ring-inset ring-teal-500' : ' text-slate-200 hover:bg-white/10 hover:text-white')}><item.icon aria-hidden="true" className="size-[18px] shrink-0" /><span className="min-w-0">{item.label}</span>{item.href === '/notifications' && unread > 0 && <span className="ml-auto rounded bg-teal-100 px-1.5 text-xs font-semibold text-teal-950">{unread > 99 ? '99+' : unread}<span className="sr-only"> unread</span></span>}</Link>;
    })}</div></div>)}</nav>;
    return <div className="min-h-dvh bg-civic-canvas font-stitch text-civic-ink"><Head title={title + ' · Student'} />
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[90] focus:rounded-lg focus:bg-white focus:p-4">Skip to content</a>
        <aside id="student-sidebar" inert={collapsed} aria-hidden={collapsed} className={'fixed inset-y-0 left-0 z-40 hidden w-60 flex-col overflow-y-auto overscroll-contain border-r border-slate-700 bg-[#102b3c] px-3 pb-3 text-white scrollbar-hidden lg:flex lg:transition-[translate,opacity,visibility] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none ' + (collapsed ? 'lg:invisible lg:-translate-x-full lg:opacity-0' : 'lg:visible lg:translate-x-0 lg:opacity-100')}>
            <div className="flex h-16 shrink-0 items-center px-2"><Brand compact inverse /></div>
            <div className="mb-5 mt-3 flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-3"><GraduationCap aria-hidden="true" className="size-6 shrink-0 text-teal-200" /><div className="min-w-0"><p className="text-xs font-semibold">Student workspace</p><p className="mt-1 truncate text-[11px] text-slate-300">{auth.user.name}</p></div></div>
            {links}<div className="mt-auto pt-6"><Link href="/" className={'flex min-h-11 items-center justify-between rounded-lg border border-white/15 px-3 text-xs font-semibold text-slate-200 hover:bg-white/10 ' + focusRing}>View public site<ArrowUpRight aria-hidden="true" className="size-4" /></Link></div>
        </aside>
        <div className={'min-w-0 transition-[padding-left] duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? '' : 'lg:pl-60')}>
            <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-2 border-b border-civic-panel-strong bg-white px-3 sm:px-6 lg:px-8"><div className="flex min-w-0 items-center gap-2"><SidebarToggle collapsed={collapsed} onToggle={toggle} controls="student-sidebar" /><button type="button" aria-label="Open student navigation" aria-expanded={open} onClick={() => setOpen(true)} className={'flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel lg:hidden ' + focusRing}><MenuIcon aria-hidden="true" className="size-5" /></button><span className="hidden text-xs text-civic-muted sm:block">Student /</span><span className="truncate text-xs font-semibold sm:text-sm">{title}</span></div><div className="flex shrink-0 items-center gap-1"><NotificationBell unread={unread} /><Menu><MenuButton className={'flex min-h-11 items-center gap-2 rounded-lg p-1 ' + focusRing}><Avatar name={auth.user.name} /><ChevronDown aria-hidden="true" className="size-4" /><span className="sr-only">Account menu</span></MenuButton><MenuItems anchor="bottom end" className="z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-civic-panel-strong bg-white p-2 font-stitch text-sm shadow-civic-overlay"><p className="break-all border-b border-civic-panel-strong px-3 py-3 text-xs text-civic-muted">{auth.user.email}</p><MenuItem><Link href="/student/profile" className={'flex min-h-11 items-center rounded-lg px-3 data-focus:bg-civic-panel ' + focusRing}>My Profile</Link></MenuItem><MenuItem><Link href="/logout" method="post" as="button" className={'flex min-h-11 w-full items-center gap-2 rounded-lg px-3 data-focus:bg-civic-panel ' + focusRing}><LogOut aria-hidden="true" className="size-4" />Log out</Link></MenuItem></MenuItems></Menu></div></header>
            <main id="main-content" className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8"><div className="mb-6"><h1 className="text-2xl font-bold leading-8 tracking-tight text-civic-navy sm:text-[28px] sm:leading-9">{title}</h1><p className="mt-2 max-w-3xl text-sm leading-6 text-civic-muted">{description}</p></div>{status && <p role="status" className="mb-6 rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900">{status}</p>}{children}</main>
        </div>
        <Drawer open={open} onClose={() => setOpen(false)} hideScrollbar title="Student navigation" description="Your scholarship activities and account."><div className="rounded-xl bg-[#102b3c] p-3">{links}</div><Link href="/" className={'mt-4 flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold ' + focusRing}>View public site<ArrowUpRight aria-hidden="true" className="size-4" /></Link></Drawer>
    </div>;
}
