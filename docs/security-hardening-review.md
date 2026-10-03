# Security hardening follow-up — 2026-10-03

Scope: six user-supplied security checklists, assessed against the existing PHP application. Existing controls were retained where appropriate. This is a targeted local review and regression report, not a penetration-test certification or proof that every endpoint is secure. No live deployment or credential rotation was performed.

## Existing protections retained

- Bcrypt password hashing, the shared passphrase policy, privileged email-code MFA, single-use/expiring reset codes, session revocation and CSRF support.
- Permission-based access and document visibility rules, including intentionally shared staff records. We did not replace these with owner-only access.
- Environment-based external API authentication, ignored local credentials, prepared statements in inspected paths, dataset validation, profile-photo content checks and escaped report output.
- Owner-scoped scheduled reports and notifications, guarded downloads/previews, and audit trails. A public object-storage bucket can still bypass application checks; see operational follow-ups below.

## Implemented fixes

### Request abuse and authentication

`security_rate_limits` stores SHA-256 bucket identifiers, counters and expiry seconds. Atomic database increments work across browser sessions and app instances sharing this database. Identity-based login/reset limits use the resolved account ID so database-equivalent username/email spellings cannot create fresh account buckets. Counts are fixed-window, so bursts can span a boundary; this is not a WAF or DDoS defense.

| Operation | Limit |
|---|---|
| Public landing/reader requests | 120 / minute / remote IP |
| Session API requests | 600 / minute / remote IP |
| POST requests through shared authentication | 60 / minute / route / remote IP |
| Search, OCR scan, report/audit export, file download/preview | 30 / minute / route / session user, otherwise remote IP |
| External integration API | 120 / minute / remote IP and authenticated shared key |
| Password login | 60 / 15 minutes / IP and 15 / 15 minutes / account |
| Login-code issuance | 1 / minute and 5 / 15 minutes / account |
| Password-reset code requests | 15 / 15 minutes / IP and 3 / 15 minutes / account |
| Password-reset code validation | 60 / 15 minutes / IP and 10 / 15 minutes / account |
| Reset-link validation | 60 / 15 minutes / IP |

Existing five-failure password/OTP account lockouts remain. Password failure increments are now atomic. Excess requests receive HTTP 429 and `Retry-After`; an unavailable rate store returns generic HTTP 503 rather than bypassing enforcement. Login AJAX receives JSON. Random bounded cleanup removes expired buckets; absence of traffic can leave expired operational rows until later cleanup. CLI workers bypass HTTP throttling deliberately; core limiter tests invoke it directly.

Sessions expire after **30 minutes without an authenticated request** or **12 hours after creation**. Polling counts as request activity and can extend the inactivity window; it cannot extend the absolute lifetime. Expiry revokes the stored session. Malformed scalar authentication fields and array-shaped CSRF tokens are rejected.

### Validation and public access

- OCR scan now requires intake permission, completed password-change requirements, POST, CSRF and a real upload. Content/extension, 25 MB size and 40-megapixel image limits apply; only PDF/images are accepted for this OCR endpoint. Temporary files are cleaned even when extraction throws. No current frontend caller of this legacy endpoint was found; future callers must send CSRF.
- Shared document storage validates supported content against extension before saving. DOCX archives require expected Word entries and have archive-count/uncompressed-size/path/macro checks. These checks are not antivirus, full PDF parsing or content disarm; legacy binary DOC validation remains MIME-based.
- External JSON intake is capped at 5 MB and body/OCR text fields at 2 MB each.
- The public document reader previously loaded the linked next version without checking its visibility. It now requires a public, registered, eligible record for both the main record and linked version. The public listing also excludes unregistered records. Published records remain visible; private or unregistered references are withheld.

### Deployment and logging

- Apache denies direct access to internal source/configuration, migration/seed tools, repository dotfiles, local evidence and backups; directory listing is disabled. Nosniff, same-origin framing and referrer-policy headers are configured.
- Docker enables the headers module and disables browser PHP errors, startup errors and PHP version disclosure while retaining server logs.
- The deployment database template returns a generic 503 on connection failure, without database host/user/error details. Existing ignored local `config/database.php` copies must adopt that handler separately; Docker copies the updated template automatically.
- Structured events cover failed password/API authentication, throttling, limiter outages, session expiry and external API errors. Events contain operation/time, not passwords, reset codes, tokens, raw request bodies or API keys. Existing business audit logging remains. Alert routing/retention are hosting tasks.

