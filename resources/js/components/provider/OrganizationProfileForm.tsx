import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { textError, emailError } from '../ui/inputValidation';
import type { FormEvent } from 'react';
import type { ProviderProfile } from '../../types';
import { Button, Input } from '../ui/DesignSystem';
import { Modal } from '../ui/Overlays';
import { useRequestFeedback } from '../ui/useRequestFeedback';
export default function OrganizationProfileForm({ profile }: { profile: ProviderProfile }) {
    const form = useForm({ organization_name: profile.organization_name ?? '', contact_email: profile.contact_email ?? '' });
    const request = useRequestFeedback(form.cancel);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const save = () => {
        if (!request.begin()) return;
        form.patch('/provider/profiles/' + profile.id, { preserveScroll: true, onError: () => setConfirmOpen(false), onFinish: request.finish });
    };
    const submit = (event: FormEvent) => { event.preventDefault();
        form.clearErrors();
        const organization = textError(form.data.organization_name, true);
        const email = emailError(form.data.contact_email);
        if (organization) form.setError('organization_name', organization);
        if (email) form.setError('contact_email', email);
        if (organization || email) return;
        if (profile.verification_status === 'approved' && (form.data.organization_name.trim() !== (profile.organization_name ?? '').trim() || form.data.contact_email.trim().toLowerCase() !== (profile.contact_email ?? '').trim().toLowerCase())) setConfirmOpen(true); else save(); };
    return <><form onSubmit={submit} noValidate aria-busy={form.processing} aria-label="Organization profile" className="max-w-xl space-y-5">
        <Input label="Organization name" name="organization_name" required maxLength={255} autoComplete="organization" value={form.data.organization_name} onChange={e => form.setData('organization_name', e.target.value)} error={form.errors.organization_name} />
        <Input label="Contact email" name="contact_email" type="email" required maxLength={255} autoComplete="email" value={form.data.contact_email} onChange={e => form.setData('contact_email', e.target.value)} error={form.errors.contact_email} />
        <p className="text-sm leading-6 text-civic-muted">Changing either field returns your organization to pending review and restricts approved-provider tools until an administrator approves it again.</p>
        {request.error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{request.error}</p>}
        <Button type="submit" loading={form.processing} disabled={!form.isDirty}>Save organization details</Button>
    </form><Modal open={confirmOpen} onClose={() => { if (!form.processing) setConfirmOpen(false); }} title="Submit organization changes?" description="Your current approval will be revoked while an administrator reviews the updated organization details.">
        {request.error && <p role="alert" className="mb-4 text-sm leading-6 text-civic-danger">{request.error}</p>}<div className="flex flex-wrap gap-3"><Button onClick={save} loading={form.processing}>Save and request review</Button><Button variant="outline" disabled={form.processing} onClick={() => setConfirmOpen(false)}>Cancel</Button></div>
    </Modal></>;
}
