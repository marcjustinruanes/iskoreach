import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';
import AppLayout from '../layouts/AppLayout';
import ProviderLayout from '../layouts/ProviderLayout';
export default function ChatShell({ title, description, children }: { title: string; description: string; children: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    const Shell = auth.user?.role === 'provider' ? ProviderLayout : AppLayout;
    return <Shell title={title} description={description}>{children}</Shell>;
}
