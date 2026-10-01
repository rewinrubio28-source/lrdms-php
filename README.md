# LRDMS — Legislative Records & Document Management System

This is the **Legislative Records & Document Management System** subsystem of the larger Legislative Services platform — the system of record for finalized ordinances, resolutions, committee reports, and session minutes. It encodes documents, versions them, makes them searchable, and exposes read/write API endpoints so the other subsystems (Ordinance Lifecycle, Session Management, Research & Policy Analysis, Citizen Engagement) can integrate with it.

Built with **native PHP, MySQL, and Bootstrap 5** — no framework, per the project's chosen stack.

> **Where this system's responsibility starts and stops:** see [`docs/ordinance-process-workflow.md`](docs/ordinance-process-workflow.md) — it walks through the client's actual drafting-to-first-reading process flow and confirms LRDMS picks up only once a document is formally Enacted, not before.

## Tech stack

| Layer | Choice |
|---|---|
| Frontend | HTML5, CSS3, JavaScript, Bootstrap 5 |
| Backend | PHP (native, procedural) |
| Database | MySQL |
| API | REST (PHP + JSON) |
| Auth | PHP Sessions + `password_hash()` / `password_verify()` |
| Dev environment | XAMPP (Apache + MySQL) |

## Setup (XAMPP)

1. **Copy the project** into your XAMPP `htdocs` folder, e.g. `C:\xampp\htdocs\lrdms-php\` (or `/Applications/XAMPP/htdocs/lrdms-php/` on macOS).
2. **Start Apache and MySQL** from the XAMPP control panel.
3. **Create the database**: open phpMyAdmin (`http://localhost/phpmyadmin`), go to *Import*, and import `sql/schema.sql`. This creates the `lrdms_db` database, all tables, and static lookup data (roles, permissions, committees).
4. **Check `config/database.php`** — the defaults (`localhost` / `root` / no password) match a stock XAMPP install. Change them if yours is different.
5. **Run the seed script once**, in your browser: `http://localhost/lrdms-php/database/seed.php`. This creates demo user accounts (with real hashed passwords — that has to happen in PHP, not in the SQL file) and a handful of sample documents.
6. **Delete or move `database/seed.php`** out of the web root once you've run it — it's not something you want reachable in a real deployment.
7. Go to `http://localhost/lrdms-php/public.php` and sign in.

### Password reset email setup

Run `composer install` to install PHPMailer, then set `SMTP_USERNAME` and
`SMTP_PASSWORD` in your local `.env` (use an app password for Gmail).
Optional settings are `SMTP_HOST`, `SMTP_PORT`, `SMTP_FROM_EMAIL`, and
`SMTP_FROM_NAME`. The forgot-password flow uses `config/email.example.php`
when no local `config/email.php` override exists. Delivery failures appear
on the email form; successful requests proceed to the six-digit code form.

### Applying the Revision Plan v5 database update

For Pending Records & Follow-up, run `php database/migrate_record_followups.php`
after the v5 migration. Open **Encoding & Submission → Pending Records & Follow-up**.
The working reminder is 15 days from `pending_since`, falling back to the received
date and then the creation date. Staff record completed contact and choose the
next reminder date. This records follow-up activity; it does not send messages.
The official timer trigger remains subject to client confirmation.

After importing the schema, run `php database/migrate_revision_v5.php` from
the project directory, or sign in as Super Admin and open
`http://localhost/lrdms-php/database/migrate_revision_v5.php`. This adds
records-management state, source-status provenance, organizational identity,
validation history, integration receipts, access-request, and archive support.
It is safe to reopen; existing values are preserved. Upstream API intake and
the Encoding validation queue use these new fields, so apply the migration
before accepting new records.

### Demo accounts (created by seed.php)

