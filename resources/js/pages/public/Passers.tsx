import { router, useForm } from '@inertiajs/react';
import type { Paginated, SharedProps } from '../../types';
import PublicDirectoryLayout from '../../layouts/PublicDirectoryLayout';
import { Button, EmptyState, Input } from '../../components/ui/DesignSystem';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
import { Pagination } from '../../components/ui/DataDisplay';
interface Passer { name: string; scholarship: string; provider: string | null; published_at: string }
export default function Passers({ passers, filters }: SharedProps & { passers: Paginated<Passer>; filters: { q: string; year: string } }) {
    const form = useForm(filters);
    const request = useRequestFeedback(form.cancel);
    return <PublicDirectoryLayout title="Scholarship passers" description="Active awardees with student consent and publication approval.">
        <form className="grid items-end gap-4 sm:grid-cols-[1fr_180px_auto_auto]" aria-label="Filter public passers" onSubmit={event => { event.preventDefault(); if (!request.begin()) return; form.get('/passers', { preserveState: true, onFinish: request.finish }); }}><Input label="Search public listings" name="q" maxLength={120} error={form.errors.q} placeholder="Name, scholarship, or provider" value={form.data.q} onChange={event => form.setData('q', event.target.value)} /><Input label="Publication year" name="year" error={form.errors.year} type="number" min="2000" max="2100" placeholder="Any year" value={form.data.year} onChange={event => form.setData('year', event.target.value)} /><Button type="submit" loading={form.processing}>Search</Button><Button variant="outline" onClick={() => { if (!request.begin()) return; router.get('/passers', {}, { onSuccess: () => form.setData({ q: '', year: '' }), onFinish: request.finish }); }}>Reset</Button></form>
        {request.error && <p role="alert" className="mt-4 text-sm text-civic-danger">{request.error}</p>}
        <p aria-live="polite" className="mt-8 text-sm text-civic-muted">{passers.total} approved public {passers.total === 1 ? 'listing' : 'listings'}.</p>
        {passers.data.length ? <section aria-label="Approved scholarship passers" className="mt-5 overflow-hidden rounded-lg border border-civic-border bg-white">
            <div aria-hidden="true" className="hidden grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_9rem] gap-6 border-b border-civic-border bg-slate-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-civic-muted md:grid">
                <span>Awardee</span><span>Scholarship / Provider</span><span>Published</span>
            </div>
            <ul className="divide-y divide-civic-border">{passers.data.map((passer, index) => <li key={passers.current_page + ':' + index} className="grid min-w-0 gap-3 px-5 py-4 transition-colors hover:bg-slate-50 md:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_9rem] md:items-center md:gap-6">
                <h2 className="break-words text-base font-semibold text-civic-navy">{passer.name}</h2>
                <div className="min-w-0"><p className="break-words text-sm font-medium leading-6 text-civic-navy">{passer.scholarship}</p><p className="break-words text-sm leading-6 text-civic-muted">{passer.provider}</p></div>
                <p className="text-sm text-civic-muted"><span className="md:hidden">Published </span><time dateTime={passer.published_at}>{new Date(passer.published_at).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' })}</time></p>
            </li>)}</ul>
        </section> : <div className="mt-5"><EmptyState title={filters.q || filters.year ? "No public listings match" : "No public results yet"} description={filters.q || filters.year ? "Try another name, scholarship, provider, or publication year, or reset the filters." : "Approved scholarship passers will appear here when confirmed grants are ready for publication."} /></div>}
        <Pagination page={passers} label="Public passers pages" />
    </PublicDirectoryLayout>;
}
