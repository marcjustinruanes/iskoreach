import { useState } from 'react';
import { useLocationOptions } from './provider/LocationScopePicker';
import { Button, focusRing } from './ui/DesignSystem';

type Field = 'campus_region_code' | 'campus_province_code' | 'campus_city_code';
interface Props { data: Record<Field, string> & { campus: string }; errors: Partial<Record<Field, string>>; onChange: (field: Field, value: string) => void }
export default function CampusLocationFields({ data, errors, onChange }: Props) {
    const [retry, setRetry] = useState(0);
    const regions = useLocationOptions('/locations/regions', retry);
    const provinces = useLocationOptions(data.campus_region_code ? '/locations/regions/' + data.campus_region_code + '/provinces' : null, retry);
    const cities = useLocationOptions(data.campus_region_code ? '/locations/regions/' + data.campus_region_code + '/cities' : null, retry);
    const cityChoices = cities.data.filter(city => data.campus_province_code === 'none' ? !city.province_code : city.province_code === data.campus_province_code);
    const select = (field: Field, label: string, choices: { code: string; name: string }[], disabled: boolean) => <div><label htmlFor={field} className="mb-2 block text-sm font-semibold">{label} *</label><select id={field} name={field} required value={data[field]} disabled={disabled} aria-invalid={Boolean(errors[field])} aria-describedby={errors[field] ? field + '-error' : undefined} className={'min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base disabled:bg-civic-panel ' + focusRing} onChange={event => { onChange(field, event.target.value); if (field === 'campus_region_code') { onChange('campus_province_code', ''); onChange('campus_city_code', ''); } else if (field === 'campus_province_code') onChange('campus_city_code', ''); }}><option value="">Choose {label.toLowerCase()}</option>{data[field] && !choices.some(choice => choice.code === data[field]) && <option value={data[field]}>Saved selection: {data[field]}</option>}{choices.map(choice => <option key={choice.code} value={choice.code}>{choice.name}</option>)}</select>{errors[field] && <p id={field + '-error'} role="alert" className="mt-2 text-sm text-civic-danger">{errors[field]}</p>}</div>;
    return <fieldset className="space-y-4 rounded-xl border border-civic-border p-4"><legend className="px-1 text-sm font-bold">School / campus location</legend><p className="text-sm text-civic-muted">Select where your school campus is located. This is separate from your home address.</p>
        {data.campus && !data.campus_city_code && <p className="text-sm text-civic-muted">Previously saved campus: {data.campus}. Select its location to update it.</p>}
        {select('campus_region_code', 'Campus region', regions.data, regions.loading || !!regions.error)}
        <div className="grid gap-4 sm:grid-cols-2">{select('campus_province_code', 'Campus province', [...provinces.data, { code: 'none', name: 'Not applicable - independent city / NCR' }], !data.campus_region_code || provinces.loading || !!provinces.error)}{select('campus_city_code', 'Campus city / municipality', cityChoices, !data.campus_province_code || cities.loading || !!cities.error)}</div>
        {[regions, provinces, cities].some(state => state.loading) && <p role="status" className="text-sm text-civic-muted">Loading campus locations...</p>}
        {[regions, provinces, cities].some(state => state.error) && <div role="alert"><p className="mb-2 text-sm text-civic-danger">Campus locations are temporarily unavailable. Your selections are preserved.</p><Button variant="outline" onClick={() => setRetry(current => current + 1)}>Retry campus locations</Button></div>}
        {[regions, provinces, cities].some(state => state.stale) && <p className="text-xs text-amber-900">Using previously retrieved campus locations while the service is unavailable.</p>}
    </fieldset>;
}
