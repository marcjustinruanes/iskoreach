import { LogOut } from 'lucide-react';
import LogoutButton from './LogoutConfirmation';
import { Avatar, focusRing } from './ui/DesignSystem';

export default function SidebarAccount({ user, onSignOut }: { user: { name: string; email: string; photo_url?: string | null }; onSignOut?: () => void }) {
    return <div data-sidebar-account className="shrink-0 border-t border-white/15 bg-civic-navy pt-3 text-white">
        <div className="sidebar-account-identity flex min-w-0 items-center gap-3 px-1 py-2" title={user.name}>
            <Avatar name={user.name} src={user.photo_url ?? undefined} /><div className="sidebar-account-copy min-w-0"><p className="truncate text-sm font-semibold">{user.name}</p><p className="mt-0.5 truncate text-xs text-slate-300">{user.email}</p></div>
        </div>
        <LogoutButton aria-label="Sign out" title="Sign out" onClick={onSignOut} className={'sidebar-sign-out mt-1 flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm font-semibold text-red-300 transition-colors hover:bg-red-400/10 hover:text-red-200 focus-visible:outline-red-300 ' + focusRing}>
            <LogOut aria-hidden="true" className="size-[18px] shrink-0" /><span className="sidebar-account-copy">Sign out</span>
        </LogoutButton>
    </div>;
}
