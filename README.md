# LRDMS — Legislative Records & Document Management System

This is the **Legislative Records & Document Management System** subsystem of the larger Legislative Services platform — the system of record for finalized ordinances, resolutions, committee reports, and session minutes. It encodes documents, versions them, makes them searchable, and exposes read/write API endpoints so the other subsystems (Ordinance Lifecycle, Session Management, Research & Policy Analysis, Citizen Engagement) can integrate with it.

Built with **native PHP, MySQL, and Bootstrap 5** — no framework, per the project's chosen stack.

> **Where this system's responsibility starts and stops:** see [`docs/SCOPE_DECISION.md`](docs/SCOPE_DECISION.md) — it walks through the client's actual drafting-to-first-reading process flow and confirms LRDMS picks up only once a document is formally Enacted, not before.

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
7. Go to `http://localhost/lrdms-php/login.php` and sign in.

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
├── login.php / logout.php / index.php
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

Document `status` values: `Draft → Submitted → Under Review → Enacted → (Amended | Superseded | Withdrawn)`. `is_public` is a separate flag — a document can be `Enacted` and still not public if you want a staging period before it's citizen-visible.

## What's real vs. what's a stub

Being upfront about this matters for a thesis defense — a panel will ask.

- **Everything except OCR and semantic search is fully functional today**: login/sessions, the permission engine, encoding, the repository with filtering, version control (amend → new linked row, old one marked `Amended`; a standalone Module 02 with a revision timeline, side-by-side version comparison, and rollback/restore that creates a new current version rather than deleting history), change notes, the audit trail, user management, and both REST API endpoints.
- **Keyword search is real** — a parameterized `LIKE` query against `documents.title` and `documents.ocr_text` (there's also a `FULLTEXT` index on those columns in the schema if you want to switch to `MATCH() AGAINST()` for better ranking later).
- **OCR is implemented** (`includes/ocr.php`), backed by a small Python microservice (`ocr_service/` — Flask + PyTesseract + pdf2image) that PHP calls over HTTP via `cURL`. If that service isn't running, `ocr_extract()` falls back to a labeled placeholder automatically so encoding never breaks. See `ocr_service/README.md` to run it.
- **Semantic (BERT) search is implemented** (`includes/semantic_search.php`), backed by a small Python microservice (`bert_service/` — Flask + `sentence-transformers`) that PHP calls over HTTP via `cURL`. If that service isn't running, `semantic_search()` falls back to `keyword_search()` automatically so the search page never breaks. See `bert_service/README.md` to run it.

## API endpoints

Both require an `X-API-Key` header — the shared secret is a constant at the top of each file (`change-this-shared-key`). **Move that to an environment variable before any real deployment.**

**Ingest** (System 1 / System 2 push a finalized document in):
```bash
curl -X POST http://localhost/lrdms-php/api/upload_document.php \
  -H "X-API-Key: change-this-shared-key" \
  -H "Content-Type: application/json" \
  -d '{"title":"An Ordinance Regulating E-Trike Operations","doc_number":"2026-067","doc_type":"Ordinance","sponsor":"Councilor P. Villanueva","enactment_date":"2026-07-08"}'
```

**Retrieve** (System 9 / Citizen Engagement query, read-only, enacted + public only):
```bash
curl "http://localhost/lrdms-php/api/search.php?query=fare%20hike" \
  -H "X-API-Key: change-this-shared-key"

# Add &mode=semantic to route through the BERT microservice instead of keyword LIKE matching
curl "http://localhost/lrdms-php/api/search.php?query=fare%20hike&mode=semantic" \
  -H "X-API-Key: change-this-shared-key"
```

## Known gaps to close before any real deployment

- **No CSRF tokens** on the forms yet — add a per-session token check before this touches real data.
- **API key is a hardcoded constant** — move it to an environment variable / untracked config file.
- **No rate limiting** on the API endpoints or login form.
- **`config/database.php` and `database/seed.php`** should move outside the web root (or at minimum be deleted/blocked) in production.
- **2FA** isn't implemented — fold it into `includes/auth.php` if you need it.

## Suggested Git workflow

Since the stack list includes Git + GitHub: a simple `main` + feature-branch flow works fine for a project this size — branch per module (`feature/version-control`, `feature/audit-export`, etc.), PR into `main`, tag a release before your defense so you have a known-good snapshot to demo from.
