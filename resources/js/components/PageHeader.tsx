import type { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: string;
    actions?: ReactNode;
}

export default function PageHeader({ title, description, actions }: PageHeaderProps) {
    return <header data-page-header className="mb-5 flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-5">
        <div className="min-w-0 flex-1">
            <h1 className="break-words text-2xl font-bold leading-8 tracking-tight text-civic-navy">{title}</h1>
            {description && <p className="mt-2 max-w-3xl text-sm leading-6 text-civic-muted">{description}</p>}
        </div>
        {actions && <div className="flex max-w-full flex-wrap items-center gap-2 sm:shrink-0">{actions}</div>}
    </header>;
}
