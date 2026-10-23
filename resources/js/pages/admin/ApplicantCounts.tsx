import { Link, useForm } from '@inertiajs/react';
import type { ApplicantCountSummary, Paginated } from '../../types';
import Layout from '../../layouts/AdminLayout';
import { Card, Input, Button, EmptyState } from '../../components/ui/DesignSystem';
import { Pagination, Table } from '../../components/ui/DataDisplay';
interface Props { filters: { q: string }; scholarships: Paginated<{ id:number; title:string; provider:string; show_url:string; summary:ApplicantCountSummary }> }
export default function ApplicantCounts({filters,scholarships}:Props) {
    const form=useForm(filters);
    return <Layout title="Applicant counts" description="Counts are calculated from individual application records."><Card className="mb-5"><form className="flex flex-wrap items-end gap-3" onSubmit={event=>{event.preventDefault();form.get('/admin/applicant-counts',{preserveScroll:true,preserveState:true});}}><Input label="Search scholarships" name="q" value={form.data.q} onChange={event=>form.setData('q',event.target.value)} /><Button type="submit" loading={form.processing}>Search</Button></form></Card><Card>{scholarships.data.length?<Table rows={scholarships.data} rowKey={item=>item.id} caption="Scholarship applicant counts" columns={[
        {key:'title',label:'Scholarship',render:item=><Link href={item.show_url} className="font-semibold text-civic-teal underline">{item.title}</Link>},
        {key:'provider',label:'Provider',render:item=>item.provider},
        {key:'internal',label:'Internal',render:item=>item.summary.internal_count},
        {key:'external',label:'External',render:item=>item.summary.external_count},
        {key:'total',label:'Total',render:item=><strong>{item.summary.public_count}</strong>},
    ]}/>:<EmptyState title="No scholarships found" description="Scholarship applicant counts will appear here."/>}<Pagination page={scholarships} label="Scholarship count pages"/></Card></Layout>;
}
