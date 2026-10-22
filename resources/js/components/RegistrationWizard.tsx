import { registrationContact, registrationName } from './ui/inputValidation';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ArrowLeft, ArrowRight, Check, CheckCheck, ShieldCheck, UserRound, GraduationCap, MapPin, KeyRound, Building2, FileCheck2, UploadCloud, Pencil, LockKeyhole } from 'lucide-react';
import AuthLayout from '../layouts/AuthLayout';
import StudentRegistrationFields, { RegistrationSuffix, StudentAgreement, studentRegistrationDefaults } from './StudentRegistrationFields';
import { Button, Input, focusRing } from './ui/DesignSystem';
import PasswordField from './ui/PasswordField';

const providerDefaults = {
    organization_description: '', country: 'PH', position: '', work_email: '', organization_name: '', organization_type: 'foundation', office_address: '', organization_email: '', organization_phone: '', website: '', authorization_required: false,
    representative_id: null as File | null, organization_proof: null as File | null, administration_evidence: null as File | null, authorization_letter: null as File | null, accreditation: null as File | null,
};
const documents = { representative_id: 'Valid ID of authorized representative', organization_proof: 'Proof of organization or registration', administration_evidence: 'Evidence of scholarship administration (plan, funding or appointment)', authorization_letter: 'Authorization letter', accreditation: 'Additional accreditation documents (optional)' };
export default function RegistrationWizard({ accountType, registrationIntent, verificationPolicy = {} }: { accountType: 'student' | 'provider'; registrationIntent: string; verificationPolicy?: Record<string, { required: string[]; instructions: string }> }) {
    const provider = accountType === 'provider';
    const form = useForm({ ...studentRegistrationDefaults, ...providerDefaults, email: '', password: '', password_confirmation: '', account_type: accountType, registration_intent: registrationIntent });
    type Data = typeof form.data;
    const [step, setStep] = useState(1);
    const [checking, setChecking] = useState(false);
    const [requestError, setRequestError] = useState('');
    const locked = useRef(false);
    const formRef = useRef<HTMLFormElement>(null);
    const cancel = form.cancel;
    useEffect(() => {
        const stop = router.on('exception', event => { if (!locked.current) return; event.preventDefault(); locked.current = false; cancel(); setRequestError('Connection failed. Your entries are preserved. Reconnect and try again.'); });
        const stopInvalid = router.on('invalid', event => { if (!locked.current) return; event.preventDefault(); locked.current = false; cancel(); setRequestError(event.detail.response.status === 419 ? 'Your session expired. Reload this page before submitting.' : 'Registration could not be completed. Please retry.'); });
        return () => { stop(); stopInvalid(); };
    }, [cancel]);
    const steps = provider ? ['Representative information', 'Organization information', 'Verification documents', 'Account setup'] : ['Personal information', 'Academic information', 'Address information', 'Account setup'];
    const stepDescriptions = provider
        ? ['Tell us who will administer the scholarships.', 'Introduce your organization or independent practice.', 'Provide the evidence for administrator review.', 'Secure your account and review the policies.']
        : ['Start with a few details about yourself.', 'Tell us where you are in your academic journey.', 'Add your current address using the location selectors.', 'Secure your account and review the policies.'];
    const icons = provider ? [UserRound, Building2, FileCheck2, KeyRound] : [UserRound, GraduationCap, MapPin, KeyRound];
    const headingRef = useRef<HTMLHeadingElement>(null);
    const submitted = useRef(false);
    const [choiceLabels, setChoiceLabels] = useState<Record<string, string>>({});
    useEffect(() => { headingRef.current?.focus({ preventScroll: true }); }, [step]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => { if (form.isDirty && !form.processing && !submitted.current) { event.preventDefault(); event.returnValue = ''; } };
        window.addEventListener('beforeunload', warn);
        const stop = router.on('before', event => {
            if (event.detail.visit.method === 'get' && form.isDirty && !form.processing && !submitted.current && event.detail.visit.url.pathname !== window.location.pathname && !window.confirm('Leave registration? Your entered details and selected files will be lost.')) event.preventDefault();
        });
        return () => { window.removeEventListener('beforeunload', warn); stop(); };
    }, [form.isDirty, form.processing]);
    const goBack = (target: number) => { form.clearErrors(); setRequestError(''); setStep(target); };
    const change = <K extends keyof Data>(field: K, value: Data[K]) => form.setData(current => ({ ...current, [field]: value }));
    const field = (name: keyof Data, label: string, optional = false, type = 'text') => <Input name={name} label={label + (optional ? ' (optional)' : ' *')} value={String(form.data[name] ?? '')} onChange={event => change(name, (['contact_number', 'organization_phone'].includes(name) ? registrationContact(event.target.value) : ['first_name', 'surname', 'middle_name'].includes(name) ? registrationName(event.target.value) : event.target.value) as Data[typeof name])} error={form.errors[name]} type={type} required={!optional} placeholder={name === 'position' ? 'e.g. Scholarship coordinator' : name === 'contact_number' || name === 'organization_phone' ? 'e.g. 09171234567' : name.includes('email') ? 'name@example.com' : name === 'website' ? 'https://example.org' : name === 'office_address' ? 'Building, street and office number' : undefined} maxLength={name === 'suffix' ? 20 : 255} />;
    const errorStep = (errors: Record<string, string>) => {
        const keys = Object.keys(errors);
        const personal = ['first_name', 'surname', 'middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'position', 'work_email'];
        const academic = ['student_number', 'school', 'campus', 'college', 'course', 'year_level', 'enrollment_status'];
        const address = ['region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'];
        const org = ['organization_description', 'country', 'organization_name', 'organization_type', 'office_address', 'organization_email', 'organization_phone', 'website', 'authorization_required'];
        const first = keys[0];
        const target = personal.includes(first) ? 1 : (provider ? [...org, ...address] : academic).includes(first) ? 2 : (provider ? Object.keys(documents) : address).includes(first) ? 3 : 4;
        setStep(target);
        if (errors.account_type) setRequestError(errors.account_type);
        window.setTimeout(() => { const input = formRef.current?.elements.namedItem(first); if (input instanceof HTMLElement) input.focus(); }, 50);
    };
    const next = async () => {
        if (locked.current) return;
        locked.current = true; setChecking(true); setRequestError(''); form.clearErrors();
        const body = new FormData();
        Object.entries(form.data).forEach(([key, value]) => { if (value !== null) body.append(key, value instanceof File ? value : typeof value === 'boolean' ? (value ? '1' : '0') : String(value)); });
        body.append('step', String(step));
        try {
            const token = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
            const response = await fetch('/register/validate-step', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }, body });
            if (response.status === 422) {
                const result = await response.json() as { errors: Record<string, string[]> };
                const errors = Object.fromEntries(Object.entries(result.errors).map(([key, values]) => [key, values[0]]));
                form.setError(errors); if (errors.account_type) setRequestError(errors.account_type);
                window.setTimeout(() => { const input = formRef.current?.elements.namedItem(Object.keys(errors)[0]); if (input instanceof HTMLElement) input.focus(); }, 30);
                return;
            }
            if (!response.ok) throw new Error(response.status === 419 ? 'Your session expired. Reload the registration page and try again.' : 'Validation could not be completed. Please retry.');
            const labels: Record<string, string> = {};
            formRef.current?.querySelectorAll('select').forEach(select => { labels[select.name] = select.selectedOptions[0]?.textContent ?? select.value; });
            setChoiceLabels(current => ({ ...current, ...labels }));
            setStep(current => current + 1);
        } catch (error) { setRequestError(error instanceof Error ? error.message : 'Check your connection and retry.'); }
        finally { locked.current = false; setChecking(false); }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (step < 5) { void next(); return; }
        if (!navigator.onLine) { setRequestError('You are offline. Reconnect before submitting.'); return; }
        if (locked.current) return;
        locked.current = true; setRequestError('');
        form.post('/register', { forceFormData: true, onError: errorStep, onSuccess: () => { submitted.current = true; }, onFinish: () => { locked.current = false; } });
    };
    const requiredDocuments = verificationPolicy[form.data.organization_type]?.required ?? [form.data.organization_type === 'individual' ? 'administration_evidence' : 'organization_proof'];
    const documentKinds = [...new Set(['representative_id', ...requiredDocuments, ...(form.data.authorization_required ? ['authorization_letter'] : []), 'accreditation'])].filter(kind => kind in documents) as (keyof typeof documents)[];
    const file = (kind: keyof typeof documents) => <div key={kind} className={'registration-upload ' + (form.errors[kind] ? 'registration-upload-error' : form.data[kind] ? 'registration-upload-selected' : '')}><div className="flex items-start gap-3"><span className="rounded-xl bg-civic-panel p-2.5 text-civic-navy">{form.data[kind] ? <FileCheck2 aria-hidden="true" className="size-5" /> : <UploadCloud aria-hidden="true" className="size-5" />}</span><div className="min-w-0 flex-1"><label className="block text-sm font-semibold leading-6" htmlFor={kind}>{requiredDocuments.includes(kind) ? documents[kind].replace(' (optional)', '') : documents[kind]}{(kind !== 'accreditation' || requiredDocuments.includes(kind)) && ' *'}</label><p className="mt-1 text-xs leading-5 text-civic-muted">{form.data[kind] ? 'File selected. You can replace it below.' : 'Choose a clear, readable copy. PDF, JPG or PNG, up to 1 MB.'}</p></div>{form.data[kind] && <Check aria-hidden="true" className="mt-1 size-5 shrink-0 text-civic-navy" />}</div><input id={kind} name={kind} type="file" accept="application/pdf,image/jpeg,image/png" aria-invalid={Boolean(form.errors[kind])} aria-describedby={kind + '-feedback'} onChange={event => change(kind, event.target.files?.[0] ?? null)} className={'mt-4 block w-full min-w-0 rounded-lg text-sm text-civic-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-civic-navy file:px-4 file:py-2.5 file:text-xs file:font-semibold file:text-white ' + focusRing} /><p id={kind + '-feedback'} role={form.errors[kind] ? 'alert' : undefined} className={'mt-2 break-all text-xs leading-5 ' + (form.errors[kind] ? 'text-civic-danger' : 'text-civic-muted')}>{form.errors[kind] || (form.data[kind] ? 'Selected: ' + form.data[kind]?.name + ' (' + Math.ceil((form.data[kind]?.size ?? 0) / 1024) + ' KB)' : 'No document selected yet.')}</p></div>;
    const sections = provider ? [
        ['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email'],
        ['organization_description', 'country', 'barangay_code', 'organization_type', ...(form.data.organization_type === 'individual' ? [] : ['organization_name']), 'office_address', 'region_code', 'province_code', 'city_code', 'organization_email', 'organization_phone', 'website', 'authorization_required'],
        ['representative_id', form.data.organization_type === 'individual' ? 'administration_evidence' : 'organization_proof', ...(form.data.authorization_required ? ['authorization_letter'] : []), 'accreditation'],
        ['email', 'terms_accepted', 'privacy_accepted'],
    ] : [
        ['first_name', 'surname', 'middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number'],
        ['student_number', 'school', 'campus', 'college', 'course', 'year_level', 'enrollment_status'],
        ['region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'],
        ['email', 'terms_accepted', 'privacy_accepted'],
    ];
    const labels: Record<string, string> = { region_code: 'Region', province_code: 'Province', city_code: 'City / municipality', barangay_code: 'Barangay', terms_accepted: 'Terms of Use', privacy_accepted: 'Privacy Policy', authorization_required: 'Authorization letter required', ...documents };
    const stepper = (mobile = false) => <ol aria-label="Registration progress" className={mobile ? 'registration-stepper-mobile' : 'registration-stepper'}>{[...steps, 'Final review'].map((label, index) => {
        const number = index + 1; const complete = step > number; const active = step === number; const Icon = number === 5 ? CheckCheck : icons[index];
        return <li key={label} aria-current={active ? 'step' : undefined} className={'registration-step ' + (complete ? 'is-complete' : active ? 'is-active' : 'is-upcoming')}><span className="registration-step-icon">{complete ? <Check aria-hidden="true" className="size-4" /> : <Icon aria-hidden="true" className="size-4" />}</span><div className={mobile ? 'sr-only' : 'min-w-0 pt-1'}><p className="text-sm font-semibold">{label}</p><p className="mt-1 text-xs leading-5 opacity-75">{complete ? 'Completed' : number === 5 ? 'Check your details before submitting.' : stepDescriptions[index]}</p></div><span className="sr-only">{active ? 'Current step' : complete ? 'Completed' : 'Upcoming'}</span></li>;
    })}</ol>;
    const panel = <div className="rounded-2xl border border-civic-border/50 bg-civic-navy p-7 text-white"><div className="flex items-center gap-3"><span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-civic-canvas"><img src="/images/iskoreach-logo.png" alt="IskoReach logo" width={500} height={500} className="size-full scale-125 object-contain" /></span><p className="text-lg font-bold tracking-tight">Your next chapter.</p></div><h2 className="mt-7 text-2xl font-bold leading-9">{provider ? 'Make opportunities possible.' : 'A step closer to your future.'}</h2><p className="mt-3 text-sm leading-7 text-white/80">{provider ? 'Create a trusted space for your scholarship programs. Start with your details; our administrators will review your documents.' : 'Create your student account to discover scholarships, prepare applications, and keep your opportunities in one place.'}</p><div className="my-7 h-px bg-white/15" />{stepper()}<p className="mt-7 flex items-start gap-2 border-t border-white/15 pt-5 text-xs leading-6 text-white/80"><ShieldCheck aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-cream" />{provider ? 'Verification documents stay private. Publishing access follows administrator approval.' : 'No scholarship documents needed at signup. Add them when you apply.'}</p></div>;
    return <AuthLayout title={provider ? 'Provider registration' : 'Student registration'} registrationPanel={panel}>
        <nav aria-label="Registration type" className="mb-6 grid grid-cols-2 gap-1 rounded-xl bg-civic-panel p-1">{(['student', 'provider'] as const).map(role => <Link key={role} href={'/register/' + role} aria-current={accountType === role ? 'page' : undefined} className={'flex min-h-11 items-center justify-center gap-2 rounded-lg px-2 text-xs font-semibold transition sm:text-sm ' + focusRing + (accountType === role ? ' bg-white text-civic-navy shadow-sm' : ' text-civic-muted hover:bg-white/60')}>{role === 'student' ? <GraduationCap aria-hidden="true" className="size-4 shrink-0" /> : <Building2 aria-hidden="true" className="size-4 shrink-0" />}{role === 'student' ? 'Student registration' : 'Provider registration'}</Link>)}</nav>
        <div className="mb-6 lg:hidden">{stepper(true)}</div>
        <div className="mb-6 border-b border-civic-border/40 pb-5"><div className="mb-2 flex items-center justify-between gap-3"><p aria-live="polite" className="text-xs font-bold uppercase tracking-widest text-civic-muted">{step === 5 ? 'Ready when you are' : 'Step ' + step + ' of 4'}</p></div><h2 ref={headingRef} tabIndex={-1} className="registration-heading text-xl font-bold leading-8 text-civic-navy">{step === 5 ? 'Review your registration' : steps[step - 1]}</h2><p className="mt-1 text-sm leading-6 text-civic-muted">{step === 5 ? 'Make sure everything looks right. You can edit any section before creating your account.' : stepDescriptions[step - 1]}</p></div>
        {requestError && <p role="alert" className="mb-4 rounded bg-red-50 p-3 text-sm text-civic-danger">{requestError}</p>}
        <form ref={formRef} onSubmit={submit} noValidate aria-busy={checking || form.processing} className="registration-form">
            <div key={step} className="registration-step-content space-y-5">
            {!provider && step <= 3 && <StudentRegistrationFields step={step} data={form.data} errors={form.errors} onChange={(field, value) => form.setData(current => ({ ...current, [field]: value }))} />}
            {provider && step === 1 && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Representative information</legend><div className="grid gap-4 sm:grid-cols-2">{field('first_name', 'First name')}{field('surname', 'Surname')}{field('middle_name', 'Middle name', true)}<RegistrationSuffix data={form.data} errors={form.errors} onChange={(field, value) => form.setData(current => ({ ...current, [field]: value }))} /></div>{field('position', 'Position / designation')}{field('contact_number', 'Contact number', false, 'tel')}{field('work_email', 'Work email address', false, 'email')}</fieldset>}
            {provider && step === 2 && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Organization information</legend><label className="block text-sm font-semibold">Organization type *<select name="organization_type" value={form.data.organization_type} onChange={event => change('organization_type', event.target.value)} className={'mt-2 w-full rounded border bg-white p-3 ' + focusRing}>{['foundation', 'government', 'school', 'organization', 'individual', 'lgu', 'nonprofit', 'corporate', 'other'].map(value => <option key={value} value={value}>{value === 'individual' ? 'Individual scholarship administrator' : value.charAt(0).toUpperCase() + value.slice(1)}</option>)}</select></label>{form.errors.organization_type && <p role="alert">{form.errors.organization_type}</p>}{form.data.organization_type !== 'individual' ? field('organization_name', 'Organization / institution name') : <p className="text-sm leading-6">Individual applicants use their own name and provide evidence of their scholarship administration role instead of organization registration. An administrator reviews this evidence before granting publishing access.</p>}<p className="text-sm text-civic-muted">Country: Philippines</p><div><label htmlFor="organization_description" className="mb-2 block text-sm font-semibold">Organization and scholarship program description *</label><textarea id="organization_description" name="organization_description" rows={4} required minLength={10} maxLength={4000} value={form.data.organization_description} onChange={event => change('organization_description', event.target.value)} className={'w-full rounded-lg border border-civic-control p-3 text-base ' + focusRing} />{form.errors.organization_description && <p role="alert" className="text-sm text-civic-danger">{form.errors.organization_description}</p>}</div>{field('office_address', form.data.organization_type === 'individual' ? 'Administrative / correspondence address' : 'Office address')}<StudentRegistrationFields step={3} providerAddress data={form.data} errors={form.errors} onChange={(field, value) => form.setData(current => ({ ...current, [field]: value }))} />{field('organization_email', form.data.organization_type === 'individual' ? 'Administration contact email' : 'Organization email', false, 'email')}{field('organization_phone', 'Organization / administration contact number', false, 'tel')}{field('website', 'Official website', true, 'url')}<label className="flex items-start gap-3 text-sm"><input type="checkbox" name="authorization_required" checked={form.data.authorization_required} onChange={event => change('authorization_required', event.target.checked)} className="mt-1" />I act on behalf of another organization or administrator and need an authorization letter.</label><p className="text-xs text-civic-muted">Select this when you are an appointed representative rather than the organization head or independent administrator. Administrators may request further evidence.</p></fieldset>}
            {provider && step === 3 && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Verification documents</legend><p className="text-sm leading-6">PDF, JPG or PNG; maximum 1 MB per file. These files are private and accessible only to your account and authorized administrators.</p><p className="rounded-lg bg-civic-panel p-3 text-sm leading-6">{verificationPolicy[form.data.organization_type]?.instructions || 'Provide appropriate evidence of your organization or administration role.'}</p>{documentKinds.map(kind => file(kind))}{verificationPolicy[form.data.organization_type]?.required.includes('accreditation') && <p className="text-sm font-semibold">Additional accreditation is required for this organization type.</p>}</fieldset>}
            {step === 4 && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Account setup</legend>{field('email', 'Account email address', false, 'email')}<PasswordField name="password" label="Password *" value={form.data.password} onChange={event => change('password', event.target.value)} error={form.errors.password} autoComplete="new-password" hint="Use at least 8 characters and at most 72 UTF-8 bytes." /><PasswordField name="password_confirmation" label="Confirm password *" value={form.data.password_confirmation} onChange={event => change('password_confirmation', event.target.value)} error={form.errors.password_confirmation} autoComplete="new-password" /><StudentAgreement data={form.data} errors={form.errors} onChange={(field, value) => form.setData(current => ({ ...current, [field]: value }))} /><p className="text-sm leading-6">Verify your email before entering your workspace.{provider && ' Your provider account remains pending verification until an administrator approves the required documents. You cannot publish scholarships before approval.'}</p></fieldset>}
            {step === 5 && <div className="space-y-5">{sections.map((fields, index) => { const Icon = icons[index]; return <section key={index} className="overflow-hidden rounded-xl border border-civic-border/50"><div className="flex items-center justify-between gap-3 bg-civic-canvas px-4 py-3"><h3 className="flex items-center gap-2 text-sm font-bold"><Icon aria-hidden="true" className="size-4" />{steps[index]}</h3><button type="button" aria-label={'Edit ' + steps[index]} disabled={checking || form.processing} onClick={() => goBack(index + 1)} className={'inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-civic-navy hover:bg-civic-panel ' + focusRing}><Pencil aria-hidden="true" className="size-3.5" />Edit</button></div><dl className="grid gap-x-5 gap-y-4 p-4 sm:grid-cols-2">{fields.map(key => { const value = form.data[key as keyof Data]; if (value === null || value === '') return null; return <div key={key} className="min-w-0"><dt className="text-xs capitalize leading-5 text-civic-muted">{labels[key] ?? key.replaceAll('_', ' ')}</dt><dd className="mt-1 break-words text-sm font-medium leading-6">{value instanceof File ? value.name : typeof value === 'boolean' ? value ? 'Yes' : 'No' : choiceLabels[key] ?? String(value).replaceAll('_', ' ')}</dd></div>; })}{index === 3 && <div className="flex items-center gap-2 text-xs text-civic-muted sm:col-span-2"><LockKeyhole aria-hidden="true" className="size-3.5" />Your password is set and hidden for your security.</div>}</dl></section>; })}</div>}
            </div>
            {Object.keys(form.errors).length > 0 && <p role="alert" className="mt-4 rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">Please check the highlighted fields before continuing. Your other details are kept.</p>}
            <div className="registration-navigation"><div className="flex items-center justify-between gap-3"><Button variant="outline" className="registration-nav-button" disabled={step === 1 || checking || form.processing} onClick={() => goBack(step - 1)}><ArrowLeft aria-hidden="true" className="size-4" />Previous</Button><Button type="submit" variant="secondary" className="registration-nav-button min-w-0" loading={checking || form.processing}>{checking ? 'Validating...' : form.processing ? 'Creating account...' : step === 5 ? 'Create ' + accountType + ' account' : step === 4 ? 'Review registration' : 'Next'}{!checking && !form.processing && (step === 5 ? <Check aria-hidden="true" className="size-4 shrink-0" /> : <ArrowRight aria-hidden="true" className="size-4 shrink-0" />)}</Button></div><p className="mt-3 text-center text-xs leading-5 text-civic-muted">{step === 5 ? 'Your account will require email verification.' : 'Your details stay here as you move between steps.'}</p></div>
        </form><p className="mt-6 text-center text-sm">Already registered? <Link href="/login" className="font-semibold text-civic-teal underline">Log in</Link></p>
    </AuthLayout>;
}
