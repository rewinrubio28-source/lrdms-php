# Manually recorded document source history

Document Details → Tracking & History separates prior source events from LRDMS receiving and registration events. The latter is processing history, not a complete listing of download/view audit events.

Users who can view the document and have `repository/edit_metadata` can select **Add source event**. Enter an event date, action, source office/system, optional destination and source actor, and remarks. Choose **Simulated demo** for fictional presentation entries. **Manual source record** requires a supporting reference (document/page or attachment filename). References are descriptive text, not verified links or uploaded evidence.

The event date is a date supplied by the recorder. A separate database timestamp and signed-in account identify when/by whom it was entered in LRDMS. No entry claims to be received through an API. Adding history does not approve, register, or change the document's status. Entries are append-only; clarify a mistake in a new entry referencing its ID.

Deployment: `database/upgrade.php` includes `migrate_source_history.php`. Manual installations can run `php database/migrate_source_history.php`. The migration creates an empty table and can be rerun. The view reports unavailability until migration runs. No demo records are seeded.

Validation: `php database/test_source_history.php` exercises temporary-table writes, manual/demo provenance, required references, malformed inputs, permission denial, missing documents, rollback, and preservation of document metadata/status. The existing records workflow checks also run. Live browser acceptance remains to be performed.
