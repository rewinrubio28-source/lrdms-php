# Records backup and recovery

Run these commands from the project folder. This is an administrator CLI tool, not a public web page. It uses the configured database account and PHP PDO; no extra package is required.

```sh
php database/backup_restore.php backup
php database/backup_restore.php verify records-TIMESTAMP-ID
php database/backup_restore.php restore records-TIMESTAMP-ID lrdms_restore_recovery
```

Use the actual set name printed by `backup`. A complete set contains `database.json`, `uploads/`, and `manifest.json`. Keep them together. The manifest records SHA-256 file checksums and table counts. Binary database fields (including profile photos) are base64 encoded. Restore verifies checksums, every restored database row, and referenced document files.

Backups are written to `backups/`; Apache denies HTTP access, and Git/Docker ignore their contents. Copy completed sets to separate secured storage: a backup on the same disk alone does not protect against disk failure. Restrict filesystem access because these sets contain personal records and authentication data. Preserve server configuration/secrets separately; this tool intentionally does not copy `.env` or application source. Use matching application code when recovering.

## Consistency and scope

### Automatic schedule and durable destination

The approved automatic schedule is **02:00 Asia/Manila daily**. A supervised `backup-worker` is included in deployment, but remains idle until explicitly configured:

```dotenv
BACKUP_ENABLED=1
BACKUP_TIME=02:00
BACKUP_DIR=/var/backups/lrdms
```

`/var/backups/lrdms` is an example **mounted persistent volume**, not a claim that the hosting platform creates one automatically. The directory must be writable by the container's `www-data` user, protected from HTTP access, and retained across redeploys. The tool accepts the protected application `backups/` directory or a secured absolute destination outside the application web root; automatic mode requires an explicit `BACKUP_DIR`. The example `.env` keeps automatic mode disabled until these hosting details are configured.

The CLI commands above also use `BACKUP_DIR` when configured; otherwise they retain the original `backups/` default. Copy verified sets to separately secured off-host storage. This implementation does not upload offsite, encrypt archive files or automatically delete old backups. Plan capacity and retention with the hosting administrator. Do not place `BACKUP_DIR` inside `uploads/`.

The worker attempts a backup between 02:00 and 03:00, retries once per minute after busy/failure results, and records at most one completed set per daily slot. Missing that window waits until the next day. An exclusive scheduler lock prevents overlapping backup workers; verified manifests allow completion recovery after scheduler-state loss. Startup/redeploy at noon does not trigger an immediate maintenance outage.

```sh
php bin/backup_worker.php --once
```

One-shot mode obeys enablement and the scheduled window; use the manual `backup` command for an intentionally immediate backup. Production logs are available under the supervised `backup-worker`; a success line includes the verified set name. Read logs and verify a new set before treating automatic backups as operational.

Automatic backups retain the **single-application-instance/local-upload scope** below. If production uses S3/object storage, arrange a coordinated database and bucket snapshot instead; remote references are rejected by this tool rather than silently excluded. Hosting screenshots showing a backup job alone do not prove its data is recoverable.

The shared request lock in `.runtime/records.lock` covers PHP requests that load the application configuration, including uploads and registration. Backup requires an exclusive lock; if requests are active, retry in a quiet period. During backup, new application requests receive HTTP 503 with Retry-After. The lock releases automatically when the process ends, including on failure.

Run backup on a single application instance. Pause external database writers, scheduled migrations, and jobs that directly manipulate uploads before starting. The lock cannot coordinate other hosts, direct SQL clients, or external file operations. The database uses an InnoDB consistent snapshot while local uploads are copied. Unsupported database views/triggers/routines/events and missing or remote document references cause failure instead of producing a misleading complete backup. This implementation supports the current local uploads deployment; object-storage deployments require a coordinated bucket backup. The logical snapshot is assembled in memory; for a substantially larger database use a native database backup and coordinated file snapshot.

## Restore without replacing live records

Restore requires a new database name beginning with `lrdms_restore_`. Existing databases and destination folders are refused. It creates:

- A new database with the requested name.
- Matching files in `backups/NEW_DATABASE/uploads/`.

It never changes the active database setting or live uploads. Failed backups have `.partial` in their name; failed restores remain available for inspection and must not be activated. Only a command that exits successfully and prints `Restore complete` is a validated restore.

The strengthened restore also writes `restore-complete.json` beside its restored uploads only after comparing every database row, checking every declared foreign key and checking file references. Keep that marker with the set. Re-enabling foreign-key checks alone is insufficient to validate already-loaded rows. The new validator rejects inconsistent old sets even when their file checksums match.

Request-letter files are included in reference checks, along with document attachments and legacy profile paths. Database-backed profile photos and scheduled-report PDFs are included in the binary-safe database dump.

For recovery into service: stop the web application and scheduled jobs, retain the current database and uploads for rollback, set `DB_NAME` to the successfully restored database, and replace the application's entire `uploads/` directory with that restore's matching `uploads/` directory (including `.htaccess`). Do both before restarting. Check login, document preview/download, history, and permissions before reopening access. Do not merge files from different backup dates or switch the database while leaving the old uploads active.

## Integrity checks

```sh
php database/test_record_workflow.php
php database/test_storage_integrity.php
php database/test_backup_recovery.php
```

The workflow test uses temporary tables and checks repeat registration, original-record preservation, revision linking, stale revisions, inconsistent stamps, invalid self-links, and permission denial. The storage test uses temporary files to prove an existing original cannot be overwritten.
