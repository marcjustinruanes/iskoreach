# IskoReach implementation progress report

Report date: 21 October 2026 (Asia/Manila, Philippines).

This report separates the seven completed **admin stages** from the original nine-phase **whole-platform roadmap**. Completing the admin stages does not mean the entire scholarship platform is complete.

Terminology: a **phase** is a milestone in the whole-platform roadmap. A **stage** is a smaller milestone within the admin or provider work. Both describe development steps; their numbering belongs to different sequences.

## Current position

The project has working authentication and role-based access, a Stitch-based landing page, shared UI components, provider verification, admin monitoring, applicant-count verification, public award publication with student consent, reports, and activity logs.

Provider Stages 1 and 2 and platform Phases 3–8 now cover dashboard/profile, scholarship management/discovery, student applications/tracking, provider review, private documents/checklists, notifications/badges, private chat, renewals/reminders, explicit provider award creation, and benefit-release records/history. Public passers support search and publication-year filters. The scoped admin stages are also implemented; remaining gaps and production-runtime checks are listed below rather than treating the whole platform as finished.

The project retains Laravel, PHP, React, TypeScript, Inertia, Tailwind CSS, MySQL, Laravel Notifications/Mail, and the existing Reverb/Echo configuration. There is no separate Express or REST backend.

## Foundation and UI work

| Area | Implemented behavior |
| --- | --- |
| Accounts | One role per account: student, provider, or admin. Guests are unauthenticated visitors. |
| Student registration | Students self-register and receive immediate access. No admin approval queue for students. |
| Provider registration | Providers register pending. Admin approval is required for the approved workspace and external-count reporting. |
| Admin provisioning | A protected interactive local Artisan command creates a new admin. Public registration cannot create admins. |
| Authentication | Existing Fortify login, registration, logout, password reset, and password confirmation flows. |
| Profiles | Basic student school/course/year fields and provider organization/contact fields. The full proposed student profile is not implemented. Residential street addresses are not collected. |
| Scholarship locations | API-backed region and city/municipality selectors, dependent selection reset, server validation, cached lookup/retry handling, stored place names, and name-based public/admin search. The community API's dataset coverage remains a documented limitation. |
| Academic terms | Admin creation/search/filtering/editing of unused terms, reference protection including retained records, optional dates, audit history, saved application term display, and Philippine renewal date boundaries. |
| Shared UI | Reusable buttons, fields, cards, tables, pagination, status badges, dialogs, drawers, and empty states. |
| Landing page | Preserved Stitch styling and bundled Plus Jakarta Sans; real public scholarship search, recent listings, database counts, honest empty states, public-passer navigation, and reduced-motion support. |
| Navigation | Hamburger controls for collapsible sidebars; collapse preference persists. Sidebar scrollbars are hidden while scrolling remains available. |
| Compact admin layout | 64px header, 240px sidebar, compact page headings, readable navigation, and at least 44px sidebar link targets. |
| Provider interface refinement | Grouped navigation with distinct icons, consistent active states, compact 64px header/240px sidebar, complete mobile navigation, clearer dashboard/workspace actions, responsive filters, and more readable list/card layouts across all nine provider destinations. |
| Student interface | Dedicated dashboard/profile, grouped navigation, persistent collapse and accessible mobile drawer; student discovery layout, application tracker, vault/checklist guidance, messages/notices, renewals and benefit/consent navigation verified. |

## Admin stages

### Stage 1 — Admin foundation

Implemented protected admin routes, the shared admin layout, a dashboard using database queries, recent provider registrations, administrative activity, navigation, and empty states. Dashboard totals were extended as later monitoring tables became available.

Main page: `/admin/dashboard`.

### Stage 2 — Provider verification

Implemented provider search and status filters, provider details, approve/reject confirmations, required rejection reasons, reviewer/timestamp tracking, retained verification history, and notification handling. Pending and rejected providers cannot access approved provider operations. Changes to organization identity/contact details return the provider to pending verification.

Verification document uploads are not part of the current registration workflow. The UI does not fabricate documents or offer unsupported suspension controls.

Main pages: `/admin/providers` and `/admin/providers/{providerProfile}`.

### Stage 3 — User management

Implemented student/provider account lists, search, role/status filters, sorting, pagination, account summaries, and relevant recorded account activity.

This is account oversight. Suspension/reactivation and permanent account deletion are not implemented. Admins cannot retrieve existing passwords through these pages. Students remain independently registered accounts.

Main pages: `/admin/users` and `/admin/users/{user}`.

### Stage 4 — Scholarship monitoring

Implemented scholarship list/detail monitoring, provider/category/location/status filters, Philippine opening/deadline date filters, current/retained record visibility, recorded eligibility and requirements, and applicant-count links.

The scholarship foundation tables support the monitoring pages and Provider Stage 2 management/discovery. These admin pages do not create scholarships or introduce a scholarship approval/moderation workflow; approved providers manage publication themselves.

Main pages: `/admin/scholarships` and `/admin/scholarships/{scholarship}`.

### Stage 5 — Applicant-count accuracy

Implemented approved-provider manual external-count reporting, admin verification/rejection, corrections, re-verification, source dates, retained report history, and audit entries.

The counting rule is:

`Count preview = eligible internal submitted applications + the latest valid admin-verified external snapshot.`

Internal counts include submitted, under-review, accepted, rejected, and waitlisted student applications with submission timestamps. Drafts are excluded. Retained submitted application history remains included. Pending/rejected external reports are excluded. A changed internal applicant set invalidates external inclusion until re-verification.

Provider/admin confirmations support reconciliation of aggregate external figures. The system cannot automatically deduplicate individuals from an aggregate number. No Google Forms/Sheets synchronization is implemented. Provider Stage 2 reuses the same counting service in the live public directory and details, including the internal/verified-external breakdown.

Main pages: `/provider/applicant-counts` and `/admin/applicant-counts`.

### Stage 6 — Awards, passers, renewals, and releases

Implemented award monitoring, renewal monitoring, benefit release monitoring, real dashboard totals, a public passers page, student consent/withdrawal, and admin publish/unpublish decisions with confirmations, version checks, transactions, and audit entries.

Public entries require a confirmed active student award, current eligible records, an approved provider, student consent, and publication approval. Inactive/expired/revoked or otherwise ineligible awards are excluded. Consent withdrawal removes the public listing. Accepted applications alone do not create public passers.

The public entry contains the approved student name, scholarship, provider, and publication date. Phase 8 adds search and publication-year filtering. School publication/filtering remains unavailable because the current consent covers name, scholarship, and provider, not school details.

The admin renewal and benefit release pages monitor recorded tracking data. Renewal submission/review, private checklists, and reminder scheduling were added in platform Phase 7. Provider award creation, release-entry workflows, and owner-scoped student benefit history were added in Phase 8. The system does not transfer money.

Main pages: `/admin/awards`, `/admin/renewals`, `/admin/releases`, `/student/awards`, and `/passers`.

### Stage 7 — Reports and activity logs

Implemented nine aggregate report types: account registrations, provider verification, scholarship status, scholarship locations, submitted applications, active awards, renewal status, benefit release status, and external-count verification.

Reports support Philippine calendar date ranges of up to 366 days, pagination, empty states, and authorized backend CSV downloads containing all matching summary rows. CSV values receive spreadsheet-formula protection. Release amounts remain separated by currency. Report descriptions explain each period's meaning; current statuses are not presented as historical snapshots. External-count reports count report/history records, not automatically valid public applicants.

Implemented read-only activity logs with action, actor-account, target-record, record-type, and date filters. Supported events show selected decision context. Student actors use account references, and raw metadata, private documents/messages, and authentication secrets are excluded. No log editing/deletion endpoints are exposed.

Main pages: `/admin/reports`, `/admin/reports/export`, and `/admin/activity-logs`.

## Original whole-platform roadmap

### Provider Stage 1 — Dashboard and organization profile

Implemented a dedicated provider layout with a 64px header, 240px animated collapsible sidebar, organization identity/status, active navigation, mobile drawer, account menu, and an owner-scoped verification-notification entry point. Upcoming tools are unavailable states rather than links to nonexistent routes.

The dashboard queries the authenticated organization's current scholarship totals, retained-record count, submitted-application total, recent scholarships, and aggregate application activity. Draft submissions and non-student records are excluded; student names and private applicant details are not returned. Review, renewal-processing, and release-action metrics are not presented as functioning workflows.

