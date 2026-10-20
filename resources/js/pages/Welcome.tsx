import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, GraduationCap, Search } from 'lucide-react';
import type { SharedProps } from '../types';
import { Badge, Button, EmptyState, Input, buttonStyles, focusRing, motion } from '../components/ui/DesignSystem';
import { Modal } from '../components/ui/Overlays';
import { LandingCallToAction, LandingHero, LandingJourney, LandingProviders, LandingStudentTools, landingContainer, landingTitle, useLandingMotion } from '../components/LandingSections';
import type { PublicScholarship } from '../scholarshipTypes';
import { locationLabel, scholarshipDate } from '../components/ScholarshipDisplay';
import { useForm } from '@inertiajs/react';
import { useRequestFeedback } from '../components/ui/useRequestFeedback';
import PublicLayout from '../layouts/PublicLayout';

export default function Welcome({ auth, recentScholarships, publicStats }: SharedProps & { recentScholarships: PublicScholarship[]; publicStats: { programs: number; open: number; passers: number } }) {
    const mainRef = useLandingMotion();
    const form = useForm({ q: '', location: '' });
    const request = useRequestFeedback(form.cancel);
    const [verificationOpen, setVerificationOpen] = useState(false);
    const accountHref = auth.user ? '/dashboard' : '/register';
    const providerHref = auth.user ? '/dashboard' : '/register?account=provider';

    return <PublicLayout>
        <Head title="Scholarship discovery"><meta name="description" content="IskoReach scholarship discovery, application, and monitoring for Filipino students and scholarship providers." /></Head>
        <main ref={mainRef} id="main-content">
            <LandingHero stats={publicStats} />
            <section id="discover" className="scroll-mt-24 bg-civic-panel py-14 lg:py-20" aria-labelledby="directory-title"><div className={landingContainer}>
                <div className="mb-8 flex flex-wrap items-end justify-between gap-5"><div className="max-w-2xl"><span className="mb-3 block text-xs font-bold uppercase tracking-widest text-civic-teal">Public scholarship directory</span><h2 id="directory-title" className={landingTitle}>Find Scholarships That Fit Your Goals.</h2><p className="mt-3 text-civic-small text-civic-muted">Explore published programs from approved providers. Search any location and review each program’s criteria before applying.</p></div><Link href="/scholarships" className={buttonStyles('outline')}>Browse all scholarships<ArrowRight aria-hidden="true" className="size-4" /></Link></div>
                <form aria-label="Find scholarships" className="mb-8 grid items-start gap-4 rounded-xl bg-white p-4 shadow-civic-card sm:p-5 lg:grid-cols-[1fr_1fr_auto]" onSubmit={event => { event.preventDefault(); if (!request.begin()) return; form.get('/scholarships', { onFinish: request.finish }); }}>
                    <Input label="Scholarship or provider" id="scholarship-search" name="q" type="search" maxLength={200} value={form.data.q} onChange={event => form.setData('q', event.target.value)} placeholder="Search programs or organizations" error={form.errors.q} />
                    <Input label="Region or city / municipality" name="location" maxLength={100} value={form.data.location} onChange={event => form.setData('location', event.target.value)} placeholder="Explore any location" error={form.errors.location} />
                    <Button type="submit" loading={form.processing} className="lg:mt-7"><Search aria-hidden="true" className="size-4" />Find scholarships</Button>
                    {request.error && <p role="alert" className="text-sm text-civic-danger lg:col-span-3">{request.error}</p>}
                </form>
                <h3 className="mb-4 text-lg font-bold text-civic-navy">Recently published programs</h3>
                <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3" data-testid="scholarship-grid">{recentScholarships.map(item => <ScholarshipCard key={item.id} item={item} />)}</div>
                {!recentScholarships.length && <EmptyState title="No published scholarships yet" description="Programs will appear here when approved providers publish them. You can still explore the directory and learn how applications work." action={<Link href="/scholarships" className={buttonStyles('outline')}>Open scholarship directory</Link>} />}
                <p className="mt-6 text-xs leading-5 text-civic-muted">Availability and deadlines are shown in Philippine time. Provider approval does not guarantee an award or certify every eligibility claim.</p>
            </div></section>
            <section className={landingContainer + ' py-10'} aria-labelledby="public-awards-title"><div className="flex flex-wrap items-center justify-between gap-5 rounded-2xl border border-civic-panel-strong bg-white p-6 sm:p-8"><div className="max-w-2xl"><h2 id="public-awards-title" className="text-2xl font-bold text-civic-navy">Meet the IskoReach scholars</h2><p className="mt-3 text-sm leading-6 text-civic-muted">Public listings include confirmed active awards with student consent and publication approval. Application acceptance alone does not create a public listing.</p></div><Link href="/passers" className={buttonStyles('secondary')}>View public passers<ArrowRight aria-hidden="true" className="size-4" /></Link></div></section>
            <LandingJourney /><LandingStudentTools /><LandingProviders accountHref={providerHref} onReview={() => setVerificationOpen(true)} /><LandingCallToAction accountHref={accountHref} signedIn={Boolean(auth.user)} />
        </main>
        <Modal open={verificationOpen} onClose={() => setVerificationOpen(false)} title="Provider approval process" description="The account approval process already available in IskoReach."><ol className="list-decimal space-y-3 pl-5 text-sm leading-6"><li>Register as a provider and enter your organization details.</li><li>Your account starts with pending approval.</li><li>An administrator reviews your organization and approves or rejects access.</li><li>After approval, you can publish scholarships, review submitted applications, and manage confirmed awards in the provider workspace.</li></ol></Modal>
    </PublicLayout>;
}

function ScholarshipCard({ item }: { item: PublicScholarship }) {
    return <article className={'flex h-full min-w-0 flex-col rounded-xl bg-white p-5 shadow-civic-card hover:shadow-civic-raised motion-safe:hover:-translate-y-1 ' + motion}>
        <div className="mb-5 flex flex-wrap items-center justify-between gap-2"><span className="flex size-11 items-center justify-center rounded-lg bg-civic-navy text-civic-mint"><GraduationCap aria-hidden="true" className="size-6" /></span><Badge tone={item.availability === 'Open now' ? 'green' : 'neutral'}>{item.availability}</Badge></div>
        <p className="break-words text-xs font-semibold leading-5 text-civic-teal">{item.provider}</p><h4 className="mt-2 break-words text-lg font-bold leading-6 text-civic-navy"><Link href={item.show_url} className={'rounded hover:text-civic-teal ' + focusRing}>{item.title}</Link></h4><p className="mt-2 text-xs text-civic-muted">{item.type}</p>
        <dl className="mt-5 space-y-3 rounded-lg bg-civic-panel p-3 text-xs leading-5">{[['Benefit', item.amount === null ? 'Not specified' : item.currency + ' ' + item.amount], ['Location', item.locations.map(locationLabel).join('; ') || 'Not specified'], ['Applications open', scholarshipDate(item.opens_at)], ['Deadline', scholarshipDate(item.deadline_at)]].map(([label, value]) => <div key={label}><dt className="text-civic-muted">{label}</dt><dd className="break-words font-semibold">{value}</dd></div>)}</dl>
        <Link href={item.show_url} className={buttonStyles('outline') + ' mt-5'}>View program details<ArrowRight aria-hidden="true" className="size-4" /></Link>
    </article>;
}
