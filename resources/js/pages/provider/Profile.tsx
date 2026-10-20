import { Building2, ShieldCheck, Info } from 'lucide-react';
import type { ProviderProfile, SharedProps, VerificationNotice } from '../../types';
import ProviderLayout from '../../layouts/ProviderLayout';
import OrganizationProfileForm from '../../components/provider/OrganizationProfileForm';
import VerificationNotices from '../../components/VerificationNotices';
import { Card, StatusBadge } from '../../components/ui/DesignSystem';
interface Props extends SharedProps { profile: ProviderProfile; verificationNotices: VerificationNotice[] }
export default function Profile({ profile, auth, verificationNotices }: Props) {
    return <ProviderLayout title="Organization profile" description="Manage the organization details used for verification and scholarship listings.">
        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(260px,1fr)]">
            <Card className="min-w-0 p-5 sm:p-6"><div className="mb-6 flex items-center gap-3 border-b border-civic-panel-strong pb-5"><span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-slate-50 text-civic-teal"><Building2 aria-hidden="true" className="size-5" /></span><div><h2 className="text-base font-bold text-civic-navy">Organization details</h2><p className="mt-1 text-xs leading-5 text-civic-muted">Keep your name and contact email up to date.</p></div></div><OrganizationProfileForm profile={profile} /></Card>
            <div className="min-w-0 space-y-5"><Card className="p-5"><div className="flex items-center gap-2"><ShieldCheck aria-hidden="true" className="size-4 text-civic-teal" /><h2 className="text-sm font-bold text-civic-navy">Account and verification</h2></div><div className="mt-4"><StatusBadge status={profile.verification_status} /></div><dl className="mt-5 space-y-4 border-t border-civic-panel-strong pt-5 text-sm"><div><dt className="text-xs font-medium text-civic-muted">Contact person</dt><dd className="mt-1 break-words font-semibold">{auth.user?.name}</dd></div><div><dt className="text-xs font-medium text-civic-muted">Account email</dt><dd className="mt-1 break-all">{auth.user?.email}</dd></div></dl>{profile.rejection_reason && <p role="status" className="mt-4 rounded-lg bg-red-50 p-3 text-xs leading-5 text-red-900">Review reason: {profile.rejection_reason}</p>}</Card>
            <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-4"><Info aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-amber-800" /><div><h2 className="text-xs font-bold text-amber-900">Before you make a change</h2><p className="mt-1 text-xs leading-5 text-amber-900">Updating your organization name or contact email requires another admin review. Approved tools become unavailable until approval returns.</p></div></div></div>
        </div><VerificationNotices notices={verificationNotices} />
    </ProviderLayout>;
}
