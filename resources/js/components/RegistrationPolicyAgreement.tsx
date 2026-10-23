import { useEffect, useRef, useState } from 'react';
import { Button, focusRing } from './ui/DesignSystem';
import { Modal } from './ui/Overlays';

type Kind = 'terms' | 'privacy';
type AgreementField = 'terms_accepted' | 'privacy_accepted';
interface Policy { policy: Kind; version: string; title: string; content: string; publishedAt: string }
interface Props {
    data: Record<AgreementField, boolean>;
    errors: Partial<Record<AgreementField, string>>;
    versions: { terms_version: string; privacy_version: string };
    readVersions: Partial<Record<Kind, string>>;
    onChange: (field: AgreementField, value: boolean) => void;
    onRead: (kind: Kind, version: string) => void;
    onVersion: (kind: Kind, version: string) => void;
}

export default function RegistrationPolicyAgreement({ data, errors, versions, readVersions, onChange, onRead, onVersion }: Props) {
    const [active, setActive] = useState<Kind | null>(null);
    const [document, setDocument] = useState<Policy | null>(null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState('');
    const [reachedEnd, setReachedEnd] = useState(false);
    const scrollArea = useRef<HTMLDivElement>(null);
    const request = useRef<AbortController | null>(null);
    const checkEnd = () => {
        const area = scrollArea.current;
        if (area && area.scrollHeight - area.scrollTop - area.clientHeight <= 4) setReachedEnd(true);
    };
    useEffect(() => {
        if (!document || !active) return;
        const observer = new ResizeObserver(checkEnd);
        if (scrollArea.current) observer.observe(scrollArea.current);
        const frame = requestAnimationFrame(checkEnd);
        return () => { observer.disconnect(); cancelAnimationFrame(frame); };
    }, [document, active]);
    useEffect(() => () => request.current?.abort(), []);
    const close = () => { request.current?.abort(); setActive(null); setDocument(null); };
    const open = async (kind: Kind) => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setActive(kind); setDocument(null); setLoading(true); setFailure(''); setReachedEnd(false);
        try {
            const response = await fetch('/register/policies/' + kind, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('This policy could not be loaded. Please try again.');
            const policy = await response.json() as Policy;
            if (controller.signal.aborted) return;
            onVersion(kind, policy.version);
            setDocument(policy);
        } catch (error) {
            if (!controller.signal.aborted) setFailure(error instanceof Error ? error.message : 'Check your connection and try again.');
        } finally { if (!controller.signal.aborted) setLoading(false); }
    };
    return <>
        <fieldset className="space-y-4 border-t pt-5"><legend className="font-semibold">Terms and privacy</legend>
            {(['terms', 'privacy'] as const).map(kind => {
                const field: AgreementField = kind === 'terms' ? 'terms_accepted' : 'privacy_accepted';
                const read = readVersions[kind] === versions[kind === 'terms' ? 'terms_version' : 'privacy_version'];
                return <div key={kind}>
                    <button type="button" onClick={() => void open(kind)} className={'inline-flex min-h-11 items-center rounded text-sm font-semibold text-civic-teal underline ' + focusRing}>Read {kind === 'terms' ? 'Terms of Use' : 'Privacy Policy'}</button>
                    <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name={field} required checked={data[field]} disabled={!read} aria-describedby={field + '-help'} onChange={event => onChange(field, event.target.checked)} className="mt-1 size-4 shrink-0 accent-civic-teal disabled:cursor-not-allowed" /><span>{kind === 'terms' ? 'I agree to the Terms of Use.' : 'I have read the Privacy Policy and consent to the stated processing of my registration details.'} <span aria-hidden="true">*</span></span></label>
                    <p id={field + '-help'} className="mt-2 text-xs text-civic-muted">{read ? 'Document read. You may now select the checkbox.' : 'Open and read this document before selecting the checkbox.'}</p>
                    {errors[field] && <p role="alert" className="mt-2 text-sm text-civic-danger">{errors[field]}</p>}
                </div>;
            })}
        </fieldset>
        <Modal open={active !== null} onClose={close} title={document?.title ?? (active === 'terms' ? 'Terms of Use' : 'Privacy Policy')} description="Read the document to the end, then confirm that you have read it." panelClassName="sm:max-w-2xl">
            {loading && <p role="status" className="animate-pulse py-8 text-sm text-civic-muted">Loading document...</p>}
            {failure && <div role="alert"><p className="mb-3 text-sm text-civic-danger">{failure}</p><Button variant="outline" onClick={() => active && void open(active)}>Retry</Button></div>}
            {document && <>
                <p className="mb-3 text-xs text-civic-muted">Version {document.version} · Published {new Date(document.publishedAt).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' })}</p>
                <div ref={scrollArea} role="region" aria-label="Policy document" tabIndex={0} onScroll={checkEnd} className={'max-h-[45dvh] overflow-y-auto overscroll-contain rounded-lg border border-civic-border p-4 text-sm leading-7 whitespace-pre-wrap break-words ' + focusRing}>{document.content}</div>
                <p role="status" className="mt-3 text-xs text-civic-muted">{reachedEnd ? 'You have reached the end of this document.' : 'Scroll to the end to enable confirmation.'}</p>
                <div className="mt-4 flex flex-wrap gap-3"><Button disabled={!reachedEnd} onClick={() => { onRead(document.policy, document.version); close(); }}>I have read this</Button><Button variant="outline" onClick={close}>Close</Button></div>
            </>}
        </Modal>
    </>;
}
