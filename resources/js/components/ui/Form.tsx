import { useId } from 'react';
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
        <label htmlFor={fieldId} className="mb-2 block text-sm font-medium leading-5 text-slate-800">{label}</label>
        <input {...props} id={fieldId} aria-invalid={Boolean(error)} aria-describedby={error || hint ? fieldId + '-help' : undefined}
            className={'block min-h-11 w-full rounded-lg border bg-white px-3.5 py-2.5 text-base leading-6 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-teal-700 focus:ring-2 focus:ring-teal-700/20 ' + (error ? 'border-red-600 ' : 'border-slate-300 ') + className} />
        {(error || hint) && <p id={fieldId + '-help'} role={error ? 'alert' : undefined} className={'mt-2 text-sm leading-5 ' + (error ? 'text-red-700' : 'text-slate-600')}>{error || hint}</p>}
    </div>;
}

export function Button({ className = '', type = 'button', ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
    return <button {...props} type={type} className={'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold leading-5 text-white transition hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-wait disabled:opacity-60 ' + className} />;
}

export function Notice({ children }: { children: ReactNode }) {
    return <div role="status" className="mb-6 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm leading-6 text-teal-900">{children}</div>;
}

export function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <h2 className="text-xl font-semibold leading-7 tracking-tight text-slate-950">{title}</h2>
        {description && <p className="mt-2 max-w-prose text-sm leading-6 text-slate-600">{description}</p>}
        <div className="mt-6">{children}</div>
    </section>;
}