import { Head, router, useForm } from '@inertiajs/react';
import { Award } from 'lucide-react';
import type { Paginated, SharedProps } from '../../types';
import PublicLayout, { PublicPageHeading, publicPageContainer } from '../../layouts/PublicLayout';
import { Badge, Button, Card, EmptyState, Input } from '../../components/ui/DesignSystem';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
import { Pagination } from '../../components/ui/DataDisplay';
interface Passer { name: string; scholarship: string; provider: string | null; published_at: string }
export default function Passers({ passers, filters }: SharedProps & { passers: Paginated<Passer>; filters: { q: string; year: string } }) {
    const form = useForm(filters);
    const request = useRequestFeedback(form.cancel);
    return <PublicLayout><Head title="Public scholarship passers" /><main id="main-content" className={publicPageContainer}>
        <PublicPageHeading title="Scholarship passers" description="Celebrate students with confirmed active scholarship awards. Each public listing has student consent and publication approval." />
        <form className="grid items-end gap-4 sm:grid-cols-[1fr_180px_auto_auto]" aria-label="Filter public passers" onSubmit={event => { event.preventDefault(); if (!request.begin()) return; form.get('/passers', { preserveState: true, onFinish: request.finish }); }}><Input label="Search public listings" name="q" maxLength={120} error={form.errors.q} placeholder="Name, scholarship, or provider" value={form.data.q} onChange={event => form.setData('q', event.target.value)} /><Input label="Publication year" name="year" error={form.errors.year} type="number" min="2000" max="2100" placeholder="Any year" value={form.data.year} onChange={event => form.setData('year', event.target.value)} /><Button type="submit" loading={form.processing}>Search</Button><Button variant="outline" onClick={() => { if (!request.begin()) return; router.get('/passers', {}, { onSuccess: () => form.setData({ q: '', year: '' }), onFinish: request.finish }); }}>Reset</Button></form>
        {request.error && <p role="alert" className="mt-4 text-sm text-civic-danger">{request.error}</p>}
        <p aria-live="polite" className="mt-8 text-sm text-civic-muted">{passers.total} approved public {passers.total === 1 ? 'listing' : 'listings'}.</p>
        {passers.data.length ? <ul className="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-3">{passers.data.map((passer, index) => <li key={passers.current_page + ':' + index} className="min-w-0"><Card className="h-full"><Badge tone="green" icon={Award}>Confirmed award</Badge><h2 className="mt-5 break-words text-xl font-bold leading-7 text-civic-navy">{passer.name}</h2><p className="mt-3 break-words text-base font-semibold leading-6">{passer.scholarship}</p><p className="mt-2 break-words text-sm leading-6 text-civic-muted">{passer.provider}</p><p className="mt-5 text-xs leading-5 text-civic-muted">Published{' '}<time dateTime={passer.published_at}>{new Date(passer.published_at).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' })}</time></p></Card></li>)}</ul> : <div className="mt-5"><EmptyState title={filters.q || filters.year ? "No public listings match" : "No public results yet"} description={filters.q || filters.year ? "Try another name, scholarship, provider, or publication year, or reset the filters." : "Approved scholarship passers will appear here when confirmed awards are ready for publication."} /></div>}
        <Pagination page={passers} label="Public passers pages" />
    </main></PublicLayout>;
}
