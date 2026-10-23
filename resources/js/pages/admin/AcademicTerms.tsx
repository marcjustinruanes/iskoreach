import AcademicYearField from '../../components/AcademicYearField';
import { AdminCard as Card } from '../../components/admin/AdminPanel';
import { Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, LockKeyhole, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Badge, Button, EmptyState, Input, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';

interface AcademicTerm {
    id: number; academic_year: string; semester: string; starts_on: string | null; ends_on: string | null;
    version: string; editable: boolean; update_url: string;
    usage: { scholarships: number; applications: number; awards: number; renewals: number; releases: number };
}
interface Props extends SharedProps {
    terms: Paginated<AcademicTerm>; filters: { q: string; usage: string; sort: string }; storeUrl: string;
    termRecords: { selected: number | null; label: string | null; options: { id: number; label: string }[]; grants: Paginated<TermRecord> | null; renewals: Paginated<TermRecord> | null };
}
interface TermRecord { id: number; student: string | null; scholarship: string; provider: string; status: string; published?: boolean; reviewed_at?: string | null }
const usageLabels = { scholarships: 'Scholarships', applications: 'Applications', awards: 'Grants', renewals: 'Renewals', releases: 'Benefit records' };
const displayDate = (value: string | null) => value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'Asia/Manila' }).format(new Date(value + 'T00:00:00+08:00')) : 'Not recorded';

