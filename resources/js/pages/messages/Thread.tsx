import { ArrowLeft, Check, CheckCheck, Download, FileText, LockKeyhole, MessagesSquare, Paperclip, Send } from 'lucide-react';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { SharedProps } from '../../types';
import type { ChatState } from '../../chatTypes';
import ChatShell from '../../components/ChatShell';
import { Avatar, Button, Card, EmptyState, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { scholarshipDate } from '../../components/ScholarshipDisplay';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
const messageDay = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'long', timeZone: 'Asia/Manila' }).format(new Date(value));
const messageTime = (value: string) => new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' }).format(new Date(value));
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
    const chronological = [...state.messages.data].reverse();
    return <ChatShell title="Conversation">
        <Link href="/messages" className={buttonStyles('ghost', 'sm') + ' mb-4 !px-0'}><ArrowLeft aria-hidden="true" className="size-4" />Back to messages</Link>
        {lost ? <EmptyState title="Conversation access changed" description="This conversation is no longer available to your account. Return to your messages to check current access." /> : <>
            <Card className="chat-workspace min-w-0 overflow-hidden !p-0"><div className="flex flex-col items-stretch justify-between gap-3 border-b border-civic-blue-gray sm:flex-row sm:items-center border-t-4 border-t-civic-navy bg-white px-4 py-4 sm:px-6"><div className="flex min-w-0 flex-1 items-center gap-3"><span className="shrink-0"><Avatar name={state.thread.partner} /></span><div className="min-w-0"><h2 className="break-words text-base font-bold sm:text-lg">{state.thread.partner}</h2><p className="mt-1 flex items-center gap-1.5 text-xs text-civic-muted"><LockKeyhole aria-hidden="true" className="size-3.5 shrink-0" />Private scholarship conversation</p></div></div><Button variant="outline" size="sm" className="self-end sm:self-auto" loading={reading} disabled={!state.thread.unread || state.messages.current_page !== 1} onClick={() => void markRead()}><CheckCheck aria-hidden="true" className="size-4" />Mark as read</Button></div>
                <div ref={history} onScroll={() => { if(history.current) followLatest.current = history.current.scrollHeight-history.current.scrollTop-history.current.clientHeight < 80; }} className="chat-history min-h-[15rem] max-h-[48dvh] overflow-y-auto overscroll-contain bg-civic-panel/60 px-3 py-5 sm:px-6">{state.messages.current_page !== 1 && <p role="status" className="mb-5 text-sm leading-6 text-civic-muted">Viewing older messages. <Link href={'/messages/' + thread.id} className="text-civic-teal underline">Return to latest messages</Link> for automatic updates.</p>}
                {chronological.length ? <ol className="space-y-4" aria-label="Conversation messages">{chronological.map((message, index) => <li key={message.id}>
                    {(index === 0 || messageDay(chronological[index - 1].sent_at) !== messageDay(message.sent_at)) && <div className="mb-5 mt-1 flex items-center gap-3" aria-label="Message date"><span className="h-px flex-1 bg-civic-blue-gray" /><time dateTime={message.sent_at} className="shrink-0 text-[11px] font-semibold text-civic-muted sm:text-xs">{messageDay(message.sent_at)}</time><span className="h-px flex-1 bg-civic-blue-gray" /></div>}
                    <div className={'flex ' + (message.mine ? 'justify-end' : 'justify-start')}><article aria-label={message.mine ? 'Your message' : 'Message from ' + state.thread.partner} className={'w-fit min-w-0 max-w-[92%] rounded-xl border px-4 py-3 sm:max-w-[78%] lg:max-w-[38rem] ' + (message.mine ? 'rounded-br-sm border-civic-navy bg-civic-navy text-white' : 'rounded-bl-sm border-civic-blue-gray bg-white text-civic-navy')}>
                        <p className={'mb-1.5 text-xs font-semibold ' + (message.mine ? 'text-white/80' : 'text-civic-muted')}>{message.mine ? 'You' : state.thread.partner}</p>
                        {message.body !== null && <p className="whitespace-pre-wrap break-words text-sm leading-6 [overflow-wrap:anywhere] sm:text-base">{message.body}</p>}
                        {message.attachment && <a href={message.attachment.download_url} className={'mt-3 flex min-h-16 min-w-0 items-center gap-3 rounded-lg border p-3 no-underline transition-colors ' + focusRing + (message.mine ? 'border-white/25 bg-white/10 text-white hover:bg-white/20' : 'border-civic-blue-gray bg-civic-canvas hover:bg-civic-panel')}><FileText aria-hidden="true" className={'size-7 shrink-0 ' + (message.mine ? 'text-civic-gold' : 'text-civic-slate')} /><span className="min-w-0 flex-1"><span className="block break-words text-sm font-semibold [overflow-wrap:anywhere]">{message.attachment.name}</span><span className={'mt-1 block text-xs ' + (message.mine ? 'text-white/80' : 'text-civic-muted')}>{Math.ceil(message.attachment.size_bytes / 1024)} KB / Download attachment</span></span><Download aria-hidden="true" className="size-4 shrink-0" /></a>}
                        <div className={'mt-2 flex items-center justify-end gap-3 text-[11px] leading-5 ' + (message.mine ? 'text-white/80' : 'text-civic-muted')}><time dateTime={message.sent_at} title={scholarshipDate(message.sent_at)}>{messageTime(message.sent_at)}</time>{message.mine && <span className="inline-flex items-center gap-1">{message.read_at ? <CheckCheck aria-hidden="true" className="size-3.5" /> : <Check aria-hidden="true" className="size-3.5" />}{message.read_at ? 'Read' : 'Sent'}</span>}</div>
                    </article></div>
                </li>)}</ol> : <div className="flex min-h-52 flex-col items-center justify-center px-3 py-8 text-center"><span className="mb-4 flex size-12 items-center justify-center rounded-xl border border-civic-blue-gray bg-white"><MessagesSquare aria-hidden="true" className="size-6 text-civic-slate" /></span><h3 className="text-base font-bold">Start the conversation</h3><p className="mt-2 max-w-sm text-sm leading-6 text-civic-muted">Ask about your application or share a supporting document.</p></div>}
                <p aria-live="polite" className="mt-2 min-h-5 text-sm text-civic-muted">{state.typing ? 'The other participant is typing…' : ''}</p>
                <Pagination page={state.messages} label="Message history pages" /></div>
            {feedback && <p role="status" className="my-4 text-sm leading-6 text-amber-900">{feedback}</p>}
            <div className="border-t-2 border-civic-blue-gray bg-white p-4 sm:px-6 sm:py-4"><form onSubmit={send} noValidate className="space-y-3"><div><label htmlFor="message-body" className="mb-2 block text-sm font-semibold">Your message</label><textarea id="message-body" name="body" placeholder="Write your message..." rows={2} maxLength={5000} value={form.data.body} disabled={form.processing} onChange={e => typingChange(e.target.value)} className={'w-full resize-y rounded-lg border border-civic-control bg-civic-canvas/50 px-3 py-2.5 text-base leading-6 ' + focusRing} /></div><details open={Boolean(form.data.file || form.errors.file) || undefined}><summary className={'w-fit cursor-pointer rounded-md py-1 text-sm font-semibold text-civic-muted '+focusRing}><span className="inline-flex items-center gap-1.5"><Paperclip aria-hidden="true" className="size-4" />Attach a document</span></summary><div className="mt-3"><label htmlFor="message-file" className="mb-2 block text-sm font-semibold">Attachment (optional)</label><input ref={fileInput} id="message-file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png" disabled={form.processing} onChange={e => form.setData('file', e.target.files?.[0] ?? null)} className={'min-h-11 w-full min-w-0 rounded-lg border border-civic-control p-2 text-sm ' + focusRing} /><p className="mt-2 text-xs leading-5 text-civic-muted">One PDF, JPG, or PNG per message; up to {Math.round(upload_limit_kb / 1024 * 10) / 10} MB. Message limit: 5,000 characters.</p></div></details>{form.progress && <progress aria-label="Message upload progress" max={100} value={form.progress.percentage} className="w-full" />}{request.error && <p role="alert" className="text-sm text-civic-danger">{request.error}</p>}{Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}{form.errors.submission_token && <Button variant="outline" onClick={() => router.reload()}>Reload conversation</Button>}<div className="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3"><p className="hidden items-center gap-1.5 text-xs text-civic-muted sm:inline-flex"><LockKeyhole aria-hidden="true" className="size-3.5" />Only visible to this conversation</p><Button type="submit" className="ml-auto" loading={form.processing}><Send aria-hidden="true" className="size-4" />Send message</Button></div></form></div></Card>
        </>}
    </ChatShell>;
}
