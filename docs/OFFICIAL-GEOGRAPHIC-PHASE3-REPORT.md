# IskoReach official geographic directory and importer — Phase 3

Report date: 20 October 2026 (Asia/Manila, Philippines).

## Delivered scope

Implemented isolated directory storage, normalized-package inspection, and guarded staging imports following approval of Phase 3. The existing geographic source and provider/public UI remain in use. There is no activation command, production importer, automatic mapping, or new eligibility behavior.

The official workbook was unavailable during the earlier source review. No official download was retried in this phase, and no complete official dataset was imported or certified. The implementation uses synthetic isolated fixtures and explicitly reports `authority_verified: false`, `source_row_reconciliation_verified: false`, and `activation_available: false` even when structural checks pass.

This phase implements the normalized-package machinery described in [OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md](OFFICIAL-GEOGRAPHIC-IMPORT-PLAN.md). It does not pretend to know the unavailable workbook's worksheet layout. Official XLSX-to-record extraction, full source-row reconciliation, technical-note preservation, official edition count reconciliation, and source review remain pending acquisition of the actual files.

## Package inspection

`php artisan geography:inspect <manifest.json>` reads a local package and prints structural validation as JSON. It performs no database writes, HTTP requests, cache changes, migration, or source selection. Invalid input returns a failed command status.

The version-1 manifest requires:

- `schema_version: 1`, an edition label, publisher, HTTPS source and terms URLs, attribution, transformation description, and parser version.
- Exact `as_of`/`released_on` calendar dates and a valid `retrieved_at` timestamp with timezone.
- `source_kind`, restricted to `synthetic` or `official_candidate`; neither is a certification.
- A nonempty `files` list with local filenames and SHA-256 digests, plus a `records` file descriptor with its digest.
- Integer `expected_counts` for all five levels: region, province, city, municipality, and barangay. Zero counts are permitted for partial synthetic fixtures. Caller-supplied matching counts do not prove official completeness.

Artifacts must be plain filenames in the manifest's directory. Traversal, outside-package targets, missing/empty files, invalid JSON, and digest mismatches are rejected. Limits bound manifest size, artifact sizes, row count, source-file count, and correspondence count. Record JSON is parsed from the bytes whose digest was checked.

Normalized records require a ten-digit string `code`, supported `level`, name, explicit nullable `region_code`, `province_code`, and `city_code`, and `source_ref` in `filename#worksheet-or-row-reference` form. Correspondences contain a nine- or ten-digit string code and source reference. References must identify a declared source artifact; this validates reference structure, not the truth of the claimed source row.

Names retain raw spelling alongside whitespace-normalized display names. Canonical codes remain strings, including leading zeroes. Duplicate canonical identifiers fail. Repeated names are allowed and reported with their distinct hierarchy. Output sorting and a normalized-data digest make record-order changes reproducible.

Parent checks require the explicit referenced region/province/city to exist at the correct level. Parent region context must agree, and barangays must agree with their city's province context. Regions cannot have parents; the allowed level structure prevents cycles. Region-only cities remain valid without an invented province. No code-prefix ancestry, fuzzy alias, spelling correction, or guessed identifier conversion is used.

For `official_candidate` packages, original files must be bounded ZIP containers with expected XLSX entries. Container checks reject HTML block pages and unsafe archive paths without extraction. This is deliberately a container preflight, not an XML worksheet parser or proof of official provenance. It never evaluates formulas, executes workbook content, or follows external links.

## Isolated staging and directory reads

`geography:stage` requires an explicit SQLite file directly under `storage/app/private` named `geographic-staging-NAME.sqlite`. It never defaults to the configured database, rejects other drivers/paths, and rejects database symlink redirection. An explicit `--initialize` flag creates a new file and applies only the geographic migration there; existing files cannot be overwritten by initialization.

Example invocation, using a separately prepared and reviewed package:

```powershell
php artisan geography:inspect C:/path/to/package/manifest.json
php artisan geography:stage C:/path/to/package/manifest.json --database=C:/Users/LENOVO/OneDrive/Desktop/sdg/storage/app/private/geographic-staging-review.sqlite --initialize
```

These are usage examples; they were not run against an official dataset. Do not run normal `php artisan migrate` against configured MySQL without the separately required approval.

