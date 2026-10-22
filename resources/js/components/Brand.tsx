import { Link } from '@inertiajs/react';
import { focusRing } from './ui/DesignSystem';

export default function Brand({ compact = false, inverse = false }: { compact?: boolean; inverse?: boolean }) {
    return <Link href="/" className={'inline-flex shrink-0 items-center gap-2.5 rounded-lg font-stitch ' + focusRing} aria-label="IskoReach home">
        <span className={'flex shrink-0 items-center justify-center overflow-hidden ' + (inverse ? 'rounded-xl bg-civic-canvas ' : '') + (compact ? 'size-10' : 'size-12')}><img src="/images/iskoreach-logo.png" alt="" width={500} height={500} className="size-full scale-125 object-contain" decoding="async" /></span>
        <span><span className={'block font-bold tracking-tight ' + (inverse ? 'text-white ' : 'text-civic-navy ') + (compact ? 'text-base leading-5' : 'text-lg')}>IskoReach</span><span className={'block text-xs leading-4 ' + (inverse ? 'text-slate-300' : 'text-civic-muted')}>Philippines</span></span>
    </Link>;
}
