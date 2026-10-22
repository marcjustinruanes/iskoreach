import RegistrationWizard from '../components/RegistrationWizard';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CheckCircle2, MailCheck } from 'lucide-react';
import AuthLayout from '../layouts/AuthLayout';
import { Button, Input, buttonStyles, focusRing } from '../components/ui/DesignSystem';
import PasswordField from '../components/ui/PasswordField';
import { newPasswordError } from '../components/ui/inputValidation';

type Mode = 'login' | 'register' | 'forgot' | 'reset' | 'confirm';
interface AuthPageProps { mode: Mode; token?: string; email?: string; status?: string; accountType?: 'student' | 'provider'; registrationIntent?: string }
interface AuthForm {
    name: string; email: string; password: string; password_confirmation: string; token: string;
    remember: boolean;
}

export default function Auth(props: AuthPageProps) { if (props.mode === 'register') return <RegistrationWizard key={props.accountType ?? 'student'} accountType={props.accountType ?? 'student'} registrationIntent={props.registrationIntent ?? ''} />; return <AuthScreen key={props.mode + ':' + (props.accountType ?? 'student')} {...props} />; }

function AuthScreen({ mode, token = '', email = '', status }: AuthPageProps) {
    const form = useForm<AuthForm>({ name: '', email, password: '', password_confirmation: '', token, remember: false });
    const formRef = useRef<HTMLFormElement>(null);
    const pending = useRef(false);
    const [requestAgain, setRequestAgain] = useState(false);
    const [requestError, setRequestError] = useState('');
    const cancel = form.cancel;

    useEffect(() => {
        const stopException = router.on('exception', event => {
            if (!pending.current) return;
            event.preventDefault();
            pending.current = false;
            cancel();
            setRequestError('We couldn’t connect. Check your connection and try again.');
        });
        const stopInvalid = router.on('invalid', event => {
            if (!pending.current) return;
            event.preventDefault();
            pending.current = false;
            cancel();
            setRequestError(event.detail.response.status === 419
                ? 'Your session has expired. Reload this page, then try again.'
                : 'We couldn’t complete the request. Please try again in a moment.');
        });
        return () => { stopException(); stopInvalid(); };
    }, [cancel]);

    const titles: Record<Mode, string> = { login: 'Log in', register: 'Create your account', forgot: 'Forgot password?', reset: 'Reset your password', confirm: 'Confirm your password' };
    const descriptions: Record<Mode, string> = {
        login: 'Enter your details to access your IskoReach account.',
        register: 'Choose your account type and enter your details below.',
        forgot: 'Enter the email address associated with your account.',
        reset: 'Choose a new password for your IskoReach account.',
        confirm: 'Enter your current password before continuing.',
    };
    const endpoints: Record<Mode, string> = { login: '/login', register: '/register', forgot: '/forgot-password', reset: '/reset-password', confirm: '/user/confirm-password' };
    const confirmation = mode === 'forgot' && Boolean(status) && !requestAgain;
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setRequestError('');
        if (!navigator.onLine) { setRequestError('You’re offline. Reconnect and try again.'); return; }
        if (mode === 'reset') {
            const errors: Partial<Record<keyof AuthForm, string>> = {};
            const password = newPasswordError(form.data.password, form.data.password_confirmation);
            if (password) errors.password = password;
            form.clearErrors();
            if (Object.keys(errors).length) {
                form.setError(errors);
                const input = formRef.current?.elements.namedItem(Object.keys(errors)[0]);
                if (input instanceof HTMLElement) input.focus();
                return;
            }
        }
        form.post(endpoints[mode], {
            onStart: () => { pending.current = true; },
            onSuccess: () => { if (mode === 'forgot') setRequestAgain(false); },
            onError: errors => {
                const first = Object.keys(errors)[0];
                const input = first ? formRef.current?.elements.namedItem(first) : null;
                if (input instanceof HTMLElement) input.focus();
            },
            onFinish: () => { pending.current = false; form.reset('password', 'password_confirmation'); },
        });
    };

    return <AuthLayout title={confirmation ? 'Check your email' : titles[mode]}
        description={confirmation ? 'Your account recovery request has been received.' : descriptions[mode]}>
        {confirmation ? <div>
            <span className="mb-5 flex size-14 items-center justify-center rounded-full bg-civic-panel"><MailCheck aria-hidden="true" className="size-7 text-civic-teal" /></span>
            <p role="status" className="text-sm leading-7 text-civic-muted">{status}</p>
            <p className="mt-3 text-sm leading-6 text-civic-muted">Check your spam folder too. A reset link can only be used once.</p>
            <Link href="/login" className={buttonStyles('primary') + ' mt-6 w-full'}>Back to log in</Link>
            <Button variant="ghost" className="mt-3 w-full" onClick={() => setRequestAgain(true)}>Use another email or request again</Button>
        </div> : <>
            {status && mode !== 'forgot' && <div role="status" className="mb-5 flex gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm leading-6 text-emerald-900"><CheckCircle2 aria-hidden="true" className="mt-1 size-4 shrink-0" /><p>{status}</p></div>}
            {requestError && <div role="alert" className="mb-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm leading-6 text-red-900"><p>{requestError}</p><button type="button" onClick={() => window.location.reload()} className={'mt-2 min-h-11 rounded underline ' + focusRing}>Reload page</button></div>}
            {mode === 'reset' && Object.keys(form.errors).length > 0 && <div role="alert" className="mb-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm leading-6 text-red-900"><p>We couldn’t reset your password. Check the details below.</p><Link href="/forgot-password" className={'mt-1 inline-flex min-h-11 items-center rounded font-semibold underline ' + focusRing}>Request a new reset link</Link></div>}
            <form ref={formRef} onSubmit={submit} noValidate aria-busy={form.processing} className="space-y-5">
                {mode !== 'confirm' && <Input label="Email address" name="email" type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} autoComplete="email" error={form.errors.email} required maxLength={255} />}
                {mode !== 'forgot' && <PasswordField label="Password" name="password" value={form.data.password} onChange={e => form.setData('password', e.target.value)} autoComplete={mode === 'login' || mode === 'confirm' ? 'current-password' : 'new-password'} error={form.errors.password} hint={mode === 'reset' ? 'Use at least 8 characters and at most 72 UTF-8 bytes. Spaces and symbols are allowed; accented letters and emoji can use several bytes.' : undefined} required />}
                {(mode === 'reset') && <PasswordField label="Confirm password" name="password_confirmation" value={form.data.password_confirmation} onChange={e => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" error={form.errors.password_confirmation} required />}
                {form.errors.token && <p role="alert" className="text-sm text-civic-danger">{form.errors.token}</p>}
                {mode === 'login' && <div className="flex flex-wrap items-center justify-between gap-2">
                    <label className="flex min-h-11 cursor-pointer items-center gap-2.5 text-sm text-civic-muted"><input name="remember" type="checkbox" checked={form.data.remember} onChange={e => form.setData('remember', e.target.checked)} className="size-4 accent-civic-teal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-civic-teal" />Remember me</label>
                    <Link href="/forgot-password" className={'inline-flex min-h-11 items-center rounded text-sm font-semibold text-civic-teal ' + focusRing}>Forgot password?</Link>
                </div>}
                <Button type="submit" loading={form.processing} className="w-full">{form.processing ? 'Please wait…' : mode === 'forgot' ? 'Send reset instructions' : mode === 'reset' ? 'Save new password' : titles[mode]}</Button>
            </form>
            <div className="mt-6 border-t border-neutral-200 pt-5 text-center text-sm leading-6 text-civic-muted">
                {mode === 'login' ? <>New to IskoReach? <Link href="/register" className={'rounded font-semibold text-civic-teal ' + focusRing}>Create an account</Link></>
                    : mode === 'confirm' ? <Link href="/dashboard" className={'rounded font-semibold text-civic-teal ' + focusRing}>Return to my account</Link>
                    : <>Already have an account? <Link href="/login" className={'rounded font-semibold text-civic-teal ' + focusRing}>Log in</Link></>}
            </div>
        </>}
    </AuthLayout>;
}