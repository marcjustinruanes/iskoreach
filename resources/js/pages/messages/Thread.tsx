import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { SharedProps } from '../../types';
import type { ChatState } from '../../chatTypes';
import ChatShell from '../../components/ChatShell';
import { Badge, Button, Card, EmptyState, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { scholarshipDate } from '../../components/ScholarshipDisplay';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
async function chatPost(url: string, body: Record<string, unknown>) {
    const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}) }, body: JSON.stringify(body) });
}
export default function Thread(props: SharedProps & ChatState & { upload_limit_kb: number }) {
    return <ThreadView key={props.thread.id + ':' + props.messages.current_page} {...props} />;
}
function ThreadView({ thread, typing, messages, upload_limit_kb }: SharedProps & ChatState & { upload_limit_kb: number }) {
    const [state, setState] = useState<ChatState>({ thread, typing, messages });
    const [lost, setLost] = useState(false);
    const [feedback, setFeedback] = useState('');
    const [reading, setReading] = useState(false);
    const form = useForm<{ body: string; file: File | null; submission_token: string }>({ body: '', file: null, submission_token: crypto.randomUUID() });
    const request = useRequestFeedback(form.cancel);
    const fileInput = useRef<HTMLInputElement>(null);
    const history = useRef<HTMLDivElement>(null);
    const followLatest = useRef(true);
    useEffect(() => { if (history.current && followLatest.current && state.messages.current_page === 1) history.current.scrollTop = history.current.scrollHeight; }, [state.messages.data[0]?.id]);
    const generation = useRef(0);
    const typingSent = useRef(0);
    const stopTyping = useRef<ReturnType<typeof setTimeout> | null>(null);
    const refreshRef = useRef<() => Promise<void>>(async () => {});
    useEffect(() => { generation.current++; setState({ thread, typing, messages }); }, [thread, typing, messages]);
    useEffect(() => {
        let cancelled = false; let busy = false; let active: AbortController | null = null;
        let realtime: ReturnType<typeof import('../../realtime').startRealtime> | null = null;
        const refresh = async () => {
            if (cancelled || busy || document.hidden || !navigator.onLine || messages.current_page !== 1) return;
            busy = true; active = new AbortController(); const current = generation.current;
            try {
                const response = await fetch('/messages/' + thread.id + '/state', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', signal: active.signal });
                if ([401, 403, 404].includes(response.status)) { if (!cancelled) { setLost(true); form.reset(); } return; }
                if (!response.ok) throw new Error();
                const data = await response.json() as ChatState;
                if (!cancelled && current === generation.current) { setState(data); setFeedback(''); }
            } catch { if (!cancelled) setFeedback('Updates could not be refreshed. Your unsent message is kept; check your connection.'); }
            finally { busy = false; }
        };
        refreshRef.current = refresh;
        const wake = () => void refresh();
        const timer = setInterval(wake, 4000);
        window.addEventListener('focus', wake); window.addEventListener('online', wake); document.addEventListener('visibilitychange', wake);
        if (import.meta.env.VITE_REVERB_ENABLED === 'true') {
            void import('../../realtime').then(({ startRealtime }) => { if (!cancelled) { realtime = startRealtime(); realtime.private('chat.' + thread.id).listen('.chat.changed', wake); } }).catch(() => {});
        }
        return () => { cancelled = true; active?.abort(); clearInterval(timer); realtime?.disconnect(); window.removeEventListener('focus', wake); window.removeEventListener('online', wake); document.removeEventListener('visibilitychange', wake); if (stopTyping.current) clearTimeout(stopTyping.current); };
    }, [thread.id, messages.current_page]);
    const typingChange = (value: string) => {
        form.setData('body', value);
        if (!navigator.onLine || lost) return;
        if (Date.now() - typingSent.current > 2000) { typingSent.current = Date.now(); void chatPost('/messages/' + thread.id + '/typing', { typing: value.trim().length > 0 }).catch(() => {}); }
        if (stopTyping.current) clearTimeout(stopTyping.current);
        stopTyping.current = setTimeout(() => { void chatPost('/messages/' + thread.id + '/typing', { typing: false }).catch(() => {}); }, 3000);
    };
    const send = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.file && form.data.file.size > upload_limit_kb * 1024) { form.setError('file', 'Choose a file within the upload limit.'); return; }
        if (!request.begin()) return;
        form.post('/messages/' + thread.id, { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset('body', 'file'); form.setData('submission_token', crypto.randomUUID()); if (fileInput.current) fileInput.current.value = ''; }, onFinish: request.finish });
    };
    const markRead = async () => {
        if (reading || !navigator.onLine || !state.messages.data.length) return;
        setReading(true);
        try {
            const response = await chatPost('/messages/' + thread.id + '/read', { through_id: Math.max(...state.messages.data.map(message => message.id)) });
            if (!response.ok) throw new Error();
            await refreshRef.current();
        } catch { setFeedback('The read receipt could not be saved. Reconnect and try again.'); }
        finally { setReading(false); }
    };
    return <ChatShell title="Conversation" description="Private messages about your scholarship application.">
        <Link href="/messages" className={buttonStyles('ghost') + ' mb-3'}>Back to messages</Link>
        {lost ? <EmptyState title="Conversation access changed" description="This conversation is no longer available to your account. Return to your messages to check current access." /> : <>
            <Card className="chat-workspace min-w-0 !p-0"><div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 sm:px-5"><h2 className="break-words text-lg font-bold">{state.thread.partner}</h2><Button variant="outline" size="sm" loading={reading} disabled={!state.thread.unread || state.messages.current_page !== 1} onClick={() => void markRead()}>Mark as read</Button></div>
                <div ref={history} onScroll={() => { if(history.current) followLatest.current = history.current.scrollHeight-history.current.scrollTop-history.current.clientHeight < 80; }} className="chat-history max-h-[45dvh] overflow-y-auto overscroll-contain px-4 py-4 sm:px-5">{state.messages.current_page !== 1 && <p role="status" className="mb-5 text-sm leading-6 text-civic-muted">Viewing older messages. <Link href={'/messages/' + thread.id} className="text-civic-teal underline">Return to latest messages</Link> for automatic updates.</p>}
                {state.messages.data.length ? <ol className="space-y-4" aria-label="Conversation messages">{[...state.messages.data].reverse().map(message => <li key={message.id} className={'max-w-[95%] rounded-lg border p-3 sm:max-w-[80%] sm:p-4 ' + (message.mine ? 'ml-auto border-civic-blue-gray bg-civic-panel' : 'border-neutral-200 bg-white')}><p className="mb-2 text-xs font-semibold">{message.mine ? 'You' : state.thread.partner}</p>{message.body !== null && <p className="whitespace-pre-wrap break-words text-base leading-7">{message.body}</p>}{message.attachment && <a href={message.attachment.download_url} className={'mt-2 inline-flex min-h-11 max-w-full items-center break-all text-sm font-semibold text-civic-teal underline ' + focusRing}>{message.attachment.name} ({Math.ceil(message.attachment.size_bytes / 1024)} KB)</a>}<div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs leading-5 text-civic-muted"><span>{scholarshipDate(message.sent_at)}</span>{message.mine && <span>{message.read_at ? 'Read' : 'Sent'}</span>}</div></li>)}</ol> : <EmptyState title="Start the conversation" description="Send your provider a question below. This conversation is private." />}
                <p aria-live="polite" className="mt-2 min-h-5 text-sm text-civic-muted">{state.typing ? 'The other participant is typing…' : ''}</p>
                <Pagination page={state.messages} label="Message history pages" /></div>
            {feedback && <p role="status" className="my-4 text-sm leading-6 text-amber-900">{feedback}</p>}
            <div className="border-t border-neutral-200 bg-civic-canvas/50 p-4 sm:p-5"><form onSubmit={send} noValidate className="space-y-3"><div><label htmlFor="message-body" className="mb-2 block text-sm font-semibold">Your message</label><textarea id="message-body" name="body" rows={3} maxLength={5000} value={form.data.body} disabled={form.processing} onChange={e => typingChange(e.target.value)} className={'w-full rounded-lg border border-civic-control bg-white px-3 py-2.5 text-base leading-6 ' + focusRing} /></div><details open={Boolean(form.data.file || form.errors.file) || undefined}><summary className={'cursor-pointer text-sm font-medium text-civic-muted '+focusRing}>Attach a document</summary><div className="mt-3"><label htmlFor="message-file" className="mb-2 block text-sm font-semibold">Attachment (optional)</label><input ref={fileInput} id="message-file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png" disabled={form.processing} onChange={e => form.setData('file', e.target.files?.[0] ?? null)} className={'min-h-11 w-full min-w-0 rounded-lg border border-civic-control p-2 text-sm ' + focusRing} /><p className="mt-2 text-xs leading-5 text-civic-muted">One PDF, JPG, or PNG per message; up to {Math.round(upload_limit_kb / 1024 * 10) / 10} MB. Message limit: 5,000 characters.</p></div></details>{form.progress && <progress aria-label="Message upload progress" max={100} value={form.progress.percentage} className="w-full" />}{request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}{Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}{form.errors.submission_token && <Button variant="outline" onClick={() => router.reload()}>Reload conversation</Button>}<Button type="submit" loading={form.processing}>Send message</Button></form></div></Card>
        </>}
    </ChatShell>;
}