export default function AcademicTerms({ terms, filters, storeUrl, termRecords }: Props) {
    const [editor, setEditor] = useState<{ term: AcademicTerm | null } | null>(null);
    const form = useForm(filters);
    const request = useRequestFeedback(form.cancel);
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.get('/admin/academic-terms', { preserveState: true, preserveScroll: true, onFinish: request.finish }); };
    return <AdminLayout title="Academic terms" description="Manage shared academic terms and review the grants and renewals linked to each term." actions={<Button onClick={() => setEditor({ term: null })}><Plus aria-hidden="true" className="size-4" />Create term</Button>}>
        <div className="admin-record-page admin-academic-terms-page">
        <div className="mb-5 flex flex-wrap items-start justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 p-5"><div className="flex max-w-3xl gap-3"><CalendarDays aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-amber-700" /><div><h2 className="text-sm font-semibold text-civic-navy">One shared term directory</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Terms linked to records are read-only to preserve history. Terms can overlap; there is no global active term.</p></div></div></div>
        <Card className="mb-5"><form aria-label="Filter academic terms" onSubmit={submit} className="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <Input label="Search academic year" name="q" type="search" maxLength={100} value={form.data.q} onChange={event => form.setData('q', event.target.value)} error={form.errors.q} placeholder="e.g. 2026-2027" />
            <div><label htmlFor="term-usage" className="mb-2 block text-sm font-semibold">Usage</label><select id="term-usage" name="usage" value={form.data.usage} onChange={event => form.setData('usage', event.target.value)} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base ' + focusRing}><option value="">All terms</option><option value="unused">Unused · editable</option><option value="used">In use · read-only</option></select></div>
            <div><label htmlFor="term-sort" className="mb-2 block text-sm font-semibold">Sort by</label><select id="term-sort" name="sort" value={form.data.sort} onChange={event => form.setData('sort', event.target.value)} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base ' + focusRing}><option value="year_desc">Academic year: Z to A</option><option value="year_asc">Academic year: A to Z</option><option value="latest">Recently created</option></select></div>
            <div className="flex flex-wrap gap-2 xl:pt-7"><Button type="submit" loading={form.processing}>Apply</Button><Button variant="outline" onClick={() => { if (!request.begin()) return; router.get('/admin/academic-terms', {}, { onFinish: request.finish }); }}>Reset</Button></div>
            {request.error && <p role="alert" className="text-sm text-civic-danger sm:col-span-2 xl:col-span-4">{request.error}</p>}{(form.errors.usage || form.errors.sort) && <p role="alert" className="text-sm text-civic-danger">{form.errors.usage || form.errors.sort}</p>}
        </form></Card>
        <p aria-live="polite" className="mb-4 text-sm text-civic-muted">{terms.total} matching {terms.total === 1 ? 'term' : 'terms'}. References include retained scholarships and applications.</p>
        {terms.data.length ? <ul className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">{terms.data.map(term => <li key={term.id} className="min-w-0"><Card className="flex h-full flex-col"><div className="mb-4"><Badge tone={term.editable ? 'green' : 'neutral'} icon={term.editable ? Pencil : LockKeyhole}>{term.editable ? 'Unused · editable' : 'In use · read-only'}</Badge></div><h2 className="break-words text-xl font-bold text-civic-navy">{term.academic_year}</h2><dl className="my-5 grid grid-cols-2 gap-3 rounded-lg bg-civic-panel p-3 text-xs leading-5"><div><dt className="text-civic-muted">Start date</dt><dd className="mt-1 font-semibold">{displayDate(term.starts_on)}</dd></div><div><dt className="text-civic-muted">End date</dt><dd className="mt-1 font-semibold">{displayDate(term.ends_on)}</dd></div></dl><p className="mb-3 text-xs leading-5 text-civic-muted">{Object.entries(term.usage).map(([key, count]) => `${usageLabels[key as keyof typeof usageLabels]}: ${count}`).join(' · ')}</p><Button variant="outline" className="mt-auto w-full" onClick={() => setEditor({ term })}>{term.editable ? 'Edit term' : 'View usage'}<span className="sr-only"> for {term.academic_year}</span></Button></Card></li>)}</ul> : <EmptyState title={filters.q || filters.usage ? 'No matching academic terms' : 'No academic terms yet'} description="Create a term to make it available in the existing scholarship and grant forms. Try resetting the filters if a term is missing." action={<Button onClick={() => setEditor({ term: null })}>Create term</Button>} />}
        <Pagination page={terms} label="Academic term pages" />
        <Card className="mt-6" ><h2 className="text-lg font-bold">Passers and renewals by academic term</h2><label className="mt-4 block text-sm font-semibold">Academic term<select className={'mt-2 min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 ' + focusRing} value={termRecords.selected ?? ''} onChange={event => router.get('/admin/academic-terms', { ...filters, term: event.target.value }, { preserveState: true, preserveScroll: true })}><option value="">Choose an academic term</option>{termRecords.options.map(term => <option key={term.id} value={term.id}>{term.label}</option>)}</select></label>
            {termRecords.selected && <div className="mt-6 space-y-7">{([{ key: 'grants', title: 'New grants and passers', href: '/admin/awards' }, { key: 'renewals', title: 'Renewals', href: '/admin/renewals' }] as const).map(section => { const records = termRecords[section.key]; return records && <section key={section.key}><div className="mb-3 flex flex-wrap justify-between gap-3"><h3 className="font-bold">{section.title} · {records.total}</h3><Link className="text-sm underline" href={section.href + '?term=' + termRecords.selected}>Review {section.key === 'grants' ? 'grants and publication' : 'renewals'}</Link></div>{records.data.length ? <Table rows={records.data} rowKey={record => record.id} caption={section.title + ' for ' + termRecords.label} columns={[
                { key: 'student', label: 'Student', render: record => record.student ?? 'Student account unavailable' },
                { key: 'scholarship', label: 'Scholarship / provider', render: record => <div>{record.scholarship}<p className="mt-1 text-xs text-civic-muted">{record.provider}</p></div> },
                { key: 'status', label: 'Status', render: record => <div>{record.status}{section.key === 'grants' && <p className="mt-1 text-xs text-civic-muted">{record.published ? 'Published passer' : 'Private grant'}</p>}</div> },
            ]} /> : <p className="text-sm text-civic-muted">No {section.title.toLowerCase()} for this term from approved providers.</p>}<Pagination page={records} label={section.title + ' pages'} /></section>; })}</div>}
        </Card>
        {editor && <TermEditor key={editor.term ? editor.term.id + ':' + editor.term.version : 'new'} term={editor.term} storeUrl={storeUrl} onClose={() => setEditor(null)} />}
        </div>
    </AdminLayout>;
}

