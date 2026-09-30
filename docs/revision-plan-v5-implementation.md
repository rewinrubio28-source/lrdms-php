# Revision Plan v5 implementation notes

## Pending records and follow-up

Implemented under Encoding & Submission as the Pending Records & Follow-up tab.
It lists visible incoming records with no verification timestamp, shows pending
and due counts and average days pending, and supports search and reminder filters.
The screen uses actual stored dates; it does not invent committee assignments.
Staff record the person/office contacted, contact method, notes, and next reminder.
Entries are retained in `record_followups`, with an audit event in the same
transaction. Registration removes the record from the pending list, while its
follow-up history stays in the database. No messages are sent by this screen.

Working assumption: reminder due 15 days after `pending_since`, otherwise
`received_at`, otherwise `created_at`. An explicitly selected `follow_up_due_at`
takes priority. This is a records reminder and does not certify legislative
compliance. The exact official trigger remains deferred for client validation.
Apply with `php database/migrate_record_followups.php` (CLI only, safe to rerun).

The v5 revision plan defines system behavior and subsystem boundaries. The UX Pilot PDF is a screen-layout and visual reference. Where the PDF depicts legislative approval, rejection, lifecycle ownership, or automatic amendment of legal status inside LRDMS, this implementation follows the v5 boundary instead: LRDMS validates and registers records, stores status received from the source system, and never makes the legislative decision.

## Implemented in this pass

- Added an idempotent database migration for records-management state, proposed classification values, organizational identity fields, source-status provenance, validation history, integration receipts, access-request support, record-level access rules, retention reviews, and archive transfer tracking.
- Changed incoming-record intake to support **Return for correction**, **Register privately**, and **Register & publish copy**. Returning a record requires a reason and does not mark it legislatively rejected. Publishing explicitly labels the public copy and assigns the proposed `PUBLIC` classification.
- Recorded validation and registration actions in history and integration receipts.
- Kept version creation and restore from rewriting the source-supplied legislative status on the prior record.

## Apply the migration

Import the existing `sql/schema.sql`, sign in as Super Admin, then open `database/migrate_revision_v5.php`. The migration is safe to reopen and reports which changes it applied or found already present. Apply it before using incoming-record intake or accepting new API submissions.

## Working assumptions and deferred decisions

### Organizational identity UI

- Users create/edit forms assign Office, Division, Position / Designation, and multiple committee memberships. Division choices follow the selected office and are validated again when saving.
- `organization.php`, linked from Users, lets user administrators add official office, division, and position names. No organizational names are fabricated or seeded.
- Profile displays these assignments read-only. System role remains separate. Existing primary-committee access rules are preserved; additional memberships expand verified-record visibility only when the role is explicitly granted View Assigned Committees.
- Account changes and memberships are saved together in a transaction and included in the audit entry. Committee choices use the existing reference list; System 4 remains the authority for official committee formation and membership.
- Requires the organizational tables and user columns from the existing v5 migration. No migration is rerun by these pages.
- `database/migrate_organization_permissions.php` adds three optional repository permissions without assigning them to roles: View Assigned Committees, View Originating Office, and View Originating Division. Office scopes match document originating-office names; division scopes require matching both office and division. Missing assignments or unmatched provenance do not grant access. Position remains descriptive. These scopes add visibility only, exclude unverified intake, and use the same SQL rule for listings and direct document access.

- `INTERNAL` is the default proposed classification for newly received records. The client must confirm the real classification policy.
- Releasing a public copy is an explicit Records Officer action. The real publication authority remains subject to client confirmation.
- Organizational names, offices, divisions, positions, access-review authorities, retention periods, and disposal rules remain configurable or unset until confirmed.
- The existing `documents.status` field remains for compatibility with current screens. `source_status` and its provenance fields hold the value received from an upstream system; records processing is tracked separately in `records_status`.

The v5 Deferred Validation List remains open; the UX Pilot mockup is not treated as evidence that any deferred rule or authority has been approved.
