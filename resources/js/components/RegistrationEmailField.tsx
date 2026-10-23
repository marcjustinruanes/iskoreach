import { useEffect, useId, useState } from 'react';
import { Button, Input } from './ui/DesignSystem';
import GoogleAuthButton from './GoogleAuthButton';

interface Props { purpose?: 'account' | 'work'; value: string; onChange: (email: string) => void; accountType: 'student' | 'provider'; registrationIntent: string; verified: boolean; onVerified: (email: string) => void; error?: string; onGoogle: (url: string) => void }
export default function RegistrationEmailField({ value, onChange, accountType, registrationIntent, verified, onVerified, error, onGoogle, purpose = 'account' }: Props) {
    const id = useId();
    const [code, setCode] = useState('');
    const [sent, setSent] = useState(false);
    const [remaining, setRemaining] = useState(0);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [failure, setFailure] = useState('');
    const [emailFailure, setEmailFailure] = useState('');
    const [codeFailure, setCodeFailure] = useState('');
    const [emailTouched, setEmailTouched] = useState(false);
    const gmailError = value.trim() && !/^[^\s@]+@gmail\.com$/i.test(value.trim()) ? 'Use an @gmail.com email address.' : undefined;
    useEffect(() => { setCode(''); setSent(false); setFailure(''); setEmailFailure(''); setCodeFailure(''); setMessage(''); }, [value]);
    useEffect(() => {
        if (!remaining) return;
        const timer = window.setTimeout(() => setRemaining(seconds => Math.max(0, seconds - 1)), 1000);
        return () => window.clearTimeout(timer);
    }, [remaining]);
    const request = async (action: 'send' | 'verify') => {
        if (busy) return;
        if (gmailError) { setEmailTouched(true); return; }
        setBusy(true); setFailure(''); setEmailFailure(''); setCodeFailure(''); setMessage('');
        try {
            const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
            const response = await fetch('/register/email/' + action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }, body: JSON.stringify({ email: value, account_type: accountType, registration_intent: registrationIntent, purpose, code }) });
            const result = await response.json() as { verified?: boolean; retry_after?: number; message?: string; errors?: Record<string, string[]> };
            if (!response.ok) {
                if (result.errors?.email) { setEmailFailure(result.errors.email[0]); return; }
                if (result.errors?.code) { setCodeFailure(result.errors.code[0]); return; }
                throw new Error(result.errors ? Object.values(result.errors)[0][0] : response.status === 429 ? 'Too many requests. Please wait before trying again.' : result.message ?? 'Email verification is unavailable. Please retry.');
            }
            if (action === 'send') { setSent(true); setRemaining(result.retry_after ?? 60); setMessage('Code sent. Check your inbox and spam folder.'); }
            else if (result.verified) { onVerified(value.trim().toLowerCase()); setMessage(''); }
        } catch (error) { setFailure(error instanceof Error ? error.message : 'Check your connection and retry.'); }
        finally { setBusy(false); }
    };
    return <div data-registration-email className="space-y-3 rounded-xl border border-civic-border p-4">
        <div className="flex items-start gap-2 sm:gap-3">
            <div className="min-w-0 flex-1"><Input id={id} label={purpose === 'work' ? 'Work email address *' : accountType === 'provider' ? 'Organization email *' : 'Personal email *'} name={purpose === 'work' ? 'work_email' : accountType === 'provider' ? 'organization_email' : 'email'} type="email" autoComplete="off" required maxLength={255} value={value} disabled={busy} onChange={event => onChange(event.target.value)} onBlur={() => setEmailTouched(true)} placeholder="yourname@gmail.com" error={error || emailFailure || (emailTouched ? gmailError : undefined)} /></div>
            {!verified && <Button className="mt-7 shrink-0 px-2 text-xs sm:px-4 sm:text-sm" variant="outline" loading={busy} disabled={!value.trim() || remaining > 0} onClick={() => void request('send')}>{remaining > 0 ? 'Resend in ' + remaining + 's' : sent ? 'Resend code' : <><span className="sm:hidden">Send code</span><span className="hidden sm:inline">Send verification code</span></>}</Button>}
        </div>
        <p className="text-sm text-civic-muted">{purpose === 'work' ? 'Verify a work Gmail address you can access. Your organization email will be used to sign in.' : 'Use a Gmail address you can access. This will be your login email.'}</p>
        {verified ? <p role="status" className="text-sm font-semibold text-emerald-800">Email verified</p> : <>
            {sent && <div className="flex items-end gap-2 sm:gap-3"><div className="min-w-0 flex-1"><Input label="Verification code" placeholder="6-digit code" name="email_verification_code" value={code} inputMode="numeric" autoComplete="one-time-code" maxLength={6} error={codeFailure} onChange={event => { setCode(event.target.value.replace(/\D/g, '').slice(0, 6)); setCodeFailure(''); setFailure(''); }} /></div><Button className="shrink-0 px-3" disabled={code.length !== 6} loading={busy} onClick={() => void request('verify')}>Verify email</Button></div>}
        </>}
        {!verified && message && <p role="status" className="text-sm text-civic-muted">{message}</p>}
        {failure && <p role="alert" className="text-sm text-civic-danger">{failure}</p>}
        {!verified && <><div className="flex items-center gap-3 text-sm text-civic-muted"><span aria-hidden="true" className="h-px flex-1 bg-civic-border" /><span>or</span><span aria-hidden="true" className="h-px flex-1 bg-civic-border" /></div><GoogleAuthButton intent={purpose === 'work' ? 'provider-work' : accountType} onContinue={onGoogle} /></>}
    </div>;
}
