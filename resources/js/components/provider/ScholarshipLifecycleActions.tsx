import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button, Input, focusRing } from '../ui/DesignSystem';
import { Modal } from '../ui/Overlays';
import { useRequestFeedback } from '../ui/useRequestFeedback';
export default function ScholarshipLifecycleActions({ url, version, status, disabled = false }: { url: string; version: number; status: string; disabled?: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ action: 'publish', version, reason: '', confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const actions = [{ value: 'publish', label: 'Publish', target: 'published' }, { value: 'unpublish', label: 'Return to draft', target: 'draft' }, { value: 'close', label: 'Close', target: 'closed' }, { value: 'archive', label: 'Archive', target: 'archived' }, { value: 'remove', label: 'Remove and retain history', target: '' }];
    const selected = actions.find(action => action.value === form.data.action)!;
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.patch(url, { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: request.finish }); };
    return <><div className="flex flex-wrap gap-2">{actions.filter(action => action.target !== status).map(action => <Button key={action.value} size="sm" variant="outline" disabled={disabled} onClick={() => { form.clearErrors(); form.setData({ action: action.value, version, reason: '', confirmed: false }); setOpen(true); }}>{action.label}</Button>)}</div>
        <Modal open={open} onClose={() => { if (!form.processing) setOpen(false); }} title={selected.label + ' scholarship?'} description={form.data.action === 'publish' ? 'This program will become visible in the public scholarship directory. Confirm the dates and information before publishing.' : 'This changes listing availability. Applications and historical records are retained.'}>
            <form onSubmit={submit} className="space-y-4"><Input label="Reason for this change" name="reason" required minLength={5} maxLength={1000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} error={form.errors.reason} />
                <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name="confirmed" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 accent-civic-teal ' + focusRing} />I confirm this scholarship change.</label>
                {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}{Object.entries(form.errors).filter(([field]) => field !== 'reason').map(([field, error]) => <p key={field} role="alert" className="text-sm text-civic-danger">{error}</p>)}
                {form.errors.version && <Button variant="outline" onClick={() => router.reload()}>Reload latest record</Button>}
                <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed}>Confirm change</Button><Button variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>Cancel</Button></div>
            </form>
        </Modal>
    </>;
}
