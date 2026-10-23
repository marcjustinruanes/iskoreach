import LocationSearchPicker from '../components/LocationSearchPicker';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, Search, FileText } from 'lucide-react';
import type { SharedProps } from '../types';
import { Badge, Button, Input, buttonStyles, focusRing } from '../components/ui/DesignSystem';
import { Modal } from '../components/ui/Overlays';
import { LandingHero, LandingTrust, LandingJourney, LandingStudentTools, LandingProviders, landingContainer, landingTitle } from '../components/LandingSections';
import type { PublicScholarship } from '../scholarshipTypes';
import { locationLabel, scholarshipDate } from '../components/ScholarshipDisplay';
import { useForm } from '@inertiajs/react';
import { useRequestFeedback } from '../components/ui/useRequestFeedback';
import PublicLayout from '../layouts/PublicLayout';

export default function Welcome({ auth, recentScholarships, publicStats }: SharedProps & { recentScholarships: PublicScholarship[]; publicStats: { programs: number; open: number; passers: number } }) {
    const form = useForm({ q: '', location: '' });
    const request = useRequestFeedback(form.cancel);
    const [verificationOpen, setVerificationOpen] = useState(false);
    const providerHref = auth.user ? '/dashboard' : '/register?account=provider';

    return <PublicLayout>
        <Head title="Scholarship discovery"><meta name="description" content="IskoReach scholarship discovery, application, and monitoring for Filipino students and scholarship providers." /></Head>
        <main id="main-content">
            <LandingHero stats={publicStats}>
                <form aria-label="Find scholarships" className="space-y-4" onSubmit={event => { event.preventDefault(); if (!request.begin()) return; form.get('/scholarships', { onFinish: request.finish }); }}>
                    <div className="landing-query-field"><Search aria-hidden="true" className="size-4" /><Input label="Scholarship or provider" id="scholarship-search" name="q" type="search" maxLength={200} value={form.data.q} onChange={event => form.setData('q', event.target.value)} placeholder="Search programs or organizations" error={form.errors.q} /></div>
                    <LocationSearchPicker value={form.data.location} onChange={value => form.setData('location', value)} error={form.errors.location} />
                    <Button type="submit" loading={form.processing} className="w-full"><Search aria-hidden="true" className="size-4" />Find scholarships</Button>
                    {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}
                </form>
            </LandingHero>
            <LandingTrust />
            <section id="discover" className="scroll-mt-24 bg-white py-12 lg:py-16" aria-labelledby="directory-title"><div className={landingContainer}>
                <div className="mb-7 flex flex-wrap items-end justify-between gap-4"><div><h2 id="directory-title" className={landingTitle}>Recently published scholarships</h2><p className="mt-3 text-sm leading-6 text-civic-muted">A few opportunities from the public directory. Review each provider's criteria before applying.</p></div><Link href="/scholarships" className={'inline-flex min-h-11 items-center gap-2 rounded text-sm font-semibold text-civic-navy underline underline-offset-4 ' + focusRing}>View all scholarships<ArrowRight aria-hidden="true" className="size-4" /></Link></div>
                <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3" data-testid="scholarship-grid">{recentScholarships.map(item => <ScholarshipCard key={item.id} item={item} />)}</div>
                {!recentScholarships.length && <div className="landing-empty-scholarships"><span className="landing-empty-scholarships-icon"><FileText aria-hidden="true" /></span><div><h3>No published scholarships yet</h3><p>New programs will appear here when approved providers publish them.</p></div></div>}
                <p className="mt-6 text-xs leading-5 text-civic-muted">Deadlines are shown in Philippine time. Check each program for its full eligibility criteria.</p>
            </div></section>
            <LandingJourney /><LandingStudentTools /><LandingProviders accountHref={providerHref} onReview={() => setVerificationOpen(true)} />
        </main>
        <Modal open={verificationOpen} onClose={() => setVerificationOpen(false)} title="Provider approval process" description="The account approval process already available in IskoReach."><ol className="list-decimal space-y-3 pl-5 text-sm leading-6"><li>Register as a provider and enter your organization details.</li><li>Your account starts with pending approval.</li><li>An administrator reviews your organization and approves or rejects access.</li><li>After approval, you can publish scholarships, review submitted applications, and manage confirmed grants in the provider workspace.</li></ol></Modal>
    </PublicLayout>;
}

function ScholarshipCard({ item }: { item: PublicScholarship }) {
    return <article className="flex h-full min-w-0 flex-col rounded-xl border border-civic-border/60 bg-white p-5 sm:p-6">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2"><p className="min-w-0 break-words text-xs font-semibold leading-5 text-civic-muted">{item.provider}</p><Badge tone={item.availability === 'Open now' ? 'green' : 'neutral'}>{item.availability}</Badge></div>
        <h3 className="break-words text-lg font-bold leading-7 text-civic-navy"><Link href={item.show_url} className={'rounded hover:underline ' + focusRing}>{item.title}</Link></h3><p className="mt-2 text-xs leading-5 text-civic-muted">{item.type}</p>
        <p className="mt-4 text-sm leading-6 text-civic-muted">{item.accepts_existing_scholars ? 'Students with an existing scholarship may apply.' : 'For students without another scholarship.'}</p>
        <dl className="mt-4 space-y-3 border-t border-civic-border/40 pt-4 text-xs leading-5">{[['Location', item.locations.map(locationLabel).join('; ') || 'See program details'], ['Deadline', scholarshipDate(item.deadline_at)]].map(([label, value]) => <div key={label}><dt className="text-civic-muted">{label}</dt><dd className="break-words font-medium text-civic-navy">{value}</dd></div>)}</dl>
        <Link href={item.show_url} className={'mt-auto flex min-h-11 items-center justify-between gap-2 rounded pt-5 text-sm font-semibold text-civic-navy hover:underline ' + focusRing}>View scholarship<ArrowRight aria-hidden="true" className="size-4 shrink-0" /></Link>
    </article>;
}
