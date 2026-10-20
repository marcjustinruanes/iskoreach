# IskoReach design foundation — Stitch reference

Scope: design tokens, reusable component foundations, role sitemap, and landing page only. No next-page design or scholarship backend implementation is included. Light mode follows the supplied reference; dark mode is not designed in this pass.

## Reference inspected

The root archive stitch_iskoreach_landing_page_design.zip contains code.html (57,419 bytes), screen.png (1920 × 7009), and DESIGN.md (10,943 bytes). All three were read/viewed. Extracted copies are in ignored storage/app/private/stitch-reference; the original ZIP is preserved.

The reference specifies Plus Jakarta Sans for body, headings and labels—not Inter. Its HTML and screenshot take precedence where DESIGN.md's prose has different colors/radii. Layout preserves the header, two-column hero and overlapping example widgets, scholarship discovery preview, three-step journey, four student benefit cards, dark provider panel, final CTA, and footer.

Data/content adjustments are deliberate: example figures/program dates/statuses are labelled as illustrative. Unverified claims about decentralization, automatic eligibility/GWA verification, fee guarantees, SMS delivery, legal compliance, and live scholarship counts are not presented as working facts. The mockup's historical dates remain examples. Footer links point to existing sections/auth routes; unavailable policy/forum pages are not invented.

## Color tokens

Implemented as a Tailwind config extension in tailwind.config.ts and loaded through Tailwind 4's @config directive in resources/css/app.css. UI styling uses utilities; the only new CSS declarations are config loading and font-face registration.

| Utility token | Hex | Use |
| --- | --- | --- |
| civic-navy | #001428 | Primary action, headings, logo tile |
| civic-navy-soft | #0F2942 | Provider panel, primary hover |
| civic-teal | #006A61 | Conversion action, links, focus |
| civic-teal-hover | #005049 | Teal action hover |
| civic-mint | #86F2E4 | Illustration tiles, accents on navy |
| civic-canvas | #F8F9FF | Off-white page surface |
| civic-panel | #EFF4FF | Alternating sections, metadata |
| civic-panel-strong | #DCE9FF | Pills, decorative numerals |
| civic-ink | #0D1C2E | Body foreground |
| civic-muted | #43474D | Secondary foreground |
| civic-border | #C3C6CE | Structural dividers |
| civic-control | #74777E | Input boundary; stronger than structural dividers |
| civic-gold | #F3C969 | Achievement accent, not body text |
| civic-success / warning / danger | #065F46 / #92400E / #93000A | Semantic foregrounds |

Six neutral steps: #F8FAFC, #F1F5F9, #E2E8F0, #94A3B8, #475569, #0F172A. Decorative light colors must not be used for essential text. Input/focus indicators use dark teal; a pale structural border alone must not be the only identifier of an interactive control.

Example usage:

```tsx
<section className="bg-civic-panel font-stitch text-civic-ink">
    <h2 className="text-civic-heading-mobile md:text-civic-heading">Section title</h2>
</section>
```

## Typography and rhythm

The Google Fonts Plus Jakarta Sans WOFF2 and SIL Open Font License are bundled under public/fonts. Vite emits the font as a hashed asset; the landing page preloads that same asset. No CDN font request is required at runtime. The existing authentication/account pages keep their system font until separately reviewed.

| Scale | Tailwind usage | Intended use |
| --- | --- | --- |
| 12 / 16 | text-xs | Labels, badges, metadata; minimum nondecorative landing text |
| 14 / 22 | text-civic-small | Descriptions, navigation support |
| 16 / 26 | text-civic-body | Main body; form inputs use text-base |
| 18 / 28 | text-lg | Card titles |
| 20 / 28 | text-xl | Small section/metric |
| 24 / 32 | text-2xl | Supporting headline |
| 30 / 36 | text-3xl | Step numerals/optional compact title |
| 36 / 44 | text-civic-display-mobile | Mobile hero/final CTA |
| 48 / 56 | text-civic-display | Desktop hero/final CTA |
| 60 / 60 | text-6xl | Reserved; not required by this reference |

Section headings follow the source's 26/34 mobile and 32/40 desktop sizes, with negative tracking. Body weight 400, labels 600, titles 700, display 800. Numerical amounts and counts use tabular-nums. Use 4px spacing units, 16px mobile gutters, 24px tablet gutters, 48px desktop interior gutters, max-w-7xl container. Typical sections use 56px mobile and 80px desktop vertical space.

Standard controls/cards: rounded-lg (8px). Showcase cards: rounded-xl (12px). Larger source composite cards: rounded-2xl (16px); provider banner: rounded-3xl (24px). Modals: 12px; pills: fully rounded. Shadows: civic-card, civic-raised, civic-overlay, defined once in config.

Motion uses 200ms ease-out utility transitions. Reduced-motion variants disable transforms/transitions and skeleton/spinner animation. No source float/pulse/ticker keyframes or hidden-scrollbar CSS were copied.

