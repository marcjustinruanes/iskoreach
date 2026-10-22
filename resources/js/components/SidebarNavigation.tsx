import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useState } from 'react';
import { focusRing } from './ui/DesignSystem';

export interface SidebarItem {
    label: string;
    href: string;
    icon: LucideIcon;
    count?: number;
}
export interface SidebarGroup {
    label: string;
    icon?: LucideIcon;
    items: SidebarItem[];
    expandable?: boolean;
}

export default function SidebarNavigation({ label, groups, currentPath, collapsed = false, onExpand, onNavigate }: {
    label: string; groups: SidebarGroup[]; currentPath: string; collapsed?: boolean;
    onExpand: () => void; onNavigate: () => void;
}) {
    const id = useId();
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const [previousPath, setPreviousPath] = useState(currentPath);
    if (previousPath !== currentPath) {
        setPreviousPath(currentPath);
        setExpanded({});
    }
    const active = (href: string) => {
        const path = new URL(href, window.location.origin).pathname;
        return currentPath === path || currentPath.startsWith(path + '/');
    };
    const row = 'flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium leading-5 transition-colors motion-reduce:transition-none ' + focusRing;
    const badge = (count = 0) => count > 0 ? <span className="sidebar-nav-copy ml-auto shrink-0 rounded bg-civic-cream px-1.5 text-xs font-semibold text-civic-navy">{count > 99 ? '99+' : count}<span className="sr-only"> unread or pending</span></span> : null;
    return <nav aria-label={label} className="sidebar-navigation space-y-3">{groups.map((group, index) => {
        const selected = group.items.some(item => active(item.href));
        const open = !collapsed && (expanded[group.label] ?? selected);
        const Icon = group.icon || group.items[0].icon;
        const panelId = id + '-' + index;
        return <div key={group.label} className={index ? 'border-t border-white/15 pt-3' : ''}>
            {group.expandable && <button type="button" title={group.label} aria-label={group.label} aria-expanded={open} aria-controls={panelId} onClick={() => {
                if (collapsed) onExpand();
                setExpanded(values => ({ ...values, [group.label]: collapsed || !open }));
            }} className={row + (selected ? ' bg-white/10 text-white' : ' text-slate-200 hover:bg-white/10 hover:text-white')}>
                <Icon aria-hidden="true" className="size-[18px] shrink-0" /><span className="sidebar-nav-copy min-w-0">{group.label}</span>{badge(group.items.reduce((total, item) => total + (item.count || 0), 0))}<ChevronDown aria-hidden="true" className={'sidebar-nav-copy ml-auto size-4 shrink-0 transition-transform motion-reduce:transition-none ' + (open ? 'rotate-180' : '')} />
            </button>}
            <div id={panelId} hidden={group.expandable && !open} className={group.expandable ? 'ml-5 mt-2 space-y-1 border-l border-white/20 pl-2' : 'space-y-1'}>
                {group.items.map(item => <Link key={item.href} href={item.href} aria-label={item.label} title={item.label} aria-current={active(item.href) ? 'page' : undefined} onClick={onNavigate} className={row + (active(item.href) ? ' bg-civic-gold text-civic-navy ring-1 ring-inset ring-civic-gold-soft' : ' text-slate-200 hover:bg-white/10 hover:text-white')}>
                    <item.icon aria-hidden="true" className="size-[18px] shrink-0" /><span className="sidebar-nav-copy min-w-0">{item.label}</span>{badge(item.count)}
                </Link>)}
            </div>
        </div>;
    })}</nav>;
}
