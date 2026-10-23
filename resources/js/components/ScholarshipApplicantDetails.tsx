import { Link } from '@inertiajs/react';
import type { ApplicantCountSummary, Paginated } from '../types';
import ApplicantCountBreakdown from './ApplicantCountBreakdown';
import { Card, EmptyState } from './ui/DesignSystem';
import { Pagination, Table } from './ui/DataDisplay';

export interface ScholarshipApplicantDetailsProps {
    scholarship: { id: number; title: string; provider?: string };
    summary: ApplicantCountSummary;
    applicants: Paginated<{ id: number; name: string; email: string | null; source: string; status: string; url?: string }>;
}
export default function ScholarshipApplicantDetails({ scholarship, summary, applicants, provider = false }: ScholarshipApplicantDetailsProps & { provider?: boolean }) {
    return <><ApplicantCountBreakdown summary={summary} /><Card><h2 className="mb-4 text-lg font-bold">Applicant records</h2>{applicants.data.length ? <Table rows={applicants.data} rowKey={item => item.id} caption="Applicants" columns={[
        { key:'name',label:'Applicant',render:item => item.url ? <Link href={item.url} className="font-semibold text-civic-teal underline">{item.name}</Link> : item.name },
        { key:'email',label:'Email',render:item => <span className="break-all">{item.email ?? 'Not shared'}</span> },
        { key:'status',label:'Status',render:item => item.status.replaceAll('_',' ') },
    ]} /> : <EmptyState title="No submitted applicants" description="Submitted applications will appear here." />}<Pagination page={applicants} label="Applicant pages" /></Card></>;
}
