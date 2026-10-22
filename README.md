# IskoReach

IskoReach is a scholarship discovery, application, and monitoring platform for students and scholarship providers in the Philippines. It brings scholarship listings, applications, document requirements, and scholar support into one role-based system.

Students can discover opportunities and track their applications. Providers can manage scholarship programs and support their scholars. Administrators oversee provider verification, platform records, reports, and public award publication.

## Core features

- **Public website:** scholarship search, program details, and public scholar listings governed by student consent and publication approval.
- **Student workspace:** academic profiles, application drafts and tracking, private document vaults, requirement checklists, messages, notifications, renewals, and benefit history.
- **Provider workspace:** organization profiles, scholarship management, applicant review, private student conversations, renewal requests, confirmed awards, and benefit-release records.
- **Administration:** provider verification, account and scholarship monitoring, academic-term management, external applicant-count verification, reports, CSV exports, and activity logs.
- **Access control:** role-based permissions, ownership policies, provider approval requirements, and authorized private-document downloads.

Benefit-release records track reported amounts and statuses; the platform does not transfer money. Public scholar listings require a confirmed eligible award, student consent, and publication approval.

## Tech stack

| Layer | Technologies |
| --- | --- |
| Backend | PHP, Laravel 13 |
| Frontend | React 19, TypeScript, Inertia.js 2 |
| Styling and interface | Tailwind CSS 4, Headless UI, Lucide icons, Plus Jakarta Sans |
| Database | MySQL, Eloquent ORM |
| Authentication | Laravel Fortify, session authentication |
| Notifications and email | Laravel Notifications, Laravel Mail, queued jobs |
| Realtime communication | Laravel Reverb, Laravel Echo, Pusher protocol client, HTTP polling fallback |
| Asset tooling | Vite 8, npm, Composer |
| Testing and code quality | PHPUnit, SQLite for isolated tests, Laravel Pint, TypeScript checking, Chrome browser verification |

## Architecture

Laravel handles routing, validation, authorization, business workflows, and persistence. Inertia connects the Laravel backend to React pages within a single application. Private files are delivered through authorized backend endpoints. Queued jobs support email and notification delivery, while the scheduler supports renewal reminders.

The interface provides responsive public pages and dedicated student, provider, and administrator workspaces. Scholarship coverage supports nationwide, regional, provincial, and city/municipality scopes. Geographic directory accuracy depends on the configured dataset; adoption of a verified official dataset remains in progress.

## Workflow setup and operations

Run `composer install`, `npm ci`, and `php artisan migrate --no-interaction` against your configured database. The workflow migration adds tables and columns without resetting existing records. Create academic terms in the administrator workspace before awarding or renewing scholarships.

`composer run dev` starts Laravel, Vite, the queue listener, Reverb, and the scheduler in local development. Keep that process running for automatic reminders. In production, supervise `php artisan queue:work` and schedule `php artisan schedule:run` every minute. Application draft reminders run at 08:05 Asia/Manila at 7, 3, and 1 days before closing; renewal reminders run at 08:00 at 30, 14, and 7 days. Each reminder is recorded once per applicable deadline. Students must prepare an application draft to receive its missing-requirement reminders.

In-app notifications work without external credentials. `MAIL_MAILER=log` intentionally does not deliver email. To deliver email, configure a supported Laravel mail transport and its credentials privately in the deployment environment, then clear cached configuration and keep a queue worker running. Do not commit credentials. Reverb is optional: HTTP polling remains available. To enable WebSockets, configure matching server/client Reverb values and application credentials, select the Reverb broadcast connection, enable the Vite Reverb flag, and rebuild frontend assets.

### Outside scholarships and provider rules

Students upload evidence to their private vault and add an existing-scholarship declaration. Declarations are self-reported until the provider reviews the evidence; verification belongs to that provider only. Preparing an application authorizes its provider to view the associated student's declarations and evidence. Other students and unrelated providers cannot access them. Historical declarations and verification decisions are retained.

Providers configure whether existing scholars are accepted, whether they explicitly receive lower priority, and whether outside declarations must be verified before submission. Additional written eligibility criteria are evaluated by the provider during review; the system does not automatically validate arbitrary written criteria or query a national scholarship registry. Compatibility is rechecked before application acceptance and award creation. Previously submitted priority/scholarship snapshots remain history.

### External application counts

There is no automatic Google Forms or third-party API synchronization. Two supported workflows are available under Provider > Applicant Counts:

1. Manually report the complete external count with a source label and source date, confirming that duplicate/internal applicants were excluded.
2. Export authorized responses from Google Forms/Sheets or another source as UTF-8 CSV. Rename the email column to `email`; extra columns are ignored. Upload at most 10,000 rows and 2 MB, supply a source label/date, and confirm authorization and source completeness. Duplicate emails and emails matching counted internal applicants (including waitlisted applicants) are excluded. Invalid email rows reject the import. Different email addresses used by the same person require manual source reconciliation. Only the aggregate report is retained; the email list is not stored by the application.

Both workflows require an approved provider who owns the scholarship. The administrator must approve the resulting snapshot before it contributes to public totals. A new internal-applicant identity set invalidates prior external verification until an administrator rechecks it; the public listing identifies this exclusion. No third-party credentials are needed for these workflows. CSV export requires permission to access the source responses; this project does not obtain that permission for you.

### Requirements, renewals, and releases

Providers define application and renewal requirements separately. Students can prepare drafts and upload files before opening, but may only submit during the application period. Submitted application documents have provider review outcomes and explanations; rejected documents block acceptance until the review is resolved. Renewal requests snapshot the scholarship's renewal checklist and remain separate from new applications. An approved renewal extends a bounded award through the approved term's end; an already opened renewal can be reviewed after its original award dates expire, provided the award was not revoked/completed. Rejection does not authorize benefits for the rejected term.

Benefit records are manual provider confirmations, with pending, processing, delayed, failed, and released states. A failed/delayed record can return to processing; released records are final. These records do not transfer money, prove bank receipt, or connect to a payment gateway. No payment-provider credentials are required or used.

Run `php artisan test --compact`, `npm run typecheck`, and `npm run build` for regression checks. Backend tests require in-memory SQLite and refuse to use the live database. The optional `QA_WORKFLOW_COMPLETION_CHECK=true` mode in `scripts/verify-browser.ts` expects a separately prepared disposable browser-test database and test accounts; never point fixture-based browser checks at production data.

Providers can suspend and resume awards with a reason. Suspended awards disappear from public passers and cannot receive new benefit records or renewal changes. Resuming rechecks current scholarship compatibility; public listing requires administrator approval again. Completed/revoked awards remain final.
