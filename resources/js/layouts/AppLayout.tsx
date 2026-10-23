import PageHeader from '../components/PageHeader';
import LogoutButton from '../components/LogoutConfirmation';
import NotificationBell, { useNotificationSummary } from '../components/NotificationBell';
import { Head, Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import type { SharedProps, UserRole } from '../types';
import Brand from '../components/Brand';
import StudentLayout from './StudentLayout';
import SidebarToggle, { useSidebarCollapsed } from '../components/SidebarToggle';
import { Notice } from '../components/ui/Form';

interface AppLayoutProps {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
}

const roleLabels: Record<UserRole, string> = { student: 'Student', provider: 'Scholarship provider', admin: 'Administrator' };

export default function AppLayout(props: AppLayoutProps) {
    const { auth } = usePage<SharedProps>().props;
    return auth.user?.role === 'student' ? <StudentLayout {...props} /> : <LegacyAppLayout {...props} />;
}

function LegacyAppLayout({ title, description, actions, children }: AppLayoutProps) {
    const unreadNotifications = useNotificationSummary();
    const { auth, status } = usePage<SharedProps>().props;
    const { collapsed, isDesktop, toggle } = useSidebarCollapsed(auth.user?.role || 'account');
    const [menuOpen, setMenuOpen] = useState(false);
    useEffect(() => {
        if (!menuOpen) return;
        const close = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
                document.querySelector<HTMLButtonElement>('button[aria-controls="account-navigation"]')?.focus();
            }
        };
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, [menuOpen]);
    const currentPath = usePagePath();
    const user = auth.user;
    if (!user) return null;
    const home = '/' + user.role + '/dashboard';
    const navigation = [
        { label: user.role === 'admin' ? 'Provider reviews' : 'Account overview', href: home },
        ...(user.role === 'student' ? [{ label: 'Browse scholarships', href: '/scholarships' }, { label: 'My applications', href: '/student/applications' }, { label: 'Document vault', href: '/student/documents' }, { label: 'Messages', href: '/messages' }, { label: 'My grants', href: '/student/awards' }, { label: 'Renewals', href: '/renewals' }, { label: 'Benefit history', href: '/benefits' }] : []),
        ...(user.role === 'provider' && auth.provider_status === 'approved' ? [{ label: 'Provider workspace', href: '/provider/workspace' }] : []),
    ];
    return <div className="min-h-dvh bg-civic-canvas font-stitch text-civic-ink">
        <Head title={title} />
        <a href="#main-content" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>
        <header className="flex min-h-20 items-center justify-between gap-4 border-b border-slate-200 bg-white px-5 sm:px-8 lg:hidden">
            <Brand /><NotificationBell unread={unreadNotifications} />
            <button type="button" aria-label={menuOpen ? 'Close navigation menu' : 'Open navigation menu'} aria-expanded={menuOpen} aria-controls="account-navigation" onClick={() => setMenuOpen(!menuOpen)} className="flex size-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-civic-navy"><Menu aria-hidden="true" className="size-5" /></button>
        </header>
        <aside id="account-navigation" inert={isDesktop ? collapsed : !menuOpen} aria-hidden={isDesktop ? collapsed : !menuOpen} className={(menuOpen ? 'flex ' : 'hidden ') + 'flex-col overflow-y-auto overscroll-contain scrollbar-hidden border-b border-slate-200 bg-white lg:fixed lg:inset-y-0 lg:left-0 lg:z-40 lg:flex lg:w-64 lg:border-r lg:border-b-0 lg:transition-[translate,opacity,visibility] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none ' + (collapsed ? 'lg:invisible lg:-translate-x-full lg:opacity-0' : 'lg:visible lg:translate-x-0 lg:opacity-100')}>
            <div className="hidden px-7 py-8 lg:block"><Brand /></div>
            <div className="px-5 pt-6 lg:px-7"><p className="text-xs font-semibold uppercase leading-5 tracking-widest text-slate-500">{roleLabels[user.role]}</p></div>
            <nav aria-label="Account navigation" className="space-y-1 px-4 py-4">
                {navigation.map(item => <Link key={item.href} href={item.href} onClick={() => setMenuOpen(false)} aria-current={(currentPath === item.href || currentPath.startsWith(item.href + '/')) ? 'page' : undefined}
                    className={'block rounded-lg px-3 py-3 text-sm font-medium leading-5 focus-visible:outline-2 focus-visible:outline-civic-navy ' + ((currentPath === item.href || currentPath.startsWith(item.href + '/')) ? 'bg-civic-cream text-civic-navy' : 'text-slate-600 hover:bg-slate-50')}>{item.label}</Link>)}
                <Link href="/" className="block rounded-lg px-3 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50">Public home</Link>
            </nav>
            <div className="mt-auto border-t border-slate-200 p-5 lg:p-7">
                <p className="break-words text-sm font-semibold leading-6 text-slate-900">{user.name}</p>
                <p className="mt-1 break-all text-sm leading-5 text-slate-600">{user.email}</p>
                <LogoutButton href="/logout" className="mt-4 min-h-11 rounded text-sm font-semibold text-slate-700 hover:text-civic-navy focus-visible:outline-2 focus-visible:outline-civic-navy">Log out</LogoutButton>
            </div>
        </aside>
        <div className={'transition-[padding-left] duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? '' : 'lg:pl-64')}>
            <header className="hidden h-20 items-center justify-between border-b border-slate-200 bg-white px-10 lg:flex">
                <div className="flex items-center gap-3"><SidebarToggle collapsed={collapsed} onToggle={toggle} controls="account-navigation" /><span className="text-sm text-slate-600">IskoReach / {roleLabels[user.role]}</span></div>
                <NotificationBell unread={unreadNotifications} />
            </header>
            <main id="main-content" className="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-10 lg:px-10">
                <PageHeader title={title} description={description} actions={actions} />
                {status && <Notice>{status}</Notice>}
                {children}
            </main>
        </div>
    </div>;
}

function usePagePath() {
    return usePage().url.split('?')[0];
}
