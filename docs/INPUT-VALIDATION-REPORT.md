# IskoReach input validation audit and implementation report

Report date: 21 October 2026 (Asia/Manila, Philippines).

## Outcome

Completed the three approved validation groups after the read-only audit: account credentials, provider/student profiles, and scholarship text limits with field feedback. Laravel remains authoritative, including for direct HTTP/JSON requests. Existing accounts and submitted profile snapshots were not rewritten.

The application has one full-name field, not separate first/middle/last names or a middle initial. It has no mobile number, birth date, residential address, postal code, or student-number field to validate. No such fields, routes, or verification workflows were invented. Admin account pages remain read-only; the protected local admin-provisioning command reuses the new rules.

## Audit findings and decisions

The audit inspected existing requests, Fortify actions, profiles, frontend forms, schema metadata, policies, and tests. Account/profile text columns allow 255 characters. The configured MySQL email column has a case-insensitive unique index; isolated SQLite uniqueness previously behaved differently. A read-only aggregate audit found no case/trim collision groups. No account email was bulk-normalized.

Registration accepted numeric/punctuation-only names; direct action calls bypassed HTTP trimming. RFC email validation alone allowed internal whitespace and local-only domains. New-password validation had no bcrypt byte boundary or null-character guard. Scholarship descriptions allowed 20,000 characters even though MySQL TEXT has a 65,535-byte capacity. Existing authorization, document validation, geographic ancestry, amount/date ranges, and academic-term rules already supplied relevant safeguards and were retained.

## Group 1: Accounts and new passwords

- Added shared `app/Support/InputRules.php`. Selected name/email/organization fields are explicitly trimmed; passwords and confirmations are excluded from normalization. Registration calls normalization in the Fortify action as well as the request because action validation does not execute FormRequest preparation.
- Names require at least one Unicode letter and permit combining marks, spaces, apostrophes, periods, commas, ordinary/typographic hyphens, and joiners used by non-Latin scripts. Digits, unsupported symbols, embedded line/control characters, punctuation-only input, and values over 255 characters are rejected. Capitalization and internal name formatting are preserved.
- New/contact emails use Laravel email validation plus rejection of internal whitespace/control characters and a complete dotted domain. There is no DNS lookup or delivery/ownership claim. Selected email writes use the existing lowercase account convention. Account uniqueness is explicitly case-insensitive with bound parameters and a current-user exclusion where applicable; the database unique index remains in place. Provider contact emails remain nonunique.
- Registration/reset new passwords retain the eight-character minimum; local admin provisioning retains its twelve-character minimum. All use confirmation, a 72-byte maximum, and null-character rejection. UTF-8 bytes are counted separately from characters. Spaces and symbols remain intact, fields remain masked, and errors do not echo password values.
- Login and current-password confirmation do not receive the new-password restrictions. An existing longer password still authenticates in regression coverage. Fortify verification configuration, disabled settings routes, roles, and registration approval behavior remain intact. Existing recovery email matching remains Fortify's behavior so legacy credentials are not blocked by a new registration policy.

Updated backend files: `RegisterUserRequest.php`, `CreateNewUser.php`, `PasswordValidationRules.php`, `UpdateUserProfileInformation.php`, and `CreateAdmin.php`. Frontend changes: `Auth.tsx` and shared `components/ui/inputValidation.ts`.

## Group 2: Profiles and contacts

Provider organization names and student school/course fields retain their 255-character limits and permit ordinary organizational/academic punctuation and digits. They receive selected trimming and single-line/control-character validation, rather than a personal-name character whitelist. School/course remain nullable; year level remains an optional integer from 1 through 12.

Provider email normalization occurs before validation. Inside the existing locked update transaction, a case/outer-whitespace equivalent contact email retains its stored value and approval. The frontend skips approval-reset confirmation for that cosmetic change. A meaningful organization/contact edit still returns the provider to pending review, increments its verification version, and records the existing audit event. Shared contact addresses are allowed. Ownership policies, role restrictions, and submitted snapshots remain unchanged.

Updated files: provider/student update requests, provider `AccountController.php`, `OrganizationProfileForm.tsx`, and student `Profile.tsx`. Client feedback covers basic required/length/contact/year problems; it does not replace backend authorization or validation.

## Group 3: Scholarship forms

`SaveScholarshipRequest.php` now enforces both the existing 20,000-character description limit and a 65,535-byte cap before storage. A direct-request regression verifies rejection at 65,536 bytes and successful persistence at 65,535 bytes. There is no truncation or schema expansion.

`ScholarshipForm.tsx` provides a description byte check and associated field error. Nested requirement names/instructions have their existing backend maximums exposed as 255/2,000-character input limits, with adjacent field errors; file-size errors also appear beside their input. Existing summary errors remain available. Publication/content locks, geographic parent validation, monetary limits, requirement MIME/size rules, and private upload integrity checks are preserved.

## Verification actually performed

- New `tests/Feature/InputValidationTest.php`: **13 tests, 76 assertions passed** using isolated in-memory SQLite. Coverage includes Unicode/Filipino and non-Latin names, maximums, direct action normalization, case variants, malformed email, password bytes/nulls/confirmation/spaces, reset hash preservation, existing-password login, approval-safe cosmetic changes, nonunique provider contacts, profile controls/year range, direct JSON rejection without password disclosure, and description byte boundaries.
- `php vendor/bin/pint --dirty --format agent`: passed.
- `npm run typecheck`: passed.
- `npm run build`: passed; Vite helper processes required the existing approved execution outside the sandbox.
- Final `php artisan test --compact`: **280 tests passed, 4,798 assertions**.
- `node scripts/verify-browser.ts` with `QA_INPUT_VALIDATION_CHECK=true`: passed. Chrome checked required-field focus, backend name errors, malformed/case-duplicate email, password byte and confirmation feedback, successful Unicode registration, spaced-password login, student year/trim save, provider cosmetic approval preservation, nested requirement errors/limits, and successful draft creation. Baseline landing/auth navigation also passed. No uncaught runtime errors occurred.

Scholarship form layouts passed at 320, 768, and 1440px. Registration error feedback and the phone scholarship form were visually inspected. Artifacts are under `storage/app/private/input-validation-preview`. Browser records used a new guarded disposable SQLite database, array mail, and null broadcasting. Its temporary server was stopped. The new browser mode is part of the existing verification script.

## Data safety and remaining limits

No migration, dependency, `.env`, credential, production account/domain record, real mail delivery, or production queue processing changed. Tests did not certify writable MySQL behavior or every collation-specific Unicode email comparison; the prior schema inspection establishes the configured unique-index behavior, while regressions execute in SQLite. The existing database index remains the final concurrent-write safeguard.

These changes establish format and workflow validation, not identity, email ownership, document authenticity, or automatic scholarship eligibility. The official geographic dataset acquisition/source-switch blocker and production mail/queue/scheduler/Reverb verification remain separate work. All three approved validation implementation groups are delivered.
