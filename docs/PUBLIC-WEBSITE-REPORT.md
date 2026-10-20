# IskoReach public website completion report

Report date: 20 October 2026 (Asia/Manila, Philippines).

## Outcome and scope

Completed the public-website phases requested after inspection: landing page, scholarship directory, program details and login continuation, and public passers. The later instruction to finish all phases superseded the original request for approval between phases. This completes the scoped public-site work, not every feature in the whole-platform roadmap or a production deployment.

The existing Stitch typography, navy/teal palette, section layout, cards, responsive navigation, and reduced-motion support remain. No authenticated dashboard or sidebar was changed in this public-site implementation.

## Delivered behavior

### Landing page — `/`

- Uses a public controller and the existing `ScholarshipCatalog` visibility rules. Shows the three most recently published eligible programs, including accurate open/closed/upcoming availability, provider names, benefits, locations, opening dates, deadlines, and detail links.
- Search sends program/provider keywords and any chosen location to the real directory. It preserves inputs and shows offline/request feedback.
- Hero statistics now count publicly visible programs, currently open programs, and eligible public passer listings. Counts can legitimately be zero. Published-program totals include previously published closed programs, matching the directory.
- Removed illustrative grants, fabricated financial/activity figures, document-scanning claims, mock completion percentages, and stale claims that existing tools are only planned. The original sample data file remains unused as a historical design asset.
- Replaced fictitious workspace records with practical application guidance while preserving the visual direction. Added a public-passer introduction/link and updated primary actions, approval guidance, and footer links.
- Empty data shows an explicit empty state; no sample records are substituted.

### Directory — `/scholarships`

- Reused backend filtering and 12-record pagination: keywords, any location, type, open/upcoming/closed availability, acceptance of existing scholars, academic term, currency, benefit range, and inclusive Philippine opening/deadline date ranges.
- Listing cards now show opening dates alongside deadlines. Applied filters reopen their controls, and reset has request/offline feedback.
- Copy explains that program coverage does not automatically establish personal eligibility; other provider criteria remain available on the detail page.
- Existing visibility remains unchanged: published or previously published closed programs from approved provider accounts, excluding drafts, archived/retained records, and unapproved providers. There is no new admin scholarship-moderation workflow.

### Details and application entry

- `/scholarships/{slug}` retains the program description, benefit/term, coverage, dates, existing-scholar rule, recorded eligibility, document requirements, and applicant-count breakdown.
- Guidance distinguishes vault upload, attachment, draft preparation, confirmed submission, and later renewal requirements. It does not invent a new eligibility engine or application workflow.
- Guest application links use new GET `/scholarships/{slug}/apply`. Authentication saves this local destination. After student login, the endpoint rechecks public visibility and returns to the selected detail page, where the student explicitly prepares an application.
- GET continuation creates no draft or submission. Existing role middleware remains enforced. A program that becomes hidden while someone logs in is unavailable on return.
- Count explanations identify external figures as provider-reported aggregate snapshots verified by an administrator. They explain the inability to automatically deduplicate individuals and exclusion until re-verification when internal membership changes. Existing verified source dates remain displayed; private report metadata is not exposed.

### Public passers — `/passers`

- Retained existing backend search, Philippine publication-year filtering, pagination, and consent/publication safeguards.
- Added inline filter validation, offline/request feedback, guarded reset, and distinct initial-empty versus no-match messages.
- Public data remains limited to approved public name, scholarship, provider, and publication date. Confirmed active awards, student consent, publication approval, eligible current parents, and provider approval remain required. Ordinary accepted applications do not become passers.
- Public desktop/mobile navigation identifies the current destination. Following the consistency refinement below, public browsing uses the shared public layout for guests and all account roles; My account returns users to their existing workspace.

## Files changed

Backend:

- `app/Http/Controllers/Public/HomeController.php` — new public homepage controller.
- `app/Http/Controllers/Public/ScholarshipController.php` — authorized, read-only login continuation.
- `routes/web.php` — homepage controller and named application-entry route.

Frontend:

- `resources/js/pages/Welcome.tsx`
- `resources/js/components/LandingSections.tsx`
- `resources/js/layouts/PublicLayout.tsx`
- `resources/js/pages/public/Scholarships.tsx`
- `resources/js/pages/public/ScholarshipDetails.tsx`
- `resources/js/pages/public/Passers.tsx`

Verification and reporting:

- `tests/Feature/PublicWebsiteTest.php` — four new regression tests.
- `tests/Feature/ExampleTest.php` — initializes the isolated test database now that the homepage uses real queries.
- `scripts/verify-browser.ts` — replaces obsolete sample interactions with real public-site checks and adds populated/empty public-site modes.
- `docs/PROGRESS-REPORT.md` and this report.

