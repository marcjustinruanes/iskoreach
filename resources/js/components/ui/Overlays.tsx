import { Description, Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { focusRing } from './DesignSystem';

interface OverlayProps { open: boolean; onClose: () => void; title: string; description?: string; children: ReactNode; panelClassName?: string }
function Overlay({ open, onClose, title, description, children, drawer, hideScrollbar = false, position = 'right', panelClassName = '' }: OverlayProps & { drawer?: boolean; hideScrollbar?: boolean; position?: 'right' | 'bottom' }) {
    const container = !drawer ? 'items-center justify-center p-4' : position === 'bottom' ? 'items-end justify-center' : 'justify-end';
    const panel = !drawer ? 'max-h-[calc(100dvh-2rem)] max-w-lg rounded-xl p-5 sm:p-6' : position === 'bottom' ? 'max-h-[85dvh] max-w-2xl rounded-t-xl p-6' : 'h-dvh max-w-md p-6';
    return <Dialog open={open} onClose={onClose} className="relative z-[80] font-stitch text-civic-ink">
        <DialogBackdrop transition className="fixed inset-0 bg-civic-navy/45 transition-opacity duration-200 ease-out data-closed:opacity-0 motion-reduce:transition-none" />
        <div className={'fixed inset-0 flex w-screen overflow-y-auto ' + (hideScrollbar ? 'scrollbar-hidden ' : '') + container}>
            <DialogPanel transition className={'relative w-full overflow-y-auto bg-white shadow-civic-overlay transition duration-200 ease-out data-closed:opacity-0 motion-reduce:transition-none ' + (hideScrollbar ? 'scrollbar-hidden ' : '') + panel + ' ' + panelClassName}>
                <div className="mb-5 flex items-start justify-between gap-3"><div><DialogTitle className="text-xl font-bold leading-7 text-civic-navy">{title}</DialogTitle>{description && <Description className="mt-2 text-sm leading-6 text-civic-muted">{description}</Description>}</div>
                    <button type="button" onClick={onClose} aria-label={'Close ' + title} className={'-mt-2 -mr-2 flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-civic-panel ' + focusRing}><X aria-hidden="true" className="size-5" /></button>
                </div>{children}
            </DialogPanel>
        </div>
    </Dialog>;
}
export function Modal(props: OverlayProps) { return <Overlay {...props} />; }
export function Drawer(props: OverlayProps & { hideScrollbar?: boolean; position?: 'right' | 'bottom' }) { return <Overlay {...props} drawer />; }
