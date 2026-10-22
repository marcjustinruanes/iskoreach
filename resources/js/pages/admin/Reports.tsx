import { router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Button, Card, EmptyState, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';
interface Filters { type: string; from: string; to: string }
type Row = { [key: string]: string | number };
interface Props extends SharedProps {
    filters: Filters; definition: { title: string; description: string; columns: { [key: string]: string } };
    options: { value: string; label: string }[]; rows: Paginated<Row>; export_url: string; generated_at: string;
}
const label = (value: string) => value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ');
export default function Reports({ filters, definition, options, rows, export_url, generated_at, adminNavigation }: Props) {
    const form = useForm<Filters>(filters);
    if (!adminNavigation) return null;
    const submit = (event: FormEvent) => { event.preventDefault(); form.get(adminNavigation.reports, { preserveScroll: true, preserveState: true }); };
    return <AdminLayout title="Reports">
        <Card className="mb-6 p-5 sm:p-6"><form onSubmit={submit} aria-label="Report filters" className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr]"><div><label htmlFor="report-type" className="mb-2 block text-sm font-semibold">Report</label><select id="report-type" name="type" value={form.data.type} onChange={event => form.setData('type', event.target.value)} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base ' + focusRing}>{options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select>{form.errors.type && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.type}</p>}</div>
                <Input label="From date" name="from" type="date" required value={form.data.from} onChange={event => form.setData('from', event.target.value)} error={form.errors.from} />
                <Input label="Through date (inclusive)" name="to" type="date" required min={form.data.from} value={form.data.to} onChange={event => form.setData('to', event.target.value)} error={form.errors.to} />
            </div><p className="text-xs leading-5 text-civic-muted">Dates use Asia/Manila. Select up to 366 days. Statuses reflect current records in the selected period, rather than a historical snapshot.</p>
            <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={() => router.get(adminNavigation.reports)}>Reset</Button></div>
        </form></Card>
        <Card className="min-w-0 p-5 sm:p-6"><div className="mb-5 flex flex-wrap items-start justify-between gap-4"><div className="min-w-0 max-w-3xl"><h2 className="text-lg font-bold text-civic-navy">{definition.title}</h2><p className="mt-2 text-sm leading-6 text-civic-muted">{definition.description}</p></div><a href={export_url} download className={buttonStyles('outline')}><Download aria-hidden="true" className="size-4" />Download CSV</a></div>
            <p aria-live="polite" className="mb-4 text-sm leading-6 text-civic-muted">{rows.total} matching summary {rows.total === 1 ? 'row' : 'rows'} · {filters.from} to {filters.to}.</p>
            {rows.data.length ? <Table rows={rows.data} rowKey={row => JSON.stringify(row)} caption={definition.title} columns={Object.entries(definition.columns).map(([key, title]) => ({ key, label: title, render: (row: Row) => <span className={typeof row[key] === 'number' || key === 'amount' ? 'tabular-nums' : 'break-words'}>{typeof row[key] === 'number' ? row[key].toLocaleString('en-PH') : ['role', 'status', 'visibility', 'scope', 'kind'].includes(key) ? label(String(row[key])) : row[key]}</span> }))} /> : <EmptyState title="No records in this reporting period" description="Choose another date range or report. Empty results are not estimates of unrecorded activity." />}
            <Pagination page={rows} label="Report summary pages" />
        </Card><p className="mt-4 text-xs leading-5 text-civic-muted">CSV includes every matching summary row using the applied filters. No individual student details are exported. Generated {new Date(generated_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })} (Asia/Manila).</p>
    </AdminLayout>;
}
