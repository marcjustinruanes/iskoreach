# IskoReach architecture proposal

Status: the schema and folder map below remain architectural proposals. For the current implementation, completed admin stages, verification, and unfinished platform phases, see [PROGRESS-REPORT.md](PROGRESS-REPORT.md). PHASE-1.md records the historical Phase 1 implementation and verification.

## Confirmed account decisions

1. Students self-register with immediate access. Providers register pending and require administrator approval for workspace access.
2. One authenticated role per account. An interactive local Artisan command creates a NEW administrator; public registration cannot create administrators.

Public/guest is an unauthenticated experience, not a database role or shared account. Authenticated roles are student, provider, and admin.

## Implementation order

Phase 1 adds role routing, middleware, policies, minimal profiles, authentication/account layouts, protected admin provisioning, and a minimal provider approve/reject screen. Pending providers cannot enter the approved workspace. Full verification, monitoring and reporting tooling belongs to Phase 9. Navigation must not imply that later features already work.

Phases 2–9 follow the supplied sequence. Their tables are designed here but are not created as part of Phase 1. Keep the current Laravel monolith, Fortify, React/TypeScript, Inertia, Tailwind, MySQL, Reverb/Echo, Laravel Storage, Notifications, Mail, and queues. No separate REST backend or optional UI library is needed.

## Proposed folder structure

Conventional Laravel structure; create only files needed in the current phase.

```text
app/
  Actions/
    Fortify/                         existing authentication actions
    Applications/                   later: atomic submit/conflict checks
    Providers/                      later: audited verification transitions
  Console/Commands/                 protected first-admin provisioning
  Enums/                            UserRole, ProviderVerificationStatus
                                    later: ApplicationStatus, PaymentStatus,
                                    RenewalStatus, ScholarshipStatus, AwardStatus
  Events/                           later: private chat/notification events
  Http/
    Controllers/
      Auth/                         only custom actions Fortify does not supply
      Student/                      student pages and future applications/vault
      Provider/                     provider pages and future scholarship CRUD
      Admin/                        administrator pages and future verification
      Public/                       later: public scholarship and passers pages
    Middleware/                     HandleInertiaRequests, EnsureUserHasRole,
                                    EnsureProviderIsVerified
    Requests/
      Auth/                         registration validation if customized
      Student/                      later: profile/application/document requests
      Provider/                     later: scholarship/review/count requests
      Admin/                        later: verification requests
  Jobs/                             later: reminder and integration processing
  Models/                           Eloquent models and relationships
  Notifications/                    later: queued mail/database notifications
  Policies/                         UserPolicy, profile policies;
                                    later: scholarship/application/document policies
  Providers/                        Fortify/service/policy configuration
config/                             keep existing Laravel configuration
database/
  migrations/                       incremental changes, no reset commands
  factories/                        isolated development/test fixtures
  seeders/                          safe intentional data, no default passwords
resources/
  css/app.css                       Tailwind import and small theme tokens
  views/app.blade.php                Inertia root
  js/
    app.tsx                         React entry
    realtime.ts                     optional Echo initialization
    components/
      ui/                           native-button/input/label/error primitives
      navigation/                   public/account navigation
    layouts/
      PublicLayout.tsx
      AuthLayout.tsx
      AppLayout.tsx                  common accessible account shell
    pages/
      auth/                         login/register/reset/confirmation
      student/Dashboard.tsx
      provider/Dashboard.tsx
      admin/Dashboard.tsx
      public/                       welcome; later discovery/passers
    types/                          shared auth/page/domain interfaces
routes/
  web.php                           Inertia routes and auth/role groups
  channels.php                      private channel authorization
  console.php                       later schedules
tests/
  Feature/Auth/                     auth, role routing, escalation prevention
  Feature/Authorization/            policies, ownership, verified-provider gates
  Feature/                          future domain flows
  Unit/                             isolated rules when justified
scripts/verify-browser.ts            extend existing Chrome checks
docs/                               architecture, phase guide, manual test steps
```

