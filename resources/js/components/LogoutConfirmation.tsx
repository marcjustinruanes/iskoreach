import { createContext, useContext, useState } from 'react';
import type { ComponentPropsWithRef, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { Modal } from './ui/Overlays';
import { Button } from './ui/DesignSystem';
import { useRequestFeedback } from './ui/useRequestFeedback';

const LogoutContext = createContext<((url: string) => void) | null>(null);

export function LogoutConfirmationProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [url, setUrl] = useState('/logout');
    const form = useForm({});
    const request = useRequestFeedback(form.cancel);
    const [attempted, setAttempted] = useState(false);
    const show = (target: string) => { setUrl(target); setAttempted(false); form.clearErrors(); setOpen(true); };
    return <LogoutContext.Provider value={show}>{children}<Modal open={open} onClose={() => { if (!form.processing) setOpen(false); }} title="Would you like to sign out?" description="Save any unsaved changes before signing out." panelClassName="!max-w-sm" ><form onSubmit={event => { event.preventDefault(); setAttempted(true); if (!request.begin()) return; form.post(url, { onSuccess: () => setOpen(false), onFinish: request.finish }); }} aria-busy={form.processing} className="space-y-5">{attempted && request.error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{request.error}</p>}{Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{String(error)}</p>)}<div className="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4"><Button variant="outline" size="sm" data-autofocus disabled={form.processing} onClick={() => setOpen(false)}>Stay signed in</Button><Button type="submit" variant="danger" size="sm" loading={form.processing}>{form.processing ? 'Signing out...' : 'Yes, sign out'}</Button></div></form></Modal></LogoutContext.Provider>;
}

export default function LogoutButton({ href = '/logout', children = 'Log out', onClick, ...props }: ComponentPropsWithRef<'button'> & { href?: string }) {
    const show = useContext(LogoutContext);
    return <button {...props} type="button" onClick={event => { onClick?.(event); if (!event.defaultPrevented) show?.(href); }}>{children}</button>;
}
