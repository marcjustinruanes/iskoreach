import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Building2, GraduationCap, ShieldCheck } from 'lucide-react';
import PublicLayout from './PublicLayout';

interface AuthLayoutProps { title: string; description?: string; children: ReactNode; registrationPanel?: ReactNode; loginPage?: boolean }

export default function AuthLayout({ title, description, children, registrationPanel, loginPage = false }: AuthLayoutProps) {
    return <PublicLayout>
        <Head title={title} />
        <main id="main-content" className={'mx-auto grid w-full max-w-6xl flex-1 items-start px-4 py-8 sm:px-6 sm:py-12 lg:grid-cols-[0.9fr_1.1fr] lg:gap-4 lg:py-8 ' + (loginPage ? 'auth-login-layout' : registrationPanel ? 'auth-registration-layout' : '')}>
            <aside className={(registrationPanel ? "hidden self-stretch lg:block" : "hidden py-10 lg:block") + (loginPage ? ' auth-login-aside' : '')} aria-label="Account access information">
                {registrationPanel ?? <>
                <p className="auth-login-eyebrow mb-5 text-xs font-bold uppercase tracking-widest text-civic-teal">Account access</p>
                <h2 className="auth-login-title max-w-sm text-3xl font-bold leading-10 tracking-tight text-civic-navy">Your next opportunity starts here.</h2>
                <p className="auth-login-description mt-5 max-w-sm text-base leading-7 text-civic-muted">Find support for your studies or manage your scholarship programs.</p>
                <div className="auth-login-features mt-7 space-y-5">
                    <div className="flex gap-4"><span className="auth-login-feature-icon flex size-11 shrink-0 items-center justify-center rounded-lg bg-civic-panel text-civic-teal"><GraduationCap aria-hidden="true" className="size-5" /></span><div><h3 className="text-sm font-bold text-civic-navy">Student accounts</h3><p className="mt-1 max-w-xs text-sm leading-6 text-civic-muted">Register your student account and verify your email to access your workspace.</p></div></div>
                    <div className="flex gap-4"><span className="auth-login-feature-icon flex size-11 shrink-0 items-center justify-center rounded-lg bg-civic-panel text-civic-teal"><Building2 aria-hidden="true" className="size-5" /></span><div><h3 className="text-sm font-bold text-civic-navy">Scholarship providers</h3><p className="mt-1 max-w-xs text-sm leading-6 text-civic-muted">Submit your organization details for administrator approval.</p></div></div>
                </div>
                <p className="auth-login-recovery mt-10 flex max-w-sm items-start gap-2 border-t border-civic-panel-strong pt-6 text-xs leading-6 text-civic-muted"><ShieldCheck aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-teal" />Use a verified address so you can access account recovery.</p>
                </>}
            </aside>
            <section aria-labelledby="auth-title" className={"mx-auto w-full rounded-xl border border-neutral-200 bg-white px-5 py-6 sm:p-7 " + (registrationPanel ? "max-w-none registration-card" : loginPage ? 'auth-login-card max-w-lg' : "max-w-lg")}>
                <h1 id="auth-title" className="text-2xl font-bold leading-8 tracking-tight text-civic-navy">{loginPage ? 'Welcome back' : title}</h1>
                {description && <p className="mt-2 text-sm leading-6 text-civic-muted">{description}</p>}
                <div className="mt-5">{children}</div>
            </section>
        </main>
    </PublicLayout>;
}
