# Section 2: security, privacy and AI governance

2026-10-03 follow-up: request throttling, explicit session expiry, OCR/upload safeguards, public next-version visibility checks and deployment error/file protections are implemented locally. See [security hardening results and rollout limits](security-hardening-review.md). This supplements the earlier results; hosting and complete security verification remain outstanding.

Updated 2026-10-01. Implementation and limited regression/audit evidence, not certification of security or legal compliance. No deployment or Git push was performed for these changes.

## Implemented

- MFA is mandatory for Super Admin, Administrator, and custom roles with `access.manage_users`, `access.manage_roles`, or `access.reset_password`. Optional MFA remains available to other roles. Server-side login completion rejects missing MFA; profile/admin forms cannot disable mandatory MFA.
- Existing privileged sessions without session-bound MFA proof must sign in again. Role promotion from a password-only session also requires a new MFA sign-in.
- Email login challenges expire after ten minutes. OTP consumption is atomic and single-use. Five failed verification attempts lock the account for 15 minutes; a correct password does not reset the counter before MFA completes. Login OTP resend is limited to once per minute per browser session (not a distributed/account-wide email rate limiter).
- New, changed and reset passwords use one policy: at least 15 Unicode characters, no NUL, at most 72 bytes for the bcrypt input limit, rejection of repeated-character and a small list of obvious passwords. This is not a breached-password database. Existing password hashes remain valid until changed.
- Password resets atomically consume a single-use code/link, invalidate outstanding resets and revoke all sessions; a profile password change revokes other sessions. Email changes require the current password and sign out devices. Administrative account edits revoke sessions. Privileged accounts must have a valid email when created/edited.
- Session cookies are HttpOnly and SameSite=Lax, strict session IDs are enabled, and Secure cookies are enabled for direct HTTPS or `SESSION_COOKIE_SECURE=1` behind a trusted HTTPS deployment.
- Added `privacy.php`: public notice, signed-in notice acknowledgement, own request history, and access/correction/deletion request submission. Users with `access.manage_users` can review requests with a written response. Closed decisions cannot be overwritten through the workflow. Request submissions/decisions are audit logged without copying request text into audit details.
- Optional profile-photo upload requires an unchecked consent checkbox. Photo and consent event save together transactionally. Removal deletes the current photo and records withdrawal. Notice version and consent history are retained. Notice acknowledgement is explicitly separate from consent.
- The notice contact is configurable with `PRIVACY_OFFICE` and `PRIVACY_CONTACT`. Default contact is the system administrator; no unconfirmed official name/email was invented.
- Patched OCR service Flask from 3.0.3 to 3.1.3 and Pillow from 10.4.0 to 12.3.0, following audit fix versions.

## Checklist mapping

| Panel item | Result and limits |
|---|---|
| Multi-Factor Authentication | Mandatory privileged-role enforcement implemented and tested. Real SMTP/inbox delivery still required before deployment. Email-code MFA is the implemented factor; no claim of phishing resistance. |
| Role-Based Access Control | Existing permission engine retained. Added server-side reset-password permission check and privacy-review permission checks; regression tests include unauthorized review and role promotion. Full per-module role matrix demo remains pending. |
| Password Security | Shared policy applied to create, profile change, admin reset, code reset and token reset, including backend reset helpers. Existing users are not forced to rotate all passwords automatically. |
| Account Lockout | Existing password lockout plus persistent OTP failure lockout tested. Reset-code brute-force protection and distributed throttling still require additional work; do not describe the entire authentication surface as rate-limited. |
| TLS Encryption | Deployment TLS 1.3 and certificate evidence not verified. Secure cookie support does not establish TLS 1.3. |
| Database Encryption | Not implemented/verified by this change. Need host/database/storage encryption configuration and evidence for sensitive data and backups. Password hashing is separate. Do not encrypt existing fields without a key-management and migration plan. |
| Personal Data Protection | Operational notice/request features added. Official controller/contact, processing basis, retention periods, processors and organizational procedures still need approval. No legal-compliance claim. |
| Consent Management | Versioned optional-photo consent and withdrawal implemented. Existing photos have no retroactive consent records; request renewed permission or remove those photos during rollout. Notice acknowledgement is not blanket consent. |
| Right to Delete Data | Users can submit tracked deletion requests and staff can record action/reason. Requests do not automatically erase accounts/documents. Staff must actually perform an approved action before marking Completed; backup retention and statutory records requirements need an approved procedure. |
| Audit Trail | Existing audit log retained; privacy request IDs, reviewer action/status and acknowledgement version logged. Consent decisions stored separately. Audit immutability, retention and complete action coverage remain deployment/review work. |
| AI Prompt Protection | Reviewed architecture uses OCR and embedding similarity, not a generative instruction-following assistant. Prepare architecture-specific applicability explanation for the panel. Existing retrieval visibility tests pass; no comprehensive adversarial AI report produced. |
| Source Code Security | Targeted regression tests and PHP syntax checks pass. These are not SAST/DAST; independent scan and authenticated web testing remain pending. |
| Dependency Security | Composer locked audit reports no advisories/abandoned packages. Direct OCR dependency audit is clean after fixes. BERT, transitive Python dependencies, OS libraries and deployed container images are not covered by that direct-package result. |

