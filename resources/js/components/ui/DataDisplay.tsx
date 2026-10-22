import { Tab, TabGroup, TabList, TabPanel, TabPanels } from '@headlessui/react';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import type { Paginated } from '../../types';
import type { Key, ReactNode } from 'react';
import { EmptyState, focusRing } from './DesignSystem';

type PaginationData = Pick<Paginated<unknown>, 'current_page' | 'last_page' | 'prev_page_url' | 'next_page_url' | 'total' | 'first_page_url' | 'last_page_url' | 'from' | 'to' | 'links'>;

export function Pagination({ page, label, preserveScroll = true }: { page: PaginationData; label: string; preserveScroll?: boolean }) {
    if (page.last_page <= 1) return null;
    const current = page.current_page;
    const numbers = page.last_page <= 5 ? Array.from({ length: page.last_page }, (_, index) => index + 1)
        : [...new Set([1, current - 1, current, current + 1, page.last_page])].filter(number => number >= 1 && number <= page.last_page).sort((left, right) => left - right);
    const urlFor = (number: number) => page.links?.find(link => Number(link.label) === number)?.url
        ?? (number === 1 ? page.first_page_url : number === page.last_page ? page.last_page_url : null);
    const control = 'inline-flex min-h-11 min-w-10 items-center justify-center gap-1 rounded-lg border border-neutral-200 bg-white px-2 text-sm font-semibold text-civic-navy hover:bg-civic-panel ' + focusRing;
    return <nav aria-label={label} className="mt-6 flex flex-col gap-3 border-t border-civic-blue-gray pt-4 lg:flex-row lg:flex-wrap lg:items-center lg:justify-between">
        <p aria-live="polite" className="text-xs leading-5 text-civic-muted">{page.from != null && page.to != null ? <>Showing <strong className="font-semibold text-civic-navy">{page.from.toLocaleString()}-{page.to.toLocaleString()}</strong> of {page.total.toLocaleString()} records</> : <>{page.total.toLocaleString()} records</>}<span className="ml-2">Page {current} of {page.last_page}</span></p>
        <div className="flex flex-wrap items-center gap-1">
            {page.prev_page_url ? <Link aria-label="Previous page" href={page.prev_page_url} preserveScroll={preserveScroll} className={control}><ChevronLeft aria-hidden="true" className="size-4"/><span className="hidden sm:inline">Previous</span></Link> : <button type="button" aria-label="Previous page" disabled className={control+' cursor-not-allowed opacity-40'}><ChevronLeft aria-hidden="true" className="size-4"/><span className="hidden sm:inline">Previous</span></button>}
            {numbers.map((number, index) => {
                const url = urlFor(number);
                const mobile = Math.abs(number-current) > 1 ? 'hidden sm:inline-flex ' : 'inline-flex ';
                return <span key={number} className={mobile+'items-center gap-1'}>{index > 0 && number - numbers[index-1] > 1 && <span aria-hidden="true" className="hidden px-1 text-civic-muted sm:inline">...</span>}{number === current ? <span aria-current="page" aria-label={'Page '+number+', current page'} className="inline-flex min-h-11 min-w-10 items-center justify-center rounded-lg bg-civic-navy px-2 text-sm font-bold text-white">{number}</span> : url ? <Link aria-label={'Go to page '+number} href={url} preserveScroll={preserveScroll} className={control}>{number}</Link> : null}</span>;
            })}
            {page.next_page_url ? <Link aria-label="Next page" href={page.next_page_url} preserveScroll={preserveScroll} className={control}><span className="hidden sm:inline">Next</span><ChevronRight aria-hidden="true" className="size-4"/></Link> : <button type="button" aria-label="Next page" disabled className={control+' cursor-not-allowed opacity-40'}><span className="hidden sm:inline">Next</span><ChevronRight aria-hidden="true" className="size-4"/></button>}
        </div>
    </nav>;
}

