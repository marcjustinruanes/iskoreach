import ProviderLayout from './ProviderLayout';
import AdminLayout from './AdminLayout';
import type { SharedProps } from '../types';
import StudentLayout from './StudentLayout';
import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PublicLayout, { PublicPageHeading, publicPageContainer } from './PublicLayout';

export default function PublicDirectoryLayout({ title, description, children, studentWorkspace = false }: { title: string; description?: string; children: ReactNode; studentWorkspace?: boolean }) {
    const { auth } = usePage<SharedProps>().props;
    if (auth.user?.role === 'provider') return <ProviderLayout title={title} description={description}>{children}</ProviderLayout>;
    if (auth.user?.role === 'admin') return <AdminLayout title={title} description={description}>{children}</AdminLayout>;
    if (studentWorkspace || auth.user?.role === 'student') return <StudentLayout title={title} description={description} fullWidth>{children}</StudentLayout>;
    const scholarshipDiscovery = title === 'Find a scholarship';
    return <PublicLayout><Head title={title} /><main id="main-content" className={publicPageContainer + (scholarshipDiscovery ? ' scholarship-discovery-page' : '')}>
        <PublicPageHeading title={scholarshipDiscovery ? 'Find your scholarship' : title} description={description} />
        {children}
    </main></PublicLayout>;
}
