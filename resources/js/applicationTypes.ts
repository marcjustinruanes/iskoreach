export interface ApplicationRecord { academic_priority?: boolean;
    school?: string | null; campus?: string | null;
    id: number; scholarship_id: number; title: string; provider: string; status: string;
    submitted_at: string | null; reviewed_at: string | null; decision_reason: string | null;
    low_priority: boolean; retained: boolean; program_retained: boolean; student_name?: string; applicant_source: 'internal' | 'external'; applicant_name: string | null; applicant_email: string | null;
}
export interface StudentSubmissionProfile { graduation_year?: number | null; campus_region_name?: string | null; campus_province_name?: string | null; campus_city_name?: string | null; name: string; school: string | null; course: string | null; year_level: number | null }
export interface ApplicationDetails extends ApplicationRecord {
    academic_term: string | null;
    documents: import('./documentTypes').AttachedDocument[]; version: number; student: StudentSubmissionProfile | null; program_snapshot: { title: string; version: number } | null;
}
export interface ApplicationContext { capacity_blocked: boolean; submission_status: string | null;
    academic_blocked: boolean; open: boolean; active_award_count: number; conflict_blocked: boolean; low_priority: boolean;
    required_documents: number; missing_documents: number; context_token: string; profile: StudentSubmissionProfile;
}
export const applicationLabels: Record<string, string> = {
    not_started: 'Preparing requirements', requirements_pending: 'Incomplete requirements', submitted: 'Submitted',
    under_review: 'Under review', accepted: 'Accepted', rejected: 'Rejected', waitlisted: 'Waitlisted',
};
