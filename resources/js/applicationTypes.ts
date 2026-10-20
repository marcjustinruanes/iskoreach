export interface ApplicationRecord {
    id: number; scholarship_id: number; title: string; provider: string; status: string;
    submitted_at: string | null; reviewed_at: string | null; decision_reason: string | null;
    low_priority: boolean; retained: boolean; program_retained: boolean; student_name?: string;
}
export interface StudentSubmissionProfile { name: string; school: string | null; course: string | null; year_level: number | null }
export interface ApplicationDetails extends ApplicationRecord {
    academic_term: string | null;
    documents: import('./documentTypes').AttachedDocument[]; version: number; student: StudentSubmissionProfile | null; program_snapshot: { title: string; version: number } | null;
    history: { id: number; from: string | null; to: string; reason: string | null; occurred_at: string }[];
}
export interface ApplicationContext {
    open: boolean; active_award_count: number; conflict_blocked: boolean; low_priority: boolean;
    required_documents: number; missing_documents: number; context_token: string; profile: StudentSubmissionProfile;
}
export const applicationLabels: Record<string, string> = {
    not_started: 'Draft', requirements_pending: 'Requirements pending', submitted: 'Submitted',
    under_review: 'Under review', accepted: 'Accepted', rejected: 'Rejected', waitlisted: 'Waitlisted',
};