## Component code and specifications

Reusable code is in resources/js/components/ui/DesignSystem.tsx, Overlays.tsx, and DataDisplay.tsx. Existing Phase 1 Form.tsx remains unchanged to preserve current account forms. The new foundations are typed and compile; only components exercised on this landing page have browser interaction verification in this pass.

| Component | Specification / accessibility contract |
| --- | --- |
| Button | primary navy, secondary teal, outline, ghost, danger; sm/md minimum 44px, lg 48px; native type/disabled; aria-busy and textual label retained while loading; visible focus; icon-only callers must supply aria-label |
| Input | Visible label associated through useId; text/email/date/file via native type; 16px input text; associated hint/error; aria-invalid; backend validation remains necessary |
| Card | Composable 8px white surface, low elevation; no implied click unless a real link/button is supplied |
| Badge / StatusBadge | Text plus Lucide icon; no color-only meaning; wrapping supported; not an interactive toggle |
| Modal | Headless UI Dialog; labelled title/description; focus trap, Escape dismissal and focus return; background inert; scrollable at small heights; explicit 44px close control |
| Drawer | Same dialog contract; right drawer or mobile bottom sheet; max-height and vertical overflow; mobile menu/filters use it |
| Toast | Inline live region, status for neutral/success, alert for failure; explicit dismiss; no forced timeout; nonessential detail must not repeatedly interrupt |
| Table | Typed columns/renderers; optional sort accessors; aria-sort; caption; supplied toolbar supports filtering; mobile card list and sort buttons; no fabricated totals |
| Stepper | Ordered list, aria-current=step, text for completion; caller supplies a zero-based valid current step; future step navigation should validate before advancing |
| ProgressBar | Native progress, associated label, clamped value/max, visible percentage; status announcements belong to the parent flow, not every animation frame |
| EmptyState | Small icon illustration, clear title, explanation, optional real action; empty search offers reset |
| Skeleton | Presentational, aria-hidden, reduced motion respected; parent supplies an accessible loading message and aria-busy |
| Avatar | Image alt; initials fallback if missing/error; no broken image placeholder; initials are not identity verification |
| Tabs | Headless UI TabGroup/TabList/TabPanels; selected state, keyboard navigation, matching labelled panels; controlled or uncontrolled index |

Additional component specifications for later pages (not claimed implemented here): native select/textarea with the same label/error contract; file drop zone with an equally accessible file picker, retained file metadata/preview/remove controls, clear size/type errors and server-side MIME validation; stat cards with actual values and defined time window; application cards with text status/progress/next action; segmented filters as native buttons with aria-pressed; breadcrumb nav with an ordered list and aria-current; paginated lists with server URLs and current-page announcements. Infinite scroll must offer a keyboard-operable Load more fallback and a stable return position. A root error boundary must provide a safe retry without dropping a saved application draft when those flows are built.

### Status map

statusMap and badgeColors export the actual classes, labels and icons:

| Status | Palette |
| --- | --- |
| Not started / payment pending | slate-100 / slate-700 |
| Requirements pending / processing / due soon / low priority | amber-50 / amber-900 |
| Submitted | blue-50 / blue-800 |
| Under review | indigo-50 / indigo-800 |
| Accepted / released / approved | emerald-50 / emerald-800 |
| Rejected / overdue | red-50 / red-800 |
| Waitlisted | purple-50 / purple-800 |

Each includes a border, icon and text. The component expects a domain status; due_soon/overdue are derived renewal display states, not replacements for the backend renewal enum.

### Lucide action map

GraduationCap: brand/education; Search: browse/search; Filter: filters; Menu: mobile navigation; ArrowRight: next/action; Building2/Landmark: provider; BadgeCheck/ShieldCheck: approval context; ClipboardCheck/ListChecks: requirements; FileCheck2: documents; BellRing: reminders; CalendarClock/Clock3: deadlines; Map: location; TrendingUp: tracking; CheckCircle2: completed/accepted; AlertCircle: warning; X: close/reject; LoaderCircle: loading. Decorative icons use aria-hidden; icon-only buttons require an accessible name. Headless UI/Lucide are used instead of adding the complete shadcn stack.

## Role sitemap — planned navigation

Public: Landing → Browse + filters → Scholarship detail; Active passers; About/How it works; Login/Register.

Student: Dashboard; Browse/detail; Apply steps (confirm profile → requirements → review → submit); My Applications list/kanban → application timeline/checklist/documents/provider chat; Document vault; Renewals; Payment history; Inbox/conversation; Profile/settings; Notification center.

Provider: Dashboard; Scholarship list/create/edit/detail; Applicant list → review drawer; Payment tracking; Inbox/conversation; Profile/verification status; Notification center. Pending providers retain a restricted account experience.

