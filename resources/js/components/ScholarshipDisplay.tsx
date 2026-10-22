import type { ScholarshipLocation, ScholarshipStatus } from '../types';
import { Badge } from './ui/DesignSystem';

export function ScholarshipStatusBadge({ status }: { status: ScholarshipStatus }) {
    const labels = { draft: 'Draft', published: 'Published', closed: 'Closed', archived: 'Archived' };
    return <Badge tone={status === 'published' ? 'green' : status === 'draft' ? 'amber' : 'neutral'}>{labels[status]}</Badge>;
}
export function scholarshipDate(value: string | null) {
    return value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value)) : 'Not recorded';
}
export function locationLabel(location: ScholarshipLocation) {
    return location.scope === 'nationwide' ? 'Nationwide' : [location.region_name || (location.region_code && 'Region code: ' + location.region_code), location.province_name || (location.province_code && 'Province code: ' + location.province_code), location.city_name || (location.city_code && 'City/municipality code: ' + location.city_code)].filter(Boolean).join(' · ');
}
