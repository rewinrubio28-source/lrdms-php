# Section 5 — Reporting system

Implemented and locally verified on 2026-10-02. Production scheduling, physical printing and panel acceptance remain to be demonstrated.

## Checklist mapping

| Criterion | Implementation | Evidence / limit |
|---|---|---|
| Custom Reports | Reports page lets authorized users choose a title, metadata columns, individual records or grouped counts by type/status/source/creation month. | Shared builder reconciled against role-scoped temporary-table fixtures. Up to 1,000 matching registered records; larger results are rejected with a request to narrow filters, never silently truncated. |
| Report Filters | Creation dates, document type, current status and document-number/title search; ascending/descending sort for individual records, group-label sorting for grouped reports. | Inclusive end dates, deterministic ordering, type/search filters, grouped totals and invalid parameters tested. Columns for individual records do not change grouped count output. |
| Report Branding | Native PDF includes the existing Manila seal, organization heading, report title, filter/generation metadata, footer and page numbers. | Synthetic 122-record PDF rendered to 16 landscape A4 pages; first/last pages inspected and footer/page numbers confirmed on every page. Native Excel/CSV include organization and filter metadata as text. |
| Scheduled Reports | Owner-controlled daily/weekly/monthly schedules generate private PDFs via a supervised worker; generation history and downloads are on Reports. | Real PDF generation, schedule periods, duplicate-slot prevention, worker locking, disabled-owner handling, failure recording and bounded crash retries tested. User selected secure in-app delivery. The panel checklist asks for **Email Logs**; this implementation sends no email. Submit generation history/worker logs as proposed alternative evidence and obtain panel acceptance before marking that evidence requirement complete. |
| Print Functionality | Dedicated print view with hidden controls, repeating table headings/footer and page-break rules. | Headless Chrome printed all 122 fixture records across 13 pages, without controls or the original footer overlap. Physical paper output, other browsers and printer settings remain unverified. |

## Access and data definitions

- Preview requires an existing repository visibility permission and respects the same record scope as the repository. Only verified/registered records are included.
- Export/scheduling requires `repository.download`; the dedicated print view requires `repository.print_record`. Read-only roles do not gain download rights through the new page.
- Filters use creation dates and current metadata. These reports do not reconstruct historical record states. A new export queries the current database and may differ from an earlier preview if records changed.
- Stored PDFs belong to the schedule owner. The download endpoint checks the signed-in account, owner, retention, current export permission and current visibility of **every** included record. A permission change that hides even one included record blocks the old snapshot; generate a new report instead.
- Column and sort identifiers are allowlisted. HTML values are escaped. CSV formula-like strings receive a leading apostrophe; XLSX writes text cells literally. PDF generation disables remote resource fetching, JavaScript and embedded PHP.
- Preview/history are private, no-store pages. Each schedule mutation uses CSRF protection and ownership checks. Maximum ten schedules per account.

## Schedule behavior

All schedules use **Asia/Manila** time. Daily reports cover yesterday; weekly reports run Monday and cover the previous Monday–Sunday; monthly reports run on the first day and cover the previous calendar month. These periods replace the preview dates; the other filters, columns and grouping are preserved.

Schedules begin at the next future occurrence of the chosen time. Pause/resume/remove controls are available to the owner. A run already in progress may finish when a schedule is paused. Removing a schedule deletes its generated reports through the schema's foreign-key cascade.

The worker polls every 30 seconds while idle and handles one schedule at a time. A database named lock prevents overlapping workers, and a unique schedule/time slot prevents duplicate generation. An interrupted Processing slot is retried at most three times. Ordinary render/query failures are recorded as Failed and advance to the next scheduled period. Inactive owners, forced password changes and lost reporting permissions produce Skipped runs.

After downtime, one overdue run covers the latest completed period, then advances to the next future occurrence. Earlier missed periods are skipped; this is not a full backfill service. Owner history shows the latest 100 runs within 30 days. The worker deletes older run data, and downloads reject expired runs even if cleanup has not run. PDFs are stored in the database, not publicly addressable files; each is limited to 8 MB.

## Executed tests

```powershell
C:\xampp\php\php.exe -d extension=gd database/test_record_reports.php
C:\xampp\php\php.exe database/test_report_access.php
```

Both suites use connection-temporary application tables and synthetic users/records. No real reports were emailed or real accounts modified.

- **35 report/scheduler assertions:** scope and sort reconciliation, grouping, invalid options, unchecked columns, CSV/Excel output, print branding, permission revocation, time calculations including leap year, real scheduled PDF generation, idempotent slots, pause, disabled owner, renderer failure, cross-owner mutation rejection, concurrent-worker lock, bounded crash recovery, multipage samples and explicit over-limit rejection.
- **13 endpoint assertions:** unauthenticated/forced-password access, HTTP method, read-only export denial, print permission denial, successful CSV export, owner PDF access, other-owner/expired/revoked snapshot denial, escaped preview, CSRF rejection and schedule creation.
- PHP syntax checks passed. Synthetic desktop Reports UI, first/last PDF pages and corrected browser print pages were visually inspected.
- The additive migration was applied locally and rerun successfully; the idle `report_worker.php --once` smoke check exited successfully. The migration does not load browser session helpers.
- Standalone artifact checks found all 122 rows in CSV/XLSX and both PDF/print output; ZIP integrity passed. Native PDF footer/page numbers appeared on all 16 pages. Browser print footers appeared on all 13 pages, with controls omitted.

Generated samples are ignored local files under `.runtime/report-test/`: `records.pdf`, `records.xlsx`, `records.csv`, `print.html` and `browser-print-final.pdf`. They are synthetic development evidence, not official reports.

## Deployment

This section adds `report_schedules` and `report_runs`. `database/migrate_reports.php` creates these tables without replacing existing records. It is included in `database/upgrade.php`, so the normal Docker startup applies it before Apache and workers start. There is no new SQL-file packaging exception and no new Composer library.

Rebuild/redeploy the image to activate `[program:report-worker]` in `deploy/supervisord.conf`. The worker runs as `www-data` and acquires the existing maintenance lock per job, releasing it while idle. Existing coordinated database backups include schedules and stored PDFs; account for the added storage.

For local XAMPP, apply `php database/migrate_reports.php` once and enable PHP GD for the seal in generated PDFs. Start `php bin/report_worker.php` for continuous scheduling, or `php bin/report_worker.php --once` for one due-job pass. A one-shot pass does not override future scheduled times. Docker already includes GD and installs the existing Composer dependencies.

## Remaining live evidence

1. With an authorized test account, compare filtered/grouped previews and PDF/Excel/CSV output with the matching database records.
2. Create a daily schedule a few minutes in the future. Verify the private PDF, Ready history entry and `report-worker` log after the scheduled time. Check the stated previous-day period.
3. Verify another account cannot download that run. Revoke visibility/export permission and confirm an existing snapshot becomes unavailable.
4. Pause/resume and remove a test schedule; verify expected future execution and removal of its generated history.
5. Print a multipage sample on the panel printer. Confirm landscape A4, repeating headings/footer, all rows and no clipping.
6. Present worker/history evidence as the agreed in-app alternative to the checklist's email-log example. Do not claim email delivery was implemented or tested.

The supervisor/container lifecycle and physical printer have not been exercised locally; Docker is unavailable in this development environment.
