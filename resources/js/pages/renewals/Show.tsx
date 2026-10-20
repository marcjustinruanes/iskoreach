import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { SharedProps } from '../../types';
import type { RenewalSummary } from './Index';
import ChatShell from '../../components/ChatShell';
import { Button, Card, EmptyState, StatusBadge, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
import { scholarshipDate } from '../../components/ScholarshipDisplay';
interface Vault { id: number; label: string; mime_type: string; size_bytes: number }
interface Rule { index: number; name: string; description: string | null; required: boolean; allowed_mime_types: string[]; max_size_kb: number; complete: boolean; attachment: { id: number; file_name: string; version: number; download_url: string } | null }
interface Renewal extends RenewalSummary { version: number; eligible: boolean; checklist_recorded: boolean; checklist: Rule[]; notes: string | null; history: { event: string; at: string; reason: string | null }[] }
export default function Show(props: SharedProps & { renewal: Renewal; documents: Vault[] }) { return <RenewalDetails key={props.renewal.id + ':' + props.renewal.version} {...props} />; }
function RenewalDetails({ auth, renewal, documents }: SharedProps & { renewal: Renewal; documents: Vault[] }) {
 const student = auth.user?.role === 'student';
 const pending = renewal.status === 'pending';
 const editable = student && pending && renewal.eligible && renewal.checklist_recorded && !!renewal.deadline_at && new Date(renewal.deadline_at).getTime() > Date.now();
 const [action, setAction] = useState<'submit' | 'approved' | 'rejected' | null>(null);
 const form = useForm({ action: '', version: renewal.version, confirmed: false, reason: '' });
 const request = useRequestFeedback(form.cancel);
 const select = (value: 'submit' | 'approved' | 'rejected') => { form.setData({ action: value, version: renewal.version, confirmed: false, reason: '' }); setAction(value); };
 const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.patch(renewal.url, { preserveScroll: true, onSuccess: () => setAction(null), onFinish: request.finish }); };
 const complete = renewal.checklist.every(rule => !(rule.required || rule.attachment) || rule.complete);
 return <ChatShell title={'Renewal RN-' + renewal.id} description="Review the request, document checklist, and recorded decisions.">
 <Link href="/renewals" className={buttonStyles('outline') + ' mb-6'}>All renewals</Link>
 <div className="space-y-5"><Card><div className="flex flex-wrap justify-between gap-3"><div className="min-w-0"><p className="text-xs font-semibold text-civic-muted">AW-{renewal.award_id}</p><h2 className="mt-2 break-words text-xl font-bold">{renewal.scholarship}</h2><p className="mt-2 text-sm leading-6 text-civic-muted">{renewal.term}</p></div><StatusBadge status={renewal.status} /></div><p className="mt-4 text-sm leading-6">Deadline: {scholarshipDate(renewal.deadline_at)} (Philippine time)</p><p className="mt-3 text-sm leading-6 text-civic-muted">A renewal approval records the decision. Award duration and benefit releases are managed separately.</p>
 {!renewal.eligible && <p role="status" className="mt-4 text-sm leading-6 text-civic-danger">This award is currently ineligible for renewal changes. Recorded history remains available.</p>}
 {!renewal.checklist_recorded && <p role="status" className="mt-4 text-sm leading-6 text-civic-muted">This earlier record has no saved requirement checklist. Submission is unavailable.</p>}
 {pending && student && !editable && renewal.eligible && renewal.checklist_recorded && <p role="status" className="mt-4 text-sm text-civic-danger">The deadline has passed or is unavailable. This request cannot be submitted.</p>}
 {editable && <Button className="mt-5" disabled={!complete} onClick={() => select('submit')}>Review and submit renewal</Button>}
 {editable && <p role="status" className="mt-3 text-sm leading-6 text-civic-muted">{renewal.checklist.filter(rule => rule.required && rule.complete).length} of {renewal.checklist.filter(rule => rule.required).length} required files ready. {complete ? 'Review your selected files before submitting.' : 'Attach required files and replace or remove any unavailable optional files to continue.'}</p>}
 {!student && renewal.status === 'submitted' && renewal.eligible && <div className="mt-5 flex flex-wrap gap-3"><Button onClick={() => select('approved')}>Approve renewal</Button><Button variant="danger" onClick={() => select('rejected')}>Reject renewal</Button></div>}
 </Card>
 <Card><div className="mb-5 flex flex-wrap justify-between gap-3"><h2 className="text-lg font-bold">Renewal requirements</h2>{editable && <Link href="/student/documents" className={'text-sm font-semibold text-civic-teal underline ' + focusRing}>Open document vault</Link>}</div>
 {!student && pending ? <p className="text-sm leading-6 text-civic-muted">Student draft files remain private until submission.</p> : renewal.checklist.length ? <div className="space-y-6">{renewal.checklist.map(rule => <Requirement key={rule.index} rule={rule} documents={documents} renewal={renewal} editable={editable} />)}</div> : <EmptyState title="No document requirements recorded" description="This renewal has no files to attach." />}</Card>
 <Card><h2 className="text-lg font-bold">Recorded history</h2>{renewal.history.length ? <ol className="mt-4 space-y-4">{renewal.history.map((event, index) => <li key={index} className="border-l-2 border-civic-panel-strong pl-4"><p className="text-sm font-semibold capitalize">{event.event.replaceAll('_', ' ')}</p><p className="mt-1 text-xs leading-5 text-civic-muted">{scholarshipDate(event.at)}</p>{event.reason && <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{event.reason}</p>}</li>)}</ol> : <p className="mt-4 text-sm text-civic-muted">No workflow history recorded.</p>}{renewal.notes && !renewal.history.length && <p className="mt-4 whitespace-pre-wrap break-words text-sm leading-6">{renewal.notes}</p>}</Card></div>
 <Modal open={action !== null} onClose={() => { if (!form.processing) setAction(null); }} title={action === 'submit' ? 'Submit this renewal?' : action === 'approved' ? 'Approve this renewal?' : 'Reject this renewal?'}>
 <form onSubmit={submit} className="space-y-5"><p className="text-sm leading-6 text-civic-muted">{student ? 'Your selected document versions will be frozen and shared with this provider. You cannot replace them after submission.' : 'This decision is final in this workflow. It does not change award dates or release funds.'}</p>
 {!student && <label className="block text-sm font-semibold">Decision reason<textarea name="reason" required maxLength={2000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} className={'mt-2 min-h-28 w-full rounded-lg border border-civic-control p-3 text-base ' + focusRing} /></label>}
 <label className="flex items-start gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />{student ? 'I confirm my files and agree to share them for this renewal.' : 'I confirm this decision and the recorded reason.'}</label>
 {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}{request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}
 <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed}>Confirm {action}</Button><Button variant="outline" disabled={form.processing} onClick={() => setAction(null)}>Cancel</Button><Button variant="outline" onClick={() => router.reload()}>Reload latest</Button></div></form></Modal>
 </ChatShell>;
}
function Requirement({ rule, documents, renewal, editable }: { rule: Rule; documents: Vault[]; renewal: Renewal; editable: boolean }) {
 const form = useForm({ version: renewal.version, action: 'attach', requirement_index: rule.index, document_id: '', confirmed: false });
 const request = useRequestFeedback(form.cancel);
 const [remove, setRemove] = useState(false);
 const choices = documents.filter(file => rule.allowed_mime_types.includes(file.mime_type) && file.size_bytes <= Math.min(rule.max_size_kb, 10240) * 1024);
 const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.patch(renewal.url, { preserveScroll: true, onFinish: request.finish }); };
 return <section className="min-w-0 border-t border-civic-panel-strong pt-5 first:border-0 first:pt-0"><div className="flex flex-wrap justify-between gap-2"><h3 className="break-words text-sm font-bold">{rule.name} · {rule.required ? 'Required' : 'Optional'}</h3>{editable && <span className="text-xs font-semibold text-civic-muted">{rule.complete ? 'Ready' : rule.required || rule.attachment ? 'File needed' : 'Optional'}</span>}</div>{rule.description && <p className="mt-2 break-words text-sm leading-6 text-civic-muted">{rule.description}</p>}<p className="mt-2 text-xs leading-5 text-civic-muted">{rule.allowed_mime_types.join(', ')} · up to {rule.max_size_kb} KB</p>
 {rule.attachment && <p className="mt-3 break-words text-sm leading-6"><a href={rule.attachment.download_url} className={'rounded text-civic-teal underline ' + focusRing}>{rule.attachment.file_name}</a> · version {rule.attachment.version}</p>}
 {editable && <form onSubmit={submit} className="mt-4 space-y-3"><label className="block text-sm font-semibold">Vault file<select aria-label={'Vault file for ' + rule.name} value={form.data.document_id} onChange={e => { setRemove(false); form.setData({ ...form.data, action: 'attach', document_id: e.target.value, confirmed: false }); }} className={'mt-2 min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2 text-base ' + focusRing}><option value="">Choose a compatible file</option>{choices.map(file => <option key={file.id} value={file.id}>{file.label}</option>)}</select></label>{!choices.length && <p className="text-sm leading-6 text-civic-muted">Upload a compatible file to your vault first.</p>}
 <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />{remove ? 'I confirm removing this draft attachment.' : 'I confirm this file meets the requirement.'}</label>
 <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed || (!remove && !form.data.document_id)}>{remove ? 'Confirm removal' : rule.attachment ? 'Replace attachment' : 'Attach file'}</Button>{rule.attachment && <Button variant="outline" onClick={() => { setRemove(true); form.setData({ ...form.data, action: 'remove', confirmed: false }); }}>Remove attachment</Button>}</div>
 {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}{request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}</form>}
 </section>;
}
