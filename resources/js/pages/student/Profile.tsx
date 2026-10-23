import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, ArrowRight, CheckCircle2, Save, ShieldCheck } from 'lucide-react';
import type { SharedProps, StudentProfile } from '../../types';
import type { AccountSetup } from '../../studentAccountTypes';
import StudentLayout from '../../layouts/StudentLayout';
import StudentRegistrationFields, { studentRegistrationDefaults } from '../../components/StudentRegistrationFields';
import StudentPhotoPanel from '../../components/StudentPhotoPanel';
import { Badge, Button, ProgressBar, buttonStyles, focusRing } from '../../components/ui/DesignSystem';
import { useRequestFeedback } from '../../components/ui/useRequestFeedback';

const sections = [
    {key:'personal',label:'Personal',description:'Your identity and contact information.',fields:['first_name','surname','middle_name','suffix','date_of_birth','sex','contact_number']},
    {key:'academic',label:'Academic',description:'Your current school and enrollment details.',fields:['student_number','school','campus_region_name','campus_province_name','campus_city_name','education_level','course','year_level','shs_graduate','graduation_year']},
    {key:'address',label:'Address',description:'Your home address and location.',fields:['region_name','province_name','city_name','barangay_name','street_address']},
    {key:'photo',label:'Picture',description:'Your optional profile picture.',fields:[]},
];
const hashPage=()=>Math.max(0,sections.findIndex(section=>section.key===window.location.hash.slice(1)));
export default function Profile({ auth, profile, accountSetup }: SharedProps & { profile: StudentProfile; accountSetup: AccountSetup; accountCreatedAt: string | null }) {
    const initial={...studentRegistrationDefaults};
    for(const key of Object.keys(initial) as (keyof typeof initial)[]) {if(key==='terms_accepted'||key==='privacy_accepted'||key==='no_middle_name'||key==='shs_graduate')continue;const value=(profile as unknown as Record<string,unknown>)[key];initial[key]=value==null?'':key==='date_of_birth'?String(value).slice(0,10):String(value);}
    initial.shs_graduate=Boolean((profile as unknown as Record<string,unknown>).graduation_year);
    initial.no_middle_name=!profile.middle_name;
    const form=useForm(initial);
    const request=useRequestFeedback(form.cancel);
    const [page,setPage]=useState(hashPage);
    const heading=useRef<HTMLHeadingElement>(null);
    useEffect(()=>{const sync=()=>{if(window.location.hash)setPage(hashPage());};const remove=router.on('navigate',sync);window.addEventListener('hashchange',sync);return()=>{remove();window.removeEventListener('hashchange',sync);};},[]);
    const changePage=(next:number)=>{setPage(next);window.history.replaceState(window.history.state,'','#'+sections[next].key);requestAnimationFrame(()=>heading.current?.focus());};
    const submit=()=>{
        if(!request.begin())return;
        form.transform(data=>Object.fromEntries(Object.entries(data).filter(([key,value])=>value!==initial[key as keyof typeof initial])));
        form.patch('/student/profiles/'+profile.id,{preserveScroll:true,onSuccess:()=>form.setDefaults(),onError:errors=>{const index=sections.findIndex(section=>section.fields.some(field=>field in errors));if(index>=0)changePage(index);},onFinish:request.finish});
    };
    return <StudentLayout title="My Profile"><div className="my-profile-page">
        <section aria-label="Account information" className="mb-6 grid gap-5 rounded-xl border border-civic-blue-gray border-l-4 border-l-civic-navy bg-white p-5 sm:p-6 lg:grid-cols-2"><div><h2 className="text-base font-bold">Account information</h2><p className="mt-2 break-all text-sm text-civic-muted">{auth.user?.email}</p><div className="mt-3 flex flex-wrap gap-2"></div></div><div className="border-t border-neutral-200 pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6"><ProgressBar value={accountSetup.completed} max={accountSetup.total} label={'Account setup: '+accountSetup.completed+'/'+accountSetup.total+' completed'}/><p className="mt-3 text-xs leading-5 text-civic-muted">Keep your personal, academic, and address details up to date.</p></div></section>
        <section className="overflow-hidden rounded-xl border border-civic-blue-gray bg-white">
            <nav aria-label="Profile pages" className="grid grid-cols-2 gap-px border-b border-civic-blue-gray bg-civic-blue-gray sm:grid-cols-4">{sections.map((section,index)=><button key={section.key} type="button" aria-current={page===index?'page':undefined} disabled={form.processing} onClick={()=>changePage(index)} className={'flex min-h-14 items-center gap-2 px-4 py-3 text-left text-sm font-semibold transition-colors '+(page===index?'bg-civic-navy text-white ':'bg-civic-canvas text-civic-muted hover:bg-civic-panel ')+focusRing}><span className={'flex size-6 shrink-0 items-center justify-center rounded-md text-xs '+(page===index?'bg-civic-gold text-civic-navy':'bg-white text-civic-slate')}>{index===3?<ShieldCheck aria-hidden="true" className="size-3.5"/>:index+1}</span>{section.label}</button>)}</nav>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 sm:px-6"><div><h2 ref={heading} tabIndex={-1} className="text-lg font-bold focus:outline-none">{sections[page].label+' information'}</h2><p className="mt-1 text-xs leading-5 text-civic-muted">{sections[page].description}</p></div><p className="text-xs font-semibold text-civic-muted">Page {page+1} of {sections.length}</p></div>
            <form hidden={page===3} noValidate aria-label="Student profile" onSubmit={event=>{event.preventDefault();submit();}}><div className="profile-page-fields p-5 sm:p-6"><StudentRegistrationFields step={Math.min(page+1,3)} data={form.data} errors={form.errors} onChange={(field,value)=>form.setData(current=>({...current,[field]:value}))}/>{request.error&&<p role="alert" className="mt-4 text-sm text-civic-danger">{request.error}</p>}{Object.entries(form.errors).filter(([field])=>!sections[page].fields.includes(field)).map(([field,error])=><p key={field} role="alert" className="mt-3 text-sm text-civic-danger">{error}</p>)}</div><div className="flex flex-wrap items-center justify-between gap-3 border-t border-civic-blue-gray bg-civic-canvas px-5 py-4 sm:px-6"><p className="flex items-center gap-2 text-xs text-civic-muted">{form.isDirty?'You have unsaved edits.':'Your saved details are shown.'}{!form.isDirty&&<CheckCircle2 aria-hidden="true" className="size-4 text-emerald-700"/>}</p><Button type="submit" loading={form.processing}><Save aria-hidden="true" className="size-4"/>Save profile</Button></div></form>
            <div hidden={page!==3} className="p-5 sm:p-6"><StudentPhotoPanel setup={accountSetup}/></div>
            <nav aria-label="Profile page navigation" className="flex items-center justify-between gap-3 border-t border-civic-blue-gray px-5 py-4 sm:px-6"><Button variant="outline" disabled={page===0||form.processing} onClick={()=>changePage(page-1)}><ArrowLeft aria-hidden="true" className="size-4"/>Previous</Button><span className="text-xs text-civic-muted">{page+1} / {sections.length}</span><Button variant="secondary" disabled={page===sections.length-1||form.processing} onClick={()=>changePage(page+1)}>Next<ArrowRight aria-hidden="true" className="size-4"/></Button></nav>
        </section><p className="mt-4 text-xs leading-5 text-civic-muted">Moving between pages keeps your edits. Select Save profile to update your account. Submitted application snapshots retain their original details.</p>
    </div></StudentLayout>;
}
