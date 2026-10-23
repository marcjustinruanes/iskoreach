import { useState } from 'react';
import type { LocationInput } from '../scholarshipTypes';
import { useLocationOptions } from './provider/LocationScopePicker';
import { Button, Input, focusRing } from './ui/DesignSystem';

interface Props {
    areas: LocationInput[];
    errors: Record<string, string | undefined>;
    onChange: (areas: LocationInput[]) => void;
}
const blankArea = (scope: string): LocationInput => ({ scope, region_name: '', province_name: '', city_name: '' });
const control = 'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base disabled:bg-civic-panel ' + focusRing;

export default function RegistrationCoverageFields(props: Props) {
    const scope = props.areas[0]?.scope ?? 'nationwide';
    const [panels, setPanels] = useState(() => [...new Set(props.areas.map(area => area.region_name).filter(Boolean))].map((region, id) => ({ id, region })));
    const [nextId, setNextId] = useState(panels.length + 1);
    const rows = panels.length ? panels : [{ id: 0, region: '' }];
    const selected = props.areas.filter(area => Boolean(scope === 'province' ? area.province_name : area.city_name));
    return <div className="space-y-4"><RegistrationCoveragePanel {...props} headerOnly={scope === 'province' || scope === 'city'} onChange={areas => { setPanels([]); setNextId(1); props.onChange(areas); }} />{['province', 'city'].includes(scope) && <>
        {rows.map((panel, index) => <section key={scope + ':' + panel.id} className="space-y-3 rounded-xl border border-civic-border p-4"><div className="flex items-center justify-between gap-3"><h3 className="text-sm font-bold">Region {index + 1}</h3>{rows.length > 1 && <Button variant="ghost" onClick={() => { setPanels(rows.filter(row => row.id !== panel.id)); const remaining = selected.filter(area => area.region_name !== panel.region); props.onChange(remaining.length ? remaining : [blankArea(scope)]); }}>Remove region</Button>}</div><RegistrationCoveragePanel areas={props.areas} errors={{}} panelIndex={index} initialRegion={panel.region} usedRegions={rows.filter(row => row.id !== panel.id).map(row => row.region)} onRegionChange={region => { setPanels(rows.map(row => row.id === panel.id ? { ...row, region } : row)); const remaining = selected.filter(area => area.region_name !== panel.region); props.onChange(remaining.length ? remaining : [blankArea(scope)]); }} onChange={props.onChange} panelOnly /></section>)}
        <Button variant="outline" disabled={rows.some(row => !row.region) || selected.length >= 20} onClick={() => { setPanels([...rows, { id: nextId, region: '' }]); setNextId(nextId + 1); }}>Add another region</Button>
    </>}</div>;
}