Added `/provider/profile` for all authenticated providers to review their own profile. Only organization name and contact email are editable. The same form is reused in the pending/rejected account-status screen. Approved identity edits require a UI confirmation, return the organization to pending verification, and create an audit entry using the existing backend rules. Unchanged requests preserve verification and report that no changes were made. The profile route reuses existing ownership policies; workspace/count operations remain approval-gated.

No migration or new dependency was needed for Stage 1. Scholarship management/discovery, applicant review, chat, renewals, and award/benefit workflows were added in the later milestones below.

Provider Stage 1 file inventory:

- Created `resources/js/layouts/ProviderLayout.tsx`, `resources/js/pages/provider/Profile.tsx`, and `resources/js/components/provider/OrganizationProfileForm.tsx`.
- Updated provider `Dashboard.tsx`, `Status.tsx`, `Workspace.tsx`, `ApplicantCounts.tsx`, and `ApplicantCountDetails.tsx` to use the dashboard/profile flow or dedicated layout.
- Updated `app/Http/Controllers/Provider/AccountController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`, and `resources/js/types.ts` for scoped data and the profile route.
- Updated `VerificationNotices.tsx` to accept a distinct heading ID when reused in the notification dialog.
- Added `tests/Feature/ProviderDashboardProfileTest.php`; extended `scripts/verify-browser.ts`; updated this progress report.

Commands executed for this stage: targeted provider/authentication tests, `php vendor/bin/pint --dirty --format agent`, `php artisan test --compact`, `npm run typecheck`, `npm run build`, `php artisan route:list --path=provider --except-vendor`, and `node scripts/verify-browser.ts` with the disposable Provider Stage 1 fixture mode. The final test, typecheck, build, and browser runs passed. An initial provider-context isolation test failure was corrected by explicitly clearing that context for non-provider responses.

### Provider Stage 2 — Scholarship management and public discovery

Approved providers can create owned drafts, edit program information, define multiple locations and document requirements, and publish, return to draft, close, archive, or remove listings. Lifecycle changes require a reason and confirmation. Removal uses soft deletion; scholarship children and application history remain stored. Current/retained lists support search, status filters, and pagination.

Forms cover description, scholarship type, optional benefit amount/currency and academic term, opening/deadline timestamps in Asia/Manila, the existing-scholar rule, eligibility criteria, location scopes, and requirement definitions. Academic terms use existing records; term administration remains unavailable. Location entry originally used manual region/city codes; the later API integration below replaces new entry with place-name selectors and enables name search. Defining document formats and size limits alone does not enable uploads; private application/renewal uploads were implemented in later phases.

Publication requires opening and future deadline dates in order and at least one location. Published content must return to draft before editing. After the Phase 3 refinement, a submitted application, including retained submitted history, permanently locks program content. An unsubmitted draft alone does not lock it; the student must reload and review changed program information before submitting. Status changes remain available. Stale versions are rejected; transactions recheck provider approval and ownership. Organization re-verification also removes its programs from public discovery until approval returns.

Public `/scholarships` and `/scholarships/{slug}` pages show current published/previously published closed programs from approved providers. Draft, archived, retained, and unapproved-provider programs are excluded. Filters cover search, location, type, application period, academic term, existing scholars, currency/benefit range, and inclusive Philippine opening/deadline calendar dates. Nationwide programs match every location search. Amount comparisons use one currency, defaulting to PHP when unspecified. Cards/details reuse verified applicant-count safeguards and expose no student identities or provider contact email.

Provider navigation/dashboard creation links, public navigation, and student discovery navigation now connect to real routes. Existing landing sample cards remain explicitly illustrative. The new pages reuse Plus Jakarta Sans, shared components, compact provider layout, and existing sidebar motion. The editor remounts on a new management version after saving so subsequent actions use the current version.

Added provider/public scholarship controllers, form requests, management/catalog services, policy methods, shared TypeScript types, provider list/form/lifecycle UI, public list/details, and a shared eligibility renderer. Scholarship actions appear in existing admin activity logs with selected decision context. Added the `management_version` migration; it is applied to configured MySQL. No dependencies or domain sample records were added.

Application submission/review, conflict checking, and a student tracker were added in Phase 3 below; private documents followed in Phase 4, chat in Phase 6, renewals in Phase 7, and awards/benefit records in Phase 8.

Main provider pages: `/provider/scholarships`, `/provider/scholarships/create`, and `/provider/scholarships/{scholarship}/edit`.

### Phase 3 — Student applications, conflicts, tracker, and provider review

Students can prepare one application draft per scholarship from its public detail page, review the profile information to be shared, confirm submission, and track status/history in `/student/applications` and `/student/applications/{application}`. Repeated draft preparation returns the existing application. The unique student/program constraint includes retained records; a retained application cannot be replaced with another cycle. Drafts remain private to the student and are excluded from provider applicant lists and submitted counts.

Submission requires a currently published program, an approved provider account, an open application period, explicit sharing/submission confirmation, and current record/context versions. A server-generated context token detects changed program versions, profile information, and active-award membership so stale confirmations cannot silently submit changed information. Deadline checks are enforced again inside the transaction. Names, school, course, and year level are captured in a selected profile snapshot; contact email and unrelated account fields are not shared through this workflow. Legacy application records without snapshots display that limitation rather than inferred historical data.

Conflict checks use currently active, date-eligible, non-revoked scholarship awards attached to accepted student submissions. An accepted application without an award does not create a conflict. A target program that disallows existing scholars blocks submission; one that allows them requires an additional confirmation and saves a low-priority flag and submission-time rule snapshot. External self-declared awards are not collected or treated as verified awards. Priority flags describe submission-time evaluation, not a continuously recalculated scholarship ranking.

Phase 3 initially blocked programs requiring application-stage documents. Phase 4 replaces that temporary gate with validation of actual attached files and confirmation, as described below. General eligibility criteria remain displayed for student/provider review; no automatic GWA, income, or geographic eligibility engine was added.

Approved providers can filter their submitted applicants by scholarship title, status, and submission priority, view saved profile snapshots/history, and record under-review, accepted, rejected, or waitlisted decisions with a reason and confirmation. Allowed transitions are submitted → under review/accepted/rejected/waitlisted; under review → accepted/rejected/waitlisted; waitlisted → under review/accepted/rejected. Accepted/rejected decisions are final in this stage. Further decisions on retained programs are blocked, while authorized history remains viewable. Cross-provider access, student draft access, non-student submissions, and unapproved provider operations are denied.

Each draft/submission/review writes transition history and a selected activity-log event in the same transaction. Workflow versions reject stale decisions. Writes lock provider, scholarship, student, and application records in that order as applicable; future award-creation/revocation workflows must also coordinate using the student lock before changing active-award state. The existing applicant-count service now reflects real student submissions and retains its external-count re-verification safeguard. Acceptance does not create an active award or public passer.

Added application controllers, requests, policy, transactional service, history model, workflow version/profile-snapshot/history migration, shared UI/types, student tracker/details, and provider applicant list/review pages. Navigation, public application entry points, profile-sharing copy, and provider dashboard/workspace copy now reflect the functioning workflow. No dependency was added. The migration is applied to configured MySQL, with no domain sample records inserted.

Document uploads were added in Phase 4, application notifications/badges in Phase 5, and explicit award creation in Phase 8 below. Withdrawal, resubmission, reopening final decisions, and external award verification remain unimplemented. Application detail pages refresh their status on visits/requests; unread badges refresh separately.

Main provider pages: `/provider/applications` and `/provider/applications/{application}`.

### Phase 4 — Private uploads, application requirements, and document vault

Students can upload PDF, JPG/JPEG, or PNG files to `/student/documents`, search labels, download their own current vault files, and remove reusable vault entries with confirmation. Uploads validate detected MIME type, filename extension, label, and size. Stored paths are generated and remain on a dedicated private local disk with no public serving route. Download responses require authentication/policies, use attachment disposition and private/no-store headers, and reject missing or checksum-changed files. Paths and checksums are excluded from client-facing document data and audit displays.

The application supports a maximum of 10 MB per upload, additionally capped by the running PHP upload/post limits. The checked local PHP configuration has `upload_max_filesize=2M` and `post_max_size=8M`; the vault currently displays/enforces a 2 MB file limit. Raising PHP limits is a deployment configuration task; no global PHP configuration or `.env` credentials were changed.