export function Tabs({ items, selectedIndex, onChange }: { items: { id: string; label: string; content: ReactNode }[]; selectedIndex?: number; onChange?: (index: number) => void }) {
    return <TabGroup selectedIndex={selectedIndex} onChange={onChange}>
        <TabList className="flex flex-wrap gap-1 rounded-lg bg-civic-panel p-1">{items.map(item => <Tab key={item.id} className={'min-h-11 rounded-lg px-4 py-2 text-sm font-semibold text-civic-muted data-selected:bg-civic-navy data-selected:text-white ' + focusRing}>{item.label}</Tab>)}</TabList>
        <TabPanels className="mt-5">{items.map(item => <TabPanel key={item.id} className={focusRing}>{item.content}</TabPanel>)}</TabPanels>
    </TabGroup>;
}
export interface TableColumn<T> { key: string; label: string; render: (row: T) => ReactNode; sortValue?: (row: T) => string | number }
export function Table<T>({ rows, columns, rowKey, caption, toolbar }: { rows: T[]; columns: TableColumn<T>[]; rowKey: (row: T) => Key; caption: string; toolbar?: ReactNode }) {
    const [sort, setSort] = useState<{ key: string; descending: boolean } | null>(null);
    const sorted = [...rows]; const column = columns.find(item => item.key === sort?.key);
    if (column?.sortValue && sort) {
        const accessor = column.sortValue;
        sorted.sort((a, b) => { const left = accessor(a); const right = accessor(b); const order = typeof left === 'number' && typeof right === 'number' ? left - right : String(left).localeCompare(String(right)); return sort.descending ? -order : order; });
    }
    if (!rows.length) return <div>{toolbar}<EmptyState title="Nothing to show yet" description="Try adjusting your filters or add the first record." /></div>;
    const changeSort = (key: string) => setSort(previous => ({ key, descending: previous?.key === key ? !previous.descending : false }));
    return <div>{toolbar}
        <div className="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block"><table className="w-full border-collapse bg-white text-left text-sm"><caption className="sr-only">{caption}</caption><thead className="bg-civic-panel"><tr>{columns.map(item => <th key={item.key} scope="col" aria-sort={sort?.key === item.key ? sort.descending ? 'descending' : 'ascending' : item.sortValue ? 'none' : undefined} className="px-4 py-3 font-semibold text-civic-navy">{item.sortValue ? <button type="button" onClick={() => changeSort(item.key)} className={'inline-flex min-h-11 items-center gap-2 rounded ' + focusRing}>{item.label}{sort?.key !== item.key ? <ArrowUpDown className="size-4" aria-hidden="true" /> : sort.descending ? <ArrowDown className="size-4" aria-hidden="true" /> : <ArrowUp className="size-4" aria-hidden="true" />}</button> : item.label}</th>)}</tr></thead>
            <tbody>{sorted.map(row => <tr key={rowKey(row)} className="border-t border-neutral-200 transition-colors hover:bg-civic-canvas/70 focus-within:bg-civic-canvas/70">{columns.map(item => <td key={item.key} className="break-words px-4 py-4 align-top leading-6">{item.render(row)}</td>)}</tr>)}</tbody>
        </table></div>
        <div className="mb-3 flex flex-wrap gap-2 md:hidden">{columns.filter(item => item.sortValue).map(item => <button key={item.key} type="button" onClick={() => changeSort(item.key)} className={'min-h-11 rounded-lg border border-civic-border px-3 py-2 text-xs font-semibold ' + focusRing}>Sort by {item.label}{sort?.key === item.key ? sort.descending ? ' (descending)' : ' (ascending)' : ''}</button>)}</div>
        <ul aria-label={caption} className="space-y-3 md:hidden">{sorted.map(row => <li key={rowKey(row)} className="rounded-lg border border-neutral-200 bg-white p-4"><dl className="space-y-3">{columns.map(item => <div key={item.key}><dt className="text-xs font-semibold text-civic-muted">{item.label}</dt><dd className="mt-1 min-w-0 break-words text-sm leading-6">{item.render(row)}</dd></div>)}</dl></li>)}</ul>
    </div>;
}
