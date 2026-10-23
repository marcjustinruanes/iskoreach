import { Head, Link, router } from '@inertiajs/react';
import AuthLayout from '../layouts/AuthLayout';
import { Button } from '../components/ui/DesignSystem';

export default function StudentRegistrationStatus({ registrationStatus, reviewReason }: { registrationStatus: string; reviewReason: string | null }) {
    const rejected = registrationStatus === 'rejected';
    return <AuthLayout title="Student registration status"><Head title="Student registration status" /><h1 className="text-2xl font-bold">{rejected ? 'Registration rejected' : 'Registration submitted for review'}</h1><p className="mt-4 text-sm leading-7">{rejected ? 'The administrator did not approve your registration.' : 'Your registration is pending administrator approval. You can access your student workspace after approval.'}</p>{reviewReason && <p className="mt-4 rounded-lg bg-civic-panel p-4 text-sm leading-7">{reviewReason}</p>}<div className="mt-6 flex flex-wrap gap-3"><Button onClick={() => router.reload()}>Check status</Button><Link href="/logout" method="post" as="button" className="px-4 py-2 text-sm font-semibold underline">Log out</Link></div></AuthLayout>;
}