## Verification actually performed

| Check | Result |
| --- | --- |
| New public website tests plus discovery, publication, and login regression tests | 23 passed, 459 assertions |
| Final full backend suite | 223 passed, 4,140 assertions |
| `php vendor/bin/pint --dirty --format agent` | Passed |
| `npm run typecheck` | Passed |
| `npm run build` | Passed |
| Scholarship GET route inspection | Existing public routes and new named continuation route present |
| Populated disposable Chrome run | Passed |
| Empty disposable Chrome run | Passed |

The first full-suite attempt found the old homepage smoke test lacked migrations. Adding `RefreshDatabase` corrected that test setup; the final suite passed. An initial browser run caught the old hero statistics block remaining after a text replacement; the block was corrected to real counts before the final passing run.

Chrome verified real landing-to-directory search, keyword/location preservation, nationwide matching, availability/no-results/reset, program details, selected-program login, student application entry controls, passer privacy/search/reset/offline recovery, and navigation from student/provider/admin workspaces to the public home and back. No uncaught runtime errors occurred. Font loading, mobile navigation, and reduced motion passed. Landing checks covered 320–1920px; populated directory/details/passers covered 320, 768, 1024, and 1440px; empty public pages covered 320, 768, and 1440px. Desktop hero and phone directory/passers screenshots were visually inspected.

Screenshots are local QA artifacts under `storage/app/private/public-site-preview` and `storage/app/private/public-site-empty-preview`. Fixtures use guarded disposable SQLite databases, separate file roots, and array mail/null broadcasting. Both temporary servers were stopped after checks.

## Data protection and remaining limits

No migration, dependency, credential, `.env` change, production seed, real mail delivery, or production queue processing was required. A final read-only configured MySQL check confirmed zero scholarships (including retained), applications (including retained), awards, academic terms, notifications, and queued jobs. The real public site therefore correctly has empty listings and zero public counts until providers publish actual records.

Existing limitations remain: community geographic dataset coverage/currentness; no academic-term administration; no separate structured application-instructions field; no automatic GWA/income/geographic eligibility engine; aggregate external counts cannot deduplicate individuals; school publication is outside current consent. General eligibility remains provider-recorded information for review. No new schema or business rules were introduced for these gaps.

Production hosting, external mail receipt, deployed queue/scheduler operation, and production browser Reverb delivery were not certified by this work. The next platform milestone can address academic-term administration and geographic-source review separately. No requested public-site phase remains pending.

## Public-page consistency refinement

The home page previously used its own header/footer while directory/details/passers used a smaller public layout. Student sessions also switched scholarship pages into a workspace layout. Public pages now share one `PublicLayout`, including the same six navigation labels/destinations, sticky header, brand, active states, mobile drawer, account action, content width, and full landing footer. Directory/details/passers reuse a shared page heading and vertical spacing. Students browsing public scholarship URLs now see the same public header/footer as other visitors; My account returns to the student workspace. Authenticated dashboards and sidebars themselves are unchanged.

Landing footer section links now point to home-page anchors from every route. How It Works, For Providers, and About Us remain sections on the home page. Header active states follow those section links and nested scholarship detail routes. The landing page reuses the shared shell instead of maintaining a duplicate navigation implementation.

Updated files: `PublicLayout.tsx`, `ScholarshipBrowseLayout.tsx`, `Welcome.tsx`, `LandingSections.tsx`, public `Passers.tsx`, `scripts/verify-browser.ts`, and this report. TypeScript and production build passed. Chrome passed public navigation consistency for guests and student/provider/admin accounts, selected-program login, desktop/mobile layouts, footer links, and section navigation without uncaught runtime errors. Desktop directory and phone passer screenshots were visually inspected. Artifacts: `storage/app/private/public-consistency-preview`. Checks used the existing disposable SQLite fixture; its temporary server was stopped. No backend/domain changes or new backend tests were required; the full-suite result above is from the preceding public-site implementation, not a rerun for this presentation refinement.

## Later academic-term milestone

Academic-term administration, listed above as a gap at public-site completion, is now implemented as a separate milestone. Admins can create terms and edit unused terms; referenced terms are protected. See [ACADEMIC-TERMS-REPORT.md](ACADEMIC-TERMS-REPORT.md) for current scope and verification. The later full backend suite passed 234 tests and 4,428 assertions. Geographic-source review and production-runtime certification remain separate work.
