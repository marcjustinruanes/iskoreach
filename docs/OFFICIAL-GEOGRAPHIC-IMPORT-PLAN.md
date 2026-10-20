# IskoReach official geographic snapshot import plan

Report date: 20 October 2026 (Asia/Manila, Philippines).

## Phase and authorization

This is the Phase 2 specification approved after the read-only Phase 1 review. It defines acquisition, import, validation, mapping, and rollback requirements. All commands, tables, services, routes, and integration changes described below are proposals, not implemented capabilities. This phase changes documentation only. Production migrations, data import, source activation, and legacy mapping application remain separately approval-gated.

## Candidate source and outstanding acquisition gate

PSA lists its Q3 2026 publication, as of 30 September 2026, released on 12 October 2026, together with a national/provincial summary and change workbook. Its published totals are 18 regions, 82 provinces, 149 cities, 1,493 municipalities, and 42,010 barangays. These are reference totals, not measured import results. [PSA publication page](https://psa.gov.ph/classification/psgc).

Official attachment URLs identified during Phase 1:

- [Publication datafile](https://psa.gov.ph/system/files/scd/PSGC-3Q-2026-Publication-Datafile.xlsx).
- [National and provincial summary](https://psa.gov.ph/system/files/scd/PSGC-3Q-2026-National-and-Provincial-Summary.xlsx).
- [Summary of changes](https://psa.gov.ph/system/files/scd/PSGC-3Q-2026-Summary-of-Changes_0.xlsx).

All three direct downloads returned HTTP 403 during Phase 1. The browser tool identified spreadsheet content but could not parse XLSX. No complete official workbook was obtained, saved, hashed, or parsed. Acquisition was not retried in Phase 2; its current availability is therefore unverified. A blocked HTML response must never be treated as a spreadsheet or hashed as a dataset. No source is ready for activation.

PSA documents a token-required, versioned API. The documented versions do not establish access to a Q3 2026 API edition. This plan prefers the publication files and does not request credentials or assume an API fallback. [PSA API documentation](https://psa.gov.ph/classifications-api/psgc).

PSA's terms allow sharing and adaptation with attribution, a terms link, disclosure of changes, relevant technical notes, and no implied endorsement or additional restrictions on permitted reuse. Preserve the edition's own notices and any exceptions when the workbook is obtained. [PSA terms of use](https://psa.gov.ph/terms-of-use).

Acquisition is complete only when the actual bytes are available through a legitimate official download or a provenance-reviewed copy supplied from that download, with origin evidence retained. Do not bypass access controls, substitute a community mirror, scrape a partial web listing into an allegedly complete edition, or invent missing rows.

## Snapshot package and evidence contract

Prepare an immutable private package containing the original workbooks, their technical notes/notices, a manifest, normalized records, validation output, and mapping output. Local storage locations will be selected during implementation; none is created by this plan.

| Manifest field | Required evidence |
| --- | --- |
| Source identity | Publisher, landing URL, requested/resolved attachment URLs, file names, and access method |
| Edition | Official edition label, as-of date, release date, and any revision label |
| Acquisition | Actual retrieval timestamp with UTC offset, byte length, media type, and successful workbook-format validation |
| Integrity | SHA-256 per original file and normalized artifact; manifest digest approved for the import |
| Reuse | Terms URL, captured notice/terms evidence, attribution text, and transformation description |
| Extraction | Parser version, normalization-schema version, source worksheet/row references, and reviewed column mappings |
| Measured coverage | Counts per level, included/excluded row counts, identifier checks, relationship checks, and discrepancy references |
| Review | Validation outcome, reviewer/account reference, review time, approved digest, and unresolved issues |

Unknown values remain explicitly unknown. Do not fill checksums, retrieval timestamps, measured counts, or review fields with illustrative values. An edition label alone cannot identify immutable bytes; a different digest under the same label is a new revision requiring review.

Keep original source values alongside normalized fields. Deterministic output uses UTF-8, stable field order, stable sorting by level and identifier, and LF line endings. Store run timestamps separately from the reproducible normalized artifact. Reprocessing identical source bytes with the same parser/configuration must produce identical normalized bytes and digest.

## Extraction and relationship rules

The installed PHP runtime exposes ZIP, XMLReader, DOM, and mbstring. A bounded XLSX reader can therefore be evaluated without a new dependency, but its exact implementation and worksheet mapping cannot be finalized before inspecting the workbook. No dependency is installed by this phase.

The parser must validate ZIP/XML structure, worksheet relationships, shared/inline strings, and cell types. It must reject unsupported layouts with an explicit error. Never evaluate formulas, fetch external workbook links, resolve external XML entities, or extract archive entries outside the private workspace. Enforce archive, uncompressed-size, worksheet, row, and cell limits. Numeric identifier cells require verified lossless reconstruction from source formatting/correspondence; otherwise flag them for review rather than guessing leading zeroes.

Each normalized place requires an edition reference, canonical identifier string, official name, level, source row reference, and evidence-backed relationships. Preserve official city/municipality distinctions and relevant city classification/technical notes. Whitespace normalization may produce a display field, while raw labels remain available for auditing. Do not create spelling aliases or remove diacritics.

Determine administrative ancestry from explicit source relationships or a documented, reviewed hierarchical worksheet structure. Code-prefix slicing alone is forbidden. If parent relationships cannot be established from the acquired material, the affected records fail validation. A statistical association with a province must not become an administrative parent automatically. Region-only cities and other documented exceptions must remain representable without a fabricated province.

## Proposed persistence and activation model

| Proposed structure | Fields and constraints |
| --- | --- |
| Geographic dataset editions | Source/manifest digest, source edition, artifact locations, parser/schema versions, measured counts, validation/review state; immutable after review |
| Geographic places | Edition, canonical string code, official/raw/display names, level, source reference; unique canonical code within an edition |
| Geographic relationships | Edition, child and parent place references, relationship type, evidence; both endpoints must belong to the same edition |
| Identifier correspondences | Edition, canonical target, correspondence identifier, source reference, and applicability notes; permit multiple candidates pending review |
| Legacy mapping reviews | Coverage-row ID and fingerprint, target edition, candidate target(s), classification, evidence, decision, reviewer, timestamps, and superseded-review reference |
| Directory selection | Audited pointer to the reviewed edition used for new selection, with a version for concurrent changes |

These structures are an architecture proposal, not an exact migration approval request. The implementation phase must present column definitions, indexes, foreign keys, and the migration/rollback SQL before any production execution. Keep cross-edition relationships invalid through database constraints where supported and service validation on every write.

Stages are acquired, parsed, validated, reviewed, and selected. Validation never implies review, and importing never implies selection. A failure cannot change the selected edition. Future operations should be explicit: inspect a local snapshot, validate it, load a non-selected edition, produce mapping proposals, and select a reviewed edition. No proposed command runs in this phase.

Normal provider requests use the local selected edition and require no outbound lookup. New caches include dataset identity/digest, level, parent, and normalized query; old community caches cannot satisfy official-directory requests. Browser responses and form submissions carry the edition. Recheck it inside the save transaction and reject stale selections with preserved input and a clear reload/review action. Never silently substitute another edition on outage.

## Legacy mapping and historical preservation

Existing `scholarship_locations` remains the historical coverage snapshot. Additive nullable directory references and, for future scopes, saved province/barangay labels would require a separate migration proposal. Do not backfill or overwrite legacy codes/names during directory loading or selection.

Proposed dry-run categories:

| Classification | Evidence requirement and outcome |
| --- | --- |
| Exact | Unique official correspondence/canonical identity, compatible level, and matching known hierarchy/name context; proposal only |
| Renamed or administratively changed | Official change/correspondence evidence; retain old labels and require review |
| Moved | Official identity plus evidence of changed parent; record both old and new hierarchy, require review |
| Ambiguous | More than one supported candidate or insufficient evidence; no target is applied |
| Unmatched | No supported candidate; leave unresolved and retain the original coverage |

A record may have secondary change flags, such as both renamed and moved. A correspondence collision is ambiguous even if names resemble each other. Name similarity and length conversion cannot establish identity. Nationwide rows are recorded separately as not requiring geographic mapping.

Each report row records the original values, coverage/scholarship reference, source fingerprint, target-edition digest, proposed code and hierarchy, classification/flags, evidence references, and review status. A changed original row invalidates the proposal. Never infer a high confidence score from text similarity. Applying any mapping requires separate authorization and a transaction that rechecks the fingerprint and target edition.

Current scholarship saving deletes and recreates coverage rows. Integration must replace this behavior for unchanged legacy rows, retain their IDs and snapshots, and audit deliberate coverage changes. Submitted-application content locks, publication restrictions, provider ownership, and management-version checks remain enforced. A mapping must not retroactively broaden a scholarship's eligibility or public search coverage. Any link that changes discoverability needs explicit review of its coverage meaning.

## Dry-run and integrity acceptance criteria

The dry run reads a local artifact and outputs validation/mapping reports to an isolated location. It performs no database writes, production cache changes, source activation, scholarship changes, or queue work. Any optional database comparison is read-only and explicitly targeted. Loading staging tables is a separate operation, never hidden inside dry run.

Required checks before a complete edition may be reviewed:

- File/edition/manifest agreement; all source rows accounted for; no missing worksheet silently skipped.
- Canonical identifiers are lossless strings of the edition's verified format; duplicate canonical identifiers fail. Repeated names are reported with hierarchy and can be legitimate.
- Counts reconcile by level and region with the companion summary, with city subcategories reconciled without double-counting. Website reference totals are corroboration, not proof of a successful parse.
- Every required parent exists in the same edition, levels are compatible, there are no cycles, and valid absent-province cases are distinguished from missing parents.
- NIR, Bacolod, Sulu, province-independent cities, official corrections, and correspondence examples agree with the chosen edition. Preserve source technical notes, including exceptional classifications.
- All discrepancies have explicit disposition; incomplete relationships or unexplained totals block selection. Passing row-format checks alone cannot certify accuracy.
- Repeated import is idempotent by digest; interrupted loads leave no visible partial edition; conflicting same-edition bytes cannot overwrite reviewed content.
- Synthetic legacy fixtures cover every mapping category, correspondence collisions, moved parents, combined changes, missing labels, changed fingerprints, and rollback. No fixture data enters production.

Integration verification must cover nationwide/multi-location search, ancestor/descendant coverage rules, similar names, invalid parent chains, edition changes mid-form, empty results, offline behavior, and historical display. An ancestor-wide scholarship should match a selected descendant; selecting a broader place should find narrower coverage within it, without treating sibling places as equivalent. These proposed search semantics require approval before implementation. Province-independent cities must not be matched through an invented provincial relationship.

Use isolated SQLite tests and a separately created disposable MySQL database for constraints, transactions, identifier storage, and relevant collation behavior. Production MySQL is not a test target. Full backend tests, PHP formatting, TypeScript, production build, and responsive Chrome checks follow actual integration changes. Complete-edition integrity evidence remains separate from application tests.

## Rollout and rollback sequence

1. Review the obtained snapshot, manifest, parser mapping, full validation, and exact additive schema proposal. Keep the existing source selected.
2. With separate authorization, apply the additive migration and load the reviewed snapshot as non-selected data. Verify measured counts/digests against the dry run and retain import-run identity. Do not modify scholarship coverage.
3. Generate and review the mapping report against actual current records. A previous zero-row observation does not authorize assuming the database remains empty.
4. Deploy compatible reading/writing code and test isolated examples. Switch selection only through an explicitly approved, audited operation. Loading a new edition does not automatically migrate old coverage.
5. Apply only separately approved mappings, if any, recording old/new references and retaining original names/codes. Monitor validation errors and historical display.

Before rollout, record a tested database backup/restore procedure and a compatible application rollback release. Capture the previous directory pointer and retained snapshot digests. If staging import fails, leave the failed run non-selected; clean up only that isolated run after review. Never remove an edition referenced by coverage or review history.

After new-edition coverage exists, rollback must retain its tables and artifacts. Revert the selection for future writes only if the compatibility release supports both editions; otherwise pause new geographic writes while retaining read access. Do not return ten-digit selections to the old nine-digit-only adapter. Do not run a destructive down migration. Reversing a mapping restores the prior optional link through an audited, version-checked operation; it does not erase history or overwrite a later edit. A full database restore requires separate coordination because it could discard unrelated later activity.

## Proposed implementation boundaries

Future backend work includes directory/import/validation/mapping services, console commands, edition-aware lookup requests/controllers, and reviewed additive models/migrations. Integration would update `PhilippineLocations`, `ScholarshipManagement`, `SaveScholarshipRequest`, `ScholarshipLocationSearch`, catalog/admin serialization, location routes, shared TypeScript types, the provider picker, and shared location display.

Start provider integration with region, province, and city/municipality coverage. Import barangays only when source integrity supports them; exposing barangay coverage remains a separately approved capability. Preserve nationwide and up to 20 coverage rows. Public visibility, account geography collection, landing presentation, and unrelated workflows remain unchanged.

## Work performed and next approval

Phase 2 created this specification and linked it from the geographic report. Read-only checks confirmed the current adapter constraints, save behavior, PHP parsing extensions, and absence of a spreadsheet library among direct Composer requirements. Official publication and reuse pages were rechecked. No official file acquisition, import, dry run, mapping execution, migration, source activation, dependency change, `.env` change, or database write was performed. No application tests/builds were run because executable code is unchanged.

The specification is complete; the official snapshot acquisition and integrity gate is still open. Next approval should cover Phase 3 implementation of the importer/directory in isolation, including drafting migrations and tests, without production migration, source switch, or remapping. Full-edition validation and production authorization remain prerequisites for rollout. If workbook access remains unavailable, implement only the isolated machinery and fixtures; report source validation as pending rather than using invented official data.

## Subsequent implementation status

Phase 3's isolated normalized-package validator, SQLite staging importer, explicit-edition directory reads, and additive schema have now been implemented following approval. See [OFFICIAL-GEOGRAPHIC-PHASE3-REPORT.md](OFFICIAL-GEOGRAPHIC-PHASE3-REPORT.md) for the actual delivered subset and verification. This planning document remains the broader target: official workbook extraction, review/activation, legacy mapping, and UI integration are not implied by the isolated implementation. No production migration or source selection has occurred.
