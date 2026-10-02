# Section 6 — Database architecture

Implemented and locally verified on 2026-10-02. Production schema/backup activation remains an operational follow-up, not a claimed completed live deployment.

| Checklist item | Completed work | Remaining evidence / limit |
|---|---|---|
| Database Normalization | Entity/relationship rationale, core ER diagram and full generated ER diagram. | Deliberate snapshots, JSON payloads and hierarchy/version redundancies are documented; no blanket 3NF certification. |
| Foreign Key Integrity | Schema inspection; zero local orphan rows over 63 declared FKs; added previous/next-version constraints with preflight checks. | Inspect the migrated live schema. Undeclared logical relationships and version graph consistency are not established by FK counts. |
| Data Dictionary | All 38 tables and 290 columns documented with type, nullability, default, key, description and indexes. | Regenerate after schema changes and compare with live metadata. |
| Index Optimization | Seven targeted indexes; repeat application creates no duplicates. | Production plan selection/write overhead remains unmeasured. |
| Query Performance | Seven queries tested before/after on 20,000 synthetic rows per table; exact results preserved; plans and timings committed. | Warm-cache local SQL benchmark, not a concurrent-user SLA or live latency measurement. |
| Backup Procedures | CLI backup now checks all declared FKs and request-letter files; supervised daily backup scheduler at approved 02:00 Asia/Manila. | Live automatic backup remains disabled until a secured persistent BACKUP_DIR is configured and BACKUP_ENABLED=1. Single-instance, local uploads only. |
| Restore Procedures | Full-schema synthetic backup/restore drill, every restored row compared, binary/file checks, post-load orphan rejection and success marker. | Live recovery and object-storage coordination remain untested. Production activation must switch the database and matching files together. |

## Evidence produced

- [Database architecture and normalization](database-architecture.md)
- [Complete data dictionary](database-data-dictionary.md)
- [Full ER diagram](database-erd.mmd) and [schema metadata](database-schema.json)
- [Query benchmark and plans](database-performance.md)
- [Backup and recovery procedure](backup-restore.md)

The baseline had 61 declared FKs. Adding the two document-version references brought it to 63; a read-only audit found zero declared-FK orphans before and after. This does not imply every business rule is encoded as a database constraint.

## Recovery drill

`database/test_backup_recovery.php` creates fresh random-name local databases, copies the application **schema only**, and inserts synthetic fixtures. It never copies live account/document data. The successful drill checked:

1. Orphan attachment insertion and deletion of a referenced original are rejected by the database.
2. All 38 tables are backed up and restored into a new database.
3. All restored row values compare with the encoded originals, including binary profile data; uploaded originals and request letters match.
4. Existing restore destinations and the configured active database are refused.
5. Changed file bytes fail checksum verification; missing request-letter files prevent a completed backup.
6. A checksum-valid archive containing a deliberately introduced orphan fails **after loading**, and receives no restore success marker.
7. Scheduler execution creates a real verified backup, prevents duplicate daily runs and recovers completion after losing its state file. Time-window logic includes midnight crossing.

The initial full drill measured approximately **8.6 seconds for backup and 10.3 seconds for restore**, for the small synthetic fixture across 38 tables/63 FKs. Those values are not recovery-time guarantees for production-sized datasets. Fresh test databases are removed after the drill; ignored files remain under `.runtime/recovery-*` for inspection. Exact timing may change on reruns.

Reproduce locally:

```sh
php database/test_database_performance.php
php database/test_backup_recovery.php
php database/inspect_database.php --integrity
```

The performance test uses connection-temporary tables. The recovery drill needs local CREATE/DROP DATABASE privileges for its exact, randomly named synthetic targets. Do not run the fixture drill against a remote/production host.

## Restore validation improvement

Re-enabling `FOREIGN_KEY_CHECKS` does not retroactively verify the rows loaded while it was disabled. The restore tool now explicitly checks every declared FK with an anti-join before writing `restore-complete.json`. This closes the previous possibility of declaring an inconsistent restore complete. See the [MySQL foreign-key documentation](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html).

The logical format and commands remain compatible with existing `lrdms-local-v1` sets. The new tool performs stronger validation, so a previously unchecked inconsistent set may now fail. SHA-256 checksums detect changed bytes; they are not proof of backup authenticity. Restore only trusted administrator-controlled sets.

## Deployment

The query-index/version-FK migration is included in the normal startup upgrade. Standard ALTER TABLE operations can take time and hold locks on a large database; deploy in a maintenance window with a known recoverable backup. The migration checks version orphans and stops rather than repairing business data automatically.

The supervised backup worker stays idle by default. Enable it only after configuring persistent protected storage and confirming a single application instance/local-upload deployment. Approved time is 02:00 Asia/Manila, with retries during the following hour if the exclusive maintenance lock is busy. New PHP requests receive maintenance responses while a backup owns that lock. A missed window waits until the next day; the worker does not unexpectedly start maintenance at noon after a redeploy.

Docker/supervisor behavior, actual volume durability, off-host copying, production SQL plans and live scheduled logs were not verified here. The repository now provides the implementation and reproducible local evidence; hosting configuration and a live recovery demonstration still need to be completed.
