import { useId } from 'react';
import { buttonStyles } from './DesignSystem';
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react';

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    error?: string;
    hint?: string;
}

export function TextField({ label, error, hint, id, className = '', ...props }: TextFieldProps) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    return <div>
        <label htmlFor={fieldId} className="mb-2 block text-sm font-medium leading-5 text-civic-ink">{label}</label>
        <input {...props} id={fieldId} aria-invalid={Boolean(error)} aria-describedby={error || hint ? fieldId + '-help' : undefined}
            className={'block min-h-11 w-full rounded-lg border bg-white px-3.5 py-2.5 text-base leading-6 text-civic-ink outline-none transition placeholder:text-civic-muted/70 focus:border-civic-navy focus:ring-2 focus:ring-civic-navy/15 ' + (error ? 'border-civic-danger ' : 'border-civic-border ') + className} />
        {(error || hint) && <p id={fieldId + '-help'} role={error ? 'alert' : undefined} className={'mt-2 text-sm leading-5 ' + (error ? 'text-civic-danger' : 'text-civic-muted')}>{error || hint}</p>}
    </div>;
}

export function Button({ className = '', type = 'button', ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
    return <button {...props} type={type} className={buttonStyles() + ' ' + className} />;
}

export function Notice({ children }: { children: ReactNode }) {
    return <div role="status" className="mb-6 rounded-lg border border-civic-blue-gray bg-civic-panel px-4 py-3 text-sm leading-6 text-civic-navy">{children}</div>;
}

export function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return <section className="rounded-lg border border-neutral-200 bg-white p-5 sm:p-6">
        <h2 className="text-lg font-semibold leading-7 tracking-tight text-civic-ink">{title}</h2>
        {description && <p className="mt-2 max-w-prose text-sm leading-6 text-civic-muted">{description}</p>}
        <div className="mt-6">{children}</div>
    </section>;
}