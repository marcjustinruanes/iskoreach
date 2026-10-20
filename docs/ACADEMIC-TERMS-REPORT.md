# IskoReach academic-term administration report

Report date: 20 October 2026 (Asia/Manila, Philippines).

## Completed milestone

Completed the scoped academic-term administration, validation, workflow integration, and verification work. Administrators can open **Academic terms** under Scholarship Management at `/admin/academic-terms`. The page follows the existing navy/teal admin layout and shared components.

Reused the existing `AcademicTerm` model and `academic_terms` table. No model/table duplication, migration, new dependency, `.env` change, production seed, or production data write was needed.

## Delivered behavior

- Search academic year or semester, filter by referenced/unused terms, sort by academic-year label or recent creation, and paginate 12 records per page.
- Create a term or edit an unused term through a labelled dialog with confirmation, server validation, input preservation, saving/success feedback, and offline/request feedback.
- Display optional start/end dates and separate reference counts for scholarships, applications, awards, renewals, and benefit records. Counts include retained scholarships/applications and describe records, not distinct students.
- Show referenced terms as read-only, with a wrapping label/date summary and usage details. Recheck usage on the server when saving so an already-open edit form cannot bypass newly added references.
- Require academic year and semester, preserving their existing 20/40-character limits. Trim surrounding whitespace and enforce the existing composite unique pair, including translating database uniqueness conflicts into validation errors.
- Preserve nullable dates. When both dates are supplied, the end must follow the start. Equal dates, reversed dates, and invalid calendar dates are rejected. Overlapping different terms remain allowed.
- Reject stale edits using a signed fingerprint of the saved term state. Unchanged updates preserve the record and create no duplicate audit event.
- Save term changes and their before/after audit records in the same transaction. Existing activity logs expose readable term-created/updated action and record labels without raw metadata.
- Expose no delete endpoint. Existing restrictive foreign keys remain intact. There is no new term status, globally active term, automatic selection, cancellation, or historical reassignment.

The edit restriction applies to current stored references, including retained parents. No permanent “ever used” flag was introduced. A missing date remains supported, but an end date is required for existing renewal selection. The form explains these limitations before administrators save.

## Workflow connections and history safety

Scholarship, award, public-directory, monitoring, and benefit selectors continue using the same term IDs and existing rules. New terms become available through those existing queries; public directory choices remain limited to terms attached to publicly visible scholarships. Existing award reports continue grouping by academic year and semester.

Application details now show the application's saved academic term for both student and provider views, or “Not recorded” for a null value. Draft creation/submission retains the existing scholarship-term copying behavior. No application, scholarship, award, renewal, or release was silently moved to another term.

Admin edits lock the term row before checking all five reference tables. Scholarship save, explicit award creation, and renewal creation now coordinate first references with that same row lock. Applications inherit an already-referenced scholarship term; benefit records require an already-referenced award or approved-renewal term. Existing parent lock order and authorization are preserved.

Renewal creation now compares the term end date with the current **Asia/Manila calendar date**, matching the selector. A regression test verifies eligibility immediately before Philippine midnight and rejection immediately after it, even while the UTC date has not changed. Term dates do not automatically extend awards or change reminder schedules.

## Routes

| Method | Route | Behavior |
| --- | --- | --- |
| GET | `/admin/academic-terms` | Authorized admin list/search/filter/sort |
| POST | `/admin/academic-terms` | Confirmed creation |
| PATCH | `/admin/academic-terms/{academicTerm}` | Confirmed, version-checked unused-term update |

All routes use authentication and admin-role middleware; requests and the management service also enforce the term policy. Student/provider requests are denied. Deletion is unavailable.

## Files

New:

- `app/Http/Controllers/Admin/AcademicTermController.php`
- `app/Http/Requests/Admin/ListAcademicTermsRequest.php`
- `app/Http/Requests/Admin/SaveAcademicTermRequest.php`
- `app/Policies/AcademicTermPolicy.php`
- `app/Services/AcademicTermManagement.php`
- `resources/js/pages/admin/AcademicTerms.tsx`
- `tests/Feature/AcademicTermAdministrationTest.php`

