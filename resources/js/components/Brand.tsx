import { Link } from '@inertiajs/react';
import { GraduationCap } from 'lucide-react';
import { focusRing } from './ui/DesignSystem';

export default function Brand({ compact = false, inverse = false }: { compact?: boolean; inverse?: boolean }) {
    return <Link href="/" className={'inline-flex shrink-0 items-center gap-2.5 rounded-lg font-stitch ' + focusRing} aria-label="IskoReach home">
        <span className={'flex items-center justify-center rounded-lg text-white ' + (inverse ? 'bg-white/10 ring-1 ring-white/15 ' : 'bg-civic-navy ') + (compact ? 'size-8' : 'size-10')}><GraduationCap aria-hidden="true" className={compact ? 'size-5' : 'size-6'} /></span>
        <span><span className={'block font-bold tracking-tight ' + (inverse ? 'text-white ' : 'text-civic-navy ') + (compact ? 'text-base leading-5' : 'text-lg')}>IskoReach</span><span className={'block text-xs leading-4 ' + (inverse ? 'text-slate-300' : 'text-civic-muted')}>Philippines</span></span>
    </Link>;
}