Fortify remains responsible for login/session/password-reset mechanics. Reuse its actions/contracts rather than duplicating authentication controllers. Custom writable endpoints use Form Requests; equivalent registration rules must remain enforced in Fortify's creation action. Middleware enforces route roles, while policies authorize the actual user/resource action.

## Complete proposed database structure

Conventions: unless stated otherwise, `id` is an unsigned BIGINT primary key and tables have created_at/updated_at. Foreign keys use matching unsigned BIGINTs. Store money as DECIMAL(12,2), never floating point. Store event timestamps in UTC; use Asia/Manila for Philippine deadline display and explicit deadline interpretation. Date-only opening/academic dates use DATE. Use PHP-backed enums with validated string columns so adding statuses does not require changing MySQL ENUM definitions.

Use soft deletion for scholarships and applications, and selectively for user accounts/documents. Financial/audit rows are retained; do not cascade-delete them. Restrict parent deletion for important records. Domain account deletion/anonymization requires a defined retention policy. Foreign keys enforce structural relationships; policies and transactional services enforce cross-table role/ownership rules.

| Table | Principal columns and relationships | Constraints / indexes |
| --- | --- | --- |
| users | id; name; unique email; email_verified_at nullable; password hash; role; remember_token; disabled_at nullable; deleted_at nullable | unique(email); index(role, disabled_at). No public admin assignment. |
| student_profiles | id; user_id → users; school; course; year_level; income_bracket; contact_number; region_code; city_code; address nullable; gwa DECIMAL(5,2) nullable; grading_scale nullable; family_information JSON nullable | unique(user_id); index(region_code, city_code); index(school, year_level). Never infer GWA ordering without its grading scale. Collect sensitive family fields only when a scholarship needs them. |
| provider_profiles | id; user_id → users; organization_name; organization_type; contact_email; contact_number; region_code; city_code; verification_status; reviewed_by → users nullable; reviewed_at nullable; rejection_reason nullable | unique(user_id); index(verification_status). Review transitions are administrator-only and audited. |
| academic_terms | id; academic_year; semester; starts_on; ends_on | unique(academic_year, semester). Shared by applications, awards, renewals, and disbursements. |
| scholarships | id; provider_id → provider_profiles; title; slug; description TEXT; scholarship_type; benefit_amount DECIMAL nullable; currency CHAR(3); academic_term_id → academic_terms nullable; opens_at; deadline_at; accepts_existing_scholars bool; eligibility_rules JSON nullable; status; published_at nullable; deleted_at nullable | unique(slug); index(provider_id, status); index(status, opens_at); index(status, deadline_at); index(scholarship_type, benefit_amount); index(accepts_existing_scholars). Version/validate rule structure rather than interpreting arbitrary text as executable eligibility. |
| scholarship_locations | id; scholarship_id → scholarships; region_code nullable; city_code nullable; scope string | index(region_code, city_code, scholarship_id); prevent duplicate scopes in service validation. Supports nationwide/multiple locations; a student's location never silently restricts browsing. |
| scholarship_requirements | id; scholarship_id → scholarships; name; description nullable; required bool; allowed_mime_types JSON; max_size_kb; sort_order; requirement_stage | index(scholarship_id, requirement_stage, sort_order). Stage distinguishes application from renewal requirements. |
| applications | id; scholarship_id → scholarships; student_id → users; academic_term_id → academic_terms nullable; status; has_existing_scholarship bool; low_priority bool; conflict_rule_snapshot JSON nullable; submitted_at nullable; reviewed_by → users nullable; reviewed_at nullable; decision_reason nullable; deleted_at nullable | unique(scholarship_id, student_id) for one lifecycle per program; index(student_id, status); index(scholarship_id, status, low_priority). Resubmission/reapplication rules need definition before allowing multiple cycles. |
| application_status_histories | id; application_id → applications; from_status nullable; to_status; changed_by → users nullable; reason nullable; occurred_at | index(application_id, occurred_at). Immutable transitions created in the same transaction as status changes. |
| scholarship_awards | id; application_id → applications; student_id → users; scholarship_id → scholarships; academic_term_id → academic_terms; status; starts_on; ends_on nullable; revoked_at nullable; public_listing_consented_at nullable | unique(application_id); index(student_id, status); index(scholarship_id, status, ends_on). Separates an accepted application from an active funded award. |
| student_documents | id; student_id → users; document_type; original_name; disk; path; mime_type; size_bytes; checksum nullable; uploaded_at; deleted_at nullable | index(student_id, document_type); unique(disk, path). Private storage; never expose raw paths as downloadable URLs. Files attached to submissions must remain immutable/versioned. |
| application_documents | id; application_id → applications; scholarship_requirement_id → scholarship_requirements; student_document_id → student_documents nullable; disk; path; original_name; mime_type; size_bytes; version; checklist_completed_at nullable; uploaded_at; verified_by → users nullable; verified_at nullable; review_status; review_notes nullable | unique(application_id, scholarship_requirement_id, version); index(application_id, review_status). Vault attachment references a retained immutable version or an application-specific copy. Verify every requirement belongs to that application's scholarship. |
| renewals | id; scholarship_award_id → scholarship_awards; academic_term_id → academic_terms; deadline_at; status; submitted_at nullable; reviewed_by → users nullable; reviewed_at nullable; review_notes nullable | unique(scholarship_award_id, academic_term_id); index(status, deadline_at). RenewalStatus: pending, submitted, approved, rejected. |
| renewal_documents | id; renewal_id → renewals; scholarship_requirement_id → scholarship_requirements; student_document_id → student_documents nullable; disk; path; original_name; mime_type; size_bytes; version; uploaded_at; review_status; review_notes nullable | unique(renewal_id, scholarship_requirement_id, version); index(renewal_id, review_status). Same private-file and requirement-ownership rules as application documents. |
| payments | id; scholarship_award_id → scholarship_awards; academic_term_id → academic_terms; amount DECIMAL; currency CHAR(3); status; reference_number nullable; released_at nullable; recorded_by → users; notes nullable | index(scholarship_award_id, academic_term_id); index(status, released_at). PaymentStatus: pending, processing, released. Multiple installments allowed; records track disbursements, not payment processing. |
| conversations | id; student_id → users; provider_id → provider_profiles; last_message_at nullable | unique(student_id, provider_id); index(student_id, last_message_at); index(provider_id, last_message_at). Participant role rules checked on creation; no student-to-student conversation. |
| messages | id; conversation_id → conversations; sender_id → users; body TEXT nullable; read_at nullable; edited_at nullable | index(conversation_id, id); index(conversation_id, read_at). Sender must be a conversation participant. A message requires text or an authorized attachment. Typing indicators are ephemeral broadcast events. |
| message_attachments | id; message_id → messages; uploaded_by → users; disk; path; original_name; mime_type; size_bytes | index(message_id). Access requires current conversation participation; validate uploads server-side. |
| notifications | Laravel default UUID id; type; notifiable_type; notifiable_id; data TEXT/JSON; read_at nullable; timestamps | index(notifiable_type, notifiable_id); index(notifiable_type, notifiable_id, read_at). Avoid exposing private data in broadcast/email previews. |
| external_applicant_counts | id; scholarship_id → scholarships; reported_count; corrected_count nullable; source_label; source_as_of; reported_by → users; duplicates_excluded; unique submission_token; status; reviewed_by → users nullable; reviewed_at nullable; reason nullable; internal_fingerprint nullable; correction_of_id → external_applicant_counts nullable | Stage 5 uses retained report snapshots, not one overwritable row per scholarship. Corrections/re-verifications append rows. Manual reporting only; no Sheets integration. |
| activity_logs | id; actor_id → users nullable; action; subject_type; subject_id; changes JSON nullable; occurred_at | index(subject_type, subject_id, occurred_at); index(actor_id, occurred_at). Append-only; never log passwords, reset tokens, document content, or unnecessary personal information. Polymorphic subjects cannot have a normal relational FK. |
| reminder_deliveries | id; renewal_id → renewals; recipient_id → users; days_before; delivered_at nullable; claimed_at nullable | unique(renewal_id, recipient_id, days_before). Supports idempotent 30/14/7-day renewal reminder dispatch. Retry/delivery rules defined in Phase 7. |

