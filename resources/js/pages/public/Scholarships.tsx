import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { SharedProps, Paginated } from '../../types';
import type { DiscoveryFilters, PublicScholarship } from '../../scholarshipTypes';
import ScholarshipBrowseLayout from '../../layouts/ScholarshipBrowseLayout';
import { Badge, Button, Card, EmptyState, Input, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { locationLabel, scholarshipDate } from '../../components/ScholarshipDisplay';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
interface Props extends SharedProps { studentWorkspace?: boolean; browseUrl?: string; scholarships: Paginated<PublicScholarship>; filters: DiscoveryFilters; types: string[]; terms: { id: number; label: string }[] }
export default function Scholarships({ scholarships, filters, types, terms, studentWorkspace = false, browseUrl = '/scholarships' }: Props) {
    const form = useForm<DiscoveryFilters>(filters);
    const request = useRequestFeedback(form.cancel);
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.get(browseUrl, { preserveState: true, preserveScroll: true, onFinish: request.finish }); };
    const advancedFilters = Object.entries(filters).some(([field, value]) => !['q', 'location'].includes(field) && value !== '');
    const options: { field: 'type' | 'availability' | 'existing' | 'term' | 'currency'; label: string; values: { value: string; label: string }[] }[] = [
        { field: 'type', label: 'Scholarship type', values: types.map(type => ({ value: type, label: type })) },
        { field: 'availability', label: 'Application period', values: [{ value: 'open', label: 'Open now' }, { value: 'upcoming', label: 'Opens later' }, { value: 'closed', label: 'Closed' }] },
        { field: 'existing', label: 'Accepts existing scholars', values: [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }] },
        { field: 'term', label: 'Academic term', values: terms.map(term => ({ value: String(term.id), label: term.label })) },
        { field: 'currency', label: 'Benefit currency', values: [{ value: 'PHP', label: 'PHP' }, { value: 'USD', label: 'USD' }] },
    ];
    return <ScholarshipBrowseLayout studentWorkspace={studentWorkspace} title="Find a scholarship">
        <Card className="mb-6"><form onSubmit={submit} aria-label="Scholarship discovery filters" className="space-y-3"><div className="grid gap-4 sm:grid-cols-2"><Input label="Search programs or providers" name="q" type="search" maxLength={200} value={form.data.q} onChange={e => form.setData('q', e.target.value)} error={form.errors.q} /><Input label="Location (optional)"  name="location" placeholder="e.g. Quezon City or NCR" value={form.data.location} onChange={e => form.setData('location', e.target.value)} maxLength={100} error={form.errors.location} /></div>
            <details key={JSON.stringify(filters)} open={advancedFilters || undefined} className="border-t border-neutral-200 pt-2"><summary className={'min-h-11 cursor-pointer py-2 text-sm font-semibold text-civic-teal ' + focusRing}>More filters{advancedFilters ? ' · Filters applied' : ''}</summary><div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{options.map(option => <div key={option.field}><label htmlFor={'discover-' + option.field} className="mb-2 block text-sm font-semibold">{option.label}</label><select id={'discover-' + option.field} name={option.field} value={form.data[option.field]} onChange={e => form.setData(option.field, e.target.value)} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 text-base ' + focusRing}><option value="">All</option>{option.values.map(value => <option key={value.value} value={value.value}>{value.label}</option>)}</select></div>)}
                {(['amount_min', 'amount_max', 'opens_from', 'opens_to', 'deadline_from', 'deadline_to'] as const).map(field => <Input key={field} label={{ amount_min: 'Minimum benefit', amount_max: 'Maximum benefit', opens_from: 'Opening date from', opens_to: 'Opening date through', deadline_from: 'Deadline from', deadline_to: 'Deadline through' }[field]} name={field} type={field.startsWith('amount') ? 'number' : 'date'} min={field.startsWith('amount') ? 0 : undefined} step={field.startsWith('amount') ? '0.01' : undefined} value={form.data[field]} onChange={e => form.setData(field, e.target.value)} />)}
            </div><p className="mt-4 text-xs leading-5 text-civic-muted">Philippine time. Amounts default to PHP. Location searches include nationwide programs; add a region to distinguish places with the same name.</p></details>
            {Object.entries(form.errors).filter(([field]) => field !== 'q' && field !== 'location').map(([field, error]) => <p key={field} role="alert" className="text-sm text-civic-danger">{error}</p>)}
            {request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}
            <div className="flex flex-wrap gap-3"><Button type="submit" loading={form.processing}>Apply filters</Button><Button variant="outline" onClick={() => { if (!request.begin()) return; router.get(browseUrl, {}, { onFinish: request.finish }); }}>Reset</Button></div>
        </form></Card>
        <p aria-live="polite" className="mb-4 text-sm text-civic-muted">{scholarships.total} matching {scholarships.total === 1 ? 'scholarship' : 'scholarships'}.</p>
        {scholarships.data.length ? <ul aria-label="Scholarship results" className="grid gap-x-5 gap-y-5 md:grid-cols-2 xl:grid-cols-3">{scholarships.data.map(item => <li key={item.id} data-scholarship-card className="grid min-w-0 grid-rows-subgrid gap-y-0 rounded-xl border border-neutral-200 border-t-2 border-t-civic-navy bg-white row-span-9">
            <div className="flex flex-wrap items-start gap-2 px-5 pt-5 sm:px-6"><Badge tone={item.availability === 'Open now' ? 'green' : item.availability === 'Opens later' ? 'amber' : 'neutral'}>{item.availability}</Badge><Badge>{item.type}</Badge></div>
            <h2 className="px-5 pt-4 text-lg font-bold leading-6 sm:px-6"><Link href={item.show_url} className={'break-words rounded text-civic-navy underline-offset-4 hover:underline ' + focusRing}>{item.title}</Link></h2>
            <p className="px-5 pt-2 pb-5 text-sm leading-6 break-words text-civic-muted sm:px-6">{item.provider}</p>
            <dl className="grid grid-rows-subgrid gap-y-0 row-span-5 text-sm">
                <div data-scholarship-field="benefit" className="border-y border-civic-blue-gray bg-civic-panel/60 px-5 py-4 sm:px-6"><dt className="text-xs font-semibold text-civic-muted">Scholarship benefit</dt><dd className="mt-1 text-xl font-bold tabular-nums text-civic-navy">{item.amount === null ? 'Not specified' : item.currency + ' ' + Number(item.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</dd></div>
                <div data-scholarship-field="location" className="border-b border-neutral-200 px-5 py-3 sm:px-6"><dt className="font-semibold">Location</dt><dd className="mt-1 break-words leading-6 text-civic-muted">{item.locations.map(locationLabel).join('; ') || 'Not specified'}</dd></div>
                <div data-scholarship-field="opening" className="border-b border-neutral-200 px-5 py-3 sm:px-6"><dt className="font-semibold">Applications open</dt><dd className="mt-1 leading-6 text-civic-muted">{scholarshipDate(item.opens_at)}</dd></div>
                <div data-scholarship-field="deadline" className="border-b border-neutral-200 px-5 py-3 sm:px-6"><dt className="font-semibold">Deadline</dt><dd className="mt-1 leading-6 text-civic-muted">{scholarshipDate(item.deadline_at)}</dd></div>
                <div data-scholarship-field="applicants" className="px-5 py-4 sm:px-6"><dt className="font-semibold">Applicant count</dt><dd className="mt-1 tabular-nums"><strong>{item.applicant_count.toLocaleString('en-PH')} total</strong><span className="mt-1 block text-xs leading-5 text-civic-muted">{item.internal_count} internal + {item.external_count} verified external</span>{item.external_count_needs_reverification && <span className="mt-2 block text-xs leading-5 text-amber-900">External snapshot awaits re-verification; the displayed count excludes it.</span>}</dd></div>
            </dl>
            <div data-scholarship-action className="flex items-end rounded-b-xl border-t border-civic-blue-gray bg-civic-canvas px-5 py-4 sm:px-6"><Link href={item.show_url} className={buttonStyles('outline') + ' w-full'}>View program details</Link></div>
        </li>)}</ul> : <EmptyState title="No matching scholarships" description="Try another search or reset the filters. Programs will appear when approved providers publish them." />}
        <Pagination page={scholarships} label="Scholarship result pages" preserveScroll={false} />
    </ScholarshipBrowseLayout>;
}
