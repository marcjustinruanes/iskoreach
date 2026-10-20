import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { SharedProps, Paginated } from '../types';
import AppLayout from '../layouts/AppLayout';
import ProviderLayout from '../layouts/ProviderLayout';
import AdminLayout from '../layouts/AdminLayout';
import { Badge, Button, Card, EmptyState, buttonStyles, focusRing } from '../components/ui/DesignSystem';
import { Pagination } from '../components/ui/DataDisplay';
import { Modal } from '../components/ui/Overlays';
import { scholarshipDate } from '../components/ScholarshipDisplay';
import { useRequestFeedback } from '../components/ui/useRequestFeedback';
interface Notice { id: string; title: string; message: string; url: string | null; read_at: string | null; created_at: string }
export default function Notifications({ auth, notifications, filter }: SharedProps & { notifications: Paginated<Notice>; filter: string }) {
    const Shell = auth.user?.role === 'admin' ? AdminLayout : auth.user?.role === 'provider' ? ProviderLayout : AppLayout;
    const [allOpen, setAllOpen] = useState(false);
    const form = useForm({ confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const read = (notice: Notice) => { if (!request.begin()) return; form.patch('/notifications/' + notice.id + '/read', { preserveScroll: true, onFinish: request.finish }); };
    return <Shell title="Notifications" description="Review recorded updates for your account. Open the linked record to check its current status.">
        <Card className="mb-6"><div className="flex flex-wrap items-center justify-between gap-4"><nav aria-label="Notification filters" className="flex flex-wrap gap-2">{['all', 'unread', 'read'].map(value => <a key={value} href={'/notifications?filter=' + value} aria-current={filter === value ? 'page' : undefined} onClick={e => { e.preventDefault(); router.get('/notifications', { filter: value }); }} className={buttonStyles(filter === value ? 'primary' : 'outline', 'sm')}>{value.charAt(0).toUpperCase() + value.slice(1)}</a>)}</nav><Button variant="outline" onClick={() => { form.reset(); form.clearErrors(); setAllOpen(true); }}>Mark all as read</Button></div><p className="mt-4 text-sm leading-6 text-civic-muted">Unread badges refresh while you are online and this tab is visible. Opening a record does not automatically mark its notice as read.</p></Card>
        {request.error && <p role="alert" className="mb-5 text-sm text-civic-danger">{request.error}</p>}
        {notifications.data.length ? <ul className="space-y-4">{notifications.data.map(notice => <li key={notice.id}><Card><div className="flex flex-wrap items-start justify-between gap-3"><h2 className="text-lg font-bold">{notice.title}</h2><Badge tone={notice.read_at ? 'neutral' : 'blue'}>{notice.read_at ? 'Read' : 'Unread'}</Badge></div><p className="mt-3 text-sm leading-6">{notice.message}</p><p className="mt-2 text-xs leading-5 text-civic-muted">{scholarshipDate(notice.created_at)}</p><div className="mt-4 flex flex-wrap gap-3">{notice.url ? <a href={notice.url} onClick={e => { e.preventDefault(); router.visit(notice.url!); }} className={buttonStyles('outline')}>Open record</a> : <p className="text-sm leading-6 text-civic-muted">This record is currently unavailable to your account.</p>}{!notice.read_at && <Button variant="ghost" disabled={form.processing} onClick={() => read(notice)}>Mark as read</Button>}</div></Card></li>)}</ul> : <EmptyState title="No matching notifications" description="Your account updates will appear here. Try another filter if you have already read them." />}
        <Pagination page={notifications} label="Notification pages" />
        <Modal open={allOpen} onClose={() => { if (!form.processing) setAllOpen(false); }} title="Mark all notifications as read?" description="This changes only your account’s current notifications. Records remain in your inbox."><form className="space-y-4" onSubmit={e => { e.preventDefault(); if (!request.begin()) return; form.patch('/notifications/read-all', { preserveScroll: true, onSuccess: () => setAllOpen(false), onFinish: request.finish }); }}><label className="flex items-start gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I confirm marking my current notifications as read.</label>{Object.values(form.errors).map(error => <p key={error} role="alert" className="text-sm text-civic-danger">{error}</p>)}{request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}<div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed}>Confirm mark all read</Button><Button variant="outline" disabled={form.processing} onClick={() => setAllOpen(false)}>Cancel</Button></div></form></Modal>
    </Shell>;
}