Application drafts now show application-stage requirements and compatible vault choices. Each attachment requires an explicit checklist confirmation and is checked against the requirement's MIME and size rules. Students can replace or remove draft attachments with version checks. Replacement appends a new version; removal marks the current version rather than falling back to an older file. The workflow rejects foreign vault entries, foreign/renewal requirements, stale changes, and attachment changes after submission or on retained programs.

Submission rechecks required attachments and any selected optional attachments for existence, size, allowed MIME, and SHA-256 integrity, then freezes the selected versions in the same transaction as submission. Document selection/completeness participates in the confirmation context token. Missing or incompatible required files block submission; optional and renewal requirements alone do not require an upload. Provider review status does not certify document authenticity; separate document verification/rejection tools were not added.

Approved providers may download only frozen files belonging to their own authorized submitted applications. Student drafts, earlier unselected versions, unrelated vault entries, and other organizations' attachments remain inaccessible. Students retain access to their own application attachment versions. Providers have no direct vault access, and admins receive document audit references rather than private download privileges through this stage.

Vault file bytes are immutable through application endpoints. Attachments reference those retained blobs; removing a vault entry does not erase application files. Submitted documents remain downloadable through authorized application routes after vault removal. Old requirement definitions may be replaced before submission; draft attachment rows retain a requirement snapshot when the old requirement foreign key becomes null. Old files are not counted against new requirements. No automatic file purge, retention-policy deletion, malware scanning, OCR, public preview, or cloud-storage implementation is claimed.

Added private document tables/models/factories/policies, upload/mutation requests, controller/service, private disk configuration, authenticated routes, vault UI, checklist UI/types, frozen-document displays, and selected audit events. No dependency was added. Browser fixtures use a separate document directory as well as a disposable database. The migration is applied to configured MySQL; its two new document tables remain empty.

Main pages: `/student/documents`, the document checklist in `/student/applications/{application}`, and submitted document downloads in `/provider/applications/{application}`.

### Phase 5 — Application notifications, inbox, and unread badges

Application submission saves a notification for the owning provider. Under-review, waitlisted, accepted, and rejected review transitions save notifications for the student. Draft creation and failed/stale requests do not produce notifications. Inbox records are saved inside the workflow transaction; rolling back the transition also rolls back its notification. Existing provider-verification notifications remain supported.

Authenticated student, provider, and admin accounts have `/notifications`, with all/unread/read filters, pagination, empty states, individual read actions, and confirmation before marking all current notices read. Reads are owner-scoped and idempotent; other accounts' UUIDs return not found. Pending providers retain inbox access. Opening a linked record does not implicitly mark its notice read, and unavailable records remain visible as historical notices with no actionable link.

Inbox data uses selected fields and derives record URLs from current ownership/approval policies rather than stored URLs. Application notification payloads contain only the application reference, event, and recorded status. Inbox previews omit private profile details, filenames, documents, and decision explanations. Historical notification statuses are labelled as recorded updates; linked application pages show current state. Existing owner-only verification context still contains the provider's own saved account decisions.

Account header badges refresh from an authenticated, private/no-store summary endpoint every 15 seconds while the tab is visible and online, and on focus/reconnection. Requests are cancelled on account/layout cleanup. Late responses cannot overwrite a newer server-confirmed read count. The browser test covers a background-created notice followed by read-all on the same page when the original server count was zero. Inbox rows refresh through page requests rather than continuously adding rows while someone is reading.

An optional `notifications.changed` event is queued after commit on the recipient's private `App.Models.User.{id}` channel when the server broadcast driver is Reverb. Its payload is only a change signal; the client retrieves its own authorized count. The authenticated account shell subscribes only when `VITE_REVERB_ENABLED` is enabled and disconnects on cleanup. Realtime private authorization targets `/broadcasting/auth` and uses the current XSRF cookie. The prior unconditional page-entry initialization was removed so public guests do not initialize an account subscription. Polling remains available when Reverb is disabled or unavailable.

Application email notifications are queued after commit with three attempts and generic sign-in/inbox links. They recheck recipient identity/role and application access before delivery. Emails do not contain student identities, documents, filenames, or decision explanations. A working queue worker and configured mail transport are required; a queued notification is not proof of email receipt. No mail was sent to real user accounts during development checks, and no existing MySQL jobs were processed by these checks.

Added application notification/event/service/controller, four authenticated inbox/summary/read routes, shared unread props, role-aware inbox UI, and the shared badge refresh hook. Provider/admin existing verification/pending-work controls remain available with distinct icons. Existing notification schema and dependencies were reused; no migration or dependency was added. Chat notices followed in Phase 6, renewals in Phase 7, and awards/benefit records in Phase 8.

### Phase 6 — Private student/provider chat

Students and approved providers can open a conversation from an authorized, current submitted application. Drafts, unrelated applications, and retained application/program entry points cannot create conversations. A unique student/provider pair shares one conversation across that organization's applications. `/messages` lists only participant conversations whose provider is approved; `/messages/{conversation}` provides paginated message history. Admins and other students/providers cannot enter these conversations. Existing chat records remain stored if provider approval is lost, but state, sends, receipts, typing, downloads, and notification links become unavailable until access returns.

Messages support plain text up to 5,000 characters, one optional PDF/JPG/PNG attachment, or an attachment without text. Files use the existing private disk and effective upload limit (currently 2 MB), with MIME/extension/size validation, generated paths, checksums, private download headers, and participant authorization. Download responses reject missing/changed files. Raw markup is displayed as text. Message rows and inbox notices exclude stored paths, checksums, and send tokens. No public attachment links, edit/delete endpoints, file previews, or new admin access were added.

Each send includes a UUID retry token scoped to its conversation and authenticated sender. A repeated identical request returns the stored message without duplicating its notification/file; changed content using an old token is rejected. Writes recheck participation and approval under provider/conversation locks. Send, start, typing, and receipt endpoints are throttled. A new message creates a generic recipient inbox notification containing only conversation/message references, not text or filenames. Chat notices are in-app only; chat emails were not added.

Read receipts are explicit: the participant selects “Mark received messages read.” The server validates a boundary message in that conversation and marks only incoming messages through that boundary, leaving the participant's own sends and newer messages untouched. Matching chat notices are marked read in the same transaction. A stored receipt is shown as Read to the sender; merely fetching the page does not mark messages read.

Typing state stores only a temporary boolean for a participant and expires after five seconds. Unsent text is not stored or broadcast. The latest conversation page refreshes every four seconds while visible/online, with guarded fetches and request cleanup. Older history pages pause live replacement and link back to the latest page. If current access is revoked, the client hides cached message content and the composer after its next state check. Network failures retain the unsent draft and show feedback.

When Reverb is enabled, the client can subscribe to private `chat.{id}` and refresh authorized HTTP state after `chat.changed`. Server events dispatch after commit and contain only `{ changed: true }`, keeping private text/files out of broadcast payloads. Typing, send, and receipt changes use this signal. Default runtime broadcasting remains the log driver; polling works without enabling Reverb.

The local transport check started a separate Reverb process with fixture credentials, authenticated a participant through the real HTTP channel endpoint, subscribed with a native WebSocket client, and received a committed-message signal independently of earlier typing signals. It also denied an outsider's channel authorization. Browser chat updates, typing, and receipts were checked through HTTP polling with the normal frontend Reverb flag disabled. This verifies local broker/private-event delivery; it does not claim a production deployment or a production browser Echo configuration is verified.

Added conversations/messages/attachment tables, models/factories/policy, chat service/controller/requests/event/notification, authenticated routes/channel callback, role navigation, application entry controls, inbox/thread UI, private attachments, and receipt/typing handling. One migration was applied to MySQL; no dependency or credential change was needed. Message editing/deletion, moderation, search, multi-user provider teams, and retention/purge policies remain outside this milestone.

### Phase 7 — Renewal requests, private documents, decisions, and reminders

Approved providers can open `/renewals` requests against their own currently active confirmed awards, selecting an existing academic term with a future end date and a future Philippine-time deadline. Each award/term pair permits one request. Accepted applications alone cannot open renewals. No award is created by this workflow, and the configured MySQL database still has no awards; eligible-award and term empty states explain why request creation may be unavailable.