Laravel infrastructure tables remain: password_reset_tokens (email PK), sessions (string id PK), cache/cache_locks (string key PK), jobs, job_batches (string id PK), and failed_jobs (numeric PK plus unique UUID). Their framework schemas are retained unless a concrete requirement warrants changes.

### Status and consistency rules

- ApplicationStatus: not_started, requirements_pending, submitted, under_review, accepted, rejected, waitlisted. Count submitted/reviewed/accepted/rejected/waitlisted records as submitted applications; exclude drafts. Withdrawal semantics must be defined before adding withdrawal or excluding withdrawn records.
- Confirmed Stage 5 rule: count preview = eligible internal submitted applications (including waitlisted, excluding drafts) + the latest admin-verified external snapshot by source date and record ID. Pending/rejected external reports are excluded. Require provider and admin confirmation that external figures exclude internal applicants. A changed internal applicant set invalidates inclusion until re-verification; aggregate data cannot be deduplicated automatically. Retain reports, corrections, source dates, reviewer decisions, and audit history. Student submission/review workflows and live public scholarship pages remain separate work.
- Conflict checks read active scholarship_awards, not merely accepted applications. Evaluate the target scholarship's explicit accepts_existing_scholars rule at submission. If disallowed, block; if allowed, record the conflict and low-priority flag. Display a confirmation warning before submission, and enforce the decision again server-side. Concurrency requires transactional locking around a student's submission/award transitions.
- Do not fabricate active scholarships for a student. Self-declared external awards need a provenance/verification design when Phase 3 requirements are clarified.
- Public passers derive from active awards whose date range includes the current date; expose only approved name, school, and scholarship fields. Public publication/consent requirements must be resolved before Phase 8. Sensitive profiles/documents never become public through relationship serialization.
- Renewal reminders need uniqueness/idempotency, queues, retries, and schedule timezone decisions. A notification row alone does not prove email delivery.

