import { AdminCard as Card } from '../../components/admin/AdminPanel';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Badge, Button, EmptyState, Input, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';
interface Filters { action: string; type: string; actor: string; record: string; from: string; to: string }
interface Log { id: number; actor: string; actor_reference: string | null; action: string; action_code: string; record: string; occurred_at: string; reason: string | null; transition: { from?: string; to?: string } }
interface Option { value: string; label: string }
interface Props extends SharedProps { filters: Filters; logs: Paginated<Log>; actions: Option[]; types: Option[] }
const selectClass = 'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base ' + focusRing;
export default function ActivityLogs({ filters, logs, actions, types, adminNavigation }: Props) {
    const form = useForm<Filters>(filters);
    if (!adminNavigation) return null;
    const submit = (event: FormEvent) => { event.preventDefault(); form.get(adminNavigation.activity, { preserveScroll: true, preserveState: true }); };
    return <AdminLayout title="Activity logs">
        <Card className="mb-5 p-5 sm:p-6"><form onSubmit={submit} aria-label="Activity log filters" className="space-y-4"><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Select field="action" label="Action" value={form.data.action} options={actions} change={value => form.setData('action', value)} error={form.errors.action} />
            <Select field="type" label="Record type" value={form.data.type} options={types} change={value => form.setData('type', value)} error={form.errors.type} />
            <Input label="Actor account number" name="actor" type="number" min={1} value={form.data.actor} onChange={event => form.setData('actor', event.target.value)} error={form.errors.actor} />
            <Input label="Target record number" name="record" type="number" min={1} value={form.data.record} onChange={event => form.setData('record', event.target.value)} error={form.errors.record} />
            <Input label="From date" name="from" type="date" value={form.data.from} onChange={event => form.setData('from', event.target.value)} error={form.errors.from} />
            <Input label="Through date (inclusive)" name="to" type="date" value={form.data.to} onChange={event => form.setData('to', event.target.value)} error={form.errors.to} />
        </div><div className="admin-form-actions flex flex-wrap gap-3"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={() => router.get(adminNavigation.activity)}>Reset</Button></div></form></Card>
        <Card className="min-w-0 p-5 sm:p-6"><div className="mb-5 flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-lg font-bold text-civic-navy">Recorded activity</h2><p aria-live="polite" className="mt-2 text-sm leading-6 text-civic-muted">{logs.total} matching {logs.total === 1 ? 'event' : 'events'}. Newest first; times use Asia/Manila.</p></div><Badge>Read only</Badge></div>
            {logs.data.length ? <Table rows={logs.data} rowKey={log => log.id} caption="Recorded administrative and related platform activity" columns={[
                { key: 'time', label: 'When', render: log => <time dateTime={log.occurred_at} className="text-xs leading-5 text-civic-muted">{new Date(log.occurred_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</time> },
                { key: 'actor', label: 'Actor', render: log => <div><p className="break-words font-medium">{log.actor}</p>{log.actor_reference && <p className="mt-1 text-xs text-civic-muted">{log.actor_reference}</p>}</div> },
                { key: 'action', label: 'Action', render: log => <span className="font-semibold text-civic-navy">{log.action}</span> },
                { key: 'record', label: 'Target record', render: log => log.record },
                { key: 'context', label: 'Decision context', render: log => <div className="space-y-2">{(log.transition.from || log.transition.to) && <p className="text-xs leading-5 text-civic-muted">{log.transition.from || 'Not recorded'} → {log.transition.to || 'Not recorded'}</p>}{log.reason ? <p className="whitespace-pre-wrap break-words text-sm leading-6 [overflow-wrap:anywhere]">{log.reason}</p> : <p className="text-xs leading-5 text-civic-muted">No decision reason recorded</p>}</div> },
            ]} /> : <EmptyState title="No matching activity" description="Try another filter. Only supported audit events appear here; private record contents are excluded." />}
            <Pagination page={logs} label="Activity log pages" />
        </Card><p className="mt-4 text-xs leading-5 text-civic-muted">Logs cannot be edited or deleted here. Student actors use account references. Unrecognized metadata, private documents, messages, and authentication secrets are excluded.</p>
    </AdminLayout>;
}
function Select({ field, label, value, options, change, error }: { field: string; label: string; value: string; options: Option[]; change: (value: string) => void; error?: string }) {
    return <div><label htmlFor={'activity-' + field} className="mb-2 block text-sm font-semibold">{label}</label><select id={'activity-' + field} name={field} value={value} onChange={event => change(event.target.value)} className={selectClass}><option value="">All</option>{options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select>{error && <p role="alert" className="mt-2 text-sm text-civic-danger">{error}</p>}</div>;
}