Updated integrations:

- `routes/web.php`, `app/Providers/AppServiceProvider.php`, and `app/Http/Middleware/HandleInertiaRequests.php` for authorization, routes, and navigation.
- `app/Models/AcademicTerm.php` and `app/Models/Application.php` for existing-table relationships.
- `app/Services/ScholarshipManagement.php`, `AwardBenefits.php`, and `RenewalWorkflow.php` for term-lock coordination and renewal calendar handling.
- `app/Services/ApplicationWorkflow.php` and `AdminAuditHistory.php` for saved term display and audit support.
- `resources/js/layouts/AdminLayout.tsx`, `resources/js/types.ts`, `resources/js/applicationTypes.ts`, and `resources/js/components/ApplicationRecordDetails.tsx`.
- `resources/js/components/ui/Overlays.tsx` adds an optional panel class used to bound the term dialog height; other dialogs keep their existing defaults.
- `scripts/verify-browser.ts`, `docs/PROGRESS-REPORT.md`, `docs/PUBLIC-WEBSITE-REPORT.md`, and this report.

## Verification actually performed

| Check | Result |
| --- | --- |
| Academic-term feature tests | **11 passed, 288 assertions** |
| Full backend suite | **234 passed, 4,428 assertions** |
| `php vendor/bin/pint --dirty --format agent` | Passed |
| `npm run typecheck` | Passed |
| `npm run build` | Passed |
| `php artisan route:list --path=academic-terms --except-vendor` | Three intended routes present |
| Full disposable Chrome term workflow | Passed |
| Final read-only/mobile display and workflow regression | Passed |

Backend tests cover admin/guest/student/provider access, true empty data, creation/editing, injected fields, missing/oversized labels, invalid/reversed/equal dates, duplicate pairs, allowed overlap/undated terms, stale/unchanged edits, all five reference types, retained references, reference changes after opening a form, filtering/sorting/pagination, connected selectors/application data, audit rollback, and Philippine renewal date boundaries. The full suite also exercised existing scholarship/discovery, application, award, renewal, monitoring, and report regressions.

Chrome checked empty/populated admin pages, active navigation, real creation and server date validation, duplicate feedback, edit/offline input recovery, stale-edit rejection/reload, read-only usage, search/no-results/reset, readable audit actions, provider/student restrictions, the existing provider term selector, and saved student application term. Pages/dialogs fit 320, 768, and 1440px without uncaught runtime errors. Desktop list and phone dialogs were visually reviewed. A height limit and wrapped read-only summaries were refined after that review. Local artifacts are under `storage/app/private/academic-terms-preview` and `storage/app/private/academic-terms-final-preview`.

An initial test expected a validation response for an unauthorized draft award-entry GET; its expectation was corrected to the existing 403 behavior. Initial browser attempts also required fixes to script escaping, dialog timing, accessible button selection, and the provider selector's existing ID. Those harness issues were corrected before the final passing runs. The final read-only display was checked separately after the full browser workflow passed.

## Database and remaining limits

Backend tests used isolated in-memory SQLite. Browser records used fresh guarded SQLite databases and separate file roots, with array mail/null broadcasting. No real messages or production queue jobs were processed. Temporary fixture servers were stopped.

A final read-only configured **MySQL** check confirmed zero academic terms, scholarships, applications, awards, renewals, payments, notifications, and queued jobs. The existing 17 migration records remain unchanged. Administrators must create actual institutional terms through the new page; no example terms were inserted into MySQL.

SQLite tests and serial browser checks verify the application safeguards, not simultaneous MySQL load behavior. Row-lock coordination was implemented, but this milestone did not perform a concurrent production-database test or production deployment. There is no referenced-term correction/merge workflow, deletion, global active-term setting, or new status system. Geographic-source coverage and the previously documented production-runtime work remain separate platform scope.
