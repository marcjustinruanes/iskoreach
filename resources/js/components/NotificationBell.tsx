import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import type { SharedProps } from '../types';
import { focusRing } from './ui/DesignSystem';
export function useNotificationSummary() {
    const { auth, notificationSummary } = usePage<SharedProps>().props;
    const [unread, setUnread] = useState(notificationSummary?.unread ?? 0);
    const generation = useRef(0);
    useEffect(() => { generation.current++; setUnread(notificationSummary?.unread ?? 0); }, [notificationSummary, auth.user?.id]);
    useEffect(() => {
        if (!auth.user) return;
        let stopped = false;
        let busy = false;
        let active: AbortController | null = null;
        let realtime: ReturnType<typeof import('../realtime').startRealtime> | null = null;
        const refresh = async () => {
            if (stopped || busy || document.hidden || !navigator.onLine) return;
            busy = true;
            const requestGeneration = generation.current;
            active = new AbortController();
            try {
                const response = await fetch('/notifications/summary', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: active.signal });
                if (response.ok && response.headers.get('content-type')?.includes('application/json')) {
                    const data = await response.json();
                    if (!stopped && requestGeneration === generation.current && Number.isSafeInteger(data.unread) && data.unread >= 0) setUnread(data.unread);
                }
            } catch { /* Keep the last confirmed count until connectivity returns. */ }
            finally { busy = false; }
        };
        const timer = window.setInterval(() => void refresh(), 15000);
        const wake = () => void refresh();
        document.addEventListener('visibilitychange', wake);
        window.addEventListener('online', wake);
        window.addEventListener('focus', wake);
        if (import.meta.env.VITE_REVERB_ENABLED === 'true') {
            void import('../realtime').then(({ startRealtime }) => {
                if (stopped) return;
                realtime = startRealtime();
                realtime.private('App.Models.User.' + auth.user!.id).listen('.notifications.changed', wake);
            }).catch(() => { /* Polling remains available if realtime cannot start. */ });
        }
        return () => { stopped = true; active?.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', wake); window.removeEventListener('online', wake); window.removeEventListener('focus', wake); realtime?.disconnect(); };
    }, [auth.user?.id]);
    return unread;
}
export default function NotificationBell({ unread }: { unread: number }) {
    return <Link href="/notifications" aria-label={'Notifications: ' + unread + ' unread'} className={'relative inline-flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel ' + focusRing}><Bell aria-hidden="true" className="size-5" />{unread > 0 && <span aria-hidden="true" className="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-civic-teal px-1 text-center text-[10px] font-bold leading-4 text-white">{unread > 99 ? '99+' : unread}</span>}</Link>;
}
