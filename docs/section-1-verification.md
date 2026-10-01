# Section 1: core capabilities — implementation and evidence

Updated 2026-10-01. This is an engineering verification record, not final panel approval. Database tests below use connection-local temporary tables; existing accounts/documents were not modified. Earlier uncommitted authentication/email changes were preserved.

## Completed changes

- Both external endpoints enforce supported HTTP methods and shared-key authentication, returning JSON for request rejection. Missing key configuration returns 503.
- Search rejects array/non-string queries, invalid modes, and queries over 500 characters with 422.
- Intake accepts only JSON objects or multipart requests; unsupported media types return 415 and malformed JSON returns 422.
- Public API search now excludes unverified records as well as private and pre-filing records.
- Semantic search validates service result IDs and falls back safely for unavailable/invalid responses. A three-second connection timeout bounds failed connection setup (30-second total timeout remains).
- API responses expose requested/effective search modes and fallback state. The internal search page displays a fallback notice; search logs record the effective mode.
- Added API contract, authentication workflow and public-search recovery regression tests, plus a read-only AI health checker.
- Updated API documentation and stale README claims.

## Executed evidence

| Command/check | Observed result | Coverage/limit |
|---|---|---|
| `php database/test_record_workflow.php` | PASS | Intake validation, correction, registration, version preservation/linking, duplicate registration, stale revisions, permission denial and transaction rollback. Not a complete browser CRUD test. |
| `php database/test_auth_workflow.php` | 12 checks PASS | Unknown user, lockout/expiry, login, session revocation, MFA gate, reset-code expiry/reuse, reset-token reuse, new-password login, logout. No SMTP delivery or browser cookie test. |
| `php database/test_api_contract.php` | 10 check groups PASS | JSON/query validation and eight endpoint rejection cases (401/405/415/422). Uses PHP endpoint execution, not a deployed HTTP proxy. |
| `php database/test_search_recovery.php` | PASS | Actual search endpoint with temporary records: privacy/verification filtering, amended record retrieval, unavailable AI fallback, metadata and search logging. |
| `php database/test_ocr_jobs.php` | All queue checks PASS | Duplicate clicks, claiming/progress, completion, failed attachment preservation, retries, stale worker recovery, concurrent text/attachment changes. Uses simulated extraction. |
| `php database/test_storage_integrity.php` | PASS | Original bytes cannot be overwritten; revisions stored separately. |
| `python -m unittest discover -s ocr_service -p "test_*.py"` | 4 tests PASS | OCR text layout; not image recognition accuracy. |
| PHP lint on changed application/test files | PASS | Syntax only. |
| `git diff --check` | PASS | Whitespace check. |
| `php bin/check_core_services.php` | FAIL for both services | Configured BERT and OCR health requests returned HTTP 0 (no HTTP response) from this environment. This does not distinguish stopped services, routing or environment network restrictions. |

## Panel checklist status

| Item | Current status | Remaining evidence |
|---|---|---|
| End-to-End Workflow Logic | Backend regression tests pass | Browser walk-through from incoming record to review/registration, search/download and amendment. |
| User Authentication Workflow | Backend tests pass | Real inbox code receipt, browser login/logout/reset, cookie behavior and session revocation across two browsers. |
| CRUD Operations | Partial evidence | Exercise create/read/update/archive for each major module with allowed and denied roles. |
| AI Integration | Not fully verified | Reachable real services, representative OCR/semantic accuracy and processing-time report. |
| IoT Integration | N/A proposed; user confirms outside approved scope | Panel acceptance of justification below. |
| API Integration | Validation/search regression tests pass; docs updated | Deployed HTTP success/error/multipart tests and infrastructure failure behavior. |
| Offline Synchronization | N/A proposed; user confirms outside approved scope | Panel acceptance of justification below. |
| Background Processing | Queue/recovery regression tests pass | Actual worker logs processing a real document while the browser is closed. |
| Error Recovery | Tested scenarios pass | Deployed upload/storage/database interruption scenarios; orphan-file cleanup is not covered by current tests. |
| Scalability | Not verified | Concurrent-user workload measurements with representative database size. |

## Scope justifications

**IoT Integration — N/A:** LRDMS manages legislative records through browser users and software-to-software APIs. The user confirmed that connected hardware/IoT device transmission is outside the approved subsystem scope. REST integration is evaluated separately under API Integration.

**Offline Synchronization — N/A:** The user confirmed that offline encoding and reconnect synchronization are outside the approved subsystem scope. The current application relies on an online PHP/MySQL service and does not queue offline transactions. Do not demonstrate offline support or label it implemented.

## Remaining demo procedure

1. Confirm database migrations/configuration, SMTP, AI service URLs and OCR worker startup. Run the health checker; a passing health request alone does not prove extraction/search accuracy.
2. Use designated demo records/accounts. Show intake starting private/Pending Validation, correction and registration, authorized search/download, amendment and preserved original history. Capture screenshots and observed results for every step.
3. Run real email reset/login-code flows, including invalid/expired code and two-browser session revocation. Keep credentials/codes out of submitted reports.
4. Submit staging API payloads from `external-api.md`: valid JSON, valid multipart, duplicate, incorrect API key, bad date/type and service outage. Verify both status/body and persisted records/files.
5. For AI: prepare representative scans with ground-truth transcription and search queries with expected relevant records. Record OCR errors, retrieval relevance, per-request durations, service/model versions and failures. Keyword fallback must not count as successful semantic AI.
6. For background jobs: enqueue a real scan, close the browser, verify completion/text and capture worker logs. Demonstrate retry after a controlled service interruption.
7. For scalability: agree expected concurrency and response-time target first. On staging, measure mixed login/search/dashboard/upload workloads at increasing concurrency, record dataset size, duration, median/p95 latency, error rate and server utilization. PHP's single-process development server is not representative load-test infrastructure.

Section 1 remains **in progress** until the applicable live/demo and performance evidence is completed. No compliance percentage is claimed.
