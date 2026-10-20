# Phase 1: authentication, roles, policies, and layouts

Implemented on 19 October 2026. Phase 2 has not started. The [architecture proposal](ARCHITECTURE-PROPOSAL.md) contains the full future database/folder design; its later tables are not implemented.

## What works

Students register and access their account immediately, without admin approval. Providers register with an organization name, start pending, and cannot enter the approved workspace until an administrator approves them. Guests can access the public/auth pages. Each user has one role: student, provider or admin; guest is an unauthenticated experience.

The administrator has a minimal paginated approve/reject screen. Rejection needs a reason, and incomplete organization details cannot be approved. Decisions record actor, timestamp and old/new status. Changing a provider's organization name/contact email resets approval to pending and records an audit entry. An unchanged save does not revoke approval.

Public registration accepts only student/provider account_type values. Submitted role/approval fields cannot grant privileges. Middleware separates role areas; policies independently enforce ownership/review/workspace permissions. Form Requests restrict writable data. Fortify owns registration and reuses RegisterUserRequest rules in its atomic user/profile creation action.

Login/logout, reset and password confirmation remain Fortify-backed. There is no separate API/backend, scholarship CRUD, application workflow, chat, payment processing, or reporting. The approved workspace explicitly states that scholarship tools come in Phase 2. No default administrator/password was created.

## Actual migration

UserRole adds Admin to the existing string role column. The new incremental migration creates:

| Table | Columns and constraints |
| --- | --- |
| student_profiles | BIGINT id PK; unique user_id FK users with restricted deletion; nullable school/course VARCHAR(255); nullable unsigned tinyint year_level; timestamps |
| provider_profiles | BIGINT id PK; unique user_id FK users; nullable organization_name/contact_email VARCHAR(255) for legacy accounts; indexed verification_status VARCHAR default pending; nullable reviewed_by FK users, reviewed_at TIMESTAMP, rejection_reason TEXT; timestamps; restricted FK deletion |
| activity_logs | BIGINT id PK; nullable actor_id FK users with restricted deletion; action VARCHAR; nullable polymorphic subject_type/subject_id indexed pair; nullable changes JSON; occurred_at TIMESTAMP; nullable created_at; index(actor_id, occurred_at) |

The forward migration backfills profiles for existing student/provider accounts without changing their roles or account details. Existing providers receive pending status and must complete missing organization details before approval. Sensitive student profile fields are deferred until actual workflows need them. No database rollback/reset was executed against application data.

## File-by-file implementation guide

All source code is in the workspace files below. Paths are relative to the project root.

| File | Purpose |
| --- | --- |
| app/Enums/UserRole.php | Student, Provider, Admin |
| app/Enums/ProviderVerificationStatus.php | Pending, Approved, Rejected |
| app/Models/User.php | Profile/audit relationships, role cast, restricted assignment |
| app/Models/StudentProfile.php | School/course/year, user relationship |
| app/Models/ProviderProfile.php | Organization fields, status cast, reviewer relationship, approval check |
| app/Models/ActivityLog.php | Actor/polymorphic subject relationships and audit casts |
| database/migrations/2026_10_19_104924_create_account_profiles_and_activity_logs.php | Incremental tables and account backfill |
| database/factories/StudentProfileFactory.php | Student profile fixtures |
| database/factories/ProviderProfileFactory.php | Pending provider fixtures |
| database/factories/UserFactory.php | Explicit default student role |
| database/seeders/DatabaseSeeder.php | Existing empty seeder, no shared credentials |
| app/Http/Requests/Auth/RegisterUserRequest.php | Account type and registration validation |
| app/Actions/Fortify/CreateNewUser.php | Atomic user/profile creation, ignores privilege inputs |
| app/Http/Requests/Student/UpdateStudentProfileRequest.php | Owner authorization and profile validation |
| app/Http/Requests/Provider/UpdateProviderProfileRequest.php | Owner authorization and organization/contact validation |
| app/Http/Requests/Admin/ReviewProviderRequest.php | Admin authorization, decision and rejection-reason validation |
| app/Http/Middleware/EnsureUserHasRole.php | Server role checks |
| app/Http/Middleware/EnsureProviderIsVerified.php | Approved-provider boundary |
| app/Policies/StudentProfilePolicy.php | Owner update; owner/admin view |
| app/Policies/ProviderProfilePolicy.php | Owner update, admin review, approved-owner workspace |
| app/Providers/AppServiceProvider.php | Explicit policy registration and role gates |
| bootstrap/app.php | Middleware aliases; existing web/CSRF/session behavior retained |
| app/Http/Controllers/DashboardController.php | Redirect to the authenticated role's page |
| app/Http/Controllers/Student/AccountController.php | Student Inertia page and profile save |
| app/Http/Controllers/Provider/AccountController.php | Provider status/details/workspace; locked identity-change transaction |
| app/Http/Controllers/Admin/ProviderReviewController.php | Paginated provider review, locked decision and audit |
| app/Console/Commands/CreateAdmin.php | Local interactive NEW administrator creation with hidden confirmed password |
| app/Http/Middleware/HandleInertiaRequests.php | Restricted user/provider-status props and session feedback |
| routes/web.php | Role middleware groups and route-bound profiles |
| resources/js/types.ts | Role/user/profile/review/pagination/shared Inertia interfaces |
| resources/js/components/Brand.tsx | Local SVG mark without an icon dependency |
| resources/js/components/ui/Form.tsx | Labelled inputs, associated errors, buttons, notices and sections |
| resources/js/layouts/PublicLayout.tsx | Guest header/footer and skip link |
| resources/js/layouts/AuthLayout.tsx | Responsive authentication shell |
| resources/js/layouts/AppLayout.tsx | Shared role-aware shell and working mobile menu |
| resources/js/pages/Welcome.tsx | Public account entry, no fake listings/counts |
| resources/js/pages/Auth.tsx | Login, student/provider registration, reset and confirmation |
| resources/js/pages/student/Dashboard.tsx | Private school/course/year editor |
| resources/js/pages/provider/Dashboard.tsx | Approval status and organization form |
| resources/js/pages/provider/Workspace.tsx | Approved-only starting page |
| resources/js/pages/admin/Dashboard.tsx | Real provider records, approval controls, pagination and empty state |
| resources/css/app.css | Explicit system sans stack replacing unloaded Instrument Sans |
| tests/Feature/PhaseOneTest.php | Phase 1 authorization, validation, approval and command tests |
| tests/Feature/FoundationTest.php | Existing auth/broadcast checks; account assertion now follows role redirect |
| tests/TestCase.php / phpunit.xml | Force memory SQLite; refuse unsafe cached test configuration |
| scripts/verify-browser.ts | Typography/navigation checks and opt-in isolated role/approval flow |
| composer.json / composer.lock / boost.json / AGENTS.md / CLAUDE.md | Boost 2.10.3 dev tooling and generated instructions, required by the previous AGENTS.md |

