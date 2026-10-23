import { useState } from 'react';
import { Button, focusRing } from '../ui/DesignSystem';

export default function ScholarshipShareLink({ url }: { url: string | null }) {
    const [message, setMessage] = useState('');
    if (!url) {
        return <p className="text-sm leading-6 text-civic-muted">Save your scholarship and publish it to activate its shareable link.</p>;
    }
    const local = ['localhost', '127.0.0.1', '::1'].includes(new URL(url).hostname);
    return <div className="space-y-3"><label className="block text-sm font-semibold">Scholarship link<input aria-label="Scholarship link" readOnly value={url} onFocus={event => event.target.select()} className={'mt-2 min-h-11 w-full rounded-lg border border-civic-control bg-white px-3 text-sm ' + focusRing} /></label><Button variant="outline" onClick={() => { if (!navigator.clipboard) { setMessage('Select the link and press Ctrl+C to copy it.'); return; } void navigator.clipboard.writeText(url).then(() => setMessage('Scholarship link copied.')).catch(() => setMessage('Select the link and press Ctrl+C to copy it.')); }}>Copy scholarship link</Button><p role="status" className="text-sm text-civic-muted">{message}</p>{local && <p className="text-sm leading-6 text-amber-800">This is a local preview link. Publish the website on your public domain before sharing it with other people.</p>}</div>;
}
