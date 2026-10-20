# IskoReach geographic data coverage and accuracy report

Report date: 20 October 2026 (Asia/Manila, Philippines).

## Outcome

Completed the implementation audit, source comparison, bounded search corrections, and regression checks. The instruction to finish without further questions superseded the attachment's pauses between phases. No source replacement, schema migration, or production data correction was performed. The current geographic directory has confirmed coverage problems; this report does not certify nationwide accuracy.

Subsequent upgrade work is recorded below and in [OFFICIAL-GEOGRAPHIC-UPGRADE-REPORT.md](OFFICIAL-GEOGRAPHIC-UPGRADE-REPORT.md). The directory/importer and read-only mapping machinery are implemented; the official dataset download still returns HTTP 403. The official source switch and website integration remain unfinished, and the prepared directory migration is unapplied to configured MySQL.

## Implementation inspected

| Area | Actual behavior and limits |
| --- | --- |
| Source | Laravel retrieves public options from `services.psgc.url`, configured through `PSGC_API_URL`, defaulting to the community-maintained `https://psgc.gitlab.io/api`. It is not a PSA-operated API. |
| Storage | `Scholarship` has many `ScholarshipLocation` records. `scholarship_locations` contains scholarship ID, scope, nullable region/city codes and saved names, and timestamps. Codes are strings, preserving leading zeroes. There is no local authoritative directory, source edition, province field, or barangay field. |
| Account geography | Student profiles contain school, course, and year level; provider profiles contain organization/contact and verification information. Neither collects residential geography. Search is independent of a student's residence. |
| Selection | Providers choose nationwide, region, or city/municipality coverage, with up to 20 rows. Region-to-city selection is dependent; changing a region clears the city. Native select controls are retained. Dedicated searchable dropdowns were not introduced. |
| Validation | Server lookups establish region membership and explicit city-to-region ancestry. Saved names come from the server, not client labels. Duplicate coverage rows are rejected. The adapter and city route accept nine-digit identifiers only. |
| Display | Public cards/details and admin monitoring reuse saved names, with explicit code fallbacks for legacy unnamed rows. Rendering does not contact the upstream API. No historical labels are invented. |
| Reliability | Successful options are cached for six hours, with a marked stale fallback for up to 30 days. Missing usable options produce controlled errors/retry. Caches are availability safeguards, not evidence of current geography. Existing cached labels expire normally; this change does not flush production caches. |
| Supported levels | Nationwide, regional, city/municipality, and multiple coverage rows work. Province and barangay selection/search are unsupported. A city record stores its region, but text search cannot infer a region-wide program from an arbitrary city name or resolve unrecorded aliases. |

A read-only configured MySQL inspection found **zero scholarships, scholarship locations, applications, notifications, and queued jobs**. There were no stored place values to repair or actual duplicate/spelling defects to claim. No private address collection was added.

## Source accuracy and provenance

The live community API audit returned **17 regions and 1,634 city/municipality rows** across all 17 region endpoints. Every endpoint succeeded; returned city rows had nine-digit codes, nonempty names, matching parent-region codes, and no duplicate codes within or across those responses. These structural checks do not prove administrative accuracy. The audit snapshot, including public rows and per-region counts, is saved at `storage/app/private/geographic-review-preview/community-source-audit.json`.

Targeted live checks also returned Bacolod under Western Visayas, Siquijor municipality under Central Visayas, and Sulu province under BARMM. The regions response omitted NIR. These are observed upstream findings, not guessed missing records.

PSA's summary identifies its publication as of **30 September 2026**, with a third-quarter release dated **12 October 2026**. It lists NIR, including Negros Occidental, Negros Oriental and Siquijor; Bacolod is under NIR. Sulu is listed under Zamboanga Peninsula. Its table exposes ten-digit codes and correspondence codes, including ten-digit correspondence values for NIR. Its footer states CC BY 4.0 unless otherwise indicated. These observations establish concrete differences from the community responses and a compatibility problem with the current nine-digit adapter. [PSA PSGC summary](https://psa.gov.ph/classification/psgc/summary).

The community project's README links its API, but the inspected material did not establish a reliable release edition, update schedule, or applicable redistribution license for its transformed dataset/code. Do not infer a license or SLA from public accessibility. [Community project README](https://gitlab.com/psgc/api/-/raw/master/README.md).

