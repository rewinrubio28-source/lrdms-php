# Panel evaluation gap review

Basis: TECHNICAL-SOFTWARE-EVALUATION-AUDIT-CHECKLIST (1).docx and local source inspection, 2026-10-01.

Section 3 follow-up: shared dashboard metrics, 15-second auto-refresh, date/type/status filters, interactive drill-down, monthly history and PDF/XLSX/CSV summary exports are implemented and locally tested. See [Section 3 evidence and live-demo steps](section-3-verification.md). The original Section 3 gaps below are superseded by that report; live panel validation remains outstanding.

Section 2 follow-up: mandatory privileged MFA, shared passphrase policy, privacy request/optional-photo consent features, regression tests and direct OCR dependency patches are implemented. See [Section 2 results and rollout requirements](section-2-verification.md). Its findings supersede the initial static review below; deployment evidence and several security checks remain pending.

Follow-up: Section 1 implementation has started. See [executed tests, fixes and remaining evidence](section-1-verification.md) and [updated API documentation](external-api.md). The tables below preserve the initial review; the Section 1 report supersedes its original untested findings. The user confirmed IoT and offline synchronization are outside approved scope, so N/A justifications are prepared. README corrections have now been made for the outdated API/authentication claims noted below.

Static review only: no live application, database, deployment, email, security scan, or performance tests were executed. Existing test scripts are not passing-test evidence. This is a preparation guide, not a panel compliance certification. Document checklist statements were treated as evaluation criteria, not instructions to change the application.

Status: **Present** = implementation found, still needs demonstration; **Partial** = only part of the requirement found; **Missing** = not found in reviewed project files; **Verify** = needs runtime, deployment, or external evidence; **Scope** = possible N/A with agreed justification.

## 1. Core IT capabilities

| Checklist item | Status | Finding / next evidence |
|---|---|---|
| End-to-End Workflow Logic | Verify | Intake, review, registration, versioning and retrieval code exists (`encoding.php`, `includes/record_processing.php`, `includes/workflow.php`). Submit a functional test report covering full flows and failures. |
| User Authentication Workflow | Present | `includes/auth.php`, `public.php`, `verify_2fa.php`, `logout.php`, `reset_password.php`: login, reset, session tracking and revocation. Demonstrate actual email delivery and expiry. |
| CRUD Operations | Verify | Major modules have management code; demonstrate permitted create/read/update/archive operations per role. Explain retained records and archival behavior where hard deletion is inappropriate to the agreed workflow. |
| AI Integration | Partial | OCR and BERT services exist. No accuracy/latency report found; verify services actually run and semantic search is not using its keyword fallback. |
| IoT Integration | Scope | No device integration found. Candidate N/A if outside approved LRDMS scope. |
| API Integration | Partial | Authenticated intake/search endpoints exist. README examples need updating to current payload, environment-key and validation behavior. Add success/error test evidence. |
| Offline Synchronization | Missing / Scope | No offline transaction queue or reconnect synchronization found. Confirm whether required by scope. |
| Background Processing | Present | OCR queue, `bin/ocr_worker.php`, `deploy/supervisord.conf` exist. Provide actual worker logs. |
| Error Recovery | Partial | Transaction handling, OCR recovery and backup/restore tools exist. Demonstrate service failure, failed upload, interrupted job and recovery. |
| Scalability | Verify | No concurrent-user load report found. Measure realistic upload/search/dashboard workload. |

## 2. Security, privacy and AI governance

| Checklist item | Status | Finding / next evidence |
|---|---|---|
| Multi-Factor Authentication | Partial | Email-code 2FA exists, but `includes/auth.php` gates it on `totp_enabled`; `profile.php` lets users disable it. Mandatory privileged-account enforcement was not found. |
| Role-Based Access Control | Present | `includes/rbac.php`, role/permission tables and document visibility checks exist. Submit role matrix and denied-access tests. |
| Password Security | Partial | Password hashing exists, but `users.php`, `profile.php`, `public.php`, `reset_password.php` accept a six-character minimum. Strengthen and consistently enforce the agreed password policy. |
| Account Lockout | Present | `includes/auth.php`: five failed attempts, 15-minute lockout. Provide authentication test evidence. |
| TLS Encryption | Verify | TLS 1.3 cannot be confirmed from source. Docker exposes HTTP internally; public TLS may terminate at the hosting proxy. Obtain deployed-endpoint evidence. |
| Database Encryption | Verify | No AES-256 sensitive-field encryption implementation/configuration evidence found. Database/storage encryption may be external to this repo. Password hashes do not establish database encryption. |
| Personal Data Protection | Missing evidence | No privacy documentation package found. Application access controls alone do not prove the checklist requirement. |
| Consent Management | Missing | No dedicated consent collection/history or privacy policy found. Document the applicable data-processing workflow and evidence required by the panel. |
| Right to Delete Data | Missing | User archival/soft deletion exists (`includes/user_archive.php`), but no personal-data deletion request and resolution workflow found. |
| Audit Trail | Present | `includes/audit.php`, `audit_trail.php`, CSV export and timestamp/user fields exist. Verify coverage of significant actions. |
| AI Prompt Protection | Scope / Verify | Reviewed AI uses OCR and embedding similarity, not an instruction-following chatbot. Explain this architecture for applicability; still test unauthorized document access and malformed AI inputs. |
| Source Code Security | Missing evidence | No SAST/DAST results found. Code inspection is not a security scan report. |
| Dependency Security | Missing evidence | Dependency manifests exist, but no vulnerability audit report found. Scan PHP and both Python services. |

## 3. Operational analytics and dashboards

