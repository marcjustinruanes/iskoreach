import RegistrationCoverageFields from './RegistrationCoverageFields';
import type { LocationInput } from '../scholarshipTypes';
import { registrationContact, registrationName } from './ui/inputValidation';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ArrowLeft, ArrowRight, Check, CheckCheck, ShieldCheck, UserRound, GraduationCap, MapPin, KeyRound, Building2, FileCheck2, UploadCloud, Pencil, LockKeyhole } from 'lucide-react';
import AuthLayout from '../layouts/AuthLayout';
import StudentRegistrationFields, { RegistrationSuffix, RegistrationMiddleName, StudentAgreement, studentRegistrationDefaults } from './StudentRegistrationFields';
import { Button, Input, focusRing } from './ui/DesignSystem';
import PasswordField from './ui/PasswordField';
import { Modal } from './ui/Overlays';
import RegistrationEmailField from './RegistrationEmailField';
import RegistrationDocumentPreview from './RegistrationDocumentPreview';
import type { RegistrationOptionsData } from './StudentRegistrationFields';

const providerDefaults = {
    coverage_areas: [{ scope: 'nationwide', region_code: '', province_code: '', city_code: '' }] as LocationInput[], organization_description: '', country: 'PH', position: '', work_email: '', organization_name: '', organization_type: 'foundation', organization_email: '', organization_phone: '', website: '', authorization_required: false,
    representative_id: null as File | null, organization_proof: null as File | null, administration_evidence: null as File | null, authorization_letter: null as File | null, accreditation: null as File | null,
};
const documents = { representative_id: 'Valid ID of authorized representative', organization_proof: 'Proof of organization or registration', administration_evidence: 'Evidence of scholarship administration (plan, funding or appointment)', authorization_letter: 'Authorization letter', accreditation: 'Additional accreditation documents (optional)' };
export default function RegistrationWizard({ accountType, registrationIntent, verificationPolicy = {} }: { accountType: 'student' | 'provider'; registrationIntent: string; verificationPolicy?: Record<string, { required: string[]; instructions: string }> }) {
    const provider = accountType === 'provider';
    const accountStep = provider ? 5 : 4;
    const reviewStep = accountStep + 1;
    const { registrationOptions, policyVersions, googleRegistration, verifiedWorkEmail: savedWorkEmail, verifiedAccountEmail: savedAccountEmail, googleReturn, errors: serverErrors, status } = usePage<{ registrationOptions: RegistrationOptionsData; policyVersions: { terms_version: string; privacy_version: string }; googleRegistration?: { email: string } | null; verifiedWorkEmail?: string | null; verifiedAccountEmail?: string | null; googleReturn?: boolean; errors?: Record<string, string>; status?: string }>().props;
    const defaults = { ...studentRegistrationDefaults, ...providerDefaults, ...policyVersions, email: '', password: '', password_confirmation: '', account_type: accountType, registration_intent: registrationIntent };
    const [googleDraft] = useState<{ data: Partial<typeof defaults>; step: number } | null>(() => {
        try {
            const key = 'registration-google-' + accountType;
            const raw = sessionStorage.getItem(key);
            if (!googleReturn || !raw) return null;
            const saved = JSON.parse(raw);
            if (saved.expires <= Date.now() || !saved.data || typeof saved.data !== 'object') return null;
            const data = Object.fromEntries(Object.entries(saved.data).filter(([key, value]) => key in defaults && !/password|email|intent|version|accepted|account_type/.test(key) && typeof value === typeof defaults[key as keyof typeof defaults] && (typeof value === 'string' || typeof value === 'boolean' || key === 'coverage_areas')));
            return { data: data as Partial<typeof defaults>, step: provider && saved.step === 2 ? 2 : 1 };
        } catch { return null; }
    });
    const form = useForm({ ...defaults, ...googleDraft?.data, work_email: savedWorkEmail ?? '', email: savedAccountEmail ?? googleRegistration?.email ?? '', organization_email: provider ? savedAccountEmail ?? googleRegistration?.email ?? '' : '' });
    type Data = typeof form.data;
    const [readPolicies, setReadPolicies] = useState<Partial<Record<'terms' | 'privacy', string>>>({});
    const [step, setStep] = useState(googleDraft?.step ?? 1);
    const [checking, setChecking] = useState(false);
    const [requestError, setRequestError] = useState('');
    const [verifiedEmail, setVerifiedEmail] = useState(savedAccountEmail ?? googleRegistration?.email ?? '');
    const [verifiedWorkEmail, setVerifiedWorkEmail] = useState(savedWorkEmail ?? '');
    const [leaveAction, setLeaveAction] = useState<(() => void) | null>(null);
    const locked = useRef(false);
    const formRef = useRef<HTMLFormElement>(null);
    const cancel = form.cancel;
    useEffect(() => { try { sessionStorage.removeItem('registration-google-' + accountType); } catch {} if (googleReturn && serverErrors) form.setError(serverErrors as Partial<Record<keyof Data, string>>); }, []);
    const continueWithGoogle = (url: string) => {
        try {
            const data = Object.fromEntries(Object.entries(form.data).filter(([key, value]) => !/password|email|intent|version|accepted|account_type/.test(key) && (typeof value === 'string' || typeof value === 'boolean')));
            sessionStorage.setItem('registration-google-' + accountType, JSON.stringify({ data, step, expires: Date.now() + 30 * 60 * 1000 }));
        } catch { setRequestError('Your browser could not preserve your registration details. Enable session storage and try again.'); return; }
        submitted.current = true;
        window.location.assign(url);
    };
    useEffect(() => {
        const stop = router.on('exception', event => { if (!locked.current) return; event.preventDefault(); locked.current = false; cancel(); setRequestError('Connection failed. Your entries are preserved. Reconnect and try again.'); });
        const stopInvalid = router.on('invalid', event => { if (!locked.current) return; event.preventDefault(); locked.current = false; cancel(); setRequestError(event.detail.response.status === 419 ? 'Your session expired. Reload this page before submitting.' : 'Registration could not be completed. Please retry.'); });
        return () => { stop(); stopInvalid(); };
    }, [cancel]);
    const steps = provider ? ['Representative information', 'Organization information', 'Verification documents', 'Coverage areas', 'Account setup'] : ['Personal information', 'Academic information', 'Address information', 'Account setup'];
    const stepDescriptions = provider
        ? ['Tell us who will administer the scholarships.', 'Introduce your organization or independent practice.', 'Provide the evidence for administrator review.', 'Choose where you plan to offer scholarships.', 'Secure your account and review the policies.']
        : ['Start with a few details about yourself.', 'Tell us where you are in your academic journey.', 'Add your current address using the location selectors.', 'Secure your account and review the policies.'];
    const icons = provider ? [UserRound, Building2, FileCheck2, MapPin, KeyRound] : [UserRound, GraduationCap, MapPin, KeyRound];
    const headingRef = useRef<HTMLHeadingElement>(null);
    const submitted = useRef(false);
    const [choiceLabels, setChoiceLabels] = useState<Record<string, string>>({});
    useEffect(() => { headingRef.current?.focus({ preventScroll: true }); }, [step]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => { if (form.isDirty && !form.processing && !submitted.current) { event.preventDefault(); event.returnValue = ''; } };
        window.addEventListener('beforeunload', warn);
        const stop = router.on('before', event => {
            if (event.detail.visit.method === 'get' && form.isDirty && !submitted.current && event.detail.visit.url.pathname !== window.location.pathname) {
                event.preventDefault();
                const visit = event.detail.visit;
                setLeaveAction(() => () => { submitted.current = true; router.visit(visit.url, visit); });
            }
        });
        return () => { window.removeEventListener('beforeunload', warn); stop(); };
    }, [form.isDirty, form.processing]);
    const goBack = (target: number) => { form.clearErrors(); setRequestError(''); setStep(target); };
    useEffect(() => {
        if (step !== accountStep) return;
        const controller = new AbortController();
        fetch('/register/policies', { signal: controller.signal, headers: { Accept: 'application/json' } }).then(async response => {
            if (!response.ok) throw new Error('Policy versions could not be checked. Please try again.');
            const latest = await response.json() as { terms_version: string; privacy_version: string };
            if (!controller.signal.aborted) {
                form.setData(current => current.terms_version === latest.terms_version && current.privacy_version === latest.privacy_version ? current : { ...current, ...latest, terms_accepted: false, privacy_accepted: false });
                form.clearErrors('terms_version', 'privacy_version');
            }
        }).catch(() => { if (!controller.signal.aborted) setRequestError('Policy versions could not be checked. Return to this step to retry.'); });
        return () => controller.abort();
    }, [step]);
    const change = <K extends keyof Data>(field: K, value: Data[K]) => { form.clearErrors(field); if (field === 'password_confirmation') form.clearErrors('password'); if (field === 'email' || field === 'organization_email') setVerifiedEmail(''); if (field === 'work_email') setVerifiedWorkEmail(''); setRequestError(''); form.setData(current => ({ ...current, [field]: value })); };
    const field = (name: keyof Data, label: string, optional = false, type = 'text') => <Input name={name} label={label + (optional ? ' (optional)' : ' *')} prefix={['contact_number', 'organization_phone'].includes(name) ? '+63' : undefined} value={['contact_number', 'organization_phone'].includes(name) ? String(form.data[name] ?? '').replace(/^\+63/, '') : String(form.data[name] ?? '')} onChange={event => change(name, (['contact_number', 'organization_phone'].includes(name) ? registrationContact(event.target.value) : ['first_name', 'surname', 'middle_name'].includes(name) ? registrationName(event.target.value) : event.target.value) as Data[typeof name])} error={form.errors[name]} type={type} required={!optional} placeholder={name === 'position' ? 'e.g. Scholarship coordinator' : name === 'contact_number' || name === 'organization_phone' ? '9171234567' : name === 'work_email' ? 'yourname@gmail.com' : name.includes('email') ? 'name@example.com' : name === 'website' ? 'https://example.org' : undefined} maxLength={name === 'contact_number' || name === 'organization_phone' ? 10 : name === 'suffix' ? 20 : 255} hint={name === 'contact_number' || name === 'organization_phone' ? 'Enter the 10 digits after +63.' : name === 'work_email' ? 'Use a Gmail address you can access.' : undefined} />;
    const errorStep = (errors: Record<string, string>) => {
        if (errors.email || errors.organization_email) setVerifiedEmail('');
        const keys = Object.keys(errors);
        const personal = ['first_name', 'surname', 'middle_name', 'no_middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'position', 'work_email', ...(!provider ? ['email'] : [])];
        const academic = ['student_number', 'school', 'campus', 'campus_region_code', 'campus_province_code', 'campus_city_code', 'education_level', 'course', 'year_level'];
        const address = ['region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'];
        const org = ['organization_description', 'country', 'organization_name', 'organization_type', 'organization_email', 'organization_phone', 'website', 'authorization_required'];
        const first = keys[0];
        const target = first.startsWith('coverage_areas') ? 4 : personal.includes(first) ? 1 : (provider ? [...org, ...address] : academic).includes(first) ? 2 : (provider ? Object.keys(documents) : address).includes(first) ? 3 : accountStep;
        setStep(target);
        if (errors.account_type) setRequestError(errors.account_type);
        window.setTimeout(() => { const input = formRef.current?.elements.namedItem(first); if (input instanceof HTMLElement) input.focus(); }, 50);
    };
    const next = async () => {
        if (locked.current) return;
        locked.current = true; setChecking(true); setRequestError(''); form.clearErrors();
        const body = new FormData();
        Object.entries(form.data).forEach(([key, value]) => { if (key === 'coverage_areas') { form.data.coverage_areas.forEach((area, index) => Object.entries(area).forEach(([field, entry]) => body.append(`coverage_areas[${index}][${field}]`, entry ?? ''))); return; } if (value !== null) body.append(key, value instanceof File ? value : typeof value === 'boolean' ? (value ? '1' : '0') : String(value)); });
        body.append('step', String(step));
        try {
            const token = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
            const response = await fetch('/register/validate-step', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }, body });
            if (response.status === 422) {
                const result = await response.json() as { errors: Record<string, string[]> };
                const errors = Object.fromEntries(Object.entries(result.errors).map(([key, values]) => [key, values[0]]));
                if (errors.email || errors.organization_email) setVerifiedEmail('');
                form.setError(errors); if (errors.account_type) setRequestError(errors.account_type);
                window.setTimeout(() => { const input = formRef.current?.elements.namedItem(Object.keys(errors)[0]); if (input instanceof HTMLElement) input.focus(); }, 30);
                return;
            }
            if (!response.ok) throw new Error(response.status === 419 ? 'Your session expired. Reload the registration page and try again.' : 'Validation could not be completed. Please retry.');
            const labels: Record<string, string> = {};
            formRef.current?.querySelectorAll('select').forEach(select => { labels[select.name] = select.selectedOptions[0]?.textContent ?? select.value; });
            const result = await response.json() as { coverage_labels?: string[] };
            if (provider && step === 4 && result.coverage_labels) labels.coverage_areas = result.coverage_labels.join('; ');
            setChoiceLabels(current => ({ ...current, ...labels }));
            setStep(current => current + 1);
        } catch (error) { setRequestError(error instanceof Error ? error.message : 'Check your connection and retry.'); }
        finally { locked.current = false; setChecking(false); }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (step < reviewStep) { void next(); return; }
        if (!navigator.onLine) { setRequestError('You are offline. Reconnect before submitting.'); return; }
        if (locked.current) return;
        locked.current = true; setRequestError('');
        form.post('/register', { forceFormData: true, onError: errorStep, onSuccess: () => { submitted.current = true; }, onFinish: () => { locked.current = false; } });
    };
    const requiredDocuments = verificationPolicy[form.data.organization_type]?.required ?? [form.data.organization_type === 'individual' ? 'administration_evidence' : 'organization_proof'];
    const documentKinds = [...new Set(['representative_id', ...requiredDocuments, ...(form.data.authorization_required ? ['authorization_letter'] : []), 'accreditation'])].filter(kind => kind in documents) as (keyof typeof documents)[];
    const file = (kind: keyof typeof documents) => <div key={kind} className={'registration-upload ' + (form.errors[kind] ? 'registration-upload-error' : form.data[kind] ? 'registration-upload-selected' : '')}><div className="flex items-start gap-3"><span className="rounded-xl bg-civic-panel p-2.5 text-civic-navy">{form.data[kind] ? <FileCheck2 aria-hidden="true" className="size-5" /> : <UploadCloud aria-hidden="true" className="size-5" />}</span><div className="min-w-0 flex-1"><label className="block text-sm font-semibold leading-6" htmlFor={kind}>{requiredDocuments.includes(kind) ? documents[kind].replace(' (optional)', '') : documents[kind]}{(kind !== 'accreditation' || requiredDocuments.includes(kind)) && ' *'}</label><p className="mt-1 text-xs leading-5 text-civic-muted">{form.data[kind] ? 'File selected. You can replace it below.' : 'Choose a clear, readable copy. PDF, JPG or PNG, up to 1 MB.'}</p></div>{form.data[kind] && <Check aria-hidden="true" className="mt-1 size-5 shrink-0 text-civic-navy" />}</div><input id={kind} name={kind} type="file" accept="application/pdf,image/jpeg,image/png" aria-invalid={Boolean(form.errors[kind])} aria-describedby={kind + '-feedback'} onChange={event => change(kind, event.target.files?.[0] ?? null)} className={'mt-4 block w-full min-w-0 rounded-lg text-sm text-civic-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-civic-navy file:px-4 file:py-2.5 file:text-xs file:font-semibold file:text-white ' + focusRing} /><p id={kind + '-feedback'} role={form.errors[kind] ? 'alert' : undefined} className={'mt-2 break-all text-xs leading-5 ' + (form.errors[kind] ? 'text-civic-danger' : 'text-civic-muted')}>{form.errors[kind] || (form.data[kind] ? 'Selected: ' + form.data[kind]?.name + ' (' + Math.ceil((form.data[kind]?.size ?? 0) / 1024) + ' KB)' : 'No document selected yet.')}</p>{form.data[kind] && <RegistrationDocumentPreview file={form.data[kind]!} title={documents[kind]} />}</div>;
    const sections = provider ? [
        ['first_name', 'surname', 'middle_name', 'no_middle_name', 'suffix', 'position', 'contact_number', 'work_email'],
        ['organization_description', 'country', 'barangay_code', 'organization_type', ...(form.data.organization_type === 'individual' ? [] : ['organization_name']), 'region_code', 'province_code', 'city_code', 'organization_email', 'organization_phone', 'website', 'authorization_required'],
        ['representative_id', form.data.organization_type === 'individual' ? 'administration_evidence' : 'organization_proof', ...(form.data.authorization_required ? ['authorization_letter'] : []), 'accreditation'],
        ['coverage_areas'],
        [],
    ] : [
        ['first_name', 'surname', 'middle_name', 'no_middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'email'],
        ['student_number', 'school', 'campus', 'campus_region_code', 'campus_province_code', 'campus_city_code', 'education_level', 'course', 'year_level'],
        ['region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'],
        [],
    ];
    const labels: Record<string, string> = { campus_region_code: 'Campus region', campus_province_code: 'Campus province', campus_city_code: 'Campus city / municipality', region_code: 'Region', province_code: 'Province', city_code: 'City / municipality', barangay_code: 'Barangay', terms_accepted: 'Terms of Use', privacy_accepted: 'Privacy Policy', authorization_required: 'Authorization letter required', ...documents };
    const stepper = (mobile = false) => <ol aria-label="Registration progress" className={mobile ? 'registration-stepper-mobile' : 'registration-stepper'}>{[...steps, 'Final review'].map((label, index) => {
        const number = index + 1; const complete = step > number; const active = step === number; const Icon = number === reviewStep ? CheckCheck : icons[index];
        return <li key={label} aria-current={active ? 'step' : undefined} className={'registration-step ' + (complete ? 'is-complete' : active ? 'is-active' : 'is-upcoming')}><span className="registration-step-icon">{complete ? <Check aria-hidden="true" className="size-4" /> : <Icon aria-hidden="true" className="size-4" />}</span><div className={mobile ? 'sr-only' : 'min-w-0 pt-1'}><p className="text-sm font-semibold">{label}</p><p className="mt-1 text-xs leading-5 opacity-75">{complete ? 'Completed' : number === reviewStep ? 'Check your details before submitting.' : stepDescriptions[index]}</p></div><span className="sr-only">{active ? 'Current step' : complete ? 'Completed' : 'Upcoming'}</span></li>;
    })}</ol>;
    const panel = <div className="rounded-2xl border border-civic-border/50 bg-civic-navy p-7 text-white"><div className="flex items-center gap-3"><span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-civic-canvas"><img src="/images/iskoreach-logo.png" alt="IskoReach logo" width={500} height={500} className="size-full scale-125 object-contain" /></span><p className="text-lg font-bold tracking-tight">Your next chapter.</p></div><h2 className="mt-7 text-2xl font-bold leading-9">{provider ? 'Make opportunities possible.' : 'A step closer to your future.'}</h2><p className="mt-3 text-sm leading-7 text-white/80">{provider ? 'Create a trusted space for your scholarship programs. Start with your details; our administrators will review your documents.' : 'Create your student account to discover scholarships, prepare applications, and keep your opportunities in one place.'}</p><div className="my-7 h-px bg-white/15" />{stepper()}<p className="mt-7 flex items-start gap-2 border-t border-white/15 pt-5 text-xs leading-6 text-white/80"><ShieldCheck aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-cream" />{provider ? 'Verification documents stay private. Publishing access follows administrator approval.' : 'Submit your registration for administrator review. Student workspace access opens after approval.'}</p></div>;
    return <AuthLayout title={provider ? 'Provider registration' : 'Student registration'} registrationPanel={panel}>
        <nav aria-label="Registration type" className="mb-6 grid grid-cols-2 gap-1 rounded-xl bg-civic-panel p-1">{(['student', 'provider'] as const).map(role => <Link key={role} href={'/register/' + role} aria-current={accountType === role ? 'page' : undefined} className={'flex min-h-11 items-center justify-center gap-2 rounded-lg px-2 text-xs font-semibold transition sm:text-sm ' + focusRing + (accountType === role ? ' bg-white text-civic-navy shadow-sm' : ' text-civic-muted hover:bg-white/60')}>{role === 'student' ? <GraduationCap aria-hidden="true" className="size-4 shrink-0" /> : <Building2 aria-hidden="true" className="size-4 shrink-0" />}{role === 'student' ? 'Student registration' : 'Provider registration'}</Link>)}</nav>
        {status && <p role="status" className="mb-4 rounded-lg bg-civic-panel p-3 text-sm leading-6">{status}</p>}
        <div className="mb-6 lg:hidden">{stepper(true)}</div>
        <div className="mb-6 border-b border-civic-border/40 pb-5"><div className="mb-2 flex items-center justify-between gap-3"><p aria-live="polite" className="text-xs font-bold uppercase tracking-widest text-civic-muted">{step === reviewStep ? 'Ready when you are' : 'Step ' + step + ' of ' + accountStep}</p></div><h2 ref={headingRef} tabIndex={-1} className="registration-heading text-xl font-bold leading-8 text-civic-navy">{step === reviewStep ? 'Review your registration' : steps[step - 1]}</h2><p className="mt-1 text-sm leading-6 text-civic-muted">{step === reviewStep ? 'Make sure everything looks right. You can edit any section before creating your account.' : stepDescriptions[step - 1]}</p></div>
        {requestError && <p role="alert" className="mb-4 rounded bg-red-50 p-3 text-sm text-civic-danger">{requestError}</p>}
        <form ref={formRef} onSubmit={submit} noValidate aria-busy={checking || form.processing} className="registration-form" onChangeCapture={event => { const target = event.target; if ((target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement) && target.name in form.data) { form.clearErrors(target.name as keyof Data); if (['first_name', 'middle_name', 'no_middle_name', 'surname', 'suffix', 'date_of_birth', 'sex'].includes(target.name)) form.clearErrors('first_name'); if (target.name === 'password_confirmation') form.clearErrors('password'); setRequestError(''); } }}>
            <div key={step} className="registration-step-content space-y-5">
            {!provider && step <= 3 && <><StudentRegistrationFields step={step} data={form.data} errors={form.errors} onChange={(field, value) => { form.clearErrors(field); form.setData(current => ({ ...current, [field]: value })); }} />{step === 1 && <RegistrationEmailField onGoogle={continueWithGoogle} value={form.data.email} onChange={email => change('email', email)} accountType={accountType} registrationIntent={registrationIntent} verified={verifiedEmail === form.data.email.trim().toLowerCase() && !!verifiedEmail} onVerified={email => { setVerifiedEmail(email); form.clearErrors(provider ? 'organization_email' : 'email'); }} error={form.errors.email} />}</>}
            {provider && step === 1 && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Representative information</legend><div className="grid gap-4 sm:grid-cols-2">{field('first_name', 'First name')}{field('surname', 'Surname')}<RegistrationMiddleName data={form.data} errors={form.errors} onChange={(field, value) => { form.clearErrors(field); form.clearErrors('middle_name'); form.setData(current => ({ ...current, [field]: value })); }} /><RegistrationSuffix data={form.data} errors={form.errors} onChange={(field, value) => { form.clearErrors(field); form.setData(current => ({ ...current, [field]: value })); }} /></div>{field('position', 'Position / designation')}{field('contact_number', 'Contact number', false, 'tel')}<RegistrationEmailField purpose="work" onGoogle={continueWithGoogle} value={form.data.work_email} onChange={email => change('work_email', email)} accountType="provider" registrationIntent={registrationIntent} verified={verifiedWorkEmail === form.data.work_email.trim().toLowerCase() && !!verifiedWorkEmail} onVerified={email => { setVerifiedWorkEmail(email); form.clearErrors('work_email'); }} error={form.errors.work_email} /></fieldset>}
            {provider && step === 2 && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Organization information</legend><label className="block text-sm font-semibold">Organization type *<select name="organization_type" value={form.data.organization_type} onChange={event => change('organization_type', event.target.value)} className={'mt-2 w-full rounded border bg-white p-3 ' + focusRing}>{registrationOptions.organizationTypes.map(choice => <option key={choice.value} value={choice.value}>{choice.label}</option>)}</select></label>{form.errors.organization_type && <p role="alert">{form.errors.organization_type}</p>}{form.data.organization_type !== 'individual' ? field('organization_name', 'Organization / institution name') : <p className="text-sm leading-6">Individual applicants use their own name and provide evidence of their scholarship administration role instead of organization registration. An administrator reviews this evidence before granting publishing access.</p>}<p className="text-sm text-civic-muted">Country: Philippines</p><div><label htmlFor="organization_description" className="mb-2 block text-sm font-semibold">Organization and scholarship program description *</label><textarea id="organization_description" name="organization_description" rows={4} required minLength={10} maxLength={4000} value={form.data.organization_description} onChange={event => change('organization_description', event.target.value)} className={'w-full rounded-lg border border-civic-control p-3 text-base ' + focusRing} />{form.errors.organization_description && <p role="alert" className="text-sm text-civic-danger">{form.errors.organization_description}</p>}</div><StudentRegistrationFields step={3} providerAddress data={form.data} errors={form.errors} onChange={(field, value) => { form.clearErrors(field); form.setData(current => ({ ...current, [field]: value })); }} /><RegistrationEmailField onGoogle={continueWithGoogle} value={form.data.organization_email} onChange={email => change('organization_email', email)} accountType={accountType} registrationIntent={registrationIntent} verified={verifiedEmail === form.data.organization_email.trim().toLowerCase() && !!verifiedEmail} onVerified={email => { setVerifiedEmail(email); form.clearErrors(provider ? 'organization_email' : 'email'); }} error={form.errors.organization_email} />{field('organization_phone', 'Organization / administration contact number', false, 'tel')}{field('website', 'Official website', true, 'url')}<label className="flex items-start gap-3 text-sm"><input type="checkbox" name="authorization_required" checked={form.data.authorization_required} onChange={event => change('authorization_required', event.target.checked)} className="mt-1" />I act on behalf of another organization or administrator and need an authorization letter.</label><p className="text-xs text-civic-muted">Select this when you are an appointed representative rather than the organization head or independent administrator. Administrators may request further evidence.</p></fieldset>}
            {provider && step === 3 && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Verification documents</legend><p className="text-sm leading-6">PDF, JPG or PNG; maximum 1 MB per file. These files are private and accessible only to your account and authorized administrators.</p><p className="rounded-lg bg-civic-panel p-3 text-sm leading-6">{verificationPolicy[form.data.organization_type]?.instructions || 'Provide appropriate evidence of your organization or administration role.'}</p>{documentKinds.map(kind => file(kind))}{verificationPolicy[form.data.organization_type]?.required.includes('accreditation') && <p className="text-sm font-semibold">Additional accreditation is required for this organization type.</p>}</fieldset>}
            {provider && step === 4 && <RegistrationCoverageFields areas={form.data.coverage_areas} errors={form.errors} onChange={areas => { form.clearErrors(); change('coverage_areas', areas); setChoiceLabels(current => ({ ...current, coverage_areas: '' })); }} />}
            {step === accountStep && <fieldset className="space-y-4"><legend className="mb-3 font-bold">Account setup</legend><PasswordField name="password" label="Password *" value={form.data.password} onChange={event => change('password', event.target.value)} error={form.errors.password} autoComplete="new-password" hint="Use at least 8 characters and at most 72 UTF-8 bytes." /><PasswordField name="password_confirmation" label="Confirm password *" value={form.data.password_confirmation} onChange={event => change('password_confirmation', event.target.value)} error={form.errors.password_confirmation} autoComplete="new-password" />{(form.errors.terms_version || form.errors.privacy_version) && <p role="alert" className="text-sm text-civic-danger">{form.errors.terms_version || form.errors.privacy_version}</p>}<StudentAgreement versions={{ terms_version: form.data.terms_version, privacy_version: form.data.privacy_version }} readVersions={readPolicies} onRead={(kind, version) => setReadPolicies(current => ({ ...current, [kind]: version }))} onVersion={(kind, version) => { const key = kind === 'terms' ? 'terms_version' : 'privacy_version'; const accepted = kind === 'terms' ? 'terms_accepted' : 'privacy_accepted'; form.setData(current => current[key] === version ? current : { ...current, [key]: version, [accepted]: false }); }} data={form.data} errors={form.errors} onChange={(field, value) => { form.clearErrors(field); form.setData(current => ({ ...current, [field]: value })); }} /><p className="text-sm leading-6">Your email is verified for registration.{provider && ' Your provider account remains pending verification until an administrator approves the required documents. You cannot publish scholarships before approval.'}</p></fieldset>}
            {step === reviewStep && <div className="space-y-5">{sections.map((fields, index) => { const Icon = icons[index]; return <section key={index} className="overflow-hidden rounded-xl border border-civic-border/50"><div className="flex items-center justify-between gap-3 bg-civic-canvas px-4 py-3"><h3 className="flex items-center gap-2 text-sm font-bold"><Icon aria-hidden="true" className="size-4" />{steps[index]}</h3><button type="button" aria-label={'Edit ' + steps[index]} disabled={checking || form.processing} onClick={() => goBack(index + 1)} className={'inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-civic-navy hover:bg-civic-panel ' + focusRing}><Pencil aria-hidden="true" className="size-3.5" />Edit</button></div><dl className="grid gap-x-5 gap-y-4 p-4 sm:grid-cols-2">{fields.map(key => { const value = form.data[key as keyof Data]; if (value === null || value === '' || (key === 'middle_name' && form.data.no_middle_name) || (key === 'no_middle_name' && !form.data.no_middle_name)) return null; return <div key={key} className="min-w-0"><dt className="text-xs capitalize leading-5 text-civic-muted">{labels[key] ?? key.replaceAll('_', ' ')}</dt><dd className="mt-1 break-words text-sm font-medium leading-6">{Array.isArray(value) ? choiceLabels.coverage_areas || value.map(area => area.scope === 'nationwide' ? 'Nationwide' : [area.scope, area.region_code, area.province_code, area.city_code].filter(Boolean).join(' / ')).join('; ') : value instanceof File ? value.name : typeof value === 'boolean' ? value ? 'Yes' : 'No' : choiceLabels[key] ?? String(value).replaceAll('_', ' ')}</dd></div>; })}{index === accountStep - 1 && <><div><dt className="text-xs leading-5 text-civic-muted">Email</dt><dd className="mt-1 break-words text-sm font-medium leading-6">{provider ? form.data.organization_email : form.data.email}</dd></div><div><dt className="text-xs leading-5 text-civic-muted">Password</dt><dd className="mt-1 flex items-center gap-2 text-sm font-medium leading-6"><LockKeyhole aria-hidden="true" className="size-3.5" />Password set</dd></div></>}</dl></section>; })}</div>}
            </div>
            {Object.keys(form.errors).length > 0 && <p role="alert" className="mt-4 rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">Please check the highlighted fields before continuing. Your other details are kept.</p>}
            <div className="registration-navigation"><div className="flex items-center justify-between gap-3"><Button variant="outline" className="registration-nav-button" disabled={step === 1 || checking || form.processing} onClick={() => goBack(step - 1)}><ArrowLeft aria-hidden="true" className="size-4" />Previous</Button><Button type="submit" variant="secondary" className="registration-nav-button min-w-0" loading={checking || form.processing}>{checking ? 'Validating...' : form.processing ? 'Creating account...' : step === reviewStep ? 'Submit registration' : step === accountStep ? 'Review registration' : 'Next'}{!checking && !form.processing && (step === reviewStep ? <Check aria-hidden="true" className="size-4 shrink-0" /> : <ArrowRight aria-hidden="true" className="size-4 shrink-0" />)}</Button></div><p className="mt-3 text-center text-xs leading-5 text-civic-muted">{step === reviewStep ? 'Your verified email will be used to sign in.' : 'Your details stay here as you move between steps.'}</p></div>
        </form><p className="mt-6 text-center text-sm">Already registered? <Link href="/login" className="font-semibold text-civic-teal underline">Log in</Link></p>
        <Modal open={leaveAction !== null} onClose={() => setLeaveAction(null)} title="Leave registration?" description="Your entered details and attached files will be lost if you leave this page."><div className="flex justify-end gap-3"><Button variant="outline" onClick={() => setLeaveAction(null)}>Stay in registration</Button><Button variant="danger" onClick={() => { const leave = leaveAction; setLeaveAction(null); leave?.(); }}>Leave registration</Button></div></Modal>
    </AuthLayout>;
}
