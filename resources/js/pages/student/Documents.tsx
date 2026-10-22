import { Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import type { VaultDocument } from '../../documentTypes';
import AppLayout from '../../layouts/AppLayout';
import { Button, Card, EmptyState, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
interface Props extends SharedProps { documents: Paginated<VaultDocument>; filters: { q: string }; upload_limit_kb: number }
export default function Documents({ documents, filters, upload_limit_kb }: Props) {
    const form = useForm<{ label: string; file: File | null }>({ label: '', file: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const request = useRequestFeedback(form.cancel);
    const search = useForm(filters);
    const [remove, setRemove] = useState<VaultDocument | null>(null);
    const removal = useForm({ confirmed: false });
    const removalRequest = useRequestFeedback(removal.cancel);
    const upload = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.file && form.data.file.size > upload_limit_kb * 1024) { form.setError('file', 'Choose a file within the current upload limit.'); return; }
        if (!request.begin()) return;
        form.post('/student/documents', { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); if (fileInput.current) fileInput.current.value = ''; }, onFinish: request.finish });
    };
    return <AppLayout title="My document vault" description="Keep reusable documents private and choose which files to share with each scholarship application.">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-civic-blue-gray bg-civic-panel p-4"><p className="max-w-2xl text-sm leading-6 text-civic-navy">Upload here, then attach and confirm each file in your application or renewal checklist. Uploading alone does not submit a requirement.</p><div className="flex flex-wrap gap-2"><Link href="/student/applications" className={buttonStyles('outline', 'sm')}>My applications</Link><Link href="/renewals" className={buttonStyles('outline', 'sm')}>Renewal checklists</Link></div></div>
        <div className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
        <Card className="mb-6"><h2 className="text-lg font-bold">Upload a document</h2><p className="mt-2 mb-5 text-sm leading-6 text-civic-muted">PDF, JPG, or PNG. Current upload limit: {Math.round(upload_limit_kb / 1024 * 10) / 10} MB per file. Uploading a new file creates a separate entry.</p>
            <form onSubmit={upload} className="space-y-5" noValidate><Input label="Document label" name="label" maxLength={120} value={form.data.label} onChange={e => form.setData('label', e.target.value)} error={form.errors.label} placeholder="For example: Proof of enrollment" />
                <div><label htmlFor="vault-file" className="mb-2 block text-sm font-semibold">Choose a file</label><input ref={fileInput} id="vault-file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={e => form.setData('file', e.target.files?.[0] ?? null)} aria-invalid={Boolean(form.errors.file)} aria-describedby={form.errors.file ? 'file-error' : undefined} className={'min-h-11 w-full min-w-0 rounded-lg border border-civic-control p-2 text-base file:mr-3 file:rounded file:border-0 file:bg-civic-panel file:px-3 file:py-2 ' + focusRing} />{form.errors.file && <p id="file-error" role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.file}</p>}</div>
                {form.progress && <div aria-live="polite" className="text-sm"><progress aria-label="Document upload progress" max={100} value={form.progress.percentage} className="w-full" />{form.progress.percentage}% uploaded</div>}
                {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}<Button type="submit" loading={form.processing}>Upload to vault</Button>
            </form>
        </Card>
        <Card><h2 className="text-lg font-bold">Your reusable documents</h2><form onSubmit={e => { e.preventDefault(); search.get('/student/documents', { preserveState: true }); }} className="mt-5 mb-6 space-y-4"><Input label="Search document labels" name="q" value={search.data.q} onChange={e => search.setData('q', e.target.value)} error={search.errors.q} /><div className="flex flex-wrap gap-3"><Button type="submit" loading={search.processing}>Search vault</Button><Button variant="outline" onClick={() => router.get('/student/documents')}>Reset</Button></div></form>
            {documents.data.length ? <ul className="space-y-4">{documents.data.map(document => <li key={document.id} className="rounded-lg border border-civic-panel-strong p-4"><h3 className="break-words font-semibold">{document.label}</h3><p className="mt-1 break-all text-sm leading-6 text-civic-muted">{document.original_name} · {Math.ceil(document.size_bytes / 1024)} KB</p><div className="mt-3 flex flex-wrap gap-3"><a href={document.download_url} className={'inline-flex min-h-11 items-center rounded text-sm font-semibold text-civic-teal underline ' + focusRing}>Download document</a><Button variant="outline" onClick={() => { removal.reset(); removal.clearErrors(); setRemove(document); }}>Remove from vault</Button></div></li>)}</ul> : <EmptyState title="No matching documents" description="Upload a document or try another label search. Your vault is visible only to your student account." />}
            <Pagination page={documents} label="Vault document pages" />
        </Card>
        </div>
        <Modal open={remove !== null} onClose={() => { if (!removal.processing) setRemove(null); }} title="Remove from your vault?" description="This removes the reusable entry. Existing application attachments and their retained files remain available through those applications.">
            <form className="space-y-4" onSubmit={e => { e.preventDefault(); if (!remove || !removalRequest.begin()) return; removal.delete('/student/documents/' + remove.id, { preserveScroll: true, onSuccess: () => setRemove(null), onFinish: removalRequest.finish }); }}>
                <label className="flex gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={removal.data.confirmed} onChange={e => removal.setData('confirmed', e.target.checked)} className="mt-1 size-4 shrink-0" />I confirm removal from my vault.</label>
                {removalRequest.error && <p role="alert" className="text-sm text-civic-danger">{removalRequest.error}</p>}{Object.values(removal.errors).map(error => <p role="alert" key={error} className="text-sm text-civic-danger">{error}</p>)}
                <div className="flex flex-wrap gap-3"><Button type="submit" disabled={!removal.data.confirmed} loading={removal.processing}>Confirm removal</Button><Button variant="outline" disabled={removal.processing} onClick={() => setRemove(null)}>Cancel</Button></div>
            </form>
        </Modal>
    </AppLayout>;
}
