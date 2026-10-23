import { Link } from '@inertiajs/react';
import { focusRing } from './ui/DesignSystem';

export default function WorkspaceBreadcrumbs({ role, title, path }: { role: 'student' | 'provider' | 'admin'; title: string; path: string }) {
    const parents: [string, string][] = role === 'admin' ? [
        ['/admin/providers', 'Providers'], ['/admin/users', 'Users'], ['/admin/scholarships', 'Scholarships'], ['/admin/applicant-counts', 'Applicant counts'],
    ] : [
        ['/'+role+'/scholarships', 'Scholarships'], ['/'+role+'/applications', 'Applications'], ['/messages', 'Messages'], ['/renewals', 'Renewals'], ['/benefits', 'Grants & benefits'],
    ];
    const parent = parents.find(([href]) => path.startsWith(href+'/'));
    const label = role === 'admin' ? 'Administration' : role === 'provider' ? 'Provider' : 'Student';
    return <nav aria-label="Breadcrumb" className="min-w-0 text-xs sm:text-sm"><ol className="flex min-w-0 items-center gap-2"><li className="hidden shrink-0 sm:block"><Link href={'/'+role+'/dashboard'} className={'rounded text-civic-muted hover:text-civic-navy '+focusRing}>{label}</Link></li>{parent && <li className="hidden shrink-0 items-center gap-2 md:flex"><span aria-hidden="true" className="text-civic-border">/</span><Link href={parent[0]} className={'rounded text-civic-muted hover:text-civic-navy '+focusRing}>{parent[1]}</Link></li>}<li aria-hidden="true" className="hidden text-civic-border sm:block">/</li><li aria-current="page" title={title} className="truncate font-semibold">{title}</li></ol></nav>;
}
