import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { focusRing } from './ui/DesignSystem';

export function DashboardSection({ title, description, action, children, className = '', icon }: { title: string; description?: string; action?: ReactNode; children: ReactNode; className?: string; icon?: ReactNode }) {
    const id = useId();
    return <section aria-labelledby={id} className={'min-w-0 rounded-xl border border-neutral-200 bg-white ' + className}>
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 px-4 py-3 sm:px-5"><div><h2 id={id} className={'text-base font-bold text-civic-navy ' + (icon ? 'has-icon' : '')}>{icon && <span aria-hidden="true" className="dashboard-section-icon">{icon}</span>}{title}</h2>{description && <p className="mt-1 max-w-prose text-xs leading-5 text-civic-muted">{description}</p>}</div>{action}</div>
        <div className="p-4 sm:p-5">{children}</div>
    </section>;
}
export function DashboardLink({ href, children }: { href: string; children: ReactNode }) {
    return <Link href={href} className={'inline-flex min-h-11 items-center gap-1.5 rounded text-xs font-semibold text-civic-navy hover:underline underline-offset-4 ' + focusRing}>{children}<ArrowUpRight aria-hidden="true" className="size-3.5 shrink-0" /></Link>;
}
export function StatusDistribution({ rows, label, empty }: { rows: { label: string; count: number; href: string; color: string }[]; label: string; empty: string }) {
    const [percentages, setPercentages] = useState(false);
    const total = rows.reduce((sum, row) => sum + Number(row.count), 0);
    return <div className="status-distribution"><div role="group" aria-label={label + ' display'} className="status-distribution-tabs mb-5 grid grid-cols-2 gap-1 rounded-lg bg-civic-panel p-1 text-xs">{[false, true].map(value => <button key={String(value)} type="button" aria-pressed={percentages === value} onClick={() => setPercentages(value)} className={'min-h-9 rounded-md px-3 font-semibold ' + (percentages === value ? 'bg-civic-navy text-white shadow-sm ' : 'text-civic-navy hover:bg-white/70 ') + focusRing}>{value ? 'Percentages' : 'Counts'}</button>)}</div>
        <ul aria-label={label} className="status-distribution-rows space-y-1">{rows.map(row => <li key={row.label}><Link href={row.href} className={'group flex min-h-10 items-center justify-between gap-3 rounded-md border-b border-civic-panel-strong/60 px-1 py-1 text-xs ' + focusRing}><span className="font-medium group-hover:underline">{row.label}</span><span className="font-bold tabular-nums text-civic-navy" aria-live="polite">{percentages ? total ? Math.round(Number(row.count) / total * 100) + '%' : '0%' : Number(row.count).toLocaleString('en-PH')}</span></Link></li>)}</ul>
        {total ? <p className="mt-4 border-t border-neutral-200 pt-3 text-xs text-civic-muted">{total.toLocaleString('en-PH')} recorded in total. Select a status to view records.</p> : <p className="mt-4 border-l-2 border-civic-gold pl-3 text-xs leading-5 text-civic-muted">{empty}</p>}
        {total > 0 && <p className="mt-5 border-t border-neutral-200 pt-3 text-xs text-civic-muted">{total.toLocaleString('en-PH')} recorded in total. Select a status to view records.</p>}
    </div>;
}
