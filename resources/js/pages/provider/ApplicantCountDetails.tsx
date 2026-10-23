import Layout from '../../layouts/ProviderLayout';
import ScholarshipApplicantDetails from '../../components/ScholarshipApplicantDetails';
import type { ScholarshipApplicantDetailsProps } from '../../components/ScholarshipApplicantDetails';
export default function ApplicantCountDetails(props:ScholarshipApplicantDetailsProps) { return <Layout title={props.scholarship.title}><ScholarshipApplicantDetails {...props} provider /></Layout>; }
