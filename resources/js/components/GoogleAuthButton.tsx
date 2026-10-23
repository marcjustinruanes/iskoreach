import { usePage } from '@inertiajs/react';
import { Button, buttonStyles } from './ui/DesignSystem';

interface GoogleAuth { enabled: boolean; loginUrl: string; studentUrl: string; providerUrl: string; workUrl: string }
export default function GoogleAuthButton({ intent = 'login', onContinue }: { intent?: 'login' | 'student' | 'provider' | 'provider-work'; onContinue?: (url: string) => void }) {
    const { googleAuth } = usePage<{ googleAuth?: GoogleAuth }>().props;
    const content = <><svg aria-hidden="true" viewBox="0 0 48 48" className="size-5 shrink-0"><path fill="#4285F4" d="M43.6 24.5c0-1.4-.1-2.8-.4-4.1H24v7.8h11a9.4 9.4 0 0 1-4.1 6.2v5h6.6c3.8-3.5 6.1-8.7 6.1-14.9Z"/><path fill="#34A853" d="M24 44c5.5 0 10.1-1.8 13.5-4.9l-6.6-5a12.2 12.2 0 0 1-18.1-6.4H6v5.2A20.4 20.4 0 0 0 24 44Z"/><path fill="#FBBC05" d="M12.8 27.7a12.2 12.2 0 0 1 0-7.4v-5.2H6a20.3 20.3 0 0 0 0 17.8l6.8-5.2Z"/><path fill="#EA4335" d="M24 12c3 0 5.7 1 7.8 3.1l5.8-5.8A19.5 19.5 0 0 0 24 4 20.4 20.4 0 0 0 6 15.1l6.8 5.2A12.1 12.1 0 0 1 24 12Z"/></svg>Continue with Google</>;
    const url = googleAuth?.[intent === 'provider-work' ? 'workUrl' : intent === 'login' ? 'loginUrl' : intent === 'student' ? 'studentUrl' : 'providerUrl'];
    return <div className="space-y-2">
        {googleAuth?.enabled && url ? onContinue ? <Button variant="outline" className="w-full" onClick={() => onContinue(url)}>{content}</Button> : <a href={url} className={buttonStyles('outline') + ' w-full'}>{content}</a> : <Button variant="outline" disabled className="w-full">{content}</Button>}
        {!googleAuth?.enabled && <p className="text-center text-xs leading-5 text-civic-muted">Google sign-in is temporarily unavailable. You can continue with email.</p>}
    </div>;
}
