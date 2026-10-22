import EligibilityCriteria from '../../components/EligibilityCriteria';
import { Link } from '@inertiajs/react';
import type { MonitoredScholarshipDetails, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { ScholarshipStatusBadge, locationLabel, scholarshipDate } from '../../components/ScholarshipDisplay';
import { Badge, Card, EmptyState, StatusBadge, buttonStyles } from '../../components/ui/DesignSystem';

interface ScholarshipDetailsProps extends SharedProps { scholarship: MonitoredScholarshipDetails }
export default function ScholarshipDetails({ scholarship: item, adminNavigation }: ScholarshipDetailsProps) {
    return <AdminLayout title="Scholarship details">
        {adminNavigation && <Link href={adminNavigation.scholarships} className={buttonStyles('outline') + ' mb-6'}>Back to scholarship monitoring</Link>}
        {item.deleted_at && <p role="status" className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">Retained record: removed on {scholarshipDate(item.deleted_at)}. This information is preserved for administrative history.</p>}
        <Card className="rounded-xl p-5 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-4"><h2 className="min-w-0 break-words text-2xl font-bold leading-9 text-civic-navy">{item.title}</h2><ScholarshipStatusBadge status={item.status} /></div><p className="mt-2 break-words text-sm text-civic-muted">{item.provider} · {item.scholarship_type}</p><p className="mt-6 whitespace-pre-wrap break-words text-base leading-7">{item.description}</p>
            <dl className="mt-7 grid gap-5 text-sm sm:grid-cols-2 xl:grid-cols-3">{[
                ['Recorded benefit', item.benefit_amount === null ? 'Not recorded' : item.currency + ' ' + item.benefit_amount],
                ['Academic term', item.academic_term || 'Not recorded'], ['Application opening', scholarshipDate(item.opens_at)], ['Application deadline', scholarshipDate(item.deadline_at)],
                ['Published at', scholarshipDate(item.published_at)], ['Last updated', scholarshipDate(item.updated_at)],
                ['Existing scholars', item.accepts_existing_scholars ? 'Accepted by the recorded rule' : 'Not accepted by the recorded rule'],
                ['Available locations', item.locations.length ? item.locations.map(locationLabel).join('; ') : 'Not specified'], ['Applicant count preview', item.applicant_count.toLocaleString('en-PH')],
            ].map(([label, value]) => <div key={label} className="min-w-0"><dt className="font-semibold text-civic-navy">{label}</dt><dd className="mt-1 break-words leading-6 text-civic-muted">{value}</dd></div>)}</dl>
            <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-civic-panel-strong pt-5"><span className="text-sm text-civic-muted">Provider account:</span><StatusBadge status={item.provider_status} /><Link href={item.provider_url} className={buttonStyles('outline')}>Review provider information</Link></div>
        </Card>
        <div className="mt-6 grid min-w-0 gap-6 xl:grid-cols-2"><Card className="min-w-0 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Recorded eligibility criteria</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Criteria are displayed as recorded. No eligibility decision is made on this page.</p>{item.eligibility_rules === null ? <p className="mt-5 text-sm text-civic-muted">No additional eligibility criteria recorded.</p> : <div className="mt-5 rounded-lg bg-civic-panel p-4 text-sm leading-6"><EligibilityCriteria value={item.eligibility_rules} /></div>}</Card>
            <Card className="min-w-0 rounded-xl p-5 sm:p-6"><h2 className="text-lg font-bold text-civic-navy">Document requirements</h2><div className="mt-5">{item.requirements.length ? <ul className="divide-y divide-civic-panel-strong">{item.requirements.map(requirement => <li key={requirement.id} className="py-4 first:pt-0"><div className="flex flex-wrap justify-between gap-3"><h3 className="break-words text-sm font-bold">{requirement.name}</h3><Badge tone={requirement.required ? 'amber' : 'neutral'}>{requirement.required ? 'Required' : 'Optional'}</Badge></div>{requirement.description && <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-civic-muted">{requirement.description}</p>}<p className="mt-2 break-words text-xs leading-6 text-civic-muted">Stage: {requirement.requirement_stage} · Formats: {requirement.allowed_mime_types?.join(', ') || 'Not specified'} · Size limit: {requirement.max_size_kb === null ? 'Not specified' : requirement.max_size_kb + ' KB'}</p></li>)}</ul> : <EmptyState title="No requirements recorded" description="This record does not yet contain document requirements." />}</div></Card></div>
        <Link href={item.counts_url} className={buttonStyles('outline') + ' mt-5'}>View applicant count breakdown</Link><p className="mt-5 text-sm leading-6 text-civic-muted">Student submission and application-review tools are not available yet. This page has no publication or moderation actions.</p>
    </AdminLayout>;
}
