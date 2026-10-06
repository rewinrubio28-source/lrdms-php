# Session tracking and mayor signature copies

This workflow extends the earlier receive/register-only scope. It records staff handoffs and reported session outcomes; it does not enact legislation, publish records, or automatically send to an external Agenda subsystem.

## Staff workflow

1. In Document Intake, open the record's Session Tracking panel. **Send to Agenda for Session** validates metadata and records **For Agenda — Pending External Delivery**. It does not claim successful external delivery or register the document yet.
2. After actual manual dispatch, use **Record Manual Agenda Delivery** with the sending reference. On return, use **Receive Session Documents** with receiving details and an optional returned attachment. This registers a private repository copy and starts **1st Session — Documents Received**. Existing already-registered records can also start tracking from Tracking & History.
3. Choose **Committee Request for Amendment** or **Proceed to 2nd Session**. An amendment request moves the record to **2nd Session — Documents for Amendment**. Select the receiving committee and record the actual sending reference. Saving confirms dispatch and starts 15 calendar days, including weekends. Day 1 begins immediately, with the deadline exactly 15 days after the recorded dispatch time.
4. The system notifies active eligible Records Officer, Records Supervisor, Records Validator and Super Admin accounts, and assigned Committee Secretary accounts that can view the record. These are in-app notifications; no email or external-system delivery is claimed. A notification failure rolls back the request so it can be retried.
5. When overdue, the same recipients receive one follow-up reminder per amendment cycle. Staff record actual committee follow-up with **Record Committee Follow-up**. This preserves the original deadline. **Receive Amended Document** requires a file, stops overdue reminders, and preserves the original and all received attachments. A new amendment cycle may be requested if further changes are needed.
6. **Proceed to 3rd Session** confirms the second session is complete and no changes remain. It is unavailable while an amendment is outstanding.
7. Upload the official **Final PDF**, then **Download Final PDF** to print the hard copy. The tracking ends here: a 3rd-Session record with its Final PDF on file shows **Completed**. The system does not generate legal text or signature templates from metadata. Upload replacements preserve earlier files in session history. (The old signed-copy upload and check step was removed; records completed that way keep their files and history as legacy.)

Session mutations reuse `encoding.create` and `encoding.register_record` permissions, with per-record visibility checks. Downloads reuse existing download permission/copy-request checks. Repository → Session Tracking provides a searchable list and stage filters; individual documents use Tracking & History. Existing documents are not assigned a stage automatically.

## Deployment and reminders

Run `php database/migrate_session_tracking.php` for this feature, or the normal `php database/upgrade.php`. The migration is idempotent and creates only the session state and event tables.

Day 1 notifications are transactional with the amendment request. Overdue processing runs during authenticated notification polling. For reminders while nobody has the application open, schedule `php bin/session_reminder_worker.php` every five minutes using the deployment's scheduler. No operating-system scheduled task is installed automatically. Concurrent runs lock each document and deduplicate reminders.

The Agenda integration can later consume `agenda_pending` records and their immutable `send_agenda` events. No connector or external delivery API is enabled now. Manual delivery is recorded separately from verification.

## Validation

`php database/test_session_workflow.php` checks the state transitions, permission denial, stale submissions, registration gating, 15-day deadline, correct notification recipients, reminder deduplication, the no-amendment route, the removed signed-copy step (its actions are rejected) and rendered final-PDF controls. It uses connection-local temporary tables and does not modify real records or send real notifications.

The same test supports real multipart uploads only through a loopback PHP development server with `LRDMS_SESSION_TEST_TOKEN` set and a matching `X-Test-Token` header, posting a PDF as `fixture`. It tests amendment receipt, final replacements, download permissions and preserved originals. It cleans up its uniquely generated local files and refuses remote storage.
