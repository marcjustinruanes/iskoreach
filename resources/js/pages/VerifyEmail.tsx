import type { SharedProps } from '../types';
import LogoutButton from '../components/LogoutConfirmation';
import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../layouts/AuthLayout';
import { Button, buttonStyles } from '../components/ui/DesignSystem';
export default function VerifyEmail({ status, auth }: SharedProps) {
    const form = useForm({});
    return <AuthLayout title="Verify your email" description="Confirm your email address before entering your workspace."><p className="text-sm leading-7 text-civic-muted">Open the verification link sent to your email address. Check your spam folder, or request another link below. Your registration details are saved.</p>{status === 'verification-link-sent' && <p role="status" className="mt-4 text-sm text-emerald-800">Another verification link has been sent.</p>}{Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="mt-4 text-sm text-civic-danger">{String(error)}</p>)}<form className="mt-5" onSubmit={event => { event.preventDefault(); form.post('/email/verification-notification', { preserveScroll: true }); }}><Button type="submit" className="w-full" loading={form.processing}>Resend verification email</Button></form><Link href="/dashboard" className={buttonStyles('outline') + ' mt-3 w-full'}>I have verified my email</Link>{auth.user?.role === 'student' && <Link href="/student/email-code" className={buttonStyles('outline') + ' mt-3 w-full'}>Use an email verification code</Link>}<LogoutButton href="/logout" className={buttonStyles('ghost') + ' mt-3 w-full'}>Log out</LogoutButton></AuthLayout>;
}
