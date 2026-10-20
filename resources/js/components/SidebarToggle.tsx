import { useEffect, useState, useSyncExternalStore } from 'react';
import { Menu } from 'lucide-react';
import { focusRing } from './ui/DesignSystem';

function subscribeDesktop(onChange: () => void) {
    const media = window.matchMedia('(min-width: 1024px)');
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
}
const desktopSnapshot = () => window.matchMedia('(min-width: 1024px)').matches;

export function useSidebarCollapsed(scope: string) {
    const isDesktop = useSyncExternalStore(subscribeDesktop, desktopSnapshot, () => false);
    const key = 'iskoreach.sidebar.' + scope;
    const [collapsed, setCollapsed] = useState(() => {
        try { return window.localStorage.getItem(key) === 'collapsed'; } catch { return false; }
    });
    useEffect(() => {
        try { window.localStorage.setItem(key, collapsed ? 'collapsed' : 'expanded'); } catch { /* Navigation remains usable when storage is unavailable. */ }
    }, [collapsed, key]);
    return { collapsed, isDesktop, toggle: () => setCollapsed(value => !value) };
}

export default function SidebarToggle({ collapsed, onToggle, controls }: { collapsed: boolean; onToggle: () => void; controls: string }) {
    return <button type="button" aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'} aria-expanded={!collapsed} aria-controls={controls} onClick={onToggle} className={'hidden size-11 shrink-0 items-center justify-center rounded-lg text-civic-muted hover:bg-civic-panel lg:inline-flex ' + focusRing}><Menu aria-hidden="true" className={'size-5 transition-transform duration-200 ease-out motion-reduce:transition-none ' + (collapsed ? 'scale-90' : 'scale-100')} /></button>;
}
