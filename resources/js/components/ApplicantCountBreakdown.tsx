import type { ApplicantCountSummary } from '../types';
import { Card } from './ui/DesignSystem';

export default function ApplicantCountBreakdown({ summary }: { summary: ApplicantCountSummary }) {
    return <div className="mb-6 space-y-4"><div className="grid gap-3 sm:grid-cols-3">{[['Applications',summary.public_count],['Waitlisted',summary.waitlisted_count]].map(([label,value]) => <Card key={String(label)}><p className="text-sm text-civic-muted">{label}</p><p className="mt-2 text-2xl font-bold">{Number(value).toLocaleString('en-PH')}</p></Card>)}</div><p className="text-sm leading-6 text-civic-muted">Totals come from individual submitted application records, including waitlisted and retained submissions. Drafts are excluded. Each email can have one application per scholarship.</p></div>;
}
