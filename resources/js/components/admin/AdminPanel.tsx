import type { HTMLAttributes, ReactNode } from 'react';
import { Card } from '../ui/DesignSystem';

export function AdminCard({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    return <Card {...props} className={'admin-panel min-w-0 border-civic-blue-gray [&>h2:first-child]:mb-4 [&>h2:first-child]:border-b [&>h2:first-child]:border-civic-blue-gray [&>h2:first-child]:pb-3 ' + className} />;
}

export function AdminRecordFields({ rows, className = '' }: { rows: { label: string; value: ReactNode }[]; className?: string }) {
    return <dl className={'grid min-w-0 gap-x-6 text-sm sm:grid-cols-2 ' + className}>{rows.map(row => <div key={row.label} className="min-w-0 border-b border-civic-blue-gray py-3"><dt className="text-xs font-semibold text-civic-muted">{row.label}</dt><dd className="mt-1.5 whitespace-pre-wrap break-words leading-6 text-civic-navy [overflow-wrap:anywhere]">{row.value}</dd></div>)}</dl>;
}
