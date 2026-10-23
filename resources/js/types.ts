export type UserRole = 'student' | 'provider' | 'admin';
export type ProviderVerificationStatus = 'pending' | 'approved' | 'rejected' | 'suspended';

export interface User {
    photo_url?: string | null;
    id: number;
    name: string;
    email: string;
    role: UserRole;
}

export interface SharedProps {
    [key: string]: unknown;
    auth: { user: User | null; provider_status: ProviderVerificationStatus | null };
    status?: string; notificationSummary?: { unread: number } | null;
    providerContext?: { organization_name: string | null; verification_status: ProviderVerificationStatus | null; dashboard: string; profile: string; counts: string; workspace: string; status: string; notices: VerificationNotice[] } | null;
    adminNavigation?: { dashboard: string; providers: string; users: string; scholarships: string; counts: string; awards: string; renewals: string; releases: string; terms: string; policies: string; reports: string; activity: string; home: string; logout: string; pendingProviders: number };
}

export interface StudentProfile {
    campus_region_name?: string | null; campus_province_name?: string | null; campus_city_name?: string | null;
    first_name?: string | null; surname?: string | null; middle_name?: string | null; suffix?: string | null; date_of_birth?: string | null; sex?: string | null; contact_number?: string | null;
    student_number?: string | null; education_level?: string | null; region_name?: string | null; province_name?: string | null; city_name?: string | null; barangay_name?: string | null; street_address?: string | null;
    id: number;
    school: string | null;
    course: string | null;
    year_level: number | null;
}

export interface ProviderProfile {
    first_name?: string | null; surname?: string | null; middle_name?: string | null; suffix?: string | null; position?: string | null; contact_number?: string | null; work_email?: string | null; coverage_areas?: { scope: string; region_name: string; province_name?: string; city_name: string }[] | null; organization_phone?: string | null; website?: string | null; organization_description?: string | null; country?: string | null;
    id: number;
    organization_name: string | null;
    contact_email: string | null;
    verification_status: ProviderVerificationStatus;
    rejection_reason: string | null;
}

export interface ProviderReview extends ProviderProfile {
    name: string;
    email: string;
    created_at: string;
    show_url: string;
}

export interface ProviderDetails extends ProviderReview {
    registration_details: Record<string, string | boolean | null>; registration_version: number; documents_available: boolean; documents: { kind: string; name: string; url: string; mime: string; preview_url: string }[];
    verification_version: number; reviewed_at: string | null; reviewer: string | null; review_url: string;
}

export interface VerificationHistory {
    id: number; actor: string; action: string; from: string | null; to: string | null; reason: string | null; previous_reason: string | null; occurred_at: string;
}

export interface VerificationNotice {
    id: string; status: 'approved' | 'rejected'; reason: string | null; decided_at: string;
}

export type ManagedAccountStatus = 'active' | 'pending' | 'rejected' | 'profile_missing';
export interface ManagedAccount {
    id: number; name: string; email: string; role: 'student' | 'provider'; status: ManagedAccountStatus;
    organization_name: string | null; created_at: string | null; show_url: string;
}
export interface AccountActivity { id: number; actor: string; action: string; occurred_at: string }

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    first_page_url?: string;
    last_page_url?: string;
    from?: number | null;
    to?: number | null;
    links?: { url: string | null; label: string; active: boolean }[];
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
}

export interface AdminDashboardProps extends SharedProps {
    summary: {
        applications: number; active_awards: number; pending_renewals: number; pending_releases: number;
        scholarships: number; published_scholarships: number;
        students: number; providers: number; pending: number; approved: number; rejected: number;
        missing_provider_profiles: number; students_last_30_days: number; providers_last_30_days: number;
    };
    reviewQueues: { providers: { id: number; label: string; created_at: string; url: string }[] };
    recentProviders: { id: number; organization_name: string | null; verification_status: ProviderVerificationStatus; created_at: string }[];
    recentActivity: { id: number; actor: string; action: string; reference: string; occurred_at: string }[];
    updatedAt: string;
    recentScholarships: Pick<MonitoredScholarship, 'id' | 'title' | 'provider' | 'status' | 'deleted_at' | 'show_url'>[];
}

export type ScholarshipStatus = 'draft' | 'published' | 'closed' | 'archived';
export interface ScholarshipLocation { id: number; scope: string; region_name: string | null; province_name: string | null; city_name: string | null }
export interface MonitoredScholarship { open: boolean; slots: number | null; waitlisted_count: number;
    id: number; title: string; provider: string; scholarship_type: string; status: ScholarshipStatus; locations: ScholarshipLocation[];
    opens_at: string | null; deadline_at: string | null; accepts_existing_scholars: boolean; updated_at: string | null;
    deleted_at: string | null; applicant_count: number; counts_url: string; show_url: string;
}
export interface ScholarshipFilters { q: string; status: string; provider: string; category: string; location: string; records: string; opens_from: string; opens_to: string; deadline_from: string; deadline_to: string }
export interface MonitoredScholarshipDetails extends MonitoredScholarship {
    description: string; benefit_amount: string | null; currency: string; eligibility_rules: unknown; published_at: string | null;
    provider_status: ProviderVerificationStatus; provider_url: string; academic_term: string | null;
    requirements: { id: number; name: string; description: string | null; required: boolean; allowed_mime_types: string[] | null; max_size_kb: number | null; requirement_stage: string }[];
}

export interface ApplicantCountSummary { internal_count: number; external_count: number; waitlisted_count: number; public_count: number }

export interface StudentDashboardData {
    applicationSummary: { total: number; submitted: number; in_progress: number; drafts: number; not_submitted: number };
    recentApplications: { id: number; title: string; provider: string; status: 'submitted' | 'under_review' | 'accepted' | 'rejected' | 'waitlisted'; submitted_at: string; retained: boolean; url: string }[];
    upcomingRenewals: { id: number; title: string; deadline_at: string; url: string }[];
}
