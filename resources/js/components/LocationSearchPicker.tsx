import { useId, useState } from 'react';
import { useLocationOptions } from './provider/LocationScopePicker';
import { Button, focusRing } from './ui/DesignSystem';
import { AlertCircle, ArrowRight } from 'lucide-react';

export default function LocationSearchPicker({ value, onChange, error, className = '', label = 'Region / province / city or municipality', warningStyle = false }: { value: string; onChange: (value: string) => void; error?: string; className?: string; label?: string; warningStyle?: boolean }) {
    const id = useId();
    const [retry, setRetry] = useState(0);
    const [selection, setSelection] = useState({ region: '', province: '', value: '' });
    const regions = useLocationOptions('/locations/regions', retry);
    const regionCode = value === selection.value ? selection.region : regions.data.find(region => region.name === value)?.code ?? '';
    const provinceCode = value === selection.value ? selection.province : '';
    const provinces = useLocationOptions(regionCode ? '/locations/regions/' + regionCode + '/provinces' : null, retry);
    const cities = useLocationOptions(regionCode ? '/locations/regions/' + regionCode + '/cities' : null, retry);
    const cityOptions = provinceCode ? cities.data.filter(city => city.province_code === provinceCode) : cities.data;
    const lookupError = regions.error || provinces.error || cities.error;
    const control = 'min-h-11 w-full rounded-lg border border-civic-border bg-white px-3 py-2.5 text-base text-civic-ink disabled:bg-civic-panel ' + focusRing;
    const choose = (region: string, province: string, name: string) => {
        setSelection({ region, province, value: name });
        onChange(name);
    };

    return <fieldset className={'min-w-0 space-y-3 ' + className}>
        <legend className="mb-2 text-sm font-semibold text-civic-ink">{label}</legend>
        <input type="hidden" name="location" value={value} />
        <div><label htmlFor={id + '-region'} className="sr-only">Region</label><select id={id + '-region'} className={control} value={regionCode} disabled={regions.loading || !!regions.error} onChange={event => choose(event.target.value, '', regions.data.find(region => region.code === event.target.value)?.name ?? '')}>
            <option value="">{regions.loading ? 'Loading regions…' : 'All regions'}</option>
            {regions.data.map(region => <option key={region.code} value={region.code}>{region.name}</option>)}
        </select></div>
        {regionCode && <div className="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
            <div><label htmlFor={id + '-province'} className="mb-2 block text-sm font-semibold">Province</label><select id={id + '-province'} className={control} value={provinceCode} disabled={provinces.loading || !!provinces.error || !provinces.data.length} onChange={event => choose(regionCode, event.target.value, provinces.data.find(province => province.code === event.target.value)?.name ?? regions.data.find(region => region.code === regionCode)?.name ?? '')}>
                <option value="">{provinces.loading ? 'Loading provinces…' : provinces.data.length ? 'All provinces' : 'No provinces in this region'}</option>
                {provinces.data.map(province => <option key={province.code} value={province.code}>{province.name}</option>)}
            </select></div>
            <div><label htmlFor={id + '-city'} className="mb-2 block text-sm font-semibold">City / municipality</label><select id={id + '-city'} className={control} value={cityOptions.find(city => city.name === value)?.code ?? ''} disabled={cities.loading || !!cities.error} onChange={event => choose(regionCode, provinceCode, cityOptions.find(city => city.code === event.target.value)?.name ?? provinces.data.find(province => province.code === provinceCode)?.name ?? regions.data.find(region => region.code === regionCode)?.name ?? '')}>
                <option value="">{cities.loading ? 'Loading cities / municipalities…' : 'All cities / municipalities'}</option>
                {cityOptions.map(city => <option key={city.code} value={city.code}>{city.name}</option>)}
            </select></div>
            <Button variant="ghost" size="sm" onClick={() => choose('', '', '')}>Clear location</Button>
        </div>}
        {value && <div className="flex items-center gap-2 text-xs text-civic-muted"><p>Selected location: {value}</p>{!regionCode && <Button variant="ghost" size="sm" onClick={() => choose('', '', '')}>Clear location</Button>}</div>}
        {error && <p role="alert" className="text-sm text-civic-danger">{error}</p>}
        {lookupError && (warningStyle ? <p role="alert" className="location-lookup-warning"><span className="location-lookup-warning-icon"><AlertCircle aria-hidden="true" className="size-5" /></span>Location options are temporarily unavailable.</p> : <p role="alert" className="text-sm text-civic-danger">{lookupError}</p>)}
        {lookupError && (warningStyle ? <button type="button" onClick={() => setRetry(previous => previous + 1)} className={'location-lookup-retry ' + focusRing}>Retry location lookup<ArrowRight aria-hidden="true" className="size-5" /></button> : <Button variant="outline" onClick={() => setRetry(previous => previous + 1)}>Retry location lookup</Button>)}
        {(regions.stale || provinces.stale || cities.stale) && <p role="status" className="text-xs text-civic-muted">Using previously retrieved location options while the location service is unavailable.</p>}
    </fieldset>;
}
