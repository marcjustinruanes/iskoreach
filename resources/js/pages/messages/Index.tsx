import { Link } from '@inertiajs/react';
import { ArrowRight, MessagesSquare, Clock3 } from 'lucide-react';
import type { SharedProps, Paginated } from '../../types';
import type { ChatThread } from '../../chatTypes';
import ChatShell from '../../components/ChatShell';
import { Avatar, Badge, Card, EmptyState, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { scholarshipDate } from '../../components/ScholarshipDisplay';
export default function Index({ threads, auth }: SharedProps & { threads: Paginated<ChatThread> }) {
    return <ChatShell title="Messages" description="Keep scholarship conversations organized in one private inbox.">
        <Card className="min-w-0 p-0"><div className="flex flex-wrap items-center justify-between gap-3 border-b border-civic-panel-strong px-5 py-4"><div className="flex items-center gap-2"><MessagesSquare aria-hidden="true" className="size-4 text-civic-teal" /><h2 className="text-sm font-bold">Conversations</h2></div><span className="text-xs text-civic-muted">{threads.total} conversations</span></div>
        {threads.data.length ? <ul className="divide-y divide-civic-panel-strong">{threads.data.map(thread => <li key={thread.id}><Link href={'/messages/' + thread.id} className={'group flex min-w-0 items-start gap-3 px-4 py-5 transition-colors hover:bg-slate-50 motion-reduce:transition-none sm:px-5 ' + focusRing}><span className="shrink-0"><Avatar name={thread.partner} /></span><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="break-words text-sm font-semibold text-civic-navy">{thread.partner}</h2>{thread.unread > 0 && <Badge tone="blue">{thread.unread} unread</Badge>}</div><p className="mt-2 flex items-start gap-1.5 text-xs leading-5 text-civic-muted"><Clock3 aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />{thread.last_message_at ? 'Last message: ' + scholarshipDate(thread.last_message_at) : 'No messages yet'}</p></div><ArrowRight aria-hidden="true" className="mt-1 size-4 shrink-0 text-slate-400 group-hover:text-civic-teal" /></Link></li>)}</ul> : <div className="p-5"><EmptyState title="No conversations yet" description="Start a conversation from a submitted application. Conversations are available while the provider is approved." /><div className="mt-4 flex justify-center"><Link href={auth.user?.role === 'provider' ? '/provider/applications' : '/student/applications'} className={buttonStyles('outline')}>Open applications<ArrowRight aria-hidden="true" className="size-4" /></Link></div></div>}
        </Card><Pagination page={threads} label="Conversation pages" />
    </ChatShell>;
}