Admin: Dashboard; Provider verification queue; Scholarship monitoring; User management; Applicant count monitor; Reports; Activity logs.

Only the landing page has been designed in this pass. This sitemap is not a claim that those pages/routes exist. Current Phase 1 auth/account routes remain available; broader navigation is designed one page at a time after confirmation.

## Page 1 wireframe and content hierarchy

```text
HEADER: logo + Philippines | section navigation | login / get started
HERO: [built for students]
      H1 Your Next Opportunity Starts Here. | sample tracker overlay
      body + explore / how-it-works         | sample grant card
      labelled example metric ribbon       | approval badge
DISCOVERY PREVIEW (pale blue):
      H2 Find Scholarships That Fit Your Goals.
      sample-data explanation + result count
      [labelled search] [region chips] [level/focus chips]
      [sample scholarship 1] [sample 2] [sample 3]
      [reset/view all samples]
HOW IT WORKS:
      H2 + supporting copy
      [01 Discover] [02 Prepare & Apply] [03 Track]
STUDENT FOCUS (pale blue):
      H2 + explanatory copy
      [Discover] [Prepare] [Track] [Renew]
PROVIDER PANEL (navy):
      H2 + capabilities + registration CTA | sample provider widget
FINAL CTA:
      education pill + H2 + body + explore / account actions
FOOTER: brand + SDG context | explore | providers | account/about links
```

Source files: Welcome.tsx composes state/navigation/discovery; LandingSections.tsx implements the hero/journey/benefits/provider/CTA/footer; data/landing-samples.ts holds explicitly labelled fixtures. The main hero is the single H1; sections use H2, program/feature cards H3. Primary CTA anchors to the discovery preview; provider CTA opens registration with provider preselected through a validated presentation-only query parameter. Admin privilege cannot be selected by query parameter.

### Landing states and interactions

- Default: all three samples. Keyword/region/education filters combine without automatically restricting a visitor's location.
- Empty: icon, explanation, Reset filters button. Result count is politely announced after changes.
- Loading: this page's examples are bundled synchronous data, so no artificial loading delay is inserted. For future server data, use three skeleton cards under an aria-busy grid with a status message.
- Error: no listing network request exists in this preview. Future failed queries should retain filters, show an inline retry and keep the last successful results labelled as stale where appropriate. No live error-recovery claim is made.
- Guide button: a labelled, keyboard-dismissable modal explains the sample program. It is not the next scholarship detail page.
- Approval process button: modal describes the real Phase 1 account approval rules.
- Hover/focus: card elevation changes subtly; links/buttons keep visible high-contrast focus. No hover-only information.

### Mobile rules

At 320px all columns stack, with 16px gutters and text wrapping. Hero overlays enter normal flow so they cannot cover grant details. Menu uses a focus-managed drawer. Directory filters use a bottom sheet below 1024px. Cards are one column, two at 768px, three at 1024px. Benefit cards are one/two/four columns. Desktop navigation appears at 1280px because this reference has five long labels plus account actions; 1024px retains the drawer to avoid crowded text. There is no student bottom navigation on a public page. At 1920px content remains centered and bounded rather than stretching body text across the screen.

## Verification and review

Chrome checks cover widths 320, 640, 768, 1024, 1280, 1536, and 1920; actual font loading; sample search; empty/reset behavior; guide focus/Escape dismissal; mobile menu; filter sheet; existing authentication navigation and labelled 16px forms. Full screenshots are saved locally under ignored storage/app/private/stitch-preview.

TypeScript and Vite checks are run for the changed source. PHP account-query regression checks ensure provider preselection and disallow selecting admin through that query. Existing MySQL state is unchanged, with no migrations or writes to scholarship/application data.

WCAG 2.1 AA is the design target, not a certification. Calculated contrast pairs: white/navy 18.57:1, white/teal 6.49:1, body/canvas 16.35:1, muted/canvas 8.89:1, input-control boundary/canvas 4.27:1. Keyboard/focus, labels, responsive overflow and those selected pairs are checked; screen-reader testing and a comprehensive accessibility audit remain to be done. Do not claim every library component is fully tested merely because its types compile.

Actual CLI results for this pass: strict TypeScript passed; Vite production build passed with a hashed 27.34 kB font asset; PHP suite passed (23 tests, 223 assertions); Pint passed. Browser script passed the landing interaction/viewport checks and preserved auth-page navigation. Added dependencies: Headless UI 2.2.10 and Lucide React 1.59.0; npm reported zero vulnerabilities at installation. The temporary local preview server is stopped after QA. Nothing was deployed or pushed.

Official implementation references: [Tailwind config compatibility](https://tailwindcss.com/docs/compatibility), [Headless UI Dialog](https://headlessui.com/react/dialog), [Headless UI Tabs](https://headlessui.com/react/tabs), [Lucide React](https://lucide.dev/guide/react). Brand/layout/type reference: the supplied local Stitch ZIP.