## Secrets review

`python bin/audit_secrets.py` scans tracked working files and unique reachable Git-history blobs using heuristic rules. This run examined **377 tracked files and 709 historical blobs**. It reported **57 literal-credential candidates**, primarily repeated history entries for permission names, password-toggle UI code, environment-key lists, vendored source maps and explicit `your_...` sample configuration placeholders. No high-confidence provider-token/private-key rule matched. No production credential was confirmed in the reviewed candidates. Values were never printed; location-only output is in ignored `.runtime/security-audit/secret-locations.json`.

This is not a comprehensive secret scanner: binary/over-5-MB objects, ignored local secrets, unreachable history and provider-side exposure are outside its coverage. No history rewrite or secret rotation was attempted. If a real old credential is identified, revoke/rotate it at its provider; deleting a current file alone is insufficient.

## Executed checks

- `php -d extension=zip database/test_request_security.php`: 22 checks, including expiry/window isolation and malformed/disguised/oversized uploads.
- `python database/test_request_security_http.py`: real HTTP 429/Retry-After, fail-closed 503 and twelve concurrent database calls limited to at most three accepted requests.
- `php database/test_auth_workflow.php`: 15 checks, including actual inactive/absolute session rejection and malformed CSRF.
- `php database/test_security_privacy.php`: existing 20 checks passed.
- `php database/test_ocr_security.php`: six denied-access/method/CSRF/malformed-upload cases passed, with JSON errors.
- `php database/test_public_record_security.php`: five public-reader cases passed; private/unregistered next-version references do not leak and published links still work.
- Existing API contract, report access (13 cases), user visibility (six checks), role policy and records-workflow tests passed. These are focused regression checks, not a complete endpoint/method authorization matrix.
- Local Apache returned 403 for `.env`, `.git/config`, `config/env.php`, `database/test_request_security.php` and `includes/auth.php`; the CSS asset still returned 200. No secret response bodies were printed.
- Rate migration was applied locally and rerun successfully. Updated schema references cover 39 tables / 293 columns. Synthetic test fixtures did not edit real accounts or legislative records.

## Deployment order and outstanding verification

1. The normal `database/upgrade.php` startup now includes `migrate_request_security.php`. **Deploy schema and code together.** Without the table, protected HTTP requests intentionally return 503. No existing records are rewritten. A manual non-Docker installation must run the migration before serving the new code.
2. Verify HTTPS redirect/certificate settings at the hosting proxy and set `SESSION_COOKIE_SECURE=1` only on an HTTPS-only live site. No speculative proxy redirect was added because it could create a redirect loop. This does not verify TLS version or database transport encryption.
3. The limiter deliberately uses `REMOTE_ADDR`, ignoring client-supplied forwarded headers. If the hosting proxy presents one address for all visitors, its limits will be shared. Have the host configure trustworthy client-IP restoration (e.g. trusted-proxy Apache configuration) and verify before treating IP limits as per visitor. Do not simply trust arbitrary `X-Forwarded-For`.
4. Confirm the database is private/firewalled, credentials have appropriate privileges, and OCR/BERT services are private/authenticated. PHP cannot prove the provider's network rules. No changes to hosting accounts/services were made.
5. Verify object-storage privacy. The existing S3 mode uses public base URLs: application permission checks alone cannot protect a separately public bucket. Moving to private object storage/signed server access requires the host's actual bucket configuration and a migration plan; it was not silently switched.
6. Test live login/MFA/reset email, genuine accepted uploads, long-running OCR, integrations and normal staff traffic. Additional current caller-specific upload paths, full-browser IDOR testing, malware scanning, dependency/container scans and full security review remain open. The whole app is not certified secure by this patch.
7. Existing privileged MFA confirms control of the sign-in mailbox. There is still no separate persisted email-ownership verification flow for every nonprivileged account or pending email change. This report does not claim universal verified-email enforcement.
8. Configure hosting log retention and alerts for security events. Review legitimate rate-limit hits, proxy behavior and fixed-window bursts before changing thresholds. The screenshots' generative-AI controls are not directly applicable to this OCR/embedding application; expensive app requests are covered as listed above.

References: [OWASP authentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), and [file upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
