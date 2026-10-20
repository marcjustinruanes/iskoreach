import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { DocumentRequirement, VaultDocument } from '../documentTypes';
import { Badge, Button, Card, buttonStyles, focusRing } from './ui/DesignSystem';
import { useRequestFeedback } from './ui/useRequestFeedback';
export default function ApplicationDocumentChecklist({ applicationId, version, checklist, vault, editable }: { applicationId: number; version: number; checklist: DocumentRequirement[]; vault: VaultDocument[]; editable: boolean }) {
    const required = checklist.filter(row => row.required);
    const ready = required.filter(row => row.complete).length;
    return <Card className="mt-6"><h2 className="text-lg font-bold">Application document checklist</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Attach a vault file to each required item and confirm it is the intended document. Files become shared with this provider when you submit.</p><Link href="/student/documents" className={buttonStyles('outline') + ' mt-4'}>Open my document vault</Link>
        {checklist.length > 0 && <p aria-live="polite" className="mt-4 rounded-lg border border-teal-100 bg-teal-50/60 p-3 text-sm leading-6 text-teal-950">{ready} of {required.length} required files ready. Optional files may be left empty; any file you select must also pass its requirement checks.</p>}
        {checklist.length ? <div className="mt-5 space-y-5">{checklist.map(row => <RequirementRow key={row.id + ':' + version} row={row} version={version} applicationId={applicationId} vault={vault} editable={editable} />)}</div> : <p className="mt-4 text-sm text-civic-muted">No application document requirements are listed.</p>}
    </Card>;
}
function RequirementRow({ row, version, applicationId, vault, editable }: { row: DocumentRequirement; version: number; applicationId: number; vault: VaultDocument[]; editable: boolean }) {
    const [remove, setRemove] = useState(false);
    const form = useForm({ version, document_id: '', confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const url = '/student/applications/' + applicationId + '/requirements/' + row.id;
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; if (remove) form.delete(url, { preserveScroll: true, onFinish: request.finish }); else form.post(url, { preserveScroll: true, onFinish: request.finish }); };
    const available = vault.filter(document => (row.allowed_mime_types ?? []).includes(document.mime_type) && document.size_bytes <= (row.max_size_kb ?? 0) * 1024);
    return <section aria-labelledby={'requirement-' + row.id} className="min-w-0 rounded-lg border border-civic-panel-strong p-4"><div className="flex flex-wrap items-start justify-between gap-3"><h3 id={'requirement-' + row.id} className="break-words font-semibold">{row.name}</h3><Badge tone={row.complete ? 'green' : 'amber'}>{row.complete ? 'Attached and confirmed' : row.required ? 'Required' : 'Optional'}</Badge></div>{row.description && <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-civic-muted">{row.description}</p>}<p className="mt-2 break-words text-xs leading-5 text-civic-muted">Formats: {row.allowed_mime_types?.join(', ') || 'None configured'} · Limit: {row.max_size_kb ?? 0} KB</p>
        {row.attachment && <div className="mt-3"><a href={row.attachment.download_url} className={'inline-flex min-h-11 max-w-full items-center break-all text-sm font-semibold text-civic-teal underline ' + focusRing}>{row.attachment.file_name} (version {row.attachment.version})</a>{!row.complete && <p role="status" className="text-sm text-civic-danger">This attachment is unavailable or does not match the current requirement. Replace or remove it.</p>}</div>}
        {editable && <form onSubmit={submit} className="mt-4 space-y-4">{!remove && <div><label htmlFor={'vault-choice-' + row.id} className="mb-2 block text-sm font-semibold">Choose a compatible vault document</label><select id={'vault-choice-' + row.id} name="document_id" value={form.data.document_id} onChange={e => form.setData('document_id', e.target.value)} className={'min-h-11 w-full min-w-0 rounded-lg border border-civic-control bg-white px-3 text-base ' + focusRing}><option value="">Select a document</option>{available.map(document => <option key={document.id} value={document.id}>{document.label} — {document.original_name}</option>)}</select>{!available.length && <p className="mt-2 text-sm leading-6 text-civic-muted">Upload a compatible file to your vault first, then return here.</p>}</div>}
            <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name="confirmed" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className="mt-1 size-4 shrink-0" />{remove ? 'I confirm removing this draft attachment.' : 'I checked this file and confirm it satisfies this requirement.'}</label>
            {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}{Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}{form.errors.version && <Button variant="outline" onClick={() => router.reload()}>Reload latest record</Button>}
            <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing} disabled={!form.data.confirmed || (!remove && !form.data.document_id)}>{remove ? 'Confirm attachment removal' : row.attachment ? 'Replace attachment' : 'Attach and confirm'}</Button>{row.attachment && <Button variant="outline" disabled={form.processing} onClick={() => { setRemove(!remove); form.setData('confirmed', false); }}>{remove ? 'Cancel removal' : 'Remove attachment'}</Button>}</div>
        </form>}
    </section>;
}
