import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ArrowLeft, Building2, GraduationCap, ShieldCheck } from 'lucide-react';
import PublicLayout from './PublicLayout';
import { focusRing } from '../components/ui/DesignSystem';

interface AuthLayoutProps { title: string; description: string; children: ReactNode }

export default function AuthLayout({ title, description, children }: AuthLayoutProps) {
    return <PublicLayout>
        <Head title={title} />
        <main id="main-content" className="mx-auto grid w-full max-w-6xl flex-1 items-start px-4 py-8 sm:px-6 sm:py-12 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16 lg:py-16">
            <aside className="hidden py-10 lg:block" aria-label="Account access information">
                <p className="mb-5 text-xs font-bold uppercase tracking-widest text-civic-teal">Account access</p>
                <h2 className="max-w-sm text-3xl font-bold leading-10 tracking-tight text-civic-navy">For students and scholarship providers.</h2>
                <p className="mt-5 max-w-sm text-base leading-7 text-civic-muted">Choose the account that fits your role. Your information and permissions stay specific to that role.</p>
                <div className="mt-9 space-y-7">
                    <div className="flex gap-4"><span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-civic-panel text-civic-teal"><GraduationCap aria-hidden="true" className="size-5" /></span><div><h3 className="text-sm font-bold text-civic-navy">Student accounts</h3><p className="mt-1 max-w-xs text-sm leading-6 text-civic-muted">Register and access your student profile immediately.</p></div></div>
                    <div className="flex gap-4"><span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-civic-panel text-civic-teal"><Building2 aria-hidden="true" className="size-5" /></span><div><h3 className="text-sm font-bold text-civic-navy">Scholarship providers</h3><p className="mt-1 max-w-xs text-sm leading-6 text-civic-muted">Submit your organization details for administrator approval.</p></div></div>
                </div>
                <p className="mt-10 flex max-w-sm items-start gap-2 border-t border-civic-panel-strong pt-6 text-xs leading-6 text-civic-muted"><ShieldCheck aria-hidden="true" className="mt-1 size-4 shrink-0 text-civic-teal" />Use an email address you can access for account recovery.</p>
            </aside>
            <section aria-labelledby="auth-title" className="mx-auto w-full max-w-lg rounded-xl border border-neutral-200 bg-white px-5 py-7 shadow-civic-card sm:p-9">
                <h1 id="auth-title" className="text-3xl font-bold leading-10 tracking-tight text-civic-navy">{title}</h1>
                <p className="mt-2 text-base leading-7 text-civic-muted">{description}</p>
                <div className="mt-7">{children}</div>
                <Link href="/" className={'mt-7 inline-flex min-h-11 items-center gap-2 rounded text-sm font-medium text-civic-muted hover:text-civic-teal ' + focusRing}><ArrowLeft aria-hidden="true" className="size-4" />Back to IskoReach</Link>
            </section>
        </main>
    </PublicLayout>;
}
