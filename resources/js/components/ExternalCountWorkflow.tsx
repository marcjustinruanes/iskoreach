import { Card } from './ui/DesignSystem';

export default function ExternalCountWorkflow({ administrator = false }: { administrator?: boolean }) {
    const steps = administrator ? [
        ['Check the source', 'Confirm the provider is authorized to use the source, its as-of date, and that the snapshot covers the complete external response register.'],
        ['Reconcile identities', 'Compare against current IskoReach submissions, including waitlisted applicants. CSV matching is by email; check aliases and different addresses manually. Import totals describe the import time, not the current source.'],
        ['Verify or return', 'Approve only the reconciled external total. Reject incorrect pending reports and ask for a fresh full snapshot. Use a recorded correction when an approved aggregate needs adjustment.'],
        ['Recheck changes', 'Internal applicant changes exclude stale external totals until re-verification. New external responses require a new import or report; snapshots are never added together.'],
    ] : [
        ['Export a full snapshot', 'Export the authorized external response register as UTF-8 CSV. Use one column named email, up to 10,000 rows and 2 MB. Use a consistent source label and the actual as-of date.'],
        ['Import or report', 'Import below to exclude duplicate emails and matching internal submissions. If you cannot export, reconcile identities manually and submit the complete external-only total. Do not submit only the newly added responses.'],
        ['Check the reconciliation', 'Review excluded rows in report history. Different email addresses for one person require manual review. The administrator verifies the source and current internal overlap before the snapshot counts publicly.'],
        ['Refresh when it changes', 'After new external responses or corrections, submit a fresh full snapshot. Check before publishing counts and after the application deadline. Internal applicant changes require administrator re-verification.'],
    ];
    return <Card className="mt-6"><h2 className="text-lg font-bold">External count update process</h2><p className="mt-2 text-sm leading-6 text-civic-muted">CSV import and verified manual reports are supported. Google Forms and Google Sheets are not connected automatically.</p><ol className="mt-5 grid gap-5 sm:grid-cols-2">{steps.map(([title, description], index) => <li key={title} className="border-l-2 border-civic-blue-gray pl-4"><h3 className="text-sm font-semibold">{index+1}. {title}</h3><p className="mt-2 text-sm leading-6 text-civic-muted">{description}</p></li>)}</ol><p className="mt-5 border-t border-civic-panel-strong pt-4 text-xs leading-5 text-civic-muted">Only aggregate counts and reconciliation totals are retained. The uploaded CSV and its email list are not stored. Automatic synchronization would require a separate authorized connector, scheduled updates and reconciliation; it is not enabled here.</p></Card>;
}
