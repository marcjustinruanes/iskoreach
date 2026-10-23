import type { ReactNode } from 'react';
import type { ApplicationDetails, StudentSubmissionProfile } from '../applicationTypes';
import { applicationLabels } from '../applicationTypes';
import { Badge, Card, StatusBadge } from './ui/DesignSystem';
import type { statusMap } from './ui/DesignSystem';
import { scholarshipDate } from './ScholarshipDisplay';

export function SubmissionProfile({ profile }: { profile: StudentSubmissionProfile }) {
    return <dl className="grid gap-4 text-sm sm:grid-cols-2">{Object.entries({ Name: profile.name, School: profile.school, 'School / campus location': [profile.campus_city_name, profile.campus_province_name, profile.campus_region_name].filter(Boolean).join(', ') || null, 'Course or program': profile.course, 'Year level': profile.graduation_year ? 'SHS graduate' : profile.year_level, 'Year graduated': profile.graduation_year }).map(([label, value]) => <div key={label}><dt className="font-semibold">{label}</dt><dd className="mt-1 break-words leading-6 text-civic-muted">{value ?? 'Not provided'}</dd></div>)}</dl>;
}
export default function ApplicationRecordDetails({ application: item, documentsPanel }: { application: ApplicationDetails; documentsPanel?: ReactNode }) {
    return <div className="space-y-6">
        <Card><div className="flex flex-wrap gap-2">{item.applicant_source === 'external' && <Badge>External applicant</Badge>}<StatusBadge status={item.status as keyof typeof statusMap} />{item.low_priority && <Badge tone="amber">Low priority at submission</Badge>}{item.retained && <Badge>Retained application</Badge>}{item.program_retained && <Badge>Program retained</Badge>}</div>
            <h2 className="mt-4 break-words text-xl font-bold leading-7">{item.title}</h2><p className="mt-2 break-words text-sm leading-6 text-civic-muted">{item.provider}</p>{item.applicant_source === 'external' && <p className="mt-2 break-all text-sm">{item.applicant_name} ? {item.applicant_email}</p>}
            <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2"><div><dt className="font-semibold">Saved academic term</dt><dd className="mt-1 break-words">{item.academic_term || 'Not recorded'}</dd></div><div><dt className="font-semibold">Submitted</dt><dd className="mt-1">{item.submitted_at ? scholarshipDate(item.submitted_at) : 'Not submitted'}</dd></div><div><dt className="font-semibold">Last review</dt><dd className="mt-1">{item.reviewed_at ? scholarshipDate(item.reviewed_at) : 'No review recorded'}</dd></div></dl>
            {item.decision_reason && <div className="mt-5 border-t border-civic-panel-strong pt-4"><h3 className="text-sm font-semibold">Latest provider explanation</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{item.decision_reason}</p></div>}
            {item.status === 'accepted' && <p className="mt-4 text-sm leading-6 text-civic-muted">An accepted application is a selection decision. A confirmed scholarship grant is recorded separately.</p>}
        </Card>
        {item.submitted_at && <Card><h2 className="mb-4 text-lg font-bold">Profile shared at submission</h2>{item.student ? <SubmissionProfile profile={item.student} /> : <p className="text-sm leading-6 text-civic-muted">This earlier record has no saved profile snapshot. No profile information has been inferred.</p>}</Card>}
        {item.submitted_at && (documentsPanel ?? <Card><h2 className="mb-4 text-lg font-bold">Submitted documents</h2>{item.documents.length ? <ul className="space-y-3">{item.documents.map(document => <li key={document.id}><p className="break-words text-sm font-semibold">{document.name}</p><p className="text-sm">Review: {document.review_status ?? 'pending'} {document.review_notes}</p><a href={document.download_url} className="inline-flex min-h-11 items-center break-all text-sm text-civic-teal underline">{document.file_name} (version {document.version})</a></li>)}</ul> : <p className="text-sm text-civic-muted">No document files were included with this submission.</p>}</Card>)}
    </div>;
}