| Checklist item | Status | Finding / next evidence |
|---|---|---|
| Real-Time Dashboard | Missing | `dashboard.php` gets KPI values during PHP page rendering. Its 15-second timer updates only the clock/greeting, not database metrics. |
| Dashboard Accuracy | Verify | Counts query the database with role visibility rules. Reconcile each displayed figure with role-scoped database results. |
| Interactive Charts | Partial | Monthly trend bars exist, but chart filtering/drill-down implementation was not found in the dashboard. |
| Historical Reports | Partial | Audit history, document/version history and monthly counts exist; broader historical analytical reports are limited. |
| KPI Monitoring | Present / Verify | Database-backed counts exist; correct values and agreed KPI definitions need validation. |
| Report Export | Partial | Audit and version-list CSV exports exist. No complete PDF and Excel report export found. Downloading an uploaded PDF is a different operation. |

## 4. Data interoperability

| Checklist item | Status | Finding / next evidence |
|---|---|---|
| CSV Import | Missing | No CSV dataset parser/import workflow found. |
| Excel Import | Missing | No spreadsheet dataset importer found. |
| JSON Import | Partial | `api/upload_document.php` decodes JSON and validates a document payload; demonstrate malformed and invalid requests. No dedicated JSON-file dataset import UI found. |
| Invalid File Detection | Partial | Upload errors and extension allowlists exist. An extension check alone does not establish validation of actual file contents. Provide invalid-content/type/size tests. |
| Bulk Upload | Partial | Multiple attachments per record are supported; bulk importing many independent records and large-dataset performance are not established. |
| Export Accuracy | Partial / Verify | `api/export_audit.php` limits output to 1,000 rows. Larger matching result sets will be incomplete; verify expected scope, Unicode, quoting and row counts. |

## 5. Reporting system

| Checklist item | Status | Finding / next evidence |
|---|---|---|
| Custom Reports | Partial | Filtered audit export exists; broader user-customizable reports/columns/grouping not found. |
| Report Filters | Partial | Audit export accepts filters and uses descending timestamp order. Verify required report filtering and user-selectable sorting. |
| Report Branding | Missing / Partial | Branded UI/receipt is not proof of branded report output. No generated analytical PDF report with logo/header/footer found. |
| Scheduled Reports | Missing | No report schedule/generation/email worker found. Background OCR does not satisfy scheduled reporting. |
| Print Functionality | Partial | `includes/document_workspace.php` calls print; document/receipt print styles exist. Validate actual printed reports and page breaks. |

## 6. Database architecture

| Checklist item | Status | Finding / next evidence |
|---|---|---|
| Database Normalization | Verify | Relational tables exist in `sql/schema.sql`; no ER diagram/normalization justification found. |
| Foreign Key Integrity | Present / Verify | Schema defines foreign keys. Check deployed schema after migrations and demonstrate orphan rejection. |
| Data Dictionary | Missing | No complete table/column/type/nullability/key/description dictionary found. SQL schema alone is not the requested documentation. |
| Index Optimization | Partial | FULLTEXT and other indexes exist; index suitability needs query-plan evidence. |
| Query Performance | Verify | No measured query performance report found. Capture timings and EXPLAIN for representative data volumes. |
| Backup Procedures | Partial | `database/backup_restore.php` and `docs/backup-restore.md` exist. No committed automatic backup schedule or successful scheduler logs found. External schedules remain unverified. |
| Restore Procedures | Partial / Verify | Restore validates a separate database and matching files. No executed successful recovery report inspected. Documented tool scope is local uploads; remote storage needs coordinated recovery. |

## 7. UI, UX and accessibility

| Checklist item | Status | Finding / next evidence |
|---|---|---|
| Responsive Layout | Present / Verify | Bootstrap and responsive CSS exist. Test desktop/tablet/mobile pages, tables and modals. |
| Navigation | Verify | Shared navigation exists; intuitive use needs user-testing evidence. |
| Visual Consistency | Verify | Shared styles exist, alongside page-specific styles. Requires visual inspection across modules. |
| Form Validation | Present / Verify | Required fields, server validation and error messages exist. Test boundaries and invalid inputs per form. |
| Loading Indicators | Present / Verify | OCR progress scripts/UI exist. Check remaining long-running actions and failures. |
| Error Messages | Present / Verify | Validation/API error messages exist. Assess coverage and clarity through actual failure tests. |
| Keyboard Accessibility | Verify | No full keyboard-only test evidence found. Check focus order, modal escape and all controls. |
| Screen Reader Support | Partial / Verify | Some aria labels and form labels exist; complete assistive-technology support is unverified. |
| Color Contrast | Verify | No contrast evaluation report found; requires rendered-color measurements. |

## Suggested preparation order

1. Close privileged MFA enforcement and password-policy gaps. Prepare privacy requirements/evidence, deployment TLS/encryption proof and security/dependency scan results.
2. Add required reporting exports, complete export behavior, dataset imports, dashboard refresh and interactive chart controls.
3. Configure automatic backups and demonstrate a complete restore. Add scheduled reporting if in approved scope.
4. Prepare ERD, data dictionary, accurate API/setup documentation, functional and AI reports, load/query results, accessibility evidence and export/print samples.
5. Record agreed applicability for IoT, offline sync and prompt protection; do not silently mark these compliant or add unrelated features just to fill a generic checklist.

README has stale claims: it says 2FA and CSRF are missing and API keys are hardcoded, while current source contains those controls/environment-key loading. It also references absent `login.php` and `docs/SCOPE_DECISION.md`. Update documentation against current behavior before submission.

No application code was changed during this review.