| Username | Password | Role |
|---|---|---|
| `superadmin` | `superadmin123` | Super Admin |
| `admin` | `admin123` | Administrator |
| `rofficer` | `password123` | Records Officer |
| `staff` | `password123` | Legislative Staff |
| `secretary` | `password123` | Committee Secretary |
| `system.integration` | `admin123` | Administrator (reserved for API pushes — don't log in as this one) |

## Folder structure

```
lrdms-php/
├── sql/schema.sql            → run first, in phpMyAdmin
├── database/seed.php         → run once, in your browser, then delete
├── config/database.php       → PDO connection settings
├── includes/
│   ├── auth.php              → session login/logout
│   ├── rbac.php              → the two-layer permission engine (see below)
│   ├── audit.php             → log_action(), called from every module
│   ├── ocr.php                → OCR stub + how to wire in a real engine
│   ├── semantic_search.php   → keyword search + real BERT-backed search (see below)
│   ├── layout_top.php / layout_bottom.php → shared Bootstrap shell
├── assets/css/style.css      → theme (navy/gold/red/white)
├── uploads/                  → where encoded files land
├── ocr_service/               → Python/Flask OCR microservice (PyTesseract)
├── bert_service/               → Python/Flask BERT semantic search microservice
├── api/
│   ├── upload_document.php   → System 1 / System 2 push documents here
│   ├── search.php            → System 9 / Citizen Engagement query here
│   └── export_audit.php      → CSV export of the audit log
├── public.php / logout.php / index.php
├── dashboard.php             → Module 00 — Overview
├── encoding.php              → Module 01 — Encoding & Submission
├── version.php               → Module 02 — Version Control (history, comparison, rollback)
├── repository.php            → Module 03 — Repository
├── document.php              → document detail; amend flow + per-document history/notes
├── search.php                → Module 04 — Retrieval & Search
├── users.php                 → Module 05 — Access Control (admin only)
└── audit_trail.php           → Module 06 — Audit Trail
```

## The RBAC model — two layers, on purpose

This is the part worth understanding before you extend anything, because it's easy to build a "toy" RBAC that only checks roles and misses the second layer that makes it actually reflect how a legislative office works.

**Layer 1 — role-based** (`has_permission($module, $action)` in `includes/rbac.php`): can this *role* touch this module/action at all? Backed by the `permissions` and `role_permissions` tables. A Legislative Staff account has `repository.view_own` and `repository.view_public`, but not `repository.view_all`.

**Layer 2 — status & ownership-based** (`document_visibility_clause()` and `can_view_document()`, same file): of the rows in a module a role *can* reach, which specific ones can it actually see or edit? A Legislative Staff account can reach the Repository, but that doesn't mean it should see another councilor's still-pending Draft. This layer is driven by each document's `status` and `owner_id` columns, not by the role alone.

Both functions encode the same rules — one as a SQL `WHERE` fragment (for list pages like `repository.php`), one as a PHP predicate (for a single row already loaded by ID, like `document.php`). They're hand-written twins right now; see the comment at the top of `rbac.php` for a note on refactoring that into one shared rule source later.

### Roles seeded by `sql/schema.sql`

| Role | Can do |
|---|---|
| Super Admin | Full system access: all permissions including role/permission management, user administration, all system settings, and full repository access. |
| Administrator | Day-to-day system administration: manage users, reset passwords, force logout, view user activity, view audit trail, and view full repository. Cannot create/edit roles or assign permissions. |
| Records Officer | Encode, digitize, manage the repository day-to-day, amend/version documents. The core power user. |
| Legislative Staff | Draft/submit their own documents; view their own drafts plus enacted public documents. |
| Committee Secretary | Review/endorse documents in `Submitted` / `Under Review` status for their committee; create committee documents. |

Document lifecycle: `Draft → Submitted → Under Review → Enacted → Amended`. New records accept `Draft`, `Submitted`, `Under Review`, `Enacted`, `Amended`, and `Rejected`. `Superseded` and `Withdrawn` are retired; their database values remain for historical records. `is_public` is a separate flag — a document can be `Enacted` and still not public if you want a staging period before it's citizen-visible.

## Implementation and verification

Authentication/session management, email 2FA, account lockout, role permissions, document intake/review, repository retrieval, version history and audit logging are implemented. Implementation alone does not establish panel compliance; see the [Section 1 verification record](docs/section-1-verification.md) for executed checks and outstanding live tests.

OCR uses the Python service in `ocr_service/`; background extraction on saved records requires the worker. Failed background scans preserve existing text. BERT semantic search uses `bert_service/` and falls back to keyword search when the service is unavailable or returns invalid results. The search page displays this fallback, and the external API exposes `effective_mode` and `fallback`. Keyword fallback is not evidence of AI accuracy.

## API endpoints

### Dashboard analytics and reports

Dashboard metrics refresh every 15 seconds while the tab is visible. Filter records by creation dates, document type and current status; select a month bar or document-type label to inspect matching records. Dashboard summary exports are available as PDF, native Excel (`.xlsx`) and UTF-8 CSV and respect the same role visibility and filters. Search history is private to the user unless their role has audit access.

Run `composer install` locally, or rebuild the PHP Docker image, to install the report libraries. Section 3 adds no database migration. See [metric definitions, tests and panel demo](docs/section-3-verification.md). Historical summaries group current visible records by creation month; they do not reconstruct past record states.

### Integration endpoints

See the [external API contract](docs/external-api.md) for fields, examples, response codes and validation behavior.

- `POST api/upload_document.php`: JSON or multipart intake. Required fields are `doc_number` and `title`. Incoming records start private and Pending Validation; authorized staff review/register them.
- `GET api/search.php?query=traffic&mode=keyword`: searches verified public records. Supports `keyword` and `semantic` modes.
- Both external endpoints require `X-API-Key`, matched against `API_SHARED_KEY` from the environment or local `.env`.
- `api/export_audit.php` is a separate session/permission-protected CSV export.

## Deployment and evaluation work remaining

- CSRF helpers and form checks exist; verify coverage of every mutating action.
- Privileged roles require email MFA. New/change/reset passwords use a shared passphrase policy. Before deployment, configure verified account email addresses and working SMTP; see [Section 2 verification and rollout](docs/section-2-verification.md).
- General external API rate limiting is not implemented.
- Restrict setup/seed/migration endpoints in deployment and protect configuration/secrets.
- Verify real SMTP delivery, AI services, worker execution and deployed API behavior.
- Complete load tests, security/privacy evidence and the other sections in the [panel evaluation review](docs/panel-evaluation-gap-review.md).

### Privacy and account security

Run `php database/migrate_privacy.php` (also included in `php database/upgrade.php`). Open `privacy.php` from the account menu for the notice, acknowledgement and access/correction/deletion requests. Authorized user administrators review requests there. Optional profile photos require recorded consent; removing a photo records withdrawal. Deletion requests require staff review and do not automatically erase records.

Set `PRIVACY_OFFICE` and `PRIVACY_CONTACT` to the approved office/contact text. Until configured, the notice refers users to the system administrator. This feature does not by itself establish legal compliance.

Run `php bin/security_preflight.php` before deploying mandatory MFA, then test actual email delivery. Privileged sessions without MFA proof must sign in again. Use `SESSION_COOKIE_SECURE=1` behind an HTTPS-only reverse proxy. Run `php database/test_security_privacy.php` against a local test-capable database for isolated regression checks.

## Suggested Git workflow

For coordinated database/upload backups and recovery commands, see [Records backup and recovery](docs/backup-restore.md). Restore creates a separate database and matching uploads and never overwrites the live system.

Since the stack list includes Git + GitHub: a simple `main` + feature-branch flow works fine for a project this size — branch per module (`feature/version-control`, `feature/audit-export`, etc.), PR into `main`, tag a release before your defense so you have a known-good snapshot to demo from.

### Background OCR

Run OCR on saved documents now queues a background scan with page progress.
See [setup, worker commands, and recovery](docs/background-ocr.md).
