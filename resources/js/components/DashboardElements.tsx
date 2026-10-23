import { Link } from '@inertiajs/react';
import { ArrowUpRight, Check, CircleX, Clock3, FileCheck2, Pencil } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { focusRing } from './ui/DesignSystem';
import type { LucideIcon } from 'lucide-react';

export function DashboardSection({ title, description, action, children, className = '', icon: Icon }: { title: string; description?: string; action?: ReactNode; children: ReactNode; className?: string; icon?: LucideIcon }) {
    const id = useId();
    return <section aria-labelledby={id} className={'min-w-0 rounded-xl border border-neutral-200 bg-white ' + className}>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 sm:px-5"><div className="dashboard-section-heading">{Icon && <span className="dashboard-section-icon"><Icon aria-hidden="true" /></span>}<div><h2 id={id} className={'text-base font-bold text-civic-navy ' + (Icon ? 'has-icon' : '')}>{title}</h2>{description && <p className="mt-1 max-w-prose text-xs leading-5 text-civic-muted">{description}</p>}</div></div>{action}</div>
        <div className="p-4 sm:p-5">{children}</div>
    </section>;
}
export function DashboardLink({ href, children }: { href: string; children: ReactNode }) {
    return <Link href={href} className={'inline-flex min-h-11 items-center gap-1.5 rounded text-xs font-semibold text-civic-navy hover:underline underline-offset-4 ' + focusRing}>{children}<ArrowUpRight aria-hidden="true" className="size-3.5 shrink-0" /></Link>;
}
export function StatusDistribution({ rows, label, empty }: { rows: { label: string; count: number; href: string; color: string }[]; label: string; empty: string }) {
    const [percentages, setPercentages] = useState(false);
    const total = rows.reduce((sum, row) => sum + Number(row.count), 0);
    const icons = [FileCheck2, Clock3, Pencil, Check, CircleX];
    return <div><div role="group" aria-label={label + ' display'} className="status-distribution-tabs mb-5 flex items-center justify-end gap-1 text-xs">{[false, true].map(value => <button key={String(value)} type="button" aria-pressed={percentages === value} onClick={() => setPercentages(value)} className={'min-h-11 rounded-md px-3 font-semibold ' + (percentages === value ? 'bg-civic-panel text-civic-navy ' : 'text-civic-muted hover:bg-civic-canvas ') + focusRing}>{value ? 'Percentages' : 'Counts'}</button>)}</div>
        <ul aria-label={label} className="status-distribution-rows">{rows.map((row, index) => { const Icon = icons[index % icons.length]; return <li key={row.label}><Link href={row.href} className={'group rounded ' + focusRing}><Icon aria-hidden="true" className="status-row-icon" /><span className="font-medium group-hover:underline">{row.label}</span><span className="font-bold tabular-nums" aria-live="polite">{percentages ? Math.round(total ? Number(row.count) / total * 100 : 0) + '%' : Number(row.count).toLocaleString('en-PH')}</span></Link>{total > 0 && <progress aria-label={row.label + ': ' + row.count + ' of ' + total} max={total} value={Number(row.count)} className={'dashboard-distribution block h-1.5 w-full overflow-hidden rounded-sm ' + row.color} />}</li>; })}</ul>
        {!total && <p className="application-overview-empty">{empty}</p>}
        {total > 0 && <p className="mt-5 border-t border-neutral-200 pt-3 text-xs text-civic-muted">{total.toLocaleString('en-PH')} recorded in total. Select a status to view records.</p>}
    </div>;
}
