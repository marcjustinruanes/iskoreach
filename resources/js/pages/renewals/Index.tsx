import { Link, router, useForm } from '@inertiajs/react';
import { CalendarClock, ArrowRight, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import ChatShell from '../../components/ChatShell';
import { Button, Card, EmptyState, Input, StatusBadge, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Modal } from '../../components/ui/Overlays';
import { Pagination } from '../../components/ui/DataDisplay';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
import { scholarshipDate } from '../../components/ScholarshipDisplay';
export interface RenewalSummary { id: number; scholarship: string; award_id: number; student_name?: string | null; term: string; status: 'pending' | 'submitted' | 'approved' | 'rejected'; deadline_at: string | null; submitted_at: string | null; url: string }
interface Choice { id: number; label: string }
const selectClass = 'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2 text-base ' + focusRing;
export default function Index({ auth, renewals, filters, awards, terms, filterTerms }: SharedProps & { renewals: Paginated<RenewalSummary>; filters: { status: string; term: string }; filterTerms: Choice[]; awards: Choice[]; terms: Choice[] }) {
 const provider = auth.user?.role === 'provider';
 const [open, setOpen] = useState(false);
 const form = useForm({ award_id: '', academic_term_id: '', deadline_at: '', confirmed: false });
 const request = useRequestFeedback(form.cancel);
 const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.post('/renewals', { onSuccess: () => setOpen(false), onFinish: request.finish }); };
 return <ChatShell title="Renewals"><div className="renewals-page">
 <div className="mb-5 flex flex-wrap items-end justify-between gap-4 rounded-xl border border-civic-panel-strong bg-white p-4 sm:p-5"><label className="block max-w-full text-sm font-semibold">Status<select aria-label="Renewal status" className={selectClass + ' mt-2'} value={filters.status} onChange={event => router.get('/renewals', { ...filters, status: event.target.value }, { preserveState: true })}><option value="">All statuses</option>{['pending', 'submitted', 'approved', 'rejected'].map(status => <option key={status} value={status}>{status[0].toUpperCase() + status.slice(1)}</option>)}</select></label>
 <label className="block text-sm font-semibold">Academic term<select aria-label="Renewal academic term" className={selectClass + ' mt-2'} value={filters.term} onChange={event => router.get('/renewals', { ...filters, term: event.target.value }, { preserveState: true })}><option value="">All terms</option>{filterTerms.map(term => <option key={term.id} value={term.id}>{term.label}</option>)}</select></label>{provider && <Button disabled={!awards.length || !terms.length} onClick={() => setOpen(true)}><Plus aria-hidden="true" className="size-4" />Open renewal request</Button>}</div>
 {provider && (!awards.length || !terms.length) && <p role="status" className="mb-5 rounded-xl border border-amber-200 bg-amber-50/60 p-4 text-xs leading-5 text-amber-900">To open a request, you need a confirmed active grant and an academic term with a future end date. Review grants in Grants and benefits. Academic-term setup is required if no eligible term is available.</p>}
 <div className="mb-4 flex flex-wrap items-center justify-between gap-2"><h2 className="text-base font-bold text-civic-navy">Renewal requests</h2><p aria-live="polite" className="text-xs text-civic-muted">{renewals.total} matching requests</p></div>{renewals.data.length ? <ul className="grid items-start gap-4 xl:grid-cols-2">{renewals.data.map(item => <li key={item.id}><Card className="p-5"><div className="flex flex-wrap justify-between gap-3"><div className="min-w-0"><p className="text-xs font-semibold text-civic-muted">RN-{item.id} · AW-{item.award_id}</p><h2 className="mt-2 break-words text-base font-bold"><Link href={item.url} className={'rounded text-civic-teal underline ' + focusRing}>{item.scholarship}</Link></h2></div><StatusBadge status={item.status} /></div><p className="mt-3 text-sm leading-6 text-civic-muted">{item.term}{provider && item.student_name ? ' / ' + item.student_name : ''}</p><div className="mt-4 border-t border-civic-panel-strong pt-4"><p className="flex items-start gap-2 text-xs leading-5 text-civic-muted"><CalendarClock aria-hidden="true" className="mt-0.5 size-4 shrink-0" />Deadline: {scholarshipDate(item.deadline_at)} (Philippine time)</p><Link href={item.url} className={'mt-2 inline-flex min-h-11 items-center gap-2 rounded text-xs font-semibold text-civic-teal ' + focusRing}>View renewal<ArrowRight aria-hidden="true" className="size-4" /></Link></div></Card></li>)}</ul> : <EmptyState title="No renewal requests" description="Requests for your confirmed grants will appear here. Try another status filter if needed." />}
 <Pagination page={renewals} label="Renewal pages" />
 <Modal open={open} onClose={() => { if (!form.processing) setOpen(false); }} title="Open a renewal request">
 <form onSubmit={submit} className="space-y-5">
 <p className="text-sm leading-6 text-civic-muted">This request copies the scholarship’s renewal requirements. Its checklist and deadline stay fixed. Approval records a renewal decision and does not extend a grant or release funds.</p>
 <label className="block text-sm font-semibold">Confirmed grant<select name="award_id" required className={selectClass + ' mt-2'} value={form.data.award_id} onChange={e => form.setData('award_id', e.target.value)}><option value="">Choose a grant</option>{awards.map(item => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
 <label className="block text-sm font-semibold">Academic term<select name="academic_term_id" required className={selectClass + ' mt-2'} value={form.data.academic_term_id} onChange={e => form.setData('academic_term_id', e.target.value)}><option value="">Choose a term</option>{terms.map(item => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
 <Input label="Deadline (Philippine time)" name="deadline_at" type="datetime-local" required value={form.data.deadline_at} onChange={e => form.setData('deadline_at', e.target.value)} />
 <label className="flex items-start gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I confirm the grant, term, deadline, and scholarship renewal requirements.</label>
 {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}{request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}
 <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed}>Open request</Button><Button variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>Cancel</Button></div></form></Modal>
 <Link href={provider ? '/provider/dashboard' : '/student/awards'} className={buttonStyles('outline') + ' mt-6'}>{provider ? 'Provider dashboard' : 'My grants'}</Link>
 </div></ChatShell>;
}