The existing FortifyServiceProvider still configures authentication views. Realtime/storage configuration remains intact. The unused original generic Dashboard.tsx was replaced by the role pages. No events/jobs/notifications are needed for this phase's synchronous account decisions; queued notifications belong to Phase 5.

## Typography

Uses an explicit system sans stack; no external/custom font loading is claimed. Body/inputs use 16px text with 24px input line height. Labels/navigation generally use 14px. Account/auth headings use 30px with 40px line height; section headings use 20px. The public heading is 36px on small screens and 48px on desktop. Auth forms are constrained to 28rem.

Inputs have visible associated labels/errors and focus rings. Buttons have a minimum 44px height. Account navigation includes aria-current, a skip link and an aria-expanded mobile menu. This is not a formal accessibility audit. No Stitch files were supplied, so this is not a claimed conversion of that design.

## Actual verification

- PHP tests: 23 passing, 199 assertions during Phase 1 verification.
- npm run typecheck, npm run build, composer validate --strict and composer check-platform-reqs: passed. Pint formatted changed PHP files.
- Chrome: public/login pages, provider registration, student mobile navigation, pending provider display, administrator approval and approved workspace navigation passed. No uncaught browser errors or Reverb connection occurred.
- Computed styles: 16px form inputs, 30px account/auth headings, 48px desktop public heading, system font stack. Registration fields had associated labels. No horizontal overflow at the tested 390px mobile width; desktop checks used 1440px.
- Visually reviewed screenshots: desktop login, mobile provider registration, desktop admin. Screenshots use test fixture identities and live under storage/app/private/phase1-preview (ignored by Git).
- MySQL read-only connection attempt returned driver error 1049: the configured iskoreach database does not exist. No MySQL migration was applied. MySQL-specific migration behavior is unverified.
- Browser authentication used a new temporary SQLite database with disposable fixtures and process-only environment overrides. PHP tests used separate in-memory SQLite. Your .env was not changed.

## Manual setup and checks

1. Follow README.md to create the MySQL database/user and configure .env. Run php artisan config:clear, then php artisan migrate. Preserve existing data; do not reset databases.
2. Run npm ci if dependencies are missing. Build with npm run build, or keep npm run dev running.
3. Start php artisan serve --host=127.0.0.1 --port=8000 and open http://127.0.0.1:8000.
4. Register a student. Confirm immediate /student/dashboard access, save school details, log out and back in. /admin/dashboard and /provider/dashboard should return 403.
5. Register a provider with an organization name. Confirm pending status and HTTP 403 at /provider/workspace. Arbitrary role=admin or verification_status=approved must not grant privileges.
6. Run php artisan app:create-admin locally. Enter a NEW email and confirmed password of at least 12 characters. The command hides passwords and refuses noninteractive use/existing emails. This guide does not create an admin automatically.
7. Sign in as admin at /admin/dashboard. Review the organization's identity through your actual process, then approve or reject. Rejection needs a reason visible to the provider; approval does not automatically verify legitimacy.
8. Sign in as the approved provider and enter the workspace. Change the organization name: approval must return to pending and workspace access must be denied until re-approval.
9. Run php artisan test and npm run typecheck. Clear cached configuration if the test isolation guard refuses to run.

The ordinary browser check is node scripts/verify-browser.ts with the app running. QA_AUTH_CHECK=true is ONLY for a fresh isolated fixture database containing the test-only student/provider/admin accounts used by this script. Its approval step changes the fixture provider's state; never point this mode at real data. QA_SCREENSHOT_DIR optionally saves local screenshots.

Phase 2 starts only after your confirmation. No deployment, production-readiness guarantee, real mail delivery or end-to-end WebSocket delivery is claimed.

References: [Laravel policies](https://laravel.com/docs/13.x/authorization), [Laravel validation](https://laravel.com/docs/13.x/validation), [Inertia v2 forms](https://inertiajs.com/docs/v2/the-basics/forms). Installed package versions and local source were checked before integration.
