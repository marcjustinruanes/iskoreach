import type { ManagedAccountStatus } from '../types';
import { Badge } from './ui/DesignSystem';

export default function AccountStatusBadge({ status }: { status: ManagedAccountStatus }) {
    const labels = { active: 'Active', pending: 'Pending review', rejected: 'Rejected', profile_missing: 'Provider profile missing' };
    return <Badge tone={status === 'active' ? 'green' : status === 'rejected' ? 'red' : 'amber'}>{labels[status]}</Badge>;
}
