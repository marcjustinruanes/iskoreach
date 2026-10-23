import { useEffect, useState } from 'react';
import type { LocationInput } from '../../scholarshipTypes';
import { Button, focusRing } from '../ui/DesignSystem';
interface Choice { code: string; name: string; province_name?: string }
const control = 'mt-2 min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base disabled:bg-civic-panel ' + focusRing;
export function useLocationOptions(url: string | null, retry: number) {
    const [state, setState] = useState({ data: [] as Choice[], loading: false, stale: false, error: '' });
    useEffect(() => {
        const controller = new AbortController();
        setState({ data: [], loading: !!url, stale: false, error: '' });
        if (url) fetch('/locations/names?' + new URLSearchParams({ path: url }), { signal: controller.signal, headers: { Accept: 'application/json' } }).then(async response => {
            if (!response.ok) throw new Error('Location options are unavailable. Retry shortly.');
            const result: { data: Choice[]; stale: boolean } = await response.json();
            if (!controller.signal.aborted) setState({ ...result, loading: false, error: '' });
        }).catch(() => { if (!controller.signal.aborted) setState({ data: [], loading: false, stale: false, error: 'Location options are unavailable. Retry shortly. Your selection is preserved.' }); });
        return () => controller.abort();
    }, [url, retry]);
    return state;
}
export default function LocationScopePicker({ location, index, onChange, onRemove, scopeLocked = false }: { scopeLocked?: boolean; location: LocationInput; index: number; onChange: (next: LocationInput) => void; onRemove: () => void }) {
    const [retry, setRetry] = useState(0);
    const regions = useLocationOptions(location.scope === 'nationwide' ? null : '/locations/regions', retry);
    const cities = useLocationOptions(location.scope === 'city' && Boolean(location.region_name) ? '/locations/regions/' + location.region_name + '/cities' : null, retry);
    const provinces = useLocationOptions(['province', 'city'].includes(location.scope) && Boolean(location.region_name) ? '/locations/regions/' + location.region_name + '/provinces' : null, retry);
    return <div className="space-y-3 rounded-xl border border-civic-panel-strong bg-slate-50/50 p-4"><div className="grid gap-4 lg:grid-cols-3">
        <label className="block text-sm font-semibold" htmlFor={'scope-' + index}>Coverage {index + 1}<select id={'scope-' + index} disabled={scopeLocked} className={control} value={location.scope} onChange={e => onChange({ scope: e.target.value, region_name: '', city_name: '', province_name: '' })}><option value="nationwide">Nationwide</option><option value="region">Region</option><option value="province">Province</option><option value="city">City / municipality</option></select></label>
        {location.scope !== 'nationwide' && <label className="block text-sm font-semibold" htmlFor={'region-' + index}>Region<select id={'region-' + index} className={control} required value={location.region_name} disabled={regions.loading || !!regions.error} onChange={e => onChange({ ...location, region_name: e.target.value, city_name: '', province_name: '' })}><option value="">{regions.loading ? 'Loading regions…' : 'Choose a region'}</option>{location.region_name && !regions.data.some(row => row.code === location.region_name) && <option value={location.region_name}>Saved region: {location.region_name}</option>}{regions.data.map(row => <option key={row.code} value={row.code}>{row.name}</option>)}</select></label>}
        {['province', 'city'].includes(location.scope) && <label className="block text-sm font-semibold">Province<select aria-label={'Province ' + (index + 1)} className={control} required value={location.province_name ?? ''} disabled={provinces.loading || !!provinces.error} onChange={e => onChange({ ...location, province_name: e.target.value, city_name: '' })}><option value="">Choose a province</option>{location.province_name && !provinces.data.some(row => row.code === location.province_name) && <option value={location.province_name}>Saved province: {location.province_name}</option>}{provinces.data.map(row => <option key={row.code} value={row.code}>{row.name}</option>)}{location.scope === 'city' && <option value="Not applicable (independent city)">Not applicable - independent city / NCR</option>}</select></label>}
        {location.scope === 'city'  && <label className="block text-sm font-semibold" htmlFor={'city-' + index}>City / municipality<select id={'city-' + index} className={control} required value={location.city_name} disabled={!location.province_name || cities.loading || !!cities.error || !cities.data.length} onChange={e => onChange({ ...location, city_name: e.target.value })}><option value="">{cities.loading ? 'Loading places…' : 'Choose a city / municipality'}</option>{location.city_name && !cities.data.some(row => row.code === location.city_name) && <option value={location.city_name}>Saved place: {location.city_name}</option>}{cities.data.filter(row => location.province_name === 'Not applicable (independent city)' ? !row.province_name : row.province_name === location.province_name).map(row => <option key={row.code} value={row.code}>{row.name}</option>)}</select></label>}
    </div>{(regions.error || cities.error || provinces.error) && <div role="alert" className="space-y-2 text-sm leading-6 text-civic-danger"><p>{regions.error || cities.error || provinces.error}</p><Button variant="outline" onClick={() => setRetry(value => value + 1)}>Retry location lookup</Button></div>}{(regions.stale || cities.stale || provinces.stale) && <p role="status" className="text-xs leading-5 text-amber-900">Using previously retrieved location options while the location service is unavailable.</p>}<Button variant="outline" onClick={onRemove}>Remove location</Button></div>;
}
