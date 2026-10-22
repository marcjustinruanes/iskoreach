import { Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, GraduationCap } from 'lucide-react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import type { ApplicationRecord } from '../../applicationTypes';
import { applicationLabels } from '../../applicationTypes';
import AppLayout from '../../layouts/AppLayout';
import { Badge, Button, Card, EmptyState, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { scholarshipDate } from '../../components/ScholarshipDisplay';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';

const tones = { not_started: 'neutral', requirements_pending: 'amber', submitted: 'blue', under_review: 'indigo', accepted: 'green', rejected: 'red', waitlisted: 'amber' } as const;
export default function Applications({ applications, filters }: SharedProps & { applications: Paginated<ApplicationRecord>; filters: { status: string } }) {
    const form = useForm(filters);
    const request = useRequestFeedback(form.cancel);
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.get('/student/applications', { preserveState: true, preserveScroll: true, onFinish: request.finish }); };
    return <AppLayout title="My applications" description="Continue a draft or follow your submitted applications and provider decisions.">
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3"><p className="max-w-xl text-sm leading-6 text-civic-muted">Drafts are private. An accepted application becomes a scholarship award only after a provider confirms the award separately.</p><Link href="/student/scholarships" className={buttonStyles('primary')}><GraduationCap aria-hidden="true" className="size-4" />Browse scholarships</Link></div>
        <Card className="mb-6 p-4 sm:p-5"><form onSubmit={submit} aria-label="Application filters" className="flex flex-wrap items-end gap-3"><div className="w-full sm:max-w-xs"><label htmlFor="application-status" className="mb-2 block text-sm font-semibold">Application status</label><select id="application-status" name="status" value={form.data.status} onChange={e => form.setData('status', e.target.value)} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 text-base ' + focusRing}><option value="">All statuses</option>{Object.entries(applicationLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div><Button type="submit" loading={form.processing}>Apply filter</Button><Button variant="outline" onClick={() => router.get('/student/applications')}>Reset</Button></form>{form.errors.status && <p role="alert" className="mt-3 text-sm text-civic-danger">{form.errors.status}</p>}{request.error && <p role="alert" className="mt-3 text-sm text-civic-danger">{request.error}</p>}</Card>
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2"><h2 className="text-base font-bold">Your application records</h2><p aria-live="polite" className="text-xs text-civic-muted">{applications.total} matching {applications.total === 1 ? 'application' : 'applications'}</p></div>
        {applications.data.length ? <ul aria-label="Your application records" className="grid items-start gap-4 xl:grid-cols-2">{applications.data.map(item => {
            const draft = !item.submitted_at && ['not_started', 'requirements_pending'].includes(item.status);
            return <li key={item.id}><Card className="min-w-0 p-5"><div className="flex flex-col items-start gap-3 sm:flex-row sm:justify-between"><div className="w-full min-w-0 flex-1 sm:w-auto"><p className="text-xs font-semibold text-civic-muted">Application #{item.id}</p><h3 className="mt-2 break-words text-base font-bold"><Link href={'/student/applications/' + item.id} className={'rounded text-civic-navy hover:text-civic-teal ' + focusRing}>{item.title}</Link></h3><p className="mt-2 break-words text-sm leading-6 text-civic-muted">{item.provider}</p></div><Badge tone={tones[item.status as keyof typeof tones] ?? 'neutral'}>{applicationLabels[item.status] ?? item.status}</Badge></div>
                {(item.retained || item.program_retained || item.low_priority) && <div className="mt-3 flex flex-wrap gap-2">{item.retained && <Badge>Retained history</Badge>}{item.program_retained && <Badge>Program retained</Badge>}{item.low_priority && <Badge tone="amber">Low priority at submission</Badge>}</div>}
                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-civic-panel-strong pt-3"><p className="text-xs leading-5 text-civic-muted">{item.submitted_at ? 'Submitted ' + scholarshipDate(item.submitted_at) : 'Draft · Not submitted'}</p><Link href={'/student/applications/' + item.id} className={'inline-flex min-h-11 items-center gap-2 rounded text-xs font-semibold text-civic-teal ' + focusRing}>{draft && !item.retained && !item.program_retained ? 'Continue application' : 'View application'}<ArrowRight aria-hidden="true" className="size-4" /></Link></div>
            </Card></li>;
        })}</ul> : <EmptyState title="No matching applications" description="Start with a scholarship listing to prepare a draft, or change your status filter." action={<Link href="/student/scholarships" className={buttonStyles('outline')}>Find scholarships</Link>} />}
        <Pagination page={applications} label="Your application pages" />
    </AppLayout>;
}