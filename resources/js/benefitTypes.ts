export interface BenefitAward { id: number; version: number; application_id: number; scholarship: string; provider: string; student_reference: string; status: 'active' | 'completed' | 'revoked'; active: boolean; starts_on: string; ends_on: string | null; term: string; url: string }
export interface History { event: string; at: string; reason: string }
export interface Release { id: number; version: number; amount: string; currency: string; status: 'pending' | 'processing' | 'released'; term: string; scheduled_at: string | null; released_at: string | null; notes: string | null; history: History[] }
export interface Choice { id: number; label: string }
export const benefitDate = (value: string | null) => value ? new Date(value + 'T00:00:00+08:00').toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' }) : 'No end date';
