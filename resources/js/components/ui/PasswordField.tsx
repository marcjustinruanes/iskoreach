import { useId, useState } from 'react';
import type { InputHTMLAttributes } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { Input, focusRing } from './DesignSystem';

interface PasswordFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: string;
    error?: string;
    hint?: string;
}

export default function PasswordField({ label, id, className = '', ...props }: PasswordFieldProps) {
    const generatedId = useId();
    const fieldId = id || generatedId;
    const [visible, setVisible] = useState(false);
    const Icon = visible ? EyeOff : Eye;
    return <div className="relative">
        <Input {...props} id={fieldId} label={label} type={visible ? 'text' : 'password'} className={'pr-14 ' + className} />
        <button type="button" aria-label={(visible ? 'Hide ' : 'Show ') + label.toLowerCase()} aria-controls={fieldId} aria-pressed={visible}
            onClick={() => setVisible(!visible)} className={'absolute top-7 right-1 flex size-11 items-center justify-center rounded-lg text-civic-muted hover:text-civic-teal ' + focusRing}>
            <Icon aria-hidden="true" className="size-5" />
        </button>
    </div>;
}