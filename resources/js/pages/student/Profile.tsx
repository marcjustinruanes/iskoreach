import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { SharedProps, StudentProfile } from '../../types';
import StudentLayout from '../../layouts/StudentLayout';
import { Button, Section, TextField } from '../../components/ui/Form';
import { Badge } from '../../components/ui/DesignSystem';
import { textError } from '../../components/ui/inputValidation';

export default function Profile({ auth, profile, accountCreatedAt }: SharedProps & { profile: StudentProfile; accountCreatedAt: string | null }) {
    const [offline, setOffline] = useState(false);
    const form = useForm({ school: profile.school ?? '', course: profile.course ?? '', year_level: profile.year_level?.toString() ?? '' });
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setOffline(!navigator.onLine);
        if (!navigator.onLine) return;
        form.clearErrors();
        const school = textError(form.data.school);
        const course = textError(form.data.course);
        const year = form.data.year_level !== '' && (!/^\d+$/.test(form.data.year_level) || Number(form.data.year_level) < 1 || Number(form.data.year_level) > 12);
        if (school) form.setError('school', school);
        if (course) form.setError('course', course);
        if (year) form.setError('year_level', 'Enter a whole year level from 1 to 12.');
        if (school || course || year) return;
        form.patch('/student/profiles/' + profile.id, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };
    return <StudentLayout title="My Profile" description="Review your account information and keep your academic details up to date.">
        <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]"><Section title="Academic information" description="Your details stay private until you submit an application. Submitted applications keep the profile snapshot you shared at that time.">
            <form noValidate aria-label="Student profile" onSubmit={submit} className="max-w-2xl space-y-5">
                {offline && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">You are offline. Your changes are still here. Reconnect and save again.</p>}
                <TextField label="School or university" name="school" value={form.data.school} onChange={e => form.setData('school', e.target.value)} error={form.errors.school} maxLength={255} autoComplete="organization" />
                <TextField label="Course or program" name="course" value={form.data.course} onChange={e => form.setData('course', e.target.value)} error={form.errors.course} maxLength={255} />
                <TextField label="Year level" name="year_level" type="number" min={1} max={12} value={form.data.year_level} onChange={e => form.setData('year_level', e.target.value)} error={form.errors.year_level} className="sm:max-w-40" />
                <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5"><Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save profile'}</Button>{form.isDirty && <span className="text-xs text-civic-muted">You have unsaved changes.</span>}</div>
            </form>
        </Section><Section title="Account information"><Badge tone="green">Student · Active</Badge><dl className="mt-5 space-y-5 text-sm"><div><dt className="text-xs text-civic-muted">Full name</dt><dd className="mt-1 break-words font-semibold">{auth.user?.name}</dd></div><div><dt className="text-xs text-civic-muted">Account email</dt><dd className="mt-1 break-all font-semibold">{auth.user?.email}</dd></div>{accountCreatedAt && <div><dt className="text-xs text-civic-muted">Joined</dt><dd className="mt-1 font-semibold">{new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'Asia/Manila' }).format(new Date(accountCreatedAt))}</dd></div>}</dl><p className="mt-5 border-t border-slate-200 pt-4 text-xs leading-5 text-civic-muted">Students have immediate access. No administrator approval is required. Account name and email are read-only here.</p></Section></div>
    </StudentLayout>;
}