## Executed evidence

- `php database/test_security_privacy.php`: **20 checks pass** using temporary tables: passphrase policy, custom/built-in MFA enforcement, role-promotion invalidation, MFA completion guard, OTP lockout/reuse, reset revocation, photo consent/withdrawal, privacy review authorization, closed requests and preservation of accounts.
- `php database/test_auth_workflow.php`: **12 checks pass**; nonprivileged fixture selection now matches mandatory-MFA policy.
- Existing API contract, search recovery, record workflow, OCR queue and storage integrity tests: **pass**.
- `php database/test_upgrade.php`: **pass**, including repeated upgrade preservation. It retained the isolated test database `lrdms_upgrade_test_92da0540e4`; production records were not changed by the test.
- `php database/migrate_privacy.php`: applied locally; additive tables only, no existing records edited. Upgrade chain and fresh schema now include the same tables.
- PHP syntax checks on changed PHP files and `git diff --check`: **pass**.
- `composer audit --locked --no-interaction --format=json`: **exit 0**, `advisories: []`, `abandoned: []`.
- `pip-audit 2.10.1 --no-deps --disable-pip -r ocr_service/requirements.txt`: before, 35 advisory entries (18 distinct IDs across Flask/Pillow); after, **zero**. See [machine-readable summary](section-2-dependency-audit.json). The audit queried public package advisories; application/user data was not sent.
- Patched OCR dependencies installed in ignored `.runtime/security-audit`, isolated from the service environment. OCR Flask app import, `/health`, rejection of missing upload, Pillow PNG encode/decode: **pass**. Four existing OCR layout tests: **pass**. Tested on local Python 3.14; the deployed Python 3.11 container and real Tesseract/Poppler extraction still need testing.
- `php bin/security_preflight.php`: **not deployment-ready**. Two active privileged accounts lack a valid email; SMTP environment credentials are absent. Only aggregate counts were printed. No email delivery was attempted.

## Deployment sequence

1. Review privileged browser accounts and configure their approved email addresses. API-only integration accounts do not need interactive sign-in; do not grant them an MFA bypass.
2. Configure working `SMTP_*` settings in deployment secrets and test actual code receipt. Do not share SMTP passwords in chat or commit them. Mandatory MFA intentionally has no password-only fallback.
3. Configure `PRIVACY_OFFICE` and `PRIVACY_CONTACT` with approved office/contact text. Finalize retention, processing basis, escalation and deletion/anonymization procedures with the responsible office.
4. Run `php database/upgrade.php` or `php database/migrate_privacy.php` before enabling the privacy UI/photo changes. Run the preflight and regression tests on staging. No password-policy migration of old hashes is needed.
5. Behind a TLS proxy, set `SESSION_COOKIE_SECURE=1` only for an HTTPS-only deployment. Verify cookies and deployed TLS 1.3 separately.
6. Rebuild the OCR image to install patched requirements. Repeat real extraction tests on representative scans and audit the resolved container dependencies, including system packages.
7. Test admin MFA, staff optional MFA, email/password changes, session revocation across browsers, and privacy submission/review/withdrawal through the UI. Existing privileged sessions will require a new sign-in.
8. Complete authenticated SAST/DAST, Python/BERT/container dependency evidence, TLS report and database/backup encryption proof before marking Section 2 compliant.

Remaining implementation concerns include account-wide reset/resend throttling, infrastructure error disclosure, comprehensive mutation/permission review, and deployment-specific storage privacy. These changes are not a full penetration test or a statement that no vulnerabilities remain.
