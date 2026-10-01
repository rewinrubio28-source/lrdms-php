# Section 3: operational analytics and dashboards

Updated 2026-10-01. Implemented and locally tested; final panel evidence should include the live deployment demonstration below.

## Checklist mapping

| Requirement | Implementation | Evidence / limit |
|---|---|---|
| Real-Time Dashboard | Metrics, chart, recent records, search activity and audit panels refresh through a session-authenticated endpoint every 15 seconds while the tab is visible. Manual refresh is available. | JS interaction tests cover hidden-tab pause, overlapping requests, successful refresh, errors and expired-session clearing. This is periodic polling, not instantaneous push. |
| Dashboard Accuracy | Page, refresh and report exports share `dashboard_snapshot()`/`dashboard_data()`. Each summary reads within a repeatable-read transaction. | Temporary-table fixtures reconcile totals, document types, status groups, month counts and role visibility. Exports reflect their own generation time; a later export can include records added since the last screen refresh. |
| Interactive Charts | Date range, type and current-status filters; clickable month bars and document-type labels lead to paginated matching records. | Filter validation and drill-down count agreement tested. Months with zero records are included. Desktop synthetic page visually inspected in headless Chrome. |
| Historical Reports | Monthly creation history and filtered period summaries, including older periods selected with the date controls. | Inclusive end-day boundaries tested. Maximum 60 calendar months per request; select another range to inspect older data. These are current-state records grouped by creation date, not stored snapshots of past status/permissions. |
| KPI Monitoring | Visible registered records, enacted/rejected/public counts, pending intake, digitization needs, revisions, searches and current accounts. | Role-specific fixture tests pass. Search users without audit access see only their own search history. Administrative account totals retain the existing directory exclusion of Super Admin. |
| Report Export | Native PDF, XLSX and UTF-8 CSV summaries generated from the same filtered data. All summary rows are exported. | PDF rendered and text inspected; XLSX ZIP/XML parsed; CSV row counts checked against XLSX. CSV formula prefixes are neutralized and XLSX strings are forced to literal text. No claim of an all-module document-detail export. |

## Metric definitions

- Default period: first day of the month eleven months ago through today (Asia/Manila).
- Registered records: `verified_at IS NOT NULL`, within the creation-date range, matching type/status and the existing document visibility rules. Historical versions remain separate records as in the existing repository.
- Awaiting verification: unverified external intake in the selected range, visible to the user, shown only to roles with encoding permission. It is not included in registered record totals.
- Enacted/public enacted counts use current legislative status. Public/all-status and not-public counts describe the selected registered records.
- Revision records have a previous version link. Original records have no previous version link; they can have subsequent revisions. The old “Single-version” label was inaccurate and is replaced.
- Current revision heads have a previous version but no next version. Per-head version counts in this dashboard include visible ancestors within the selected period.
- Search and recent audit activity use their event dates. Document-type/status filters apply to records, not to unrelated activity logs. Search logs are limited to the signed-in user unless that role can view audit activity.
- User totals describe current directory accounts and are not date-filtered. The dashboard labels that distinction.

## Files and endpoints

- `dashboard.php`: page shell, filters and shared summary.
- `api/dashboard.php`: GET-only authenticated JSON refresh with no-store caching. Returns 401 for missing/expired sign-in or pending forced password change; 422 for invalid filters.
- `dashboard_records.php`: role-scoped drill-down, 50 records per page.
- `api/export_dashboard.php`: GET-only authenticated PDF/XLSX/CSV summary export; server derives all figures and permissions. Export events are audit logged.
- `includes/dashboard_filters.php`, `dashboard_data.php`, `dashboard_panels.php`, `dashboard_controls.php`, `dashboard_reports.php`: shared validation, queries, views and serializers.
- `assets/js/dashboard-live.js`: visibility-aware polling, request cancellation, stale-data messaging and matching export/drill-down links.

## Executed tests

```powershell
php database/test_dashboard.php
php database/test_dashboard_access.php
node database/test_dashboard_live.js
php database/test_records_role_policy.php
php database/test_user_visibility.php
composer audit --locked --no-interaction --format=json
```

- Dashboard fixture suite: 21 checks pass, including six invalid-filter cases. Database writes are connection-local temporary tables only; no real account/document changes.
- Access tests: four unauthenticated/unsupported-method endpoint checks pass without returning report data.
- JS tests pass for paused hidden tabs, one in-flight poll, fresh rendering, successful-filter URLs, stale-data preservation, session expiry and stopped polling.
- Existing records-role-policy and user-visibility regression suites pass.
- PHP syntax checks, JavaScript syntax check and `git diff --check` pass.
- Composer locked audit: no advisories or abandoned packages reported after adding Dompdf 3.1.6 and SimpleXLSXGen 1.5.17.
- Synthetic CSV/XLSX/PDF saved under ignored `.runtime/dashboard-test/`. XLSX archive integrity, parsed metrics, zero-count month, CSV row-count parity and absence of formula nodes verified. PDF successfully opened with PyMuPDF; expected KPI/month text and the first-page layout were inspected.
- `php database/test_dashboard.php --preview` creates a synthetic HTML preview using the real dashboard templates. A headless Chrome desktop screenshot was inspected, and a previously missing chart bar color was corrected. This is not an authenticated live-server browser test.

## Deployment and panel demo

No new database migration is required for Section 3. Composer files changed: rebuild/redeploy the PHP application image so the PDF/XLSX libraries are installed. For a non-Docker installation, run `composer install --no-dev`. Existing deployment upgrades and Section 2 MFA prerequisites still apply.

1. Open Dashboard and choose a date range/type/status. Compare counts with the matching-record list.
2. Open another authorized browser/session and add/register a designated demo record. Confirm the dashboard updates within the next successful 15-second poll without page reload.
3. Choose a period containing an empty month; show the zero count and open a month/type drill-down. Test pagination with more than 50 matching records.
4. Sign in with a restricted role; verify private records and other users' searches are absent. Sign out/revoke the session and confirm the next refresh removes dashboard data.
5. Export PDF, Excel and CSV for the same filters. Open them in the panel's actual PDF/spreadsheet applications and compare metadata, totals and all monthly rows. Record generation timestamps if data changes between exports.
6. Capture live screenshots and sample files. Production load testing, full mobile/accessibility evaluation, report scheduling and general custom reports belong to the remaining verification/sections; they are not certified by these local tests.

The audit-log CSV endpoint's older 1,000-row cap is unchanged. Section 3 introduces dashboard summary exports; audit-export completeness remains a separate finding to resolve.
