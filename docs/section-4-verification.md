# Section 4 — Data interoperability

Implemented and locally verified on 2026-10-02. This records development evidence; production deployment and a real browser upload demonstration remain pending.

## Checklist coverage

| Item | Implementation and evidence |
|---|---|
| CSV import | Document Intake → Import dataset. UTF-8 comma-separated files with template headers; BOM, quoted commas, quotes and multiline values tested. |
| Excel import | Native `.xlsx` package/XML reader with bounded sizes; one worksheet with literal values. Downloadable Excel template round-trips through the parser. Legacy `.xls`, formulas and macros are rejected. |
| JSON import | Uploaded UTF-8 JSON array of record objects, validated against the same field rules as CSV/Excel. No need for an API key in the authenticated import UI. |
| Invalid file detection | File size and actual content are checked, not just extension. Malformed JSON/CSV/XML, binary text, invalid workbook structures, formulas, entities, external workbook links, expansion limits, invalid fields/dates/statuses and unauthorized authority fields are rejected. This evidence applies to dataset imports; it is not a malware scan of arbitrary document attachments. |
| Bulk upload | Up to 1,000 independent metadata records per batch / 5 MB uploaded file. All records, integration receipts and audit events commit together or roll back. A metadata dataset is not a ZIP of document attachments. |
| Export accuracy | Audit CSV no longer has the 1,000-row cap. Matching rows use a database cursor and temporary output file, UTF-8 BOM, deterministic timestamp/ID order, CSV quoting and formula neutralization. Endpoint test exports all 1,005 matching rows and excludes a nonmatching row. Dashboard PDF/XLSX/CSV evidence remains in the Section 3 report. |

## Import contract

Required fields: `doc_number`, `title`, `source_system`.

Optional fields: `doc_type`, `source_record_id`, `status`, `source_status`, `classification`, `sponsor`, `enactment_date`, `council_term`, `originating_office`, `originating_division`, `submitter_position`, `responsible_custodian`, `related_legislative_item`, `source_status_date`, `body`, `ocr_text`.

- All fields are text, except JSON `council_term` also accepts an integer. Keep Excel document numbers and dates as text to preserve leading zeros and prevent date serial conversion.
- Dates use `YYYY-MM-DD`; `source_status_date` uses `YYYY-MM-DD HH:MM:SS`. Excel numeric date serials are deliberately not guessed.
- Types: Ordinance, Resolution, Committee Report, Minutes, Other. Legislative statuses: Draft, Submitted, Under Review, Enacted, Amended, Rejected. Source status and explicit status must agree when both use those supported values.
- Classification: PUBLIC, INTERNAL, RESTRICTED, CONFIDENTIAL. Even a PUBLIC classification does **not** grant public visibility during import.
- The first row of CSV/Excel contains exact field names. Missing optional columns are allowed; duplicate/unknown headers and extra CSV fields are rejected. JSON is a top-level array of objects.
- Identify the existing external source system. `Manual Encoding` is not accepted. No ownership, registration timestamps, permission flags, attachment paths or version relationships can be imported.
- The authenticated user needs `encoding.create`; imported records belong to that user, remain private and enter Pending Validation. Registration still uses the existing review permissions. A dataset does not bypass the review queue.
- Preview is stored on the server, bound to the user/session, expires after 15 minutes and requires CSRF plus a one-time preview token for confirmation. Browser retries after success cannot reuse the consumed preview.
- Duplicate document numbers are checked at preview and again when saving. A duplicate anywhere in the batch cancels the batch, including case-equivalent numbers under the database collation. No existing record is overwritten. Concurrent writes from other integration endpoints are not a tested global deduplication guarantee; the existing schema has no unique document-number constraint.
- Limits: 5 MB upload, 1,000 records, 60,000 bytes per body/OCR text field. XLSX has package entry, expanded-size and XML complexity limits. Large datasets should be split into batches; this is not an unlimited background importer.

## Executed local verification

Run against a local test-capable database:

```powershell
C:\xampp\php\php.exe -d extension=zip database/test_dataset_import.php
C:\xampp\php\php.exe -d extension=zip database/test_dataset_access.php
```

Tests shadow application tables with connection-temporary tables. They do not add real accounts, records or audit entries. Synthetic files are under ignored `.runtime/import-test/`.

- Parser, transaction and CSV tests: successful format equivalence, malformed/oversized files, Unicode/leading zeros, forbidden metadata, workbook defenses, 1,000 records, duplicate rollback, injected database failure rollback, denied role and complete 1,001-row CSV output.
- Endpoint tests: unauthenticated redirect, unauthorized role, invalid CSRF, expired/replayed/wrong preview tokens, successful confirmation with preview consumption, downloadable template round-trip, escaped preview rendering, filtered 1,005-row export and malformed filter rejection.
- Local temporary-table 1,000-record import took approximately 3–4 seconds during development. This excludes browser upload time, real network latency and production concurrency; it is not a production capacity claim.
- PHP syntax checks and `git diff --check` passed. A synthetic desktop preview was rendered and inspected in headless Chrome. Actual multipart browser upload, mobile interaction and production export duration still need live demonstration.

## Deployment and panel demonstration

No new database migration or Composer library is required. Rebuild/redeploy the Docker image: it now installs PHP `zip` for XLSX reading. Local XAMPP needs `extension=zip` enabled in the active `php.ini` (restart Apache after changing it). Existing Section 3 Composer dependencies provide Excel template generation.

1. Open Document Intake → Import dataset using an authorized test account.
2. Download each template. Replace sample numbers with distinct test identifiers and use the agreed source system. Upload, inspect the preview, then confirm.
3. Verify all imported records appear privately in Incoming records, with matching metadata, receipts and audit events. Confirm that a reviewer still has to register them.
4. Try malformed files, a duplicate document number and an invalid date. Verify the displayed error and unchanged record count. Cancel a preview and confirm that it saves nothing.
5. In a test dataset with more than 1,000 matching audit entries, apply filters, export, and reconcile every row against the matching database result. The response also includes `X-Export-Row-Count`.
6. Save screenshots, real upload/export timing and the panel's acceptance of the supported formats/batch limits. Do not run bulk test imports into production merely to populate evaluation evidence.

For export safety, cells starting with spreadsheet formula characters receive a leading apostrophe. This is intentional; account for it when comparing the CSV to original text values.
