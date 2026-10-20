import { Tab, TabGroup, TabList, TabPanel, TabPanels } from '@headlessui/react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import type { Paginated } from '../../types';
import type { Key, ReactNode } from 'react';
import { EmptyState, focusRing } from './DesignSystem';

export function Pagination({ page, label }: { page: Pick<Paginated<unknown>, 'current_page' | 'last_page' | 'prev_page_url' | 'next_page_url' | 'total'>; label: string }) {
    if (page.last_page <= 1) return null;
    return <nav aria-label={label} className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-civic-panel-strong pt-4 text-sm"><span className="text-civic-muted">Page {page.current_page} of {page.last_page}</span><div className="flex gap-3">{page.prev_page_url && <Link href={page.prev_page_url} preserveScroll className={'inline-flex min-h-11 items-center rounded-lg px-3 font-semibold text-civic-teal ' + focusRing}>Previous</Link>}{page.next_page_url && <Link href={page.next_page_url} preserveScroll className={'inline-flex min-h-11 items-center rounded-lg px-3 font-semibold text-civic-teal ' + focusRing}>Next</Link>}</div></nav>;
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
        <div className="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block"><table className="w-full border-collapse bg-white text-left text-sm"><caption className="sr-only">{caption}</caption><thead className="bg-civic-panel"><tr>{columns.map(item => <th key={item.key} scope="col" aria-sort={sort?.key === item.key ? sort.descending ? 'descending' : 'ascending' : item.sortValue ? 'none' : undefined} className="px-4 py-2 font-semibold text-civic-navy">{item.sortValue ? <button type="button" onClick={() => changeSort(item.key)} className={'inline-flex min-h-11 items-center gap-2 rounded ' + focusRing}>{item.label}{sort?.key !== item.key ? <ArrowUpDown className="size-4" aria-hidden="true" /> : sort.descending ? <ArrowDown className="size-4" aria-hidden="true" /> : <ArrowUp className="size-4" aria-hidden="true" />}</button> : item.label}</th>)}</tr></thead>
            <tbody>{sorted.map(row => <tr key={rowKey(row)} className="border-t border-neutral-200">{columns.map(item => <td key={item.key} className="px-4 py-4 align-top">{item.render(row)}</td>)}</tr>)}</tbody>
        </table></div>
        <div className="mb-3 flex flex-wrap gap-2 md:hidden">{columns.filter(item => item.sortValue).map(item => <button key={item.key} type="button" onClick={() => changeSort(item.key)} className={'min-h-11 rounded-lg border border-civic-border px-3 py-2 text-xs font-semibold ' + focusRing}>Sort by {item.label}{sort?.key === item.key ? sort.descending ? ' ↓' : ' ↑' : ''}</button>)}</div>
        <ul aria-label={caption} className="space-y-3 md:hidden">{sorted.map(row => <li key={rowKey(row)} className="rounded-lg border border-neutral-200 bg-white p-4"><dl className="space-y-3">{columns.map(item => <div key={item.key}><dt className="text-xs font-semibold text-civic-muted">{item.label}</dt><dd className="mt-1 text-sm">{item.render(row)}</dd></div>)}</dl></li>)}</ul>
    </div>;
}
