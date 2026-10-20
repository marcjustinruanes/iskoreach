import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { SharedProps } from '../../types';
import type { Choice } from '../../benefitTypes';
import ProviderLayout from '../../layouts/ProviderLayout';
import { Button, Card, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
interface Candidate { id: number; version: number; scholarship: string; student: string; accepts_existing_scholars: boolean; context: { count: number; token: string }; existing_url: string | null }
export default function Create(props: SharedProps & { application: Candidate; terms: Choice[] }) { return <CreateForm key={props.application.context.token} {...props} />; }
function CreateForm({ application: item, terms }: { application: Candidate; terms: Choice[] }) {
 const [open, setOpen] = useState(false);
 const form = useForm({ action: 'award_create', version: item.version, academic_term_id: '', starts_on: '', ends_on: '', reason: '', confirmed: false, conflict_confirmed: false, context_token: item.context.token });
 const request = useRequestFeedback(form.cancel);
 const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.post('/benefits/applications/' + item.id, { onSuccess: () => setOpen(false), onFinish: request.finish }); };
 return <ProviderLayout title="Confirm scholarship award" description="Create a separate confirmed award for this accepted application.">
 <Link href={'/provider/applications/' + item.id} className={buttonStyles('outline') + ' mb-6'}>Back to applicant</Link>
 <Card className="max-w-3xl"><p className="text-xs font-semibold text-civic-muted">Application #{item.id}</p><h2 className="mt-2 break-words text-xl font-bold">{item.scholarship}</h2><p className="mt-3 break-words text-sm leading-6">{item.student}</p>
 {item.existing_url ? <Link href={item.existing_url} className={buttonStyles('primary') + ' mt-5'}>View existing award</Link> : !terms.length ? <p className="mt-5 text-sm leading-6 text-civic-muted">An academic term must exist before an award can be recorded. Term administration is not available here.</p> : <form className="mt-6 space-y-5" onSubmit={event => { event.preventDefault(); setOpen(true); }}>
 <p className="rounded-lg bg-civic-panel p-4 text-sm leading-6 text-civic-muted">This student has {item.context.count} award records marked active. Award dates are checked again when you confirm. This program {item.accepts_existing_scholars ? 'allows existing scholars with acknowledgement.' : 'disallows overlapping awards.'}</p>
 <label className="block text-sm font-semibold">Academic term<select name="academic_term_id" required value={form.data.academic_term_id} onChange={e => form.setData('academic_term_id', e.target.value)} className={'mt-2 min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2 text-base ' + focusRing}><option value="">Choose a term</option>{terms.map(term => <option key={term.id} value={term.id}>{term.label}</option>)}</select></label>
 <div className="grid gap-4 sm:grid-cols-2"><Input label="Award start date (Philippines)" name="starts_on" type="date" required value={form.data.starts_on} onChange={e => form.setData('starts_on', e.target.value)} /><Input label="Award end date (optional)" name="ends_on" type="date" value={form.data.ends_on} onChange={e => form.setData('ends_on', e.target.value)} /></div>
 <label className="block text-sm font-semibold">Award confirmation reason<textarea name="reason" required minLength={5} maxLength={2000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} className={'mt-2 min-h-28 w-full rounded-lg border border-civic-control p-3 text-base ' + focusRing} /></label>
 {item.accepts_existing_scholars && <label className="flex items-start gap-3 text-sm leading-6"><input name="conflict_confirmed" type="checkbox" checked={form.data.conflict_confirmed} onChange={e => form.setData('conflict_confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I acknowledge any overlapping award records and this program’s existing-scholar rule.</label>}
 <Button type="submit">Review award confirmation</Button></form>}
 </Card>
 <Modal open={open} onClose={() => { if (!form.processing) setOpen(false); }} title="Confirm this award?"><form onSubmit={submit} className="space-y-5"><p className="text-sm leading-6 text-civic-muted">The confirmed award will affect future scholarship conflict checks while active. It will remain private until the student consents and publication is approved. The student can read your recorded reason.</p><label className="flex items-start gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I confirm the award, dates, and academic term.</label>
 {Object.entries(form.errors).map(([key, error]) => <p role="alert" key={key} className="text-sm leading-6 text-civic-danger">{error}</p>)}{request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}
 <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed}>Confirm award</Button><Button variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>Cancel</Button><Button variant="outline" onClick={() => router.reload()}>Reload latest</Button></div></form></Modal>
 </ProviderLayout>;
}