Imports are transactional and stored only as `validated_staging`. Identical manifest bytes under the same edition return the existing dataset. Different bytes under an existing edition fail instead of overwriting it. A failed insert rolls back its dataset and rows. No scholarship coverage is touched. Source artifacts remain in the caller's package; this staging tool does not yet archive an immutable evidence bundle for production retention.

`GeographicDirectory` requires an explicit dataset ID for every read. It provides level/parent-filtered choices and correspondence candidates. Similar names retain distinct identifiers; province-independent cities are not returned through a fabricated provincial relationship. Correspondence collisions remain multiple candidates and never choose a mapping automatically. No HTTP route or existing form calls this service yet.

## Additive schema prepared, not applied to MySQL

Migration: `database/migrations/2026_10_20_134208_create_geographic_directory_tables.php`.

| Table | Implemented fields and constraints |
| --- | --- |
| `geographic_datasets` | ID, unique edition label, unique manifest digest, source kind, staging state, manifest/validation JSON, timestamps |
| `geographic_places` | ID, dataset FK, ten-character code, level, normalized/raw names, source reference, nullable region/province/city code references; unique dataset/code; indexed parent/level lookups |
| `geographic_correspondences` | ID, dataset and canonical target, legacy identifier, source reference; unique dataset/legacy/target combination |

Composite parent and correspondence foreign keys bind targets to the same dataset. Restrict-on-delete constraints preserve referenced directory data. Service validation supplies level/context rules that ordinary foreign keys alone cannot enforce. Canonical code syntax is validated by the inspector, not a MySQL-specific check constraint.

The migration's `down()` removes only these new tables in dependency order. It exists for isolated migration lifecycle use; it is not an approved production rollback procedure. Once references exist, retain datasets and use the compatibility approach in the import plan. No scholarship-table columns or existing records are changed by this migration.

The larger planned review ledger, source-selection pointer, coverage references, and legacy-mapping records are not created in this phase. The three-table implementation stores explicit structural parents; statistical associations and richer source technical notes require the reviewed extraction contract before production use.

## Files

- Added services: `GeographicSnapshot`, `GeographicImporter`, and `GeographicDirectory` under `app/Services`.
- Added commands: `InspectGeographicSnapshot` and `StageGeographicSnapshot` under `app/Console/Commands`.
- Added guarded read models: `GeographicDataset`, `GeographicPlace`, and `GeographicCorrespondence` under `app/Models`.
- Added the migration above and `tests/Feature/GeographicDirectoryTest.php`.
- Added this report and updated geographic/import-plan/progress reporting.

No new dependency, `.env` setting, credential, active source, provider form, public layout, or application rule changed.

## Verification actually performed

Nineteen new feature tests passed with **59 assertions**. They cover read-only inspection, normalized digest reproducibility, repeated-name reporting, string/leading-zero preservation, idempotent staging, edition conflicts/isolation, province-independent cities, ambiguous correspondence candidates, duplicate identifiers, missing/cross-region/wrong-level parents, invalid barangay context, count mismatches, changed artifact bytes, traversal, missing evidence references, non-XLSX official candidates, insertion rollback, referenced-parent constraints, and staging-command path/initialization guards.

The staging command was exercised with a fresh disposable SQLite file, including directory-only migration, repeat import, and refusal to overwrite initialization. It created no scholarship table in that file. Temporary fixture packages and staging files were removed by tests.

The final full backend suite passed **257 tests, 4,679 assertions**. `php vendor/bin/pint --dirty --format agent`, `npm run typecheck`, and `npm run build` passed. CLI registration inspection confirmed both new commands. No new browser run was needed because no HTTP route or frontend integration changed. No MySQL write tests were performed: the project test harness explicitly requires in-memory SQLite, and configured MySQL was kept read-only.

A final read-only configured MySQL check confirmed zero scholarships, scholarship locations, applications, notifications, and jobs. All three new geographic tables were absent. The new migration therefore remains unapplied there. No real queue jobs or mail were processed.

## Remaining work and approval boundary

Phase 3's isolated machinery is implemented. Official dataset acquisition/extraction/integrity verification remains pending. Passing synthetic tests does not certify the PSA edition or nationwide data accuracy.

The next phase can implement a read-only legacy mapping report with isolated fixtures for exact, renamed/changed, moved, ambiguous, and unmatched cases. It must retain fingerprints and evidence without changing coverage. Provider/public integration, reviewed activation, and production migration/remapping require their later approvals. No source switch is requested as part of this delivery.
