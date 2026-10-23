import { useState } from 'react';
import type { LocationInput } from '../scholarshipTypes';
import { useLocationOptions } from './provider/LocationScopePicker';
import { Button, Input, focusRing } from './ui/DesignSystem';

interface Props {
    areas: LocationInput[];
    errors: Record<string, string | undefined>;
    onChange: (areas: LocationInput[]) => void;
}
const blankArea = (scope: string): LocationInput => ({ scope, region_code: '', province_code: '', city_code: '' });
const control = 'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base disabled:bg-civic-panel ' + focusRing;

export default function RegistrationCoverageFields({ areas, errors, onChange }: Props) {
    const scope = areas[0]?.scope ?? 'nationwide';
    const [region, setRegion] = useState(areas[0]?.region_code ?? '');
    const [province, setProvince] = useState('');
    const [search, setSearch] = useState('');
    const [retry, setRetry] = useState(0);
    const regions = useLocationOptions(scope === 'nationwide' ? null : '/locations/regions', retry);
    const provinces = useLocationOptions(region && ['province', 'city'].includes(scope) ? '/locations/regions/' + region + '/provinces' : null, retry);
    const cities = useLocationOptions(region && scope === 'city' ? '/locations/regions/' + region + '/cities' : null, retry);
    const source = scope === 'region' ? regions : scope === 'province' ? provinces : cities;
    const field = scope === 'region' ? 'region_code' : scope === 'province' ? 'province_code' : 'city_code';
    const selected = areas.filter(area => Boolean(area[field]));
    const label = scope === 'region' ? 'regions' : scope === 'province' ? 'provinces' : 'cities / municipalities';
    const choices = source.data.filter(choice => (!province || scope !== 'city' || (province === 'none' ? !choice.province_code : choice.province_code === province)) && choice.name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()));
    const toggle = (code: string, checked: boolean) => {
        const next = checked
            ? [...selected, { ...blankArea(scope), region_code: scope === 'region' ? code : region, [field]: code }]
            : selected.filter(area => area[field] !== code);
        onChange(next.length ? next : [blankArea(scope)]);
    };
    const lookupError = regions.error || provinces.error || cities.error;

    return <fieldset className="min-w-0 space-y-4">
        <legend className="mb-3 font-bold">Coverage areas</legend>
        <p className="text-sm leading-6 text-civic-muted">Choose the areas your organization plans to serve. Each scholarship will still have its own coverage selection.</p>
        <div><label htmlFor="scope-0" className="mb-2 block text-sm font-semibold">Coverage type</label><select id="scope-0" className={control} value={scope} onChange={event => { setRegion(''); setProvince(''); setSearch(''); onChange([blankArea(event.target.value)]); }}><option value="nationwide">Nationwide</option><option value="region">Region</option><option value="province">Province</option><option value="city">City / municipality</option></select></div>
        {scope !== 'nationwide' && <>
            {scope !== 'region' && <div className="grid gap-4 sm:grid-cols-2"><div><label htmlFor="region-0" className="mb-2 block text-sm font-semibold">Browse region</label><select id="region-0" className={control} value={region} disabled={regions.loading || !!regions.error} onChange={event => { setRegion(event.target.value); setProvince(''); setSearch(''); }}><option value="">Choose a region</option>{regions.data.map(choice => <option key={choice.code} value={choice.code}>{choice.name}</option>)}</select></div>{scope === 'city' && <div><label htmlFor="coverage-province-filter" className="mb-2 block text-sm font-semibold">Filter by province</label><select id="coverage-province-filter" className={control} value={province} disabled={!region || provinces.loading || !!provinces.error} onChange={event => { setProvince(event.target.value); setSearch(''); }}><option value="">All provinces and independent cities</option>{provinces.data.map(choice => <option key={choice.code} value={choice.code}>{choice.name}</option>)}<option value="none">Independent cities / no province</option></select></div>}</div>}
            {(scope === 'region' || region) && <div className="space-y-3 rounded-xl border border-civic-border bg-civic-canvas p-4">
                <Input label={'Search ' + label} name="coverage-search" type="search" value={search} onChange={event => setSearch(event.target.value)} />
                <fieldset><legend className="mb-2 text-sm font-semibold">Select {label}</legend><div className="max-h-64 overflow-y-auto overscroll-contain rounded-lg border border-civic-border bg-white p-2" aria-busy={source.loading}>
                    {source.loading ? <p role="status" className="p-3 text-sm text-civic-muted">Loading {label}...</p> : source.error ? <p className="p-3 text-sm text-civic-muted">Retry the location lookup to see available {label}.</p> : choices.length ? choices.map(choice => {
                        const checked = selected.some(area => area[field] === choice.code);
                        return <label key={choice.code} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-civic-panel"><input type="checkbox" name="coverage-choice" value={choice.code} checked={checked} disabled={!checked && selected.length >= 20} onChange={event => toggle(choice.code, event.target.checked)} className="size-4 shrink-0" /><span className="min-w-0 break-words">{choice.name}</span></label>;
                    }) : <p className="p-3 text-sm text-civic-muted">{search || province ? 'No matching places. Try another search or filter.' : 'No ' + label + ' are listed in this region.'}</p>}
                </div></fieldset>
            </div>}
            <div className="flex flex-wrap items-center justify-between gap-2"><p role="status" className="text-sm text-civic-muted">{selected.length} of 20 {label} selected. Selections stay saved when you browse another region.</p>{selected.length > 0 && <Button variant="outline" onClick={() => onChange([blankArea(scope)])}>Clear selections</Button>}</div>
        </>}
        {scope === 'nationwide' && <p className="rounded-lg bg-civic-panel p-3 text-sm">Your coverage includes the whole Philippines.</p>}
        {lookupError && <div role="alert" className="space-y-2"><p className="text-sm text-civic-danger">{lookupError}</p><Button variant="outline" onClick={() => setRetry(value => value + 1)}>Retry location lookup</Button></div>}
        {[regions, provinces, cities].some(state => state.stale) && <p role="status" className="text-xs text-amber-900">Using previously retrieved locations while the location service is unavailable.</p>}
        {Object.entries(errors).filter(([key]) => key.startsWith('coverage_areas')).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}
    </fieldset>;
}
