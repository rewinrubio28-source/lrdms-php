# Proposed records access model

Based on the supplied **draft** Information and Communication Division chart.
This is a proposed system-access mapping, not a verified Manila job description.
Positions describe employment; role permissions govern system actions. Repeated
positions in the chart are multiple staffing items, not separate system roles.

| Assignment | Role | Access |
| --- | --- | --- |
| Chief / Supervising Administrative Officer | Division Viewer | Registered records, print summaries, audit view/export |
| RMPS Administrative Officer V | Records Supervisor | Intake, validation/registration, metadata, publication, versions, copy requests, audit |
| RMPS Senior Administrative Assistant IV / Administrative Officer III | Records Validator | Intake and private registration; no public release |
| RMPS Administrative Assistant II / Aide VI | Records Encoder | Intake, OCR and metadata/corrections; no registration or release |
| RMPS Administrative Aide IV | Records Assistant | Registered record search/view; copies require approval |
| RMPS Administrative Aide II | No automatic account | Assign only for an actual digital records task |
| Explicitly designated technical administrator | Administrator | Accounts, organizational lists, audit; no repository access |
| Other ITTS positions | No automatic account | Job title alone grants no access |

Super Admin remains hidden from the user directory, keeps every available
permission, and remains identifiable in audit logs. Its role cannot be renamed
or edited through the role editor. Existing credentials and account assignments
are preserved. Existing Records Officer is retained as a supervisor-equivalent
compatibility role; Legislative Staff retains own/public intake access;
Committee Secretary retains committee/public read access. Custodian handles
versions, publication and copies without registration. Auditor is audit-only.

The existing Office > Division fields store the chart's parent unit > section;
labels now allow Office / Parent unit and Division / Section. Existing references
are preserved. No employee's position or unit is guessed from their username.

Docker deployments run `php database/upgrade.php` before starting Apache and
the worker. A failed upgrade stops startup. For non-Docker deployments, run the
same command manually before serving the new code. The role migration runs once,
records previous permission IDs and the new grants in audit_log, and uses a
transaction. Subsequent upgrades preserve role edits. Existing sessions resolve
permissions from the database on each request, so changed grants take effect
without changing passwords. New privileged accounts still require MFA.

Verification: `php database/test_records_role_policy.php`,
`php database/test_user_visibility.php`, and `php database/test_record_workflow.php`.
The policy test shadows tables with connection-local temporary fixtures.