function TermEditor({ term, storeUrl, onClose }: { term: AcademicTerm | null; storeUrl: string; onClose: () => void }) {
    const readOnly = term !== null && !term.editable;
    const form = useForm({ academic_year: term?.academic_year ?? '', semester: term?.semester ?? 'Academic year', starts_on: term?.starts_on ?? '', ends_on: term?.ends_on ?? '', version: term?.version ?? '', confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const submit = (event: FormEvent) => {
        event.preventDefault(); if (readOnly || !request.begin()) return;
        const options = { preserveScroll: true, onSuccess: onClose, onFinish: request.finish };
        if (term) form.patch(term.update_url, options); else form.post(storeUrl, options);
    };
    return <Modal open panelClassName="max-h-[calc(100dvh-2rem)]" onClose={() => { if (!form.processing) onClose(); }} title={readOnly ? 'Academic term usage' : term ? 'Edit academic term' : 'Create academic term'} description={readOnly ? 'This term is linked to records. Its name and dates are protected to preserve their meaning.' : 'Type consecutive years as YYYY-YYYY. Dates use the Philippine calendar.'}>
        <form onSubmit={submit} className="space-y-5">{readOnly && term ? <dl className="grid gap-4 text-sm sm:grid-cols-2">{[['Academic year', term.academic_year], ['Start date', displayDate(term.starts_on)], ['End date', displayDate(term.ends_on)]].map(([label, value]) => <div key={label}><dt className="text-civic-muted">{label}</dt><dd className="mt-1 break-words font-semibold leading-6">{value}</dd></div>)}</dl> : <div className="grid gap-4 sm:grid-cols-2"><Input label="Academic year" name="academic_year" required maxLength={20} value={form.data.academic_year} disabled={readOnly} onChange={event => form.setData('academic_year', event.target.value)} error={form.errors.academic_year} placeholder="e.g. 2026-2027" /><Input label="Start date (optional)" name="starts_on" type="date" value={form.data.starts_on} disabled={readOnly} onChange={event => form.setData('starts_on', event.target.value)} error={form.errors.starts_on} /><Input label="End date (optional)" name="ends_on" type="date" value={form.data.ends_on} disabled={readOnly} onChange={event => form.setData('ends_on', event.target.value)} error={form.errors.ends_on} /></div>}
            <p className="rounded-lg bg-civic-panel p-3 text-xs leading-5 text-civic-muted">Renewal selection requires a recorded end date that has not passed. Missing dates do not prevent scholarship or grant selection. Review the dates now: used terms cannot be edited.</p>
            {readOnly && term && <dl className="grid grid-cols-2 gap-3 text-sm">{Object.entries(term.usage).map(([key, count]) => <div key={key}><dt className="text-civic-muted">{usageLabels[key as keyof typeof usageLabels]}</dt><dd className="mt-1 font-semibold tabular-nums">{count}</dd></div>)}</dl>}
            {!readOnly && <label className="flex items-start gap-3 text-sm leading-6"><input name="confirmed" type="checkbox" checked={form.data.confirmed} onChange={event => form.setData('confirmed', event.target.checked)} className={'mt-1 size-4 shrink-0 ' + focusRing} />I have reviewed the term labels and dates. I understand that linked terms become read-only.</label>}
            {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}{(['confirmed', 'version', 'term'] as const).map(key => form.errors[key as keyof typeof form.errors] && <p role="alert" key={key} className="text-sm text-civic-danger">{form.errors[key as keyof typeof form.errors]}</p>)}
            {(form.errors.version || 'term' in form.errors) && <Button variant="outline" onClick={() => router.reload({ onSuccess: onClose })}>Reload latest terms</Button>}
            <div className="flex flex-wrap gap-3">{!readOnly && <Button type="submit" loading={form.processing} disabled={!form.data.confirmed}>{term ? 'Save changes' : 'Create term'}</Button>}<Button variant="outline" disabled={form.processing} onClick={onClose}>{readOnly ? 'Close' : 'Cancel'}</Button></div>
        </form>
    </Modal>;
}
