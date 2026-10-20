# IskoReach UI Stage 1 — shared UI and authentication

Scope: the first stage of the complete UI/UX brief. Public scholarship pages and later stages are not implemented here. Existing accounts, MySQL data, migrations and .env are preserved.

## Inventory and plan before implementation

| Area found | Initial state | Stage 1 action |
| --- | --- | --- |
| Stitch source | ZIP, extracted code.html, screen.png and DESIGN.md available; landing already converted | Preserve landing source, content and sample fixtures |
| Design foundation | Tailwind civic tokens, bundled Plus Jakarta Sans, typed UI controls, Headless UI overlays, Lucide installed | Reuse without adding dependencies |
| PublicLayout / Brand | Earlier teal/system-font shell, separate from landing | Match navy mark, civic tokens, font and usable navigation |
| Auth page | One typed Inertia component for login, registration, forgot/reset and confirmation; working Fortify endpoints | Improve existing component rather than replace auth |
| Auth layout | Earlier split/system-font styling | Match Stitch typography/surfaces with a restrained responsive layout |
| Password fields | No visibility controls | Reusable labelled show/hide control |
| Reset request | Standard broker messages could disclose registered emails | Safe confirmation for sent/unknown/already-requested states; retain real validation and unexpected errors |
| Provider status | Pending/rejected notice inside provider dashboard | Dedicated owner-scoped status route and page |
| Student/provider/admin account pages | Functional Phase 1 forms/review and basic workspace | Keep existing behavior; broader dashboards belong to later UI stages |
| Directory/details/passers/applications/vault/chat/renewals/releases/reports | No production domain pages or backend tables for these workflows | Defer; no fake production navigation or statistics |
| Email verification | Disabled in Fortify; User does not implement MustVerifyEmail | No nonfunctional verification screen |

Plan followed: reuse tokens/controls and existing Fortify routes; improve auth/public shell; implement visibility, validation and confirmation states; route pending/rejected providers to a dedicated page; test using isolated fixtures; stop before UI Stage 2.

## Pages created or improved

- Login: visible labels, password visibility, remember me, forgot link, disabled/loading submit, actual authentication errors, inline offline/unexpected-response feedback, registration link.
- Student registration: name, email, password/confirmation, immediate-access explanation, duplicate-email/backend validation, clear create-account action.
- Provider registration: contact person's name, organization name, contact email, passwords, administrator-approval explanation. /register?account=provider remains supported; administrator registration is never offered.
- Forgot password: validated email request, safe confirmation screen, retry/change-email action and login link. Same user-facing message for a sent link, absent account and broker cooldown; this does not claim a second email was sent during cooldown.
- Password reset: email, new password/confirmation, visibility controls, real token/validation feedback, request-new-link action, native Fortify success redirect and status message on login.
- Password confirmation: existing authenticated Fortify screen, polished with the same fields/layout and return-to-account action.
- Pending/rejected provider: dedicated /provider/status, account/organization details, actual review reason, correction form, real server status refresh and logout. Approved accounts redirect to their dashboard; pending/rejected accounts remain blocked from workspace features.
- Shared public shell: existing landing section/auth routes only; focus-managed mobile drawer; navy mark and locally bundled font. The landing retains its independent, unchanged composition.

## Files changed

| File | Change |
| --- | --- |
| resources/js/components/Brand.tsx | Navy Lucide graduation-cap mark, Plus Jakarta Sans, same brand identity as landing |
| resources/js/components/ui/PasswordField.tsx | New composable labelled password input with show/hide, aria-controls and pressed state |
| resources/js/layouts/PublicLayout.tsx | Civic typography/colors, real links, mobile Headless UI drawer, font preload, skip link/footer |
| resources/js/layouts/AuthLayout.tsx | Responsive shared auth layout; restrained role information; one form surface |
| resources/js/pages/Auth.tsx | Reuse Fortify forms with visibility, success/confirmation/error/busy states and field focus |
| resources/js/pages/provider/Status.tsx | New pending/rejected account screen and supported organization correction form |
| app/Http/Responses/PasswordResetLinkResponse.php | Fortify response contract customization for safe reset confirmations |
| app/Providers/FortifyServiceProvider.php | Bind successful/failed reset-link responses to that customization; existing auth actions/views retained |
| app/Http/Controllers/DashboardController.php | Route unapproved providers to their status screen |
| app/Http/Controllers/Provider/AccountController.php | Owner-policy-protected status action; unapproved dashboard redirect; approved status redirect |
| routes/web.php | Add GET /provider/status under existing auth/provider middleware |
| tests/Feature/AuthUiStageOneTest.php | Auth page, reset, duplicate registration, token and provider status regression coverage |
| tests/Feature/PhaseOneTest.php | Update expected pending-provider destination for the new dedicated screen |
| scripts/verify-browser.ts | Extend existing browser setup for auth/state/viewport checks; select the intended provider review by fixture email |
| README.md / this document | Stage 1 review/startup guide and limitations |

