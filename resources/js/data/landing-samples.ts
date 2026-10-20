import { Building2, GraduationCap, Landmark } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/** Design fixtures transcribed from the supplied Stitch ZIP, not current offers. */
export interface SampleScholarship {
    id: string; provider: string; title: string; description: string;
    type: string; status: string; tone: 'green' | 'amber' | 'blue'; icon: LucideIcon;
    amount: string; amountLabel: string; scope: string; date: string; requirements: number;
    regions: string[]; tags: string[];
}
export const sampleScholarships: SampleScholarship[] = [
    { id: 'dost', provider: 'DOST–SEI', type: 'National Agency', title: 'DOST–SEI S&T Undergraduate Scholarship', description: 'Comprehensive government grant for regular 1st-year college students enrolled in priority S&T courses.', status: 'Open now', tone: 'green', icon: GraduationCap, amount: '₱40,000 + ₱7,000/mo', amountLabel: 'Annual benefits', scope: 'Nationwide (17 regions)', date: 'November 30, 2025', requirements: 4, regions: ['Luzon', 'Visayas', 'Mindanao'], tags: ['College Degree', 'STEM Priority'] },
    { id: 'pasig', provider: 'City Government of Pasig', type: 'Local Government', title: 'Pasig Tulong Kabataan Educational Aid', description: 'Local municipal financial assistance prioritizing low-income Pasigueño learners in tertiary state universities.', status: 'Closing soon', tone: 'amber', icon: Building2, amount: '₱15,000 / semester', amountLabel: 'Grant amount', scope: 'Pasig City residents', date: 'October 18, 2025', requirements: 3, regions: ['Luzon'], tags: ['College Degree', 'Need-Based'] },
    { id: 'aboitiz', provider: 'Aboitiz Foundation', type: 'Corporate Grant', title: 'Aboitiz Future Leaders College Grant', description: 'Leadership development subsidy for engineering, business, and data science majors.', status: 'Upcoming', tone: 'blue', icon: Landmark, amount: '100% tuition + laptop', amountLabel: 'Package', scope: 'NCR & Region VII (Cebu)', date: 'December 1, 2025', requirements: 5, regions: ['Luzon', 'Visayas'], tags: ['College Degree', 'STEM Priority'] },
];
