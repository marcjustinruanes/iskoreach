export interface BenefitAward { renewal_terms?: string[]; published?: boolean; student_name?: string | null; id: number; version: number; application_id: number; scholarship: string; provider: string; student_reference: string; status: 'active' | 'suspended' | 'completed' | 'revoked'; active: boolean; starts_on: string; ends_on: string | null; term: string; url: string }
export interface History { event: string; at: string; reason: string }
export interface Release { id: number; version: number; amount: string | null; currency: string; status: 'pending' | 'processing' | 'delayed' | 'failed' | 'released'; term: string; scheduled_at: string | null; released_at: string | null; notes: string | null; history: History[] }
export interface Choice { id: number; label: string }
export const benefitDate = (value: string | null) => value ? new Date(value + 'T00:00:00+08:00').toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' }) : 'No end date';
