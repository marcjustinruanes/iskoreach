# IskoReach official geographic upgrade — implementation and remaining blocker

Report date: 20 October 2026 (Asia/Manila, Philippines).

## Current outcome

The isolated directory/importer and read-only legacy-mapping machinery are implemented and tested. The instruction to finish superseded routine pauses between implementation phases. The complete official dataset upgrade is **not deployed or certified**: a fresh attempt to retrieve the PSA Q3 2026 publication still returned HTTP 403 with HTML rather than XLSX bytes.

The application continues using the existing community source. No production migration, source activation, coverage rewrite, `.env` change, credential change, dependency change, or production mail/queue processing occurred. Provider/public integration against the official edition remains pending actual acquisition and verification of that edition; wiring an unverified synthetic source into the website would not fulfill the accuracy requirement.

## Status against the requested phases

| Phase | Actual status |
| --- | --- |
| 1. Source verification and architecture | Official publication links and terms reviewed; complete workbook acquisition remains blocked |
| 2. Import specification | Complete; see [import plan](OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md) |
| 3. Directory/importer | Isolated normalized-package inspection, SQLite staging, edition-specific reads, and additive schema implemented; official XLSX extraction and full-source reconciliation remain pending |
| 4. Legacy mapping safety | Read-only proposal generation and stale-context checks implemented; no record is remapped and no persistent approval/application workflow is exposed |
| 5. Provider/public integration | Pending a verified edition and reviewed rollout; existing forms, search, visibility, and saved coverage remain active |
| 6. Verification | Application/CLI tests, typecheck, and build performed; no complete official import, production browser rollout, or MySQL write verification claimed |

The earlier [geographic audit](GEOGRAPHIC-DATA-REPORT.md) and [Phase 3 report](OFFICIAL-GEOGRAPHIC-PHASE3-REPORT.md) remain relevant historical records. This report records the subsequent mapping implementation and current completion limits.

## Legacy mapping delivered

Added `GeographicLegacyMapping` and `geography:mapping-report`. The service inspects a local checksummed normalized snapshot, indexes canonical and correspondence identifiers, and compares a selected legacy-coverage inventory. It does not import the target edition, require geographic tables in configured MySQL, query upstream APIs, or change scholarship records.

The command requires exactly one explicit inventory source:

```powershell
php artisan geography:mapping-report C:/path/to/package/manifest.json --coverage=C:/path/to/coverage.json
php artisan geography:mapping-report C:/path/to/package/manifest.json --current
```

`--coverage` reads a bounded local JSON list. `--current` explicitly reads the configured database's scholarship-location rows, including rows attached to retained scholarships. There is no implicit database inventory mode. Results are printed as JSON; any retained report should be stored privately. No official manifest is currently available, so these examples are not claims that an actual official mapping run succeeded.

Each proposal retains the selected original values, coverage/scholarship IDs, timestamps, original-value SHA-256 fingerprint, target manifest digest, candidate identifiers/hierarchy, evidence references, classification/flags, and unreviewed status. Only selected geographic context is exported; unrelated supplied fields are excluded. The reporter includes counts, sorts rows by ID, rejects duplicate inventory IDs, and honestly represents an empty inventory.

| Classification | Behavior |
| --- | --- |
| `exact` | A unique identifier/correspondence candidate agrees with available saved context; still requires review and is not applied |
| `renamed_or_changed` | A unique identity candidate has differing saved labels; an observed difference is not proof of a legal rename |
| `moved` | Identity and old region are individually resolvable, but the candidate's region differs; retains additional label-change flags where applicable |
| `ambiguous` | Multiple identity candidates, unresolved old region, missing saved label, or inconsistent nationwide context; no single proposed target |
| `unmatched` | No identifier evidence or an unsupported legacy scope; names are not used to manufacture a match |
| `nationwide` | No identifier mapping is needed; the coverage remains nationwide |

Colliding correspondence identifiers remain ambiguous even when a name or region might appear to narrow them. Codes are never padded, truncated, or inferred from names. Name comparison only normalizes case and whitespace after an identifier match; it does not create aliases or transliterations.

Every report states `authority_verified: false`, `source_row_reconciliation_verified: false`, and `apply_available: false`. Synthetic inputs remain labeled synthetic. Source references are evidence to review, not certification that a claimed workbook row was extracted faithfully.

## Review path, history, and rollback

Reviewers must first verify the official snapshot's provenance/extraction, then review each proposal's original values, evidence, proposed hierarchy, and effect on coverage. Exact proposals are not automatically approved. Ambiguous/unmatched entries require additional authoritative correspondence or explicit provider correction through the existing permitted workflow; never guess a replacement.

`GeographicLegacyMapping::isCurrent` compares both the original-row fingerprint and target manifest digest. A changed timestamp, saved value, or target manifest invalidates the proposal. This is a reusable check for a future separately authorized application workflow, not an exposed write action or persistent review ledger.

No mapping application exists in this delivery, so there is no mapping write to reverse. Existing labels/identifiers and scholarship-location IDs remain intact. The [import plan](OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md) describes the required future backup, additive migration, compatibility release, reviewed mapping ledger, and rollback sequence. Its safeguards remain necessary before any production rollout.

## Changed files in this continuation

- Added `app/Services/GeographicLegacyMapping.php`.
- Added `app/Console/Commands/ReportGeographicMappings.php`.
- Added `tests/Feature/GeographicLegacyMappingTest.php`.
- Added this report; updated the geographic audit and main progress report.

No additional migration was created for mapping. The Phase 3 directory migration remains unapplied to configured MySQL.

## Verification actually performed

Ten new mapping tests passed with **43 assertions**. Together with the Phase 3 directory tests, the targeted run passed **29 tests, 102 assertions**. Tests cover exact proposals, saved-label differences, moved parents and combined changes, correspondence collisions, unsupported/unmatched codes, missing labels, unresolved hierarchy, nationwide context, changed row/edition fingerprints, empty inventories, duplicate IDs, explicit CLI input selection, selected-context export, and unchanged persisted coverage after a current-database report.

The final full backend suite passed **267 tests, 4,722 assertions**. `php vendor/bin/pint --dirty --format agent`, `npm run typecheck`, and `npm run build` passed. CLI registration inspection found `geography:inspect`, `geography:stage`, and `geography:mapping-report`. There were no frontend or HTTP route changes, so a new browser run was not performed.

Feature tests use in-memory SQLite and temporary synthetic packages. Their success is not proof of MySQL write behavior, complete PSA data accuracy, an official extraction, or a live website source switch.

A read-only configured MySQL check again found zero scholarships, scholarship locations, applications, notifications, and jobs. The geographic dataset table was absent, confirming the directory migration was not applied. No synthetic place records were inserted into configured MySQL.

## Exact external dependency still outstanding

The fresh request to `https://psa.gov.ph/system/files/scd/PSGC-3Q-2026-Publication-Datafile.xlsx` returned **HTTP 403**, `text/html`, 5,598 bytes, beginning with HTML rather than the ZIP/XLSX signature. No official data file or dataset checksum was saved from that response. This was a remote access response, not an automatic-approval rejection.

Completion of the official upgrade requires legitimate access to the actual edition and companion material, verified extraction and parent relationships, complete counts/reconciliation, and reviewed production rollout. Until those prerequisites are satisfied, official website integration and dataset activation remain unfinished. The reports deliberately do not label the whole upgrade complete merely because isolated code passes tests.
