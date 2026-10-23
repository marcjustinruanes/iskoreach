import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Paginated, SharedProps } from '../../types';
import AppLayout from '../../layouts/AppLayout';
import { Badge, Button, Card, EmptyState, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { Pagination } from '../../components/ui/DataDisplay';
import { Modal } from '../../components/ui/Overlays';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';
interface Award { id: number; scholarship: string; provider: string | null; status: string; active: boolean; consented: boolean; published: boolean; version: number; consent_url: string }
export default function Awards({ awards }: SharedProps & { awards: Paginated<Award> }) {
    return <AppLayout title="My grants" description="Public listing requires your consent and administrator approval." actions={<Link href="/passers" className={buttonStyles('outline')}>View public passers list</Link>}>
        <div className="my-grants-page">
        <div className="space-y-5">{awards.data.length ? awards.data.map(award => <AwardCard key={award.id + ':' + award.version} award={award} />) : <EmptyState title="No confirmed grants yet" description="Your confirmed scholarship grants will appear here. An accepted application is separate from a confirmed grant." />}</div>
        <Pagination page={awards} label="My grants pages" />
        </div>
    </AppLayout>;
}
function AwardCard({ award }: { award: Award }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ consent: !award.consented, version: award.version, confirmed: false });
    const request = useRequestFeedback(form.cancel);
    const submit = (event: FormEvent) => { event.preventDefault(); if (!request.begin()) return; form.patch(award.consent_url, { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: request.finish }); };
    return <Card><div className="flex flex-wrap justify-between gap-3"><div><p className="text-xs font-semibold text-civic-muted">AW-{award.id}</p><h2 className="mt-1 break-words text-xl font-bold text-civic-navy">{award.scholarship}</h2><p className="mt-2 text-sm text-civic-muted">{award.provider}</p></div><Badge tone={award.active ? 'green' : 'neutral'}>{award.active ? 'Currently active' : 'Currently inactive'}</Badge></div>
        <p className="mt-5 text-sm leading-6 text-civic-muted">{award.published ? 'Your name is visible on the public passers list.' : award.consented ? 'Your consent is recorded. Your name will appear only after approval and while the grant is eligible.' : 'Your name is private. You can choose to share it for this grant.'}</p>
        <Link href={'/benefits/' + award.id} className={buttonStyles('outline') + ' mt-4 mr-3'}>View grant and benefits</Link>
        {(award.active || award.consented) && <Button variant="outline" onClick={() => setOpen(true)} className="mt-4">{award.consented ? 'Withdraw public listing consent' : 'Choose public listing'}</Button>}
        <Modal open={open} onClose={() => { if (!form.processing) setOpen(false); }} title={award.consented ? 'Withdraw your consent?' : 'Share your grant publicly?'}>
            <form onSubmit={submit} className="space-y-5"><p className="text-sm leading-6 text-civic-muted">{award.consented ? 'Your entry will be hidden immediately. Your scholarship grant and benefits are unchanged.' : 'If you consent and publication is approved, everyone can see your name, scholarship, and provider. Your email, grades, documents, and messages stay private. You can withdraw consent later.'}</p>
                {request.error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-civic-danger">{request.error}</p>}
                <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name="confirmed" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} className={'mt-1 size-4 shrink-0 accent-civic-teal ' + focusRing} />{award.consented ? 'I want to withdraw my public listing consent.' : 'I agree to share my name, scholarship, and provider publicly.'}</label>
                {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-civic-danger">{error}</p>)}
                {form.errors.version && <Button variant="outline" onClick={() => router.reload()}>Reload latest grant</Button>}
                <div className="flex flex-wrap gap-3"><Button type="submit" disabled={!form.data.confirmed} loading={form.processing}>{award.consented ? 'Withdraw consent' : 'Give consent'}</Button><Button variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>Cancel</Button></div>
            </form>
        </Modal>
    </Card>;
}
