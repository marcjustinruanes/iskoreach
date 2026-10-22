import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { textError, emailError } from '../ui/inputValidation';
import type { FormEvent } from 'react';
import type { ProviderProfile } from '../../types';
import { Button, Input, focusRing } from '../ui/DesignSystem';
import { Modal } from '../ui/Overlays';
import { useRequestFeedback } from '../ui/useRequestFeedback';
export default function OrganizationProfileForm({ profile }: { profile: ProviderProfile }) {
    const extraFields = ['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email', 'office_address', 'organization_phone', 'website', 'organization_description'] as const;
    const form = useForm<Record<typeof extraFields[number] | 'organization_name' | 'contact_email', string>>({ organization_name: profile.organization_name ?? '', contact_email: profile.contact_email ?? '', ...Object.fromEntries(extraFields.map(key => [key, profile[key] ?? ''])) as Record<typeof extraFields[number], string> });
    const request = useRequestFeedback(form.cancel);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const save = () => {
        if (!request.begin()) return;
        form.transform(data => Object.fromEntries(Object.entries(data).filter(([key, value]) => !extraFields.includes(key as typeof extraFields[number]) || String(value).trim() !== '' || Boolean(profile[key as typeof extraFields[number]]))));
        form.patch('/provider/profiles/' + profile.id, { preserveScroll: true, onError: () => setConfirmOpen(false), onFinish: request.finish });
    };
    const submit = (event: FormEvent) => { event.preventDefault();
        form.clearErrors();
        const organization = textError(form.data.organization_name, true);
        const email = emailError(form.data.contact_email);
        if (organization) form.setError('organization_name', organization);
        if (email) form.setError('contact_email', email);
        if (organization || email) return;
        if (profile.verification_status === 'approved' && form.isDirty) setConfirmOpen(true); else save(); };
    return <><form onSubmit={submit} noValidate aria-busy={form.processing} aria-label="Organization profile" className="max-w-xl space-y-5">
        <Input label="Organization name" name="organization_name" required maxLength={255} autoComplete="organization" value={form.data.organization_name} onChange={e => form.setData('organization_name', e.target.value)} error={form.errors.organization_name} />
        <Input label="Contact email" name="contact_email" type="email" required maxLength={255} autoComplete="email" value={form.data.contact_email} onChange={e => form.setData('contact_email', e.target.value)} error={form.errors.contact_email} />
        <details className="border-y border-neutral-200 py-3"><summary className={'cursor-pointer py-2 text-sm font-semibold ' + focusRing}>Representative and organization details</summary><div className="mt-4 grid gap-4 sm:grid-cols-2">{extraFields.map(key => <Input key={key} label={key.replaceAll('_', ' ').replace(/^./, value => value.toUpperCase()) + (['middle_name','suffix','website'].includes(key) ? ' (optional)' : '')} name={key} type={key.includes('email') ? 'email' : key === 'website' ? 'url' : key.includes('number') || key.includes('phone') ? 'tel' : 'text'} maxLength={key === 'organization_description' ? 4000 : 255} value={String(form.data[key] ?? '')} onChange={event => form.setData(key, event.target.value)} error={form.errors[key]} />)}</div></details>
        <p className="text-sm leading-6 text-civic-muted">Changing these details returns your organization to pending review and restricts approved-provider tools until an administrator approves it again.</p>
        {request.error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{request.error}</p>}
        <Button type="submit" loading={form.processing} disabled={!form.isDirty}>Save organization details</Button>
    </form><Modal open={confirmOpen} onClose={() => { if (!form.processing) setConfirmOpen(false); }} title="Submit organization changes?" description="Your current approval will be revoked while an administrator reviews the updated organization details.">
        {request.error && <p role="alert" className="mb-4 text-sm leading-6 text-civic-danger">{request.error}</p>}<div className="flex flex-wrap gap-3"><Button onClick={save} loading={form.processing}>Save and request review</Button><Button variant="outline" disabled={form.processing} onClick={() => setConfirmOpen(false)}>Cancel</Button></div>
    </Modal></>;
}
