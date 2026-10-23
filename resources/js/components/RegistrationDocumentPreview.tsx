import { useEffect, useState } from 'react';
import { Maximize2 } from 'lucide-react';
import { Button } from './ui/DesignSystem';
import { Modal } from './ui/Overlays';

export default function RegistrationDocumentPreview({ file, title }: { file: File; title: string }) {
    const [open, setOpen] = useState(false);
    const [url, setUrl] = useState('');
    useEffect(() => {
        if (!open) return;
        const objectUrl = URL.createObjectURL(file);
        setUrl(objectUrl);
        return () => URL.revokeObjectURL(objectUrl);
    }, [file, open]);
    const image = ['image/jpeg', 'image/png'].includes(file.type);
    const pdf = file.type === 'application/pdf';
    return <><Button variant="outline" size="sm" aria-label={'Enlarge ' + title} onClick={() => setOpen(true)}><Maximize2 aria-hidden="true" className="size-4" />Preview</Button>
        <Modal open={open} onClose={() => setOpen(false)} title={title} description={file.name} panelClassName="max-w-4xl">
            {url && (image ? <img src={url} alt={title + ' preview'} className="max-h-[65dvh] w-full object-contain" /> : pdf ? <iframe src={url} title={title + ' PDF preview'} className="h-[65dvh] w-full rounded border border-civic-border" /> : <p role="alert">Preview is available for PDF, JPG, and PNG files.</p>)}
            <p className="mt-3 text-xs text-civic-muted">This preview stays on your device. The file is uploaded when you create your account.</p>
        </Modal>
    </>;
}