No package, environment, migration, document-storage or domain schema changes. Brand is reused by existing account layouts, but those dashboards were not redesigned in this stage.

## Existing backend reused

Fortify still owns POST /login, /register, /logout, /forgot-password, /reset-password and password confirmation. Existing CreateNewUser, reset/password validation, session/CSRF middleware, login throttling, role middleware, provider approval middleware and profile policies remain authoritative.

Organization corrections use the existing PATCH /provider/profiles/{providerProfile} request/controller. Writable fields remain organization_name/contact_email only. Changed details return to pending and create an audit entry through existing logic. No client-side approval or role escalation path was added.

Reset confirmation customizes Fortify's response contracts, not its password broker/token generation/delivery. Unexpected broker errors still use Fortify's error response. Malformed emails still fail validation. UI does not announce success on a failed HTTP request. Request-level errors can be retried without replacing the email/name fields; password values are cleared after a completed attempt.

The status page fetches the current server state on Check status. It does not display a fake realtime connection or invent an approval deadline.

## Unsupported fields and remaining backend work

Provider organization type, contact number and structured location are not collected by the current schema; adding them requires migrations, validation and a defined verification process. Terms acceptance needs an approved terms document and persisted acceptance/version fields before adding a required checkbox. Email verification remains disabled; enabling it requires the model contract, configured mail, callbacks, route protection and tests.

The configured local mailer remains unchanged. If it is log, reset notifications are recorded in storage/logs/laravel.log rather than delivered to a mailbox. SMTP delivery was not verified. Keep reset-link logs private.

UI Stages 2–6 still need their domain backends and screens: scholarship publication/discovery/passers authorization; application eligibility/conflict rules/history; private upload/vault requirements; notifications; authorized chat/Reverb delivery; awards/renewals; benefit-release records; broader admin monitoring/reports. No shells for those future stages are advertised as live here.

## Verification

- npm run typecheck: passed with strict TypeScript.
- npm run build: passed with auth/status pages, existing pages, Tailwind and the bundled font.
- php artisan test: 32 tests, 364 assertions passed.
- php vendor/bin/pint --dirty --format agent: applied formatting; final check passed.
- php artisan route:list --except-vendor: existing route groups and new provider.status route registered.
- Browser setup: existing Chrome script, disposable SQLite database and isolated fixture accounts; no browser writes to real MySQL accounts.
- Auth screens checked at 375, 768 and 1440px: labels, font loading, 16px input text and horizontal overflow. Existing landing checks still cover 320–1920px.
- Browser interactions: password visibility, mobile public drawer/Escape/focus return, offline feedback, backend validation, duplicate email with retained name, safe reset confirmation, invalid reset token, student/provider registration, role logins, pending/rejected states, correction to pending, administrator approval and approved workspace access.
- Visual review: desktop login, mobile provider registration and mobile pending status screenshots. Screenshots saved under ignored storage/app/private/ui-stage1-preview.
- One browser attempt timed out because the previous smoke test assumed the reviewed provider was the first list row. With multiple fixtures the ordering changed. The selector was corrected to match the intended fixture email; no application authorization/result was relaxed.

Only the checks that actually ran are asserted here. Comprehensive screen-reader/accessibility certification, real SMTP delivery and production WebSocket behavior remain outside this verification. Source hash checks confirm the landing page, landing sections and sample data files are unchanged.

## Run and review

From the project root, use separate terminals:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
npm run dev -- --host 127.0.0.1
```

Alternatively run npm run build and use Laravel with the compiled assets. Open:

- /login
- /register (student default)
- /register?account=provider
- /forgot-password
- a real reset link for /reset-password/{token}; invalid links display actual errors when submitted
- /provider/status after signing in as a pending/rejected provider

Use the existing locally created role accounts; no new permanent accounts/passwords were introduced during this stage. Public /provider/status requests require authentication, students/admins are denied, and approved providers redirect to /provider/dashboard. The existing admin account can review provider access at /admin/dashboard.

Normal browser smoke check: node scripts/verify-browser.ts. QA_AUTH_CHECK=true with QA_AUTH_STAGE1_CHECK=true is only for a fresh disposable fixture database and performs registration/review writes there. Do not point that mode at real data. Set QA_SCREENSHOT_DIR to a local ignored directory if screenshots are wanted.

Stage 2 has not started. Continue only after review/confirmation.

Implementation references: official [Laravel Fortify](https://laravel.com/docs/13.x/fortify), [Inertia v2 forms](https://inertiajs.com/docs/v2/the-basics/forms), installed package types/source, and the supplied local Stitch assets.
