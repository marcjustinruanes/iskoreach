import type { VerificationNotice } from '../types';
import { StatusBadge } from './ui/DesignSystem';

export default function VerificationNotices({ notices, headingId = 'verification-notices-title' }: { notices: VerificationNotice[]; headingId?: string }) {
    if (!notices.length) return null;
    return <section aria-labelledby={headingId} className="mt-6 rounded-xl border border-civic-panel-strong bg-white p-5 font-stitch sm:p-6"><h2 id={headingId} className="text-lg font-bold text-civic-navy">Recent account decisions</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Your five most recent decision notifications. Your current access status is shown above.</p><ol className="mt-5 divide-y divide-civic-panel-strong">{notices.map(notice => <li key={notice.id} className="py-4 first:pt-0 last:pb-0"><div className="flex flex-wrap items-center justify-between gap-3"><StatusBadge status={notice.status} /><time dateTime={notice.decided_at} className="text-xs leading-5 text-civic-muted">{new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(notice.decided_at))} · Asia/Manila</time></div>{notice.reason && <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-civic-ink">{notice.reason}</p>}</li>)}</ol></section>;
}
