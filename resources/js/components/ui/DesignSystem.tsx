import { useId, useState } from 'react';
import type { ButtonHTMLAttributes, HTMLAttributes, InputHTMLAttributes, ReactNode } from 'react';
import { AlertCircle, Check, CheckCircle2, Circle, Clock3, Info, LoaderCircle, SearchX, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-civic-teal';
export const motion = 'transition duration-200 ease-out motion-reduce:transition-none motion-reduce:transform-none';
export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'outline';
const variants: Record<ButtonVariant, string> = {
    primary: 'bg-civic-navy text-white hover:bg-civic-navy-soft',
    secondary: 'border border-civic-border bg-white text-civic-navy hover:bg-civic-panel',
    ghost: 'text-civic-navy hover:bg-civic-panel',
    danger: 'bg-civic-danger text-white hover:bg-red-950',
    outline: 'border border-civic-border bg-white text-civic-ink hover:bg-civic-panel',
};
export function buttonStyles(variant: ButtonVariant = 'primary', size: 'sm' | 'md' | 'lg' = 'md') {
    return ['inline-flex items-center justify-center gap-2 rounded-lg text-sm font-semibold leading-5 active:translate-y-px motion-reduce:active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50', focusRing, motion, variants[variant], size === 'lg' ? 'min-h-12 px-6 py-3.5' : size === 'sm' ? 'min-h-11 px-3 py-2' : 'min-h-11 px-5 py-3'].join(' ');
}
export function Button({ variant = 'primary', size = 'md', loading = false, children, className = '', disabled, type = 'button', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: 'sm' | 'md' | 'lg'; loading?: boolean }) {
    return <button {...props} type={type} disabled={disabled || loading} aria-busy={loading || undefined} className={buttonStyles(variant, size) + ' ' + className}>
        {loading && <LoaderCircle aria-hidden="true" className="size-4 animate-spin motion-reduce:animate-none" />}{children}
    </button>;
}
export function Input({ label, error, hint, id, className = '', prefix, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; hint?: string; prefix?: string }) {
    const generatedId = useId(); const fieldId = id || generatedId;
    return <div><label htmlFor={fieldId} className="mb-2 block text-sm font-semibold leading-5 text-civic-ink">{label}</label>
        <div className="relative">{prefix && <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center border-r border-civic-border px-3 text-base text-civic-ink">{prefix}</span>}<input {...props} id={fieldId} aria-invalid={Boolean(error)} aria-describedby={error || hint ? fieldId + '-help' : undefined} className={'min-h-11 w-full rounded-lg border bg-white py-2.5 text-base leading-6 text-civic-ink focus:border-civic-teal focus:outline-2 focus:outline-offset-2 focus:outline-civic-teal disabled:bg-civic-panel ' + (prefix ? 'pl-16 pr-3.5 ' : 'px-3.5 ') + (error ? 'border-civic-danger bg-red-50/30 ' : 'border-civic-border ') + className} /></div>
        {(error || hint) && <p id={fieldId + '-help'} role={error ? 'alert' : undefined} className={'mt-2 text-sm leading-6 ' + (error ? 'text-civic-danger' : 'text-civic-muted')}>{error || hint}</p>}
    </div>;
}
export function Card({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div {...props} className={'civic-card rounded-lg border border-neutral-200 bg-white p-4 sm:p-5 ' + className} />;
}
export type BadgeTone = 'neutral' | 'blue' | 'indigo' | 'green' | 'amber' | 'red' | 'purple';
export const badgeColors: Record<BadgeTone, string> = {
    neutral: 'border-slate-200 bg-slate-100 text-slate-700', blue: 'border-blue-200 bg-blue-50 text-blue-800',
    indigo: 'border-blue-200 bg-blue-50 text-civic-navy', green: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    amber: 'border-amber-200 bg-amber-50 text-amber-900', red: 'border-red-200 bg-red-50 text-red-800',
    purple: 'border-amber-200 bg-amber-50 text-amber-900',
};
export function Badge({ tone = 'neutral', icon: Icon = Circle, children }: { tone?: BadgeTone; icon?: LucideIcon; children: ReactNode }) {
    return <span className={'inline-flex max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-semibold leading-4 ' + badgeColors[tone]}><Icon aria-hidden="true" className="size-3 shrink-0" /><span>{children}</span></span>;
}
export const statusMap = {
    not_started: { label: 'Not started', tone: 'neutral', icon: Circle },
    requirements_pending: { label: 'Requirements pending', tone: 'amber', icon: Clock3 },
    submitted: { label: 'Submitted', tone: 'blue', icon: CheckCircle2 },
    under_review: { label: 'Under review', tone: 'indigo', icon: SearchX },
    accepted: { label: 'Accepted', tone: 'green', icon: CheckCircle2 },
    rejected: { label: 'Rejected', tone: 'red', icon: X },
    waitlisted: { label: 'Waitlisted', tone: 'purple', icon: Clock3 },
    pending: { label: 'Pending', tone: 'neutral', icon: Clock3 },
    processing: { label: 'Processing', tone: 'amber', icon: Clock3 },
    released: { label: 'Released', tone: 'green', icon: CheckCircle2 },
    due_soon: { label: 'Due soon', tone: 'amber', icon: Clock3 },
    overdue: { label: 'Overdue', tone: 'red', icon: AlertCircle },
    approved: { label: 'Approved', tone: 'green', icon: CheckCircle2 },
    suspended: { label: 'Suspended', tone: 'amber', icon: AlertCircle },
    delayed: { label: 'Delayed', tone: 'amber', icon: AlertCircle },
    failed: { label: 'Failed', tone: 'red', icon: AlertCircle },
    low_priority: { label: 'Low priority', tone: 'amber', icon: AlertCircle },
} satisfies Record<string, { label: string; tone: BadgeTone; icon: LucideIcon }>;
export function StatusBadge({ status }: { status: keyof typeof statusMap }) {
    const value = statusMap[status];
    return <Badge tone={value.tone} icon={value.icon}>{value.label}</Badge>;
}
export function ProgressBar({ value, max = 100, label }: { value: number; max?: number; label: string }) {
    const id = useId(); const safeMax = Math.max(1, max); const safeValue = Math.max(0, Math.min(value, safeMax));
    return <div className="w-full"><div className="mb-2 flex justify-between gap-3 text-xs leading-5 text-civic-muted"><span id={id}>{label}</span><span className="tabular-nums">{Math.round(safeValue / safeMax * 100)}%</span></div>
        <progress aria-labelledby={id} value={safeValue} max={safeMax} className="block h-2 w-full overflow-hidden rounded-full border-0 bg-civic-panel text-civic-teal [&::-webkit-progress-bar]:rounded-full [&::-webkit-progress-bar]:bg-civic-panel [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-civic-teal [&::-moz-progress-bar]:bg-civic-teal" />
    </div>;
}
export function Stepper({ steps, current }: { steps: string[]; current: number }) {
    return <ol aria-label="Application steps" className="flex flex-wrap gap-4">{steps.map((step, index) => <li key={step} aria-current={index === current ? 'step' : undefined} className="flex items-center gap-2 text-sm">
        <span className={'flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ' + (index <= current ? 'bg-civic-teal text-white' : 'bg-civic-panel text-civic-muted')}>{index < current ? <Check className="size-4" aria-hidden="true" /> : index + 1}</span>
        <span className={index === current ? 'font-semibold text-civic-ink' : 'text-civic-muted'}>{step}{index < current && <span className="sr-only">, completed</span>}</span>
    </li>)}</ol>;
}
export function Avatar({ name, src }: { name: string; src?: string }) {
    const [failed, setFailed] = useState(false);
    const initials = name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase();
    return src && !failed ? <img src={src} onError={() => setFailed(true)} alt={name} className="size-10 rounded-full object-cover" /> : <span role="img" aria-label={name} className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-civic-panel text-sm font-semibold text-civic-navy">{initials || '?'}</span>;
}
export function Skeleton({ className = '' }: { className?: string }) {
    return <div aria-hidden="true" className={'animate-pulse rounded-lg bg-neutral-200 motion-reduce:animate-none ' + className} />;
}
export function EmptyState({ title, description, action, className = '' }: { title: string; description: string; action?: ReactNode; className?: string }) {
    return <div className={'rounded-lg border border-dashed border-civic-border bg-civic-canvas/50 px-4 py-5 text-center ' + className}>
        <span className="mx-auto mb-2 flex size-9 items-center justify-center rounded-full bg-civic-panel"><SearchX aria-hidden="true" className="size-5 text-civic-teal" /></span>
        <h3 className="text-base font-semibold text-civic-navy">{title}</h3><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-civic-muted">{description}</p>{action && <div className="mt-5">{action}</div>}
    </div>;
}
export function Toast({ message, tone = 'green', onDismiss }: { message: string; tone?: BadgeTone; onDismiss: () => void }) {
    return <div role={tone === 'red' ? 'alert' : 'status'} aria-atomic="true" className={'flex max-w-sm items-center gap-3 rounded-lg border p-3 text-sm shadow-civic-raised ' + badgeColors[tone]}><Info aria-hidden="true" className="size-5 shrink-0" /><span>{message}</span><button type="button" onClick={onDismiss} aria-label="Dismiss notification" className={'ml-auto flex size-11 shrink-0 items-center justify-center rounded-lg ' + focusRing}><X aria-hidden="true" className="size-4" /></button></div>;
}