Creation copies the scholarship's renewal-stage requirements into a fixed checklist. Deadlines, target terms, and checklists cannot be edited or cancelled in this milestone. Students select compatible files from their private vault with confirmation, replace/remove pending attachments, and explicitly confirm submission before the deadline. Files are rechecked for MIME, size, presence, and SHA-256 integrity at submission; selected optional files receive the same checks. Replacement appends a version, removal does not restore an older version, and submission freezes only the selected versions. Removing a vault entry retains referenced renewal files.

Providers can view request status and requirements while student draft attachments remain private. Only submitted frozen files are downloadable by the owning approved provider. Students can download their own retained attachment versions. Other students/providers and admins cannot download them. Downloads reuse private/no-store headers and integrity checks; paths/checksums are excluded from browser data. Providers can approve or reject a submitted renewal with a reason and confirmation. Decisions are final here and do not extend award dates, create an award, publish a passer, or release benefits.

Changes recheck ownership, provider approval, current parent records, active award eligibility, deadlines where applicable, and workflow versions inside transactions. Locks follow provider, scholarship, student, application, award, renewal, then vault-document order. Expired/revoked awards, retained applications/programs, and provider re-verification block workflow changes. Authorized history remains readable. Legacy pending records without a saved checklist cannot be submitted. History records and selected admin audit references are retained without exposing private documents or review reasons in admin logs.

