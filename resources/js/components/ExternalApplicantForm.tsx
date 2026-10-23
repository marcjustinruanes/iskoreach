import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, Input, focusRing } from './ui/DesignSystem';

export default function ExternalApplicantForm({ scholarshipId, scholarships = [] }: { scholarshipId?: number; scholarships?: { id: number; title: string }[] }) {
    const [selected, setSelected] = useState(scholarshipId ? String(scholarshipId) : '');
    const form = useForm({ name: '', email: '' });
    const upload = useForm({ file: null as File | null });
    return <Card className="mb-6 space-y-5"><div><h2 className="text-lg font-bold">Add an external applicant</h2><p className="mt-2 text-sm text-civic-muted">Record each person who applied outside IskoReach. This creates an application record without a login account.</p></div>
        {!scholarshipId && <label className="block text-sm font-semibold">Scholarship<select name="scholarship_id" className={'mt-2 min-h-11 w-full rounded-lg border bg-white p-3 '+focusRing} value={selected} onChange={event => setSelected(event.target.value)}><option value="">Choose your scholarship</option>{scholarships.map(program => <option key={program.id} value={program.id}>{program.title}</option>)}</select></label>}
        <form className="grid gap-4 sm:grid-cols-2" onSubmit={event => { event.preventDefault(); if (selected) form.post('/provider/applicant-counts/'+selected, { preserveScroll: true, onSuccess: () => form.reset() }); }}>
            <Input label="Applicant name" name="name" required maxLength={255} value={form.data.name} error={form.errors.name} onChange={event => { form.setData('name',event.target.value); form.clearErrors('name'); }} />
            <Input label="Applicant email" name="email" required type="email" maxLength={255} value={form.data.email} error={form.errors.email} onChange={event => { form.setData('email',event.target.value); form.clearErrors('email'); }} />
            <div className="sm:col-span-2"><Button type="submit" disabled={!selected} loading={form.processing}>Save external applicant</Button></div>
        </form>
        <form className="space-y-3 border-t pt-4" onSubmit={event => { event.preventDefault(); if (selected) upload.post('/provider/applicant-counts/'+selected+'/import', { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() }); }}>
            <label className="block text-sm font-semibold">Import people from CSV<input type="file" name="file" accept=".csv,text/csv" className="mt-2 block w-full text-sm" onChange={event => { upload.setData('file',event.target.files?.[0] ?? null); upload.clearErrors(); }} /></label><p className="text-xs text-civic-muted">Use name and email column headers. Up to 2 MB and 2,000 rows. Existing applicants and repeated emails are skipped.</p>
            {upload.errors.file && <p role="alert" className="text-sm text-civic-danger">{upload.errors.file}</p>}<Button type="submit" variant="outline" disabled={!selected || !upload.data.file} loading={upload.processing}>Import applicant records</Button>
        </form>
    </Card>;
}
