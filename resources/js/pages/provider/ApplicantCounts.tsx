import { Link } from '@inertiajs/react';
import { FileCheck2, UsersRound, ChartNoAxesCombined, ArrowRight } from 'lucide-react';
import type { ApplicantCountSummary, Paginated, SharedProps } from '../../types';
import ProviderLayout from '../../layouts/ProviderLayout';
import { Card, EmptyState, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';
interface Props extends SharedProps { scholarships: Paginated<{ id: number; title: string; show_url: string; summary: ApplicantCountSummary }> }
export default function ApplicantCounts({ scholarships }: Props) {
    return <ProviderLayout title="Applicant counts" description="Keep scholarship totals accurate with verified external application reports." actions={<Link href="/provider/scholarships" className={buttonStyles('outline')}>My scholarships<ArrowRight aria-hidden="true" className="size-4" /></Link>}>
        <div className="mb-5 grid gap-3 md:grid-cols-3">{[
            { title: 'Internal submissions', description: 'Submitted student applications are counted automatically. Drafts are excluded.', icon: UsersRound },
            { title: 'Verified external reports', description: 'Only valid admin-verified reports are included. Pending reports stay separate.', icon: FileCheck2 },
            { title: 'Combined total', description: 'Open a scholarship to see its breakdown and report external applicants.', icon: ChartNoAxesCombined },
        ].map(item => <div key={item.title} className="flex min-w-0 items-start gap-3 rounded-xl border border-civic-panel-strong bg-white p-4"><item.icon aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-civic-teal" /><div><h2 className="text-xs font-bold text-civic-navy">{item.title}</h2><p className="mt-1 text-xs leading-5 text-civic-muted">{item.description}</p></div></div>)}</div>
        <Card className="min-w-0 p-5 sm:p-6"><div className="mb-5 flex flex-wrap items-center justify-between gap-2"><h2 className="text-base font-bold text-civic-navy">Scholarship count breakdowns</h2><p className="text-xs text-civic-muted">{scholarships.total} scholarship records</p></div>{scholarships.data.length ? <Table rows={scholarships.data} rowKey={item => item.id} caption="Your scholarship applicant counts" columns={[
            { key: 'title', label: 'Scholarship', render: item => <Link href={item.show_url} className={'break-words rounded font-semibold text-civic-teal underline underline-offset-4 ' + focusRing}>{item.title}</Link> },
            { key: 'internal', label: 'Internal submitted', render: item => <span className="tabular-nums">{item.summary.internal_count.toLocaleString('en-PH')}</span> },
            { key: 'reported', label: 'Latest external report', render: item => item.summary.reported_external_count?.toLocaleString('en-PH') ?? <span className="text-xs text-civic-muted">No report</span> },
            { key: 'verified', label: 'Verified external included', render: item => <span className="tabular-nums">{item.summary.verified_external_count.toLocaleString('en-PH')}</span> },
            { key: 'total', label: 'Total preview', render: item => <span className="inline-flex min-w-10 justify-center rounded-md bg-teal-50 px-2.5 py-1 text-sm font-bold tabular-nums text-teal-900">{item.summary.public_count.toLocaleString('en-PH')}</span> },
        ]} /> : <EmptyState title="No scholarship records yet" description="Create a draft in My scholarships. Its application count breakdown will appear here." />}<Pagination page={scholarships} label="Your scholarship count pages" /></Card>
    </ProviderLayout>;
}