## Typography and layout proposal for Phase 1

The initial CSS named Instrument Sans without loading it. Phase 1 replaces that reference with an explicit system sans stack. No custom-font loading is claimed; original Stitch assets remain unavailable.

- Body: 16px with 24px line height. Form labels/navigation: 14px with at least 20px line height. Secondary text remains readable and does not carry the only explanation of an action.
- Page headings: 28–36px depending on viewport; section headings: 20–24px. Use two or three weights consistently, not oversized headings on every card.
- Reading text: approximately 60–70 characters per line; auth form width around 28rem. Inputs/buttons have comfortable hit areas, visible labels, and keyboard focus states.
- Use Tailwind utilities for spacing, text, responsive layout, focus states, and contrast. Avoid decorative font downloads and synthetic dashboard statistics.
- The public, student, provider, and admin experiences share typography and primitives. Navigation and permissions differ. Mobile navigation must work without horizontal page overflow.
- Verification: computed browser font/size, desktop/mobile widths, long names, validation messages, keyboard focus, heading hierarchy, and real Inertia navigation. Test results must be reported only after running them.

No original Stitch files are present in this workspace. Preserve any supplied design later; do not claim this proposal reproduces it.

## Phase 1 acceptance criteria

Authenticated roles route to their own pages. Guests can view public/auth pages. Cross-role requests are denied by the server; policies cover resource ownership. Public registration cannot create/promote administrators or approve providers. Pending providers have an explicit restricted experience. Profile creation and user creation are transactional. Authentication remains Fortify-backed, and role information sent to React has declared TypeScript interfaces.

Include automated tests for role routing, guest protection, role escalation attempts, ownership denial, provider approval restrictions, and administrator provisioning. Provide a Phase 1 file guide and manual checks. No Phase 2 scholarship CRUD or later workflow is implemented before confirmation.