function RegistrationCoveragePanel({ areas, errors, onChange, headerOnly = false, panelOnly = false, panelIndex = 0, initialRegion, usedRegions = [], onRegionChange }: Props & { headerOnly?: boolean; panelOnly?: boolean; panelIndex?: number; initialRegion?: string; usedRegions?: string[]; onRegionChange?: (region: string) => void }) {
    const scope = areas[0]?.scope ?? 'nationwide';
    const [region, setRegion] = useState(initialRegion ?? areas[0]?.region_name ?? '');
    const [province, setProvince] = useState('');
    const [search, setSearch] = useState('');
    const [retry, setRetry] = useState(0);
    const regions = useLocationOptions(scope === 'nationwide' ? null : '/locations/regions', retry);
    const provinces = useLocationOptions(region && ['province', 'city'].includes(scope) ? '/locations/regions/' + region + '/provinces' : null, retry);
    const cities = useLocationOptions(region && scope === 'city' ? '/locations/regions/' + region + '/cities' : null, retry);
    const source = scope === 'region' ? regions : scope === 'province' ? provinces : cities;
    const field = scope === 'region' ? 'region_name' : scope === 'province' ? 'province_name' : 'city_name';
    const selected = areas.filter(area => Boolean(area[field]));
    const label = scope === 'region' ? 'regions' : scope === 'province' ? 'provinces' : 'cities / municipalities';
    const choices = source.data.filter(choice => (!province || scope !== 'city' || (province === 'Not applicable (independent city)' ? !choice.province_name : choice.province_name === province)) && choice.name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()));
    const toggle = (code: string, provinceName: string | undefined, checked: boolean) => {
        const next = checked
            ? [...selected, { ...blankArea(scope), region_name: scope === 'region' ? code : region, [field]: code, ...(scope === 'city' ? { province_name: provinceName ?? '' } : {}) }]
            : selected.filter(area => area[field] !== code || (scope === 'city' && (area.province_name ?? '') !== (provinceName ?? '')));
        onChange(next.length ? next : [blankArea(scope)]);
    };
    const lookupError = regions.error || provinces.error || cities.error;

    return <fieldset className="min-w-0 space-y-4">
        {!panelOnly && <legend className="mb-3 font-bold">Coverage areas</legend>}
        {!panelOnly && <p className="text-sm leading-6 text-civic-muted">Choose the areas your organization plans to serve. Each scholarship will still have its own coverage selection.</p>}
        {!panelOnly && <div><label htmlFor="scope-0" className="mb-2 block text-sm font-semibold">Coverage type</label><select id="scope-0" className={control} value={scope} onChange={event => { setRegion(''); setProvince(''); setSearch(''); onChange([blankArea(event.target.value)]); }}><option value="nationwide">Nationwide</option><option value="region">Region</option><option value="province">Province</option><option value="city">City / municipality</option></select></div>}
        {!headerOnly && scope !== 'nationwide' && <>
            {scope !== 'region' && <div className="grid gap-4 sm:grid-cols-2"><div><label htmlFor={'region-' + panelIndex} className="mb-2 block text-sm font-semibold">Browse region</label><select id={'region-' + panelIndex} className={control} value={region} disabled={regions.loading || !!regions.error} onChange={event => { setRegion(event.target.value); onRegionChange?.(event.target.value); setProvince(''); setSearch(''); }}><option value="">Choose a region</option>{regions.data.filter(choice => !usedRegions.includes(choice.code)).map(choice => <option key={choice.code} value={choice.code}>{choice.name}</option>)}</select></div>{scope === 'city' && <div><label htmlFor={'coverage-province-filter-' + panelIndex} className="mb-2 block text-sm font-semibold">Filter by province</label><select id={panelIndex === 0 ? 'coverage-province-filter' : 'coverage-province-filter-' + panelIndex} className={control} value={province} disabled={!region || provinces.loading || !!provinces.error} onChange={event => { setProvince(event.target.value); setSearch(''); }}><option value="">All provinces and independent cities</option>{provinces.data.map(choice => <option key={choice.code} value={choice.code}>{choice.name}</option>)}<option value="none">Independent cities / no province</option></select></div>}</div>}
            {(scope === 'region' || region) && <div className="space-y-3 rounded-xl border border-civic-border bg-civic-canvas p-4">
                <Input label={'Search ' + label} name={panelIndex === 0 ? 'coverage-search' : 'coverage-search-' + panelIndex} type="search" value={search} onChange={event => setSearch(event.target.value)} />
                <fieldset><legend className="mb-2 text-sm font-semibold">Select {label}</legend><div className="max-h-64 overflow-y-auto overscroll-contain rounded-lg border border-civic-border bg-white p-2" aria-busy={source.loading}>
                    {source.loading ? <p role="status" className="p-3 text-sm text-civic-muted">Loading {label}...</p> : source.error ? <p className="p-3 text-sm text-civic-muted">Retry the location lookup to see available {label}.</p> : choices.length ? choices.map(choice => {
                        const checked = selected.some(area => area[field] === choice.code && (scope !== 'city' || (area.province_name ?? '') === (choice.province_name ?? '')));
                        return <label key={choice.code + ':' + (choice.province_name ?? '')} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-civic-panel"><input type="checkbox" name="coverage-choice" value={choice.code} checked={checked} disabled={!checked && selected.length >= 20} onChange={event => toggle(choice.code, choice.province_name, event.target.checked)} className="size-4 shrink-0" /><span className="min-w-0 break-words">{choice.name}{scope === 'city' && !province && choice.province_name ? ' - ' + choice.province_name : ''}</span></label>;
                    }) : <p className="p-3 text-sm text-civic-muted">{search || province ? 'No matching places. Try another search or filter.' : 'No ' + label + ' are listed in this region.'}</p>}
                </div></fieldset>
            </div>}
            <div className="flex flex-wrap items-center justify-between gap-2"><p role="status" className="text-sm text-civic-muted">{selected.length} of 20 {label} selected. Selections stay saved when you browse another region.</p>{selected.length > 0 && <Button variant="outline" onClick={() => { const remaining = panelOnly ? selected.filter(area => area.region_name !== region) : []; onChange(remaining.length ? remaining : [blankArea(scope)]); }}>Clear selections</Button>}</div>
        </>}
        {scope === 'nationwide' && <p className="rounded-lg bg-civic-panel p-3 text-sm">Your coverage includes the whole Philippines.</p>}
        {lookupError && <div role="alert" className="space-y-2"><p className="text-sm text-civic-danger">{lookupError}</p><Button variant="outline" onClick={() => setRetry(value => value + 1)}>Retry location lookup</Button></div>}
        {[regions, provinces, cities].some(state => state.stale) && <p role="status" className="text-xs text-amber-900">Using previously retrieved locations while the location service is unavailable.</p>}
        {Object.entries(errors).filter(([key]) => key.startsWith('coverage_areas')).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}
    </fieldset>;
}