PSA documents a versioned API at `classification.psa.gov.ph/psgc`, with region, province, municipality/city, and barangay levels, pagination, and a required access token. The documentation's listed versions include Q2_2024; that list does not establish availability of a Q3_2026 API edition. Its response fields and endpoints differ from the community adapter. Changing only the base URL would therefore be insufficient. No token was requested, supplied, or changed. [PSA API documentation](https://psa.gov.ph/classifications-api/psgc).

## Corrections delivered

- Public and admin location filters now share `ScholarshipLocationSearch`. Both include nationwide programs for any nonempty place search, while preserving each page's existing visibility and retained-record rules.
- Search normalizes query whitespace and lowercases tokens. Every token must match saved names/codes within the same coverage row. For example, `Quezon City NCR` distinguishes the saved region without accidentally joining a city in one coverage row to a region in another.
- `%`, `_`, and the escape character are treated literally through bound SQL parameters. They cannot turn a location query into an unrestricted wildcard search.
- Partial-name/code matching remains available. Similar names can legitimately produce several results; adding the saved region narrows them. No fuzzy spelling, invented aliases, transliteration, or accent-insensitive guarantees were added. Database collation still affects non-ASCII comparison.
- Fresh upstream labels have surrounding/repeated Unicode whitespace normalized. Empty normalized labels, malformed responses, duplicates, unsupported code formats, and incorrect parent relationships remain rejected. Codes and historical saved names are preserved.
- Provider guidance identifies the community directory's limitations and explains unsupported province/barangay selection. Public filter guidance describes searchable fields and how to distinguish similar names. Existing hierarchy, nationwide coverage, shared display, and multi-location entry are reused.

## Verification

| Check | Result |
| --- | --- |
| Targeted geography, lookup, admin monitoring, and public discovery tests | 26 passed, 648 assertions before the final additional malformed-source cases |
| Final full backend suite | 238 passed, 4,620 assertions |
| `php vendor/bin/pint --dirty --format agent` | Passed |
| `npm run typecheck` | Passed |
| `npm run build` | Passed after allowing local Vite helper processes outside the sandbox |
| Existing `QA_LOCATION_CHECK` Chrome mode | Passed against a new disposable SQLite database and isolated database-backed lookup cache |
| Read-only MySQL recheck | All five inspected domain/job tables remained empty |

Four new tests cover case/whitespace, composite similar-name searches, multiple coverage rows, literal wildcards, nationwide parity, student access, empty/reset searches, legacy code-only values, unsupported place levels, and public/admin visibility. Existing lookup tests now exercise padded labels, unsupported ten-digit input, and whitespace-only names. The first targeted run found a fixture update using a protected verification field; using `forceFill` in that fixture corrected the setup. No production model permissions were relaxed.

The full suite includes scholarship creation/editing, public discovery, application workflows, authorization, and historical-record regressions. Tests use isolated SQLite, not MySQL. They do not certify MySQL collation behavior for every Philippine spelling.

Chrome checked cascading selection/reset, saved values, blocked lookup/retry recovery, draft creation/publication, readable public place names, name search, and layouts at 320, 768 and 1440px. The existing landing/authentication smoke checks also passed, with no uncaught runtime errors. Phone provider-selection and desktop public-detail screenshots were visually inspected. Artifacts are under `storage/app/private/geographic-review-preview`. The temporary fixture server was stopped.

An initial elevated live-source check could not execute because automatic approval review hit a usage limit; this was a review failure, not a safety rejection. After review became available again, the explicitly approved live checks succeeded. No live-coverage claim relies on the failed attempt.

## Files and data impact

- Added `app/Services/ScholarshipLocationSearch.php` and `tests/Feature/GeographicSearchTest.php`.
- Updated `app/Services/ScholarshipCatalog.php`, `app/Http/Controllers/Admin/ScholarshipController.php`, `app/Services/PhilippineLocations.php`, and `tests/Feature/LocationLookupTest.php`.
- Updated guidance in `resources/js/pages/provider/ScholarshipForm.tsx` and `resources/js/pages/public/Scholarships.tsx`.
- Added this report and updated `docs/PROGRESS-REPORT.md`. Corrected the report-date timezone to Asia/Manila in the progress, public-website, academic-term, and student-stage reports, following the user's location correction.

