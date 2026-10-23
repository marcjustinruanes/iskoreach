import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../layouts/AdminLayout';
import { Button, Card, Input, focusRing } from '../../components/ui/DesignSystem';
import { Modal } from '../../components/ui/Overlays';

interface PolicyDocument { kind: string; title: string; content: string; expected_version: number; published_version: string; published_at: string; is_draft: boolean; save_url: string; public_url: string }

export default function Policies({ documents }: { documents: PolicyDocument[] }) {
    return <AdminLayout title="Registration policies" description="Edit drafts and publish the terms and privacy notice shown during registration.">
        <div className="space-y-6">{documents.map(document => <PolicyEditor key={document.kind + ':' + document.expected_version} document={document} />)}</div>
    </AdminLayout>;
}

function PolicyEditor({ document }: { document: PolicyDocument }) {
    const form = useForm({ title: document.title, content: document.content, expected_version: document.expected_version });
    const [confirmPublish, setConfirmPublish] = useState(false);
    const save = (action: 'draft' | 'publish') => { form.transform(data => ({ ...data, action })); form.post(document.save_url, { preserveScroll: true, onSuccess: () => setConfirmPublish(false) }); };
    return <Card><h2 className="text-xl font-bold">{document.kind === 'privacy' ? 'Privacy Policy' : 'Terms and Conditions'}</h2>
        <p className="my-3 text-sm text-civic-muted">Published version: {document.published_version}. {document.is_draft ? 'An unpublished draft is shown below.' : 'Edits are saved as a new revision.'}</p>
        <form className="space-y-4" onSubmit={event => { event.preventDefault(); save('draft'); }}>
            <Input label="Title" required maxLength={200} value={form.data.title} error={form.errors.title} onChange={event => { form.setData('title', event.target.value); form.clearErrors('title'); }} />
            <div><label htmlFor={'policy-' + document.kind} className="mb-2 block text-sm font-semibold">Policy text</label><textarea id={'policy-' + document.kind} required minLength={100} maxLength={50000} rows={18} value={form.data.content} onChange={event => { form.setData('content', event.target.value); form.clearErrors('content'); }} className={'w-full rounded-lg border border-civic-border p-3 text-sm leading-7 ' + focusRing} />{form.errors.content && <p role="alert" className="mt-2 text-sm text-civic-danger">{form.errors.content}</p>}</div>
            {form.errors.expected_version && <p role="alert" className="text-sm text-civic-danger">{form.errors.expected_version}</p>}
            <div className="flex flex-wrap gap-3"><Button type="submit" variant="outline" loading={form.processing}>Save draft</Button><Button disabled={form.processing} onClick={() => setConfirmPublish(true)}>Publish to registration</Button><Link href={document.public_url} target="_blank" className={'inline-flex min-h-11 items-center rounded px-3 text-sm underline ' + focusRing}>View published policy</Link></div>
        </form>
        <Modal open={confirmPublish} onClose={() => setConfirmPublish(false)} title="Publish this policy?" description="New registrations will need to accept this version. Earlier acceptance records will remain unchanged."><div className="flex justify-end gap-3"><Button variant="outline" onClick={() => setConfirmPublish(false)}>Cancel</Button><Button loading={form.processing} onClick={() => save('publish')}>Publish policy</Button></div></Modal>
    </Card>;
}
