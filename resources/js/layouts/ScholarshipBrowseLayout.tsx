import StudentLayout from './StudentLayout';
import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PublicLayout, { PublicPageHeading, publicPageContainer } from './PublicLayout';

export default function ScholarshipBrowseLayout({ title, description, children, studentWorkspace = false }: { title: string; description: string; children: ReactNode; studentWorkspace?: boolean }) {
    if (studentWorkspace) return <StudentLayout title={title} description={description}>{children}</StudentLayout>;
    return <PublicLayout><Head title={title} /><main id="main-content" className={publicPageContainer}>
        <PublicPageHeading title={title} description={description} />
        {children}
    </main></PublicLayout>;
}