No dependency, schema, migration, `.env`, credential, real domain record, mail delivery, or production queue processing changed. No stored location was automatically remapped. UI fixture records and lookup caches were isolated from configured MySQL.

## Dataset upgrade plan

1. Obtain an explicitly versioned PSA publication or authenticated API edition whose current availability and redistribution terms are checked. Record source URL, edition date, retrieval time, checksum, attribution, and counts by geographic level. Prefer a reviewed local snapshot for deterministic selection and validation rather than depending on a remote request during every cache miss.
2. Prepare an adapter and dry-run comparison for region/province/city/barangay relationships. Preserve canonical identifiers as strings. Handle highly urbanized cities and absent province relationships explicitly. Do not create a new province or infer ancestry from a code prefix alone.
3. Design a versioned geographic directory and a reviewed legacy crosswalk before introducing new scope fields or replacing the adapter. A nine-to-ten-digit conversion must use authoritative correspondence records, not padding or truncation. Separate unchanged, moved, renamed, ambiguous, and unmatched places; unresolved mappings require correction instead of guessing. Keep historical scholarship coverage snapshots intact.
4. Review schema, mapping output, rollback strategy, and the intended province/barangay eligibility semantics before applying a production migration or remapping records. The existing columns can hold longer strings, but routes, validators, client guards, caching, and source adapters also need coordinated changes.
5. Verify a complete import against the selected edition, including NIR and Sulu ancestry, duplicate names/codes, parent integrity, missing records, offline operation, search, and historical display. Schedule a review after each official release; never silently substitute an unreviewed dataset.

This completes the bounded review and compatible improvements. Adoption of a new dataset, new geographic levels, and production mapping remain a separate implementation milestone with concrete data/schema decisions; nationwide geographic completeness is still not certified.

## Official dataset upgrade follow-up

The subsequent read-only Phase 1 review identified the official Q3 2026 workbook links and reuse terms, but direct workbook downloads returned HTTP 403. No full official snapshot was obtained, hashed, or parsed. The publication's listed totals are not import results.

Phase 2 planning is now complete in [OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md](OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md), following approval to prepare the snapshot-acquisition and import specification. It defines provenance and checksum requirements, deterministic extraction, versioned local directory structures, relationship validation, dry-run mapping categories, historical preservation, rollout, and rollback. The official snapshot acquisition/integrity gate remains open. No directory implementation, production migration, source switch, or mapping application was performed in these planning phases; they require the next applicable approvals.

### Phase 3 — Isolated directory and staging importer

Following approval, implemented normalized-package inspection, explicit-edition directory reads, transactional SQLite staging, and three additive directory tables. The existing source and UI remain unchanged. The new migration is prepared but not applied to configured MySQL. No activation or legacy-mapping action exists in this delivery.

Nineteen new isolated tests passed with 59 assertions; TypeScript and production build passed. Official XLSX extraction, complete source-row reconciliation, dataset review, production migration, and provider/public integration remain pending their acquisition and approval gates. Structural validation explicitly does not certify official provenance or nationwide completeness. Commands, package contract, exact schema scope, final regression results, and limitations are documented in [OFFICIAL-GEOGRAPHIC-PHASE3-REPORT.md](OFFICIAL-GEOGRAPHIC-PHASE3-REPORT.md).

### Legacy mapping and final verification continuation

Implemented `geography:mapping-report` with explicit local-export or read-only current-database inventory. It classifies exact, label-changed, moved-parent, ambiguous, and unmatched cases; nationwide coverage needs no identifier mapping. Proposals retain original fields, evidence, fingerprints, target digest, and unreviewed status. No proposal is applied, including exact matches. Changed original rows or target manifests invalidate proposals. Ten new tests passed with 43 assertions; combined directory/mapping tests passed 29 tests and 102 assertions. Final full-suite results and precise source/integration limits are in [OFFICIAL-GEOGRAPHIC-UPGRADE-REPORT.md](OFFICIAL-GEOGRAPHIC-UPGRADE-REPORT.md).

A fresh official workbook request again returned HTTP 403 with HTML, so no official dataset was obtained or certified. The existing source stays active; no production migration or record remapping occurred. The instruction to finish removes routine phase pauses, but does not make unavailable official data verifiable.
