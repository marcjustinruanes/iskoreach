import { UserRound } from 'lucide-react';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { AccountSetup } from '../studentAccountTypes';
import { Button, Card } from './ui/DesignSystem';
import { Modal } from './ui/Overlays';
export default function StudentPhotoPanel({ setup }: { setup: AccountSetup }) {
    const photo = useForm({ photo: null as File | null });
    const removal = useForm({ confirmed: true });
    const [preview, setPreview] = useState<string | null>(null);
    const [removeOpen, setRemoveOpen] = useState(false);
    useEffect(() => { if (!photo.data.photo) { setPreview(null); return; } const url = URL.createObjectURL(photo.data.photo); setPreview(url); return () => URL.revokeObjectURL(url); }, [photo.data.photo]);
    return <Card id="photo" className="mb-6 scroll-mt-24 space-y-5"><div><h2 className="text-lg font-bold">Profile picture</h2><p className="mt-2 text-sm leading-6 text-civic-muted">Your profile picture is publicly accessible. Upload a picture only if you want it displayed on your account.</p></div><div className="flex flex-wrap items-center gap-4">{preview || setup.photo_url ? <img src={preview ?? setup.photo_url ?? ''} alt="Your profile picture preview" className="size-20 rounded-full border object-cover" /> : <span role="img" aria-label="Default profile avatar" className="flex size-20 items-center justify-center rounded-full bg-civic-panel text-civic-navy"><UserRound aria-hidden="true" className="size-8" /></span>}<form onSubmit={event => {event.preventDefault(); photo.post('/student/profile/photo', {forceFormData:true, preserveScroll:true, onSuccess:()=>photo.reset()});}} className="min-w-0 space-y-3"><label className="block text-sm font-semibold">Profile picture (JPG, PNG or WebP, up to 1 MB)<input name="photo" type="file" accept="image/jpeg,image/png,image/webp" onChange={event=>photo.setData('photo',event.target.files?.[0]??null)} className="mt-2 block w-full min-w-0 text-sm" /></label>{photo.errors.photo && <p role="alert" className="text-sm text-civic-danger">{photo.errors.photo}</p>}<Button type="submit" loading={photo.processing} disabled={!photo.data.photo}>Save picture</Button>{setup.photo_url && <Button variant="danger" onClick={()=>setRemoveOpen(true)}>Remove picture</Button>}</form></div><Modal open={removeOpen} onClose={()=>setRemoveOpen(false)} title="Remove profile picture?" description="Your current profile picture will be removed."><Button variant="outline" onClick={()=>setRemoveOpen(false)}>Cancel</Button><Button variant="danger" onClick={()=>removal.delete('/student/profile/photo',{preserveScroll:true,onSuccess:()=>setRemoveOpen(false)})} loading={removal.processing}>Confirm removal</Button></Modal></Card>;
}