Opening a request, student submission, provider decisions, and reminders save generic recipient inbox notices. Mail is queued after commit with three attempts and fresh access checks. `renewals:remind` saves reminders on exactly 30, 14, and 7 Philippine calendar days before the deadline, only for eligible pending requests. Markers on the locked renewal row prevent duplicate scheduling on repeated runs. These markers record saved/enqueued reminders, not delivery receipts; a separate delivery-ledger table was not added. Missed reminder dates are not backfilled. The command is scheduled daily at 08:00 Asia/Manila with overlap protection. Runtime needs a scheduler (`php artisan schedule:work` locally, or the deployment's scheduler) and queue worker (`php artisan queue:work`). Actual external mail receipt remains unverified; the configured mail driver remains `log`, and no real user messages or MySQL jobs were processed during checks.

Added renewal policy, controller, mutation request, transactional service, queued notification, reminder command/schedule, private attachment model/factory/table, and workflow fields on renewals. Added role-aware list/details/checklist/review screens and enabled renewal navigation. Existing typography, compact provider header/sidebar, motion, and shared components are reused. No dependency or credential change was needed. One migration was applied to MySQL; no sample domain records were inserted.

Main pages: `/renewals`, `/renewals/{renewal}`, and authenticated `/renewals/documents/{attachment}` downloads. Award provisioning and benefit records followed in Phase 8. Award-duration changes, custom per-request requirements, and reopening/cancellation remain unavailable.

### Phase 8 — Confirmed awards, benefit records, student history, and public filters

Approved providers can use “Manage confirmed award” on a current accepted student application to select an existing academic term, record start/optional end dates, enter a student-visible explanation, and confirm a separate award. Application acceptance alone still does not create an award. One award is retained per application; no replacement cycle, award deletion, date editing/extension, or reactivation was added. Existing academic terms are reused; term administration remains a separate gap.

Award confirmation rechecks ownership, approval, current application/program records, accepted/submitted student status, application version, and a signed award-context token. Changes to the student's other award records invalidate earlier confirmation screens. Date-overlap checks include scheduled active-status awards as well as currently active awards. The target program's existing-scholar rule blocks overlap when disallowed; otherwise a separate acknowledgement is required. Provider, program, student, application, then award locks coordinate these writes with application submission and renewals. The UI returns counts/context rather than other providers' private award details.

Providers can explicitly complete or revoke an active-status award record with a reason and version-checked confirmation. These decisions are final in this milestone. Both clear its public listing, increment publication/workflow versions, retain history, and exclude it from active-award checks. Revocation also records its timestamp. They do not change the accepted application or erase previous benefit releases. Future or expired date ranges remain distinct from currently active awards.

Currently active awards support positive benefit amounts in PHP, USD, or EUR, an optional Philippine-time schedule, and a student-visible note. Benefit terms must match the award term or a separately approved renewal term. Entries start pending, may move to processing, and can be recorded released with an actual timestamp no later than now and no earlier than the award start date. Release status transitions need reasons and version checks. Released rows are final; amount/currency editing, reversal/cancellation, banking references, payment processing, and automatic renewal-based award extension are not implemented.

Each benefit-creation form sends a UUID retry token. An identical retry returns the original installment without duplicating records/notices; changed details under the same token are rejected. New tokens permit separate installments. Creation fingerprints/tokens are excluded from browser data. Amounts remain exact decimal strings and are displayed separately by currency. Students can read their own award/release history through `/benefits`, including retained records; other students/providers and admins cannot use these participant pages. Existing admin monitoring/report routes retain their authorized aggregate/oversight access. Provider approval loss blocks provider operations, and retained parent records block new writes while participant history remains readable.

Award creation/status and benefit creation/status save generic student inbox notices and queue generic email after commit with three attempts and fresh recipient authorization. Notices exclude amounts, dates, reasons, filenames, and private identities. Selected admin activity references omit private decision/release explanations. Existing student consent and separate publication approval remain required; new awards are private by default. Public `/passers` now searches already eligible public names, scholarship titles, and provider names, with Philippine-calendar publication-year filtering and retained query parameters through pagination. School data is not published or filterable under the current consent.

Added the transactional award/benefit service, role-aware controller/routes, validated mutation request, queued notification, workflow/history fields, benefit retry uniqueness, provider confirmation and award/release pages, student history links, public filters, and audit/inbox integrations. Reused existing award/release models and monitoring/report tables. No dependency or credential changes were needed. One migration was applied to MySQL; no domain sample records were inserted and no real queue jobs/mail were processed.

Main pages: `/benefits`, `/benefits/applications/{application}/create`, `/benefits/{award}`, and the existing `/student/awards` consent page. `payments` stores release records; IskoReach does not transfer funds or verify bank receipt.

### Provider interface refinement — All nine navigation destinations

Refined Dashboard, Organization profile, My scholarships, Applicants, Messages, Renewals, Awards and benefits, Applicant counts, and Provider workspace. The shared sidebar now groups program management, scholar support, and organization tools, with distinct icons, a subtle active indicator, and a compact organization/approval card. All nine tools, including Provider workspace, are available in the mobile drawer. The animated hamburger collapse, persisted preference, hidden sidebar scrollbars, reduced-motion handling, keyboard focus, 64px header, and 240px desktop sidebar remain intact.

Dashboard totals now link to their relevant existing lists, with a two-column phone metric layout, clear creation/next-step actions, and corrected scholarship empty-state copy. Provider workspace is a directory of functioning tools rather than a single count-reporting link. Organization profile separates editable details from account/verification information while preserving the approval-reset warning and confirmation flow. Scholarship/applicant filters use compact desktop rows and wider tablet controls; applicant status colors and matching-record totals improve scanning. Messages use full-row conversation links, unread indicators, timestamps, and an application entry point when empty. Renewal/award cards have clearer references, dates, status, and detail actions; applicant counts explain the verified-count rule and emphasize the real combined preview.

This refinement changes frontend presentation/navigation only. Existing authorization, submission/review decisions, consent, counts, notifications, and approval rules are preserved. No backend migration, dependency, credential, or real MySQL domain-record change was made. Shared messages/renewals/benefits list presentation also applies to student views without adding provider actions to student accounts.

The provider palette was subsequently updated with navy navigation, teal page banners and active links, a softly tinted background, and mint/blue/amber/lilac dashboard accents. Workspace tools use subtle tinted surfaces; form and table bodies remain light for reading. Mobile navigation uses the same dark palette, and the brand supports an inverse treatment only where requested. Color styles are scoped to the provider interface. TypeScript and production build passed; the nine-page empty/populated responsive browser checks passed again at 320, 768, 1024, and 1440px without runtime errors. Dashboard screenshots were visually reviewed. Palette QA artifacts are under `storage/app/private/provider-color-preview`; fixture data remained isolated from MySQL.

Sidebar scrollbar styling now uses one shared cross-browser class for provider/admin/student navigation and drawers that request hidden scrollbars. Overflow scrolling remains enabled, with sidebar overscroll containment. TypeScript and build passed; Chrome checks at a short viewport verified hidden tracks plus actual mouse-wheel and PageDown scrolling for all three desktop sidebars and the provider mobile drawer. No browser runtime errors were observed, and these checks used disposable SQLite records.

### Student Stage 1 — Dashboard, navigation, and profile

Implemented a dedicated student layout with Main, Student Services, and Account navigation, existing Lucide icons, a navy sidebar, teal active links, compact headings, 64px header, 240px sidebar, accessible mobile drawer, persistent collapse, reduced-motion handling, and hidden scrollbars with overflow scrolling preserved. Existing student application, document, chat, notification, renewal, benefit, and consent pages receive this navigation through the student branch of AppLayout. Provider/admin rendering remains separate. Discovery continues to use the existing public directory; no unsupported account-settings destination was added.

The dashboard greets the signed-in student, links to scholarship discovery and profile review, and displays real owner-scoped submitted-application totals, status breakdowns, and the five latest submissions. Drafts and records without submission timestamps are excluded; retained submitted history remains included. Accepted applications are explicitly distinguished from confirmed awards. Upcoming tasks show at most five future pending renewal requests with recorded checklists and eligible active awards, current parent records, and approved providers. No private decision reasons, document metadata, fabricated deadlines, recommendations, or completion percentages were added.

Added student-only GET `/student/profile` and reused the owner-authorized PATCH `/student/profiles/{studentProfile}` endpoint. The separate profile page edits existing school/course/year fields, displays account name/email/join date read-only, preserves validation inputs, shows saving/success/unsaved/offline feedback, and retains submitted profile snapshots. Student registration remains immediate. No migration, dependency, credential, or real MySQL record change was required.

Six new feature tests and the updated profile redirect assertion passed. Final full backend suite: **212 passing tests, 4,025 assertions**. PHP formatting, TypeScript, production build, and student-route inspection passed. An initial full-suite check exposed a collision with the admin summary payload name; the student payload now uses `applicationSummary`, and the existing admin privacy assertion passes unchanged. The new browser checks passed empty/populated dashboards, profile validation and saved values, offline recovery, forbidden admin/provider access, cross-student application denial, existing student destinations, active navigation, 44px links, compact geometry, hidden scrollbar scrolling, collapse/reload persistence, drawer focus/Escape, and discovery navigation. Dashboard/profile and existing private student pages fit 320, 768, and 1440px with the bundled font and no uncaught runtime errors. Screenshots are under `storage/app/private/student-stage1-preview`; desktop dashboard, mobile profile, and mobile drawer screenshots were visually inspected. QA records used guarded disposable SQLite, not real MySQL. Backend tests use isolated SQLite. No real mail or queue jobs were processed.

This stage refines dashboard/profile/navigation only; existing domain workflows were reused. Full personal-profile fields, account-name/email editing, and future Student Stage 2 work remain outside this stage. Later provider banner refinement also removed the large colored title banners in favor of compact page headings; the admin sidebar now shares the navy navigation palette.

### Student stages completion review — Discovery through benefits

After Student Stage 1, completed the remaining scoped student workflow review: discovery/details, applications/tracking, private requirements, messages/notifications, renewals, and awards/benefits/consent. These are student delivery stages, not new numbering for the original nine platform phases. Existing backend workflows were reused; no duplicate application, chat, renewal, or award implementations were added.

Signed-in students now keep their workspace/sidebar while browsing the existing scholarship URLs; guests and other roles keep public browsing. Discovery has offline feedback and preserves search inputs. Application tracking now uses responsive status-colored cards with provider/date/retained context and clear draft/detail actions. The stale school-edit link now opens the real profile page. Vault upload and file management use a clearer responsive layout, with links back to application/renewal checklists and explicit upload-versus-attachment guidance. Application and renewal readiness uses actual required-file state; unselected optional renewal files are no longer labeled as required. Student benefits link directly to separate publication consent management.

No backend logic, authorization, route, schema, dependency, credential, or real MySQL record changes were needed in this continuation. TypeScript, production build, route inspection, and the full backend suite passed again: **212 tests, 4,025 assertions**. No new backend tests were added for presentation-only changes.

Chrome verified discovery search/reset/no-results/offline recovery, active student discovery navigation, program details, real PDF vault upload and authorized downloads, required attachment gating, attach/remove/reattach, confirmed application submission, tracker filters/reset, provider review feedback, retained submitted downloads after vault removal, notification privacy/read-all/cancel, two-way chat and real attachment download, literal markup, and explicit read receipts. Separate fixture runs passed renewal request/checklist/submission/private-download/approval restrictions, plus award confirmation, pending/processing/released benefit history, student consent/admin publication, public filters, and revocation removing public results while retaining releases. Final Student Stage 1 regression passed again after the phone-card spacing refinement.

Student pages fit 320, 768, and 1440px without uncaught runtime errors. Discovery/vault and phone tracker/chat/renewal/benefit screenshots were visually inspected. QA used guarded disposable SQLite and separate file storage; all temporary local services were stopped. An initial upload harness run stalled on a relative fixture path; rerunning with an absolute path passed, and Chrome commands now have timeouts. No production mail, real queues, or new production Reverb verification was claimed.

Detailed scope, exact changed files, commands/results, screenshots, and remaining platform gaps are in [STUDENT-STAGES-REPORT.md](STUDENT-STAGES-REPORT.md). The scoped student stages are complete; academic-term administration, geographic dataset review, expanded profile fields, undefined moderation, additional lifecycle actions, and production-runtime work remain separate unfinished platform scope.

### Login redirect correction

A real student login exposed a saved-destination case that the earlier workflow checks missed: an authenticated student was redirected to the provider dashboard and correctly received 403 there. The student's configured MySQL profile and ownership policy were valid; the access denial was not a missing profile or a need for student approval.

Added a Fortify login response that validates saved local GET destinations against the existing role and provider-approval middleware. Incompatible, external, or missing destinations fall back to the existing role-routing dashboard. Valid same-role deep links and Fortify's JSON login response remain supported. Protected routes still return 403 when accessed directly by another role. No account role, password, profile, database record, or permission was changed.

The regression was reproduced by a failing login test before the fix. Seven new tests cover cross-role redirects, authorized student deep links, pending/approved provider behavior, external/missing targets, and JSON login. PHP formatting and all **219 tests, 4,064 assertions** passed. Disposable Chrome verified student login after a saved provider URL, a valid student profile deep link, the reverse provider/student case, and unchanged direct role denial. No uncaught runtime errors occurred. The temporary fixture service was stopped afterward. Opening /dashboard recovers an already-authenticated user who is still viewing the old forbidden workspace URL.

### Public website completion review

Completed the remaining scoped public-site phases after inspection. The landing page now uses the existing public catalog for recent scholarships and real public program/open-program/passer counts, and sends keyword/location searches to the real directory. Sample listings, illustrative statistics, fictitious workspace activity, document-scanning claims, and stale planned-feature copy were removed from the rendered home page while retaining the Stitch typography, palette, and section layout. This supersedes the earlier landing-preview descriptions above.

Directory cards now include opening dates and guarded reset/request feedback. Details explain application preparation and aggregate count limitations. New authenticated student GET `/scholarships/{slug}/apply` preserves the selected program through login, rechecks public visibility, and returns to its detail page without creating an application. Existing application, provider publication, and award consent/publication workflows are reused. Passer filters now show inline validation, offline feedback, and distinct empty/no-match messages; public navigation identifies the current page. No authenticated sidebar or dashboard was changed in this public-site work.

Final full backend suite: **223 tests, 4,140 assertions**. Four new homepage/continuation tests cover real empty data, public-only bounded listings and counts, consent/approval changes, login return, role restrictions, visibility changes, and GET requests creating no applications. PHP formatting, TypeScript, production build, and route inspection passed. Populated and empty disposable Chrome checks passed live search, filters/reset, selected-program login, passer privacy/offline recovery, role-to-public navigation, responsive layouts, font loading, and reduced motion, with no uncaught runtime errors. Screenshots are under `storage/app/private/public-site-preview` and `storage/app/private/public-site-empty-preview`; desktop hero and phone directory/passers screenshots were visually reviewed. Both fixture servers were stopped.

No migration, dependency, credential, `.env`, or real MySQL domain-record change was required. A final read-only MySQL check confirmed zero scholarships, applications, awards, academic terms, notifications, and queued jobs. Production runtime and the previously documented platform gaps remain separate. Detailed delivered scope, files, test results, and limitations are in [PUBLIC-WEBSITE-REPORT.md](PUBLIC-WEBSITE-REPORT.md).

### Academic-term administration milestone

Implemented `/admin/academic-terms` using the existing model, table, composite academic-year/semester uniqueness, and five existing reference tables. Admins can search/filter/sort/paginate terms, create terms, and edit unused terms with confirmations, validation, stale-state protection, and transactional audit records. Terms referenced by scholarships, applications, awards, renewals, or benefit records are read-only; retained scholarship/application references are included. No delete endpoint, global active-term flag, term status, overlap prohibition, migration, or new dependency was added. Nullable dates remain supported, with end-after-start validation when both are provided.

Existing term selectors, public filters, monitoring, and award reports remain connected. Application details now display their saved term without reassigning history. Term-row locks coordinate first scholarship/award/renewal references with admin edits. Renewal end-date eligibility now consistently follows the Philippine calendar, with a midnight regression test. The admin page reuses the existing sidebar and components; phone dialogs are height-bounded and read-only labels wrap.

Eleven new feature tests passed: **288 assertions**. The final full backend suite passed **234 tests, 4,428 assertions**. PHP formatting, TypeScript, production build, and route inspection passed. Disposable Chrome verified empty/create/edit/validation/duplicate/offline/stale/read-only/filter/audit flows, role restrictions, the provider selector, saved student term display, and 320/768/1440px layouts with no uncaught runtime errors. Final phone read-only display regression passed after visual refinement. Screenshots are under `storage/app/private/academic-terms-preview` and `storage/app/private/academic-terms-final-preview`; desktop list and phone dialogs were visually reviewed. All temporary fixture servers were stopped.

No real MySQL records were created or changed. A final read-only check confirmed zero terms and dependent domain records, notifications, and queued jobs; all 17 existing migration records remain unchanged. This milestone supersedes earlier statements that academic-term administration is unavailable. Concurrency safeguards were implemented but not load-tested against production MySQL. Full scope, files, verification, and limitations are in [ACADEMIC-TERMS-REPORT.md](ACADEMIC-TERMS-REPORT.md).

### API-backed Philippine location selection

The scholarship editor now selects regions and cities/municipalities through authenticated-independent, throttled same-origin lookup routes: `/locations/regions` and `/locations/regions/{region}/cities`. Laravel retrieves only public geographic data from the configurable `services.psgc.url` (`PSGC_API_URL`, default `https://psgc.gitlab.io/api`). No browser API keys, student names, account emails, profile information, or private addresses are sent upstream. These fields describe scholarship coverage; no new residential-address, province, barangay, or street-address collection was added.

The integration uses the community-maintained PSGC API, not an official government-operated validation service. The real PHP smoke check retrieved 17 regions and 17 NCR cities/municipalities, including Quezon City. This verifies access/schema, not current nationwide completeness. The returned dataset has only 17 regions; source coverage and administrative changes need review before claiming a complete current PSA directory. The base URL is configurable for a future compatible source. No missing place names or codes were fabricated.

Lookup responses normalize and validate codes, names, uniqueness, response shape, and city-to-region ancestry. Laravel caches successful options for six hours and retains the last successful set for up to 30 days for outage fallback, marked as stale in the response/UI. Without a usable cached response, the endpoint returns a controlled 503 and the UI offers retry while preserving saved selections. Changing a region explicitly clears the selected city; pending requests are aborted on context changes/unmount. Nationwide scopes need no external lookup.

Scholarship writes validate chosen codes against the server's directory and derive names server-side; clients cannot inject place labels or submit a city under the wrong region. New location rows store nullable region/city names for public/admin display and search without network calls while rendering listings. Existing stored codes are retained without inventing historical names; unresolved legacy selections need correction before a new save. Existing scholarship versioning, content/history locks, and nationwide search inclusion remain in place. A migration adds the two nullable name columns; it is applied to MySQL. No dependency, credential, domain sample record, or private-address record was added.

Seven new backend tests passed for normalized/cached responses, unavailable or malformed upstream data, stale-cache expiry, ancestry/label injection, no-network nationwide scopes, failed-save protection, and public name search. The full suite passed **206 tests, 3,889 assertions**; the affected admin scholarship monitoring tests also passed separately (**10 tests, 250 assertions**). TypeScript, PHP formatting, and production build passed. Chrome checks with isolated SQLite and namespaced fixture lookup caches verified cascading choices/reset, saved selections, blocked-request/retry recovery, publication with readable place names, name search, and layouts at 320, 768, and 1440px. Screenshots are under `storage/app/private/locations-preview`. Live upstream access was checked separately with PHP using in-memory cache; the deterministic browser run does not certify live upstream availability or dataset completeness.

| Phase | Current status | Remaining scope |
| --- | --- | --- |
| 1. Auth, roles, policies, layouts | Implemented foundation | Extend layouts and permissions as domain workflows arrive. |
| 2. Provider scholarship CRUD and public discovery | Implemented in Provider Stages 1–2, with later API location selection | API-backed place names now work; source completeness/currentness remains a gap; academic-term administration is implemented in the later milestone. |
| 3. Applications, conflicts, tracker | Implemented with real document checks | Required attachments are validated by Phase 4 before submission. Withdrawal, repeat cycles, and final-decision reopening are not implemented. |
| 4. Requirements, uploads, vault | Implemented for applications and renewals | Private vault, validated uploads, immutable attachment versions, checklist confirmation, and authorized downloads. Malware scanning and file-purge policies are not implemented. |
| 5. Notifications and live badges | Implemented for application/verification/chat/renewal/award/benefit inbox workflows | Owner inbox/read actions and automatic unread polling work. Application/renewal/award/benefit email is queued; actual external mail receipt is unverified. |
| 6. Chat | Implemented for student/provider pairs | Participant authorization, messages, private files, explicit receipts, typing, and polling work; local private Reverb event delivery is verified. Production deployment, editing/deletion, moderation, search, and team conversations are not implemented. |
| 7. Renewals | Implemented for existing eligible awards | Provider requests, student submission, private files, final decisions, and idempotent scheduled reminders. Award provisioning was added in Phase 8; extension, cancellation/reopening, and custom request requirements remain unavailable. |
| 8. Payments, public passers, external counts | Implemented for scoped award/release/publication/count workflows | Explicit award creation/completion/revocation, release records and student history, publication/consent, public search/year filters, and verified manual external counts. School publication/filtering, reversals, award-date changes, banking integrations, and automatic external synchronization remain unavailable. |
| 9. Admin dashboard | Seven scoped admin stages implemented | Dispute handling, flagged-application workflows, suspension, and other undefined moderation actions remain outside the implemented scope. |

## Geographic data review and search consistency

Completed the geographic implementation audit and safe search corrections. Public and admin filters now share literal, whitespace-tolerant token matching against saved region/city names and codes. Tokens must match one coverage row; nationwide programs are included consistently. Existing public visibility and admin retained-record rules remain enforced. Fresh lookup labels normalize repeated Unicode whitespace; source limitations and unsupported province/barangay levels are explained in the UI. Existing codes and saved history were not remapped.

A new live audit confirmed 17 regions and 1,634 city/municipality rows across all returned region endpoints, with no duplicate codes or malformed city-parent relationships. Comparison with PSA's current summary confirmed stale geography: the community response omits NIR, places Bacolod in Western Visayas and Siquijor municipality in Central Visayas, and lists Sulu province under BARMM. The nine-digit adapter is incompatible with newer ten-digit identifiers. No source replacement or geographic completeness certification was made. Official-source links, licensing/version findings, supported-level limits, and the safe dataset/crosswalk upgrade plan are in [GEOGRAPHIC-DATA-REPORT.md](GEOGRAPHIC-DATA-REPORT.md).

Four new regression tests and extended malformed-source tests passed. Final full suite: **238 tests, 4,620 assertions**. PHP formatting, TypeScript, and production build passed. Isolated Chrome verified selection/reset/save/retry/publication/name display/search and 320/768/1440px layouts without runtime errors; phone provider and desktop public screenshots were visually reviewed. Artifacts and the live public-source audit snapshot are under `storage/app/private/geographic-review-preview`. The disposable server was stopped. Read-only MySQL recheck confirmed zero scholarships, location rows, applications, notifications, and queued jobs. No schema, migration, dependency, credential, `.env`, real record, mail, or production queue changes were made.

## Official geographic upgrade - Isolated Phase 3

After source verification and import planning, implemented a normalized-package inspector, transactional SQLite staging importer, and explicit-edition directory reads. Nineteen new tests passed with 59 assertions; the final full suite passed **257 tests, 4,679 assertions**. PHP formatting, TypeScript, production build, and CLI registration checks passed. The three new directory tables and composite relationships were tested only in isolated SQLite; the additive migration remains unapplied to configured MySQL. A read-only MySQL check confirmed all three geographic tables absent and zero scholarships, locations, applications, notifications, and jobs.

The existing community geographic source and provider/public UI remain in use. No activation command, source switch, legacy remapping, production migration, credential, dependency, or `.env` change was made. Official workbook acquisition, XLSX extraction, complete source-row reconciliation, edition review, MySQL write verification, and website integration remain pending. Synthetic fixture success is not official geographic certification. See [OFFICIAL-GEOGRAPHIC-PHASE3-REPORT.md](OFFICIAL-GEOGRAPHIC-PHASE3-REPORT.md) and [OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md](OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md).

## Legacy geographic mapping and upgrade limits

Completed read-only mapping proposal generation with explicit inventory selection, original-value fingerprints, target-manifest digests, evidence references, and exact/changed/moved/ambiguous/unmatched classification. No mapping is applied automatically or through a command. Ten new tests passed with 43 assertions; the final full backend suite passed **267 tests, 4,722 assertions**. PHP formatting, TypeScript, and production build passed. A read-only configured MySQL check again confirmed zero scholarship, location, application, notification, and job records, with the directory migration unapplied.

A fresh attempt to obtain the official PSA workbook again returned HTTP 403 with HTML. Official workbook extraction, complete edition verification, provider/public integration against that edition, and deployment remain unfinished. The existing geographic source stays active. See [OFFICIAL-GEOGRAPHIC-UPGRADE-REPORT.md](OFFICIAL-GEOGRAPHIC-UPGRADE-REPORT.md) for completed functionality, commands, verification, and the precise external blocker. No production record, `.env`, credential, or active-source change occurred.

### Input validation standardization

Completed the three approved implementation groups after a read-only audit. Registration, new-password/reset rules, local admin provisioning, provider contacts, student academic fields, and scholarship description limits now share the relevant validation helpers. Unicode full names retain supported punctuation and formatting; case-insensitive account-email checks match the existing policy; new passwords preserve spaces while enforcing bcrypt's 72-byte boundary. Existing login credentials remain usable. Cosmetic provider email case/whitespace edits preserve approval; meaningful identity/contact edits still require review. Scholarship descriptions enforce both character and storage-byte limits, and nested requirement inputs show their existing limits/errors.

Thirteen new regression tests passed with 76 assertions; the final full backend suite passed **280 tests, 4,798 assertions**. TypeScript, production build, PHP formatting, and disposable Chrome validation checks passed. Browser checks covered invalid feedback and successful registration, spaced-password login, profile saves, approval preservation, requirement errors, and draft creation, with phone/tablet/desktop layouts and no uncaught runtime errors. No schema, dependency, credential, `.env`, or real account/domain data changed. The fixture server was stopped. Details and final full-suite results are in [INPUT-VALIDATION-REPORT.md](INPUT-VALIDATION-REPORT.md). The official geographic dataset/source-switch blocker and production-runtime checks remain separate unfinished scope.

## Database snapshot

Read from the configured MySQL database on the report date. No account identifiers, credentials, or passwords are included.

| Record type | Stored records |
| --- | ---: |
| Admin accounts | 1 |
| Student accounts | 1 |
| Provider accounts | 1 |
| Provider profiles | 1 |
| Academic terms | 0 |
| Scholarships | 0 |
| Applications | 0 |
| Student vault documents | 0 |
| Application document versions | 0 |
| Conversations | 0 |
| Messages | 0 |
| Message attachments | 0 |
| External count reports | 0 |
| Scholarship awards | 0 |
| Renewals | 0 |
| Renewal document versions | 0 |
| Benefit release records (`payments`) | 0 |

The seventeen migrations from the earlier implementation snapshot are applied across twelve batches, including the later location-name columns. The subsequent `2026_10_20_134208_create_geographic_directory_tables` migration is prepared and tested in isolated SQLite but remains unapplied to configured MySQL. They cover framework infrastructure, roles/profiles/audit logs, notifications/provider verification versioning, scholarship monitoring, applications/external counts, award/renewal/release tracking, scholarship management versioning, application workflow versioning/snapshots/history, private document metadata, private chat, renewal workflow/private document versions, award/release workflow/history/retry metadata, and location names. A read-only MySQL check after Phase 8 confirmed zero scholarships, applications, awards, renewals/renewal documents, benefits, notifications, and queued jobs. The location-integration check again confirmed zero scholarships/location rows, applications, notifications, and jobs. No domain sample records were added to MySQL for browser demonstrations. Browser fixtures use a separate disposable SQLite database and document directory; automated feature tests use isolated in-memory SQLite and a fake private disk.

The Phase 5 read-only check also confirmed zero scholarships, applications, vault/application documents, awards, notifications, and queued jobs in MySQL. The configured queue uses the database; mail and broadcasting currently use log drivers. Real external mail and Reverb delivery therefore require explicit runtime configuration. No configuration credentials were changed.

## Verification record

Latest full backend suite after input validation standardization: **280 passing tests, 4,798 assertions**. The preceding legacy geographic mapping run passed 267 tests and 4,722 assertions. The preceding isolated directory/importer run passed 257 tests and 4,679 assertions. The preceding geographic search corrections passed 238 tests and 4,620 assertions. Academic-term administration passed 234 tests and 4,428 assertions. Public website completion passed 223 tests and 4,140 assertions. The login redirect fix passed 219 tests and 4,064 assertions. The student stages completion review passed 212 tests and 4,025 assertions. Location integration passed 206 tests and 3,889 assertions. Phase 8 passed 199 tests and 3,828 assertions; Phase 7 passed 187 tests and 3,596 assertions.

Provider interface refinement passed TypeScript checking and the production build. The existing provider dashboard/profile regression tests also passed: **8 tests, 127 assertions**. No new backend test was added for presentation-only changes, and its historical full-suite result was the earlier Phase 8 run; the latest full-suite result above supersedes it.

Chrome verified all nine provider destinations with empty and populated disposable fixtures at 320, 768, 1024, and 1440px. Checks covered no page overflow, bundled Plus Jakarta Sans, at least 16px controls, 64px headers, 240px desktop sidebars, 44px navigation targets, exactly one active desktop navigation link, collapse/reload persistence, all nine mobile links, drawer focus/Escape, scholarship/applicant filter reset, conversation entry, and profile change confirmation/cancel. No uncaught runtime errors occurred. Dashboard/profile/applicant screenshots were visually reviewed; phone metrics and tablet filter spacing were refined following that review. Final artifacts are under `storage/app/private/provider-ux-preview`. All records came from a disposable SQLite database; no real organization details were saved during checks.

Phase 8 passed PHP formatting, TypeScript checking, production build, route inspection, and MySQL migration/status inspection. Twelve new feature tests cover owned/current/accepted-only award creation, injected fields, uniqueness, confirmations, dates, stale versions/context, future award overlap, existing-scholar acknowledgement, revocation/publication/context invalidation, final decisions, student/provider/admin restrictions, release retry deduplication, amount/currency/term/timestamp validation, release status/history, approved renewal terms, retained history, transaction rollback, private inbox/mail authorization, and Philippine-year public filtering without school/email exposure.

Disposable Chrome checks passed accepted-applicant award entry, confirmation/cancel, award creation, offline benefit-form recovery with preserved inputs, pending/processing/released statuses, student history and role restrictions, student consent, admin publication, public search/reset, and revocation removing the public result while retaining release history. Provider confirmation/award/release pages, student list/history, and public filters fit 320, 768, and 1440px with bundled Plus Jakarta Sans and no uncaught runtime errors. Mobile student history and desktop provider-form screenshots were visually inspected. Artifacts are under `storage/app/private/benefits-preview`. The run used disposable SQLite, not real MySQL domain records; external email/bank receipt and production hosting remain unverified.

Phase 7 passed PHP formatting, TypeScript checking, production build, renewal-route/schedule inspection, and the MySQL migration. Eleven new backend tests cover owner/role restrictions, creation validation and duplicate pairs, fixed requirements, scoped lists/filters, required and optional file checks, stale versions, replacement/removal, immutable submitted files, private downloads and vault-removal retention, deadlines/inactive awards/retained programs/provider re-verification, final approval/rejection without award/payment mutation, legacy records, Philippine-calendar reminder deduplication, generic inbox/mail authorization, and transaction rollback.

Disposable Chrome checks passed provider request creation, confirmation/cancel, student checklist attach/remove/reattach, confirmed submission, authorized student/provider downloads, provider approval and final-decision controls, and admin download denial. List, pending/checklist/submitted/review/approved pages fit 320, 768, and 1440px with bundled Plus Jakarta Sans and no uncaught runtime errors. Screenshots are under `storage/app/private/renewals-preview`; mobile checklist typography was also visually inspected. The run found and corrected validation of an empty file selection during removal, now covered by regression tests. Browser award/document/request records used isolated SQLite and a separate document directory. Scheduler inspection and command tests verify reminder scheduling/enqueue behavior, not a deployed cron service or external email delivery.

Phase 6 passed PHP formatting, TypeScript checking, the production build, route inspection, and migration. Ten new backend tests cover application entry/unique pairs, guest/admin/nonparticipant denial, sender/token injection, identical/changed retries, empty/oversized/invalid uploads, private downloads and checksums, duplicate-blob cleanup, bounded incoming receipts and related notices, temporary typing state, provider re-verification, pagination URLs, private previews, and participant/cross-user private-channel authorization.

Disposable Chrome checks passed application-to-chat navigation, student/provider sends, real PDF attachment transfer/download, literal markup rendering, incoming-read acknowledgement, sender Read state, typing state, outsider state/download denial, private-channel outsider rejection, and hiding message content after approval revocation. Student thread, provider inbox, and provider thread layouts fit 320, 768, and 1440px with the bundled font loaded and no uncaught runtime errors. Screenshots are under `storage/app/private/chat-preview`. A separate local Reverb process and native WebSocket client verified real private subscription and a committed-message change signal after earlier typing signals had settled. Test credentials, database, and file storage were isolated; both temporary services were stopped after checks.

Phase 5 passed PHP formatting, TypeScript checking, the production build, and notification-route inspection. Nine new backend tests cover workflow recipients, stale retry duplicate prevention, rollback, inbox/read ownership, untrusted metadata/URL exclusion, idempotent read timestamps, read-all confirmation, new unread notices after bulk read, filters/pagination, unsupported types, pending-provider access, current-link authorization, generic queued-mail content/authorization, after-commit contracts, and private-channel owner/cross-user authorization. The broadcast test signs/authenticates locally with fixture keys; it does not contact a WebSocket server.

Disposable Chrome checks passed a real database notice arriving while an inbox remained open, the unread badge updating through polling, same-page read-all reset, confirmation/cancel, read/unread filters, record navigation, private preview omission, and independent student/provider/admin inboxes. All three role layouts fit 320, 768, and 1440px; provider/admin headers remained 64px, the bundled font loaded, and no uncaught runtime errors occurred. Screenshots are under `storage/app/private/notifications-preview`. Reverb stayed disabled in this browser run, so it verifies polling rather than WebSocket delivery. A queue fake verifies email-job creation in tests; actual external mail receipt is unverified.

Phase 4 passed PHP formatting, TypeScript checking, the production build, document route inspection, and the MySQL migration. Eleven new backend tests cover vault ownership, cross-role denial, content/extension/size validation, private metadata, real PDF/PNG fixture storage, required attachments, provider access after submission, cross-student/cross-program denial, requirement size/MIME limits, replacement versions, vault-removal retention, detach behavior, stale versions/confirmation contexts, changed/missing blobs, submitted-file immutability, and retained requirement snapshots. Automated tests use isolated SQLite and a fake private disk.

Disposable Chrome checks passed a real PDF upload, vault validation, private downloads, checklist attach/detach, required-document submission, submitted download headers, vault-removal confirmation/cancel, retained submitted downloads after removal, provider access to submitted files, denial of the removed vault URL, and guest redirection to login. Vault, checklist, student submitted-document, and provider submitted-document pages fit 320, 768, and 1440px with the bundled font loaded and no uncaught runtime errors. Screenshots are under `storage/app/private/private-documents-preview`. The browser database and document root are separate disposable fixtures; no real MySQL application or document was created.

Phase 3 passed PHP formatting, TypeScript checking, production build, route inspection, and the MySQL migration. Thirteen new backend tests cover role/ownership restrictions, duplicate draft handling, injected fields, snapshots, deadline/provider/publication restrictions, active and inactive award rules, stale context/versions, required-document blocking, provider privacy, review transitions/final decisions, retained history, pagination, and editable programs with unsubmitted drafts. The latter test verifies that program edits invalidate a student's earlier confirmation and that submission uses the newly reviewed program version.

Disposable-fixture Chrome checks passed student draft preparation, profile-sharing confirmation/cancel, offline feedback/recovery, submission, provider under-review/waitlisted/accepted decisions, student decision history, required-document blocking, disallowed active-award conflicts, and extra confirmation for allowed low-priority submissions. Draft/submitted student details, tracker, provider applicant list, and review pages fit 320, 768, and 1440px, with Plus Jakarta Sans loaded and no uncaught runtime errors. Screenshots are under `storage/app/private/application-workflow-preview`. These checks used disposable SQLite; no test submission was made to MySQL.

Stage 2 passed PHP formatting, TypeScript checking, the Vite production build, route inspection, and migration-status inspection. Ten new backend tests cover ownership/approval restrictions, injected fields, nested validation, dates, version conflicts, publication safeguards, history retention, public visibility, filters, pagination, privacy, and verified count invalidation. Disposable-fixture Chrome checks exercised draft validation/creation, saving an edit then publishing with the current version, document requirement entry, confirmation/cancel, public search/reset/details, closing, archiving, soft removal, retained-list filtering, and application-based content locking. Creation, published edit, public list/details, and retained-list pages fit 320, 768, and 1440px; the bundled font loaded and no uncaught runtime errors occurred. Browser screenshots are local QA artifacts under `storage/app/private/provider-stage2-preview`.

The most recent completed Stage 7 verification also passed TypeScript checking, the Vite production build, and PHP formatting. All nine report queries executed successfully against MySQL using read-only queries.

Provider Stage 1 also passed TypeScript checking, the production build, and PHP formatting. Disposable-fixture Chrome checks covered dashboard/profile layouts at 320, 768, and 1440px, disabled future tools, notification ownership, distinct dialog heading IDs, sidebar animation, validation input preservation, confirmation/cancel, offline feedback, and loss of workspace/count access after organization edits. New backend tests cover real empty totals, ownership, retained/submitted counting, rejected/pending access restrictions, validation, role/status injection protection, unchanged updates, and provider-context isolation.

Chrome checks covered all ten admin pages at 320, 768, 1024, 1440, and 1920px, the compact header/sidebar, visible navigation labels, hidden-scrollbar scrolling, hamburger collapse, mobile drawer focus/Escape behavior, the account menu, reports, CSV responses, and activity filters. Earlier browser checks covered consent/publication/withdrawal, count reporting/verification/corrections, authentication, and landing-page behavior. No uncaught browser runtime errors were observed in those runs.

Browser checks do not verify production deployment, actual external email receipt, production browser Echo configuration, or unfinished workflows. Phase 6 separately verifies local Reverb private subscription and committed-event delivery with a native WebSocket client. Existing setup/Phase 1 documents are historical records; their earlier database/font availability statements are superseded by this implementation snapshot.

## Next implementation milestone

Continue the final roadmap review with integrated admin oversight and end-to-end acceptance checks across the implemented domain workflows. The seven scoped admin stages already exist. Academic-term administration is now implemented. The geographic review and compatible search fixes are complete; the isolated directory/importer is now implemented, while official snapshot acquisition, reviewed extraction/crosswalks, and later source/UI integration remain pending because current community responses have confirmed coverage/ancestry gaps. Define dispute, flagged-application, and suspension rules before adding those moderation actions. Award extensions/reopening, school-publication consent, production mail/queue/scheduler configuration, and production browser Reverb delivery remain separate unfinished scope. Completing Phase 8 does not certify a production deployment or complete every feature from the broader brief.
