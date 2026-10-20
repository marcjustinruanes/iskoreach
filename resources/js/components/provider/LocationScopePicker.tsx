import { useEffect, useState } from 'react';
import type { LocationInput } from '../../scholarshipTypes';
import { Button, focusRing } from '../ui/DesignSystem';
interface Choice { code: string; name: string }
const control = 'mt-2 min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base disabled:bg-civic-panel ' + focusRing;
function useOptions(url: string | null, retry: number) {
    const [state, setState] = useState({ data: [] as Choice[], loading: false, stale: false, error: '' });
    useEffect(() => {
        const controller = new AbortController();
        setState({ data: [], loading: !!url, stale: false, error: '' });
        if (url) fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } }).then(async response => {
            if (!response.ok) throw new Error('Location options are unavailable. Retry shortly.');
            const result: { data: Choice[]; stale: boolean } = await response.json();
            if (!controller.signal.aborted) setState({ ...result, loading: false, error: '' });
        }).catch(() => { if (!controller.signal.aborted) setState({ data: [], loading: false, stale: false, error: 'Location options are unavailable. Retry shortly. Your selection is preserved.' }); });
        return () => controller.abort();
    }, [url, retry]);
    return state;
}
export default function LocationScopePicker({ location, index, onChange, onRemove }: { location: LocationInput; index: number; onChange: (next: LocationInput) => void; onRemove: () => void }) {
    const [retry, setRetry] = useState(0);
    const regions = useOptions(location.scope === 'nationwide' ? null : '/locations/regions', retry);
    const cities = useOptions(location.scope === 'city' && /^\d{9}$/.test(location.region_code) ? '/locations/regions/' + location.region_code + '/cities' : null, retry);
    return <div className="space-y-3 rounded-xl border border-civic-panel-strong bg-slate-50/50 p-4"><div className="grid gap-4 lg:grid-cols-3">
        <label className="block text-sm font-semibold" htmlFor={'scope-' + index}>Coverage {index + 1}<select id={'scope-' + index} className={control} value={location.scope} onChange={e => onChange({ scope: e.target.value, region_code: '', city_code: '' })}><option value="nationwide">Nationwide</option><option value="region">Region</option><option value="city">City / municipality</option></select></label>
        {location.scope !== 'nationwide' && <label className="block text-sm font-semibold" htmlFor={'region-' + index}>Region<select id={'region-' + index} className={control} required value={location.region_code} disabled={regions.loading || !!regions.error} onChange={e => onChange({ ...location, region_code: e.target.value, city_code: '' })}><option value="">{regions.loading ? 'Loading regions…' : 'Choose a region'}</option>{location.region_code && !regions.data.some(row => row.code === location.region_code) && <option value={location.region_code}>Saved region code: {location.region_code}</option>}{regions.data.map(row => <option key={row.code} value={row.code}>{row.name}</option>)}</select></label>}
        {location.scope === 'city' && <label className="block text-sm font-semibold" htmlFor={'city-' + index}>City / municipality<select id={'city-' + index} className={control} required value={location.city_code} disabled={!location.region_code || cities.loading || !!cities.error || !cities.data.length} onChange={e => onChange({ ...location, city_code: e.target.value })}><option value="">{cities.loading ? 'Loading places…' : 'Choose a city / municipality'}</option>{location.city_code && !cities.data.some(row => row.code === location.city_code) && <option value={location.city_code}>Saved place code: {location.city_code}</option>}{cities.data.map(row => <option key={row.code} value={row.code}>{row.name}</option>)}</select></label>}
    </div>{(regions.error || cities.error) && <div role="alert" className="space-y-2 text-sm leading-6 text-civic-danger"><p>{regions.error || cities.error}</p><Button variant="outline" onClick={() => setRetry(value => value + 1)}>Retry location lookup</Button></div>}{(regions.stale || cities.stale) && <p role="status" className="text-xs leading-5 text-amber-900">Using previously retrieved location options while the location service is unavailable.</p>}<Button variant="outline" onClick={onRemove}>Remove location</Button></div>;
}
