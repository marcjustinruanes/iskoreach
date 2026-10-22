import { AdminCard as Card } from '../../components/admin/AdminPanel';
import { Link } from '@inertiajs/react';
import type { Paginated } from '../../types';
import AdminLayout from '../../layouts/AdminLayout';
import { Badge, EmptyState, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';

export default function StudentVerifications({ requests }: { requests: Paginated<{ id: number; name: string; status: string; url: string }> }) {
    return <AdminLayout title="Student verification" description="Review optional student badges. Normal scholarship access does not require a badge.">
        <Card><div className="mb-5 flex flex-wrap items-center justify-between gap-2"><h2 className="text-base font-bold">Verification requests</h2><p className="text-sm text-civic-muted">{requests.total} records</p></div>
            {requests.data.length ? <Table rows={requests.data} rowKey={item => item.id} caption="Student verification requests" columns={[
                { key: 'student', label: 'Student', render: item => <Link href={item.url} className={'font-semibold underline underline-offset-4 ' + focusRing}>{item.name}</Link> },
                { key: 'status', label: 'Review status', render: item => <Badge tone={item.status === 'verified' ? 'green' : item.status === 'rejected' ? 'red' : item.status === 'pending' ? 'amber' : 'neutral'}>{item.status.replaceAll('_', ' ')}</Badge> },
                { key: 'review', label: 'Action', render: item => <Link href={item.url} className={buttonStyles('outline', 'sm')}>Review request<span className="sr-only"> for {item.name}</span></Link> },
            ]} /> : <EmptyState title="No verification requests" description="Requests appear after students complete the eligibility checklist and submit for review." />}
            <Pagination page={requests} label="Verification request pages" />
        </Card>
    </AdminLayout>;
}
