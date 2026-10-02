# Local query performance evidence

Synthetic, warm-cache SQL benchmark; not production request latency.

Server: `10.4.32-MariaDB`. Rows: **20,000 per table** in documents, audit_log and search_log. Ten measured executions per query; median shown. All tables were connection-temporary. No production records changed.

| Query | Before median (ms) | After median (ms) | Before access/key | After access/key |
|---|---:|---:|---|---|
| Document-number duplicate check | 40.075 | 6.04 | ALL/none | ref/idx_document_number |
| Date-filtered records | 22.464 | 2.362 | ALL/none | range/idx_document_created |
| Incoming queue | 16.887 | 0.548 | ALL/none | ref/idx_document_intake |
| Audit date export | 20.615 | 0.585 | ALL/none | range/idx_audit_created |
| Audit module/date export | 15.061 | 0.617 | ALL/none | range/idx_audit_module_created |
| Search date metrics | 14.991 | 0.68 | ALL/none | range/idx_search_created |
| Own search history | 0.655 | 0.416 | ref/user_id | ref/idx_search_user_created |

All result fingerprints matched before/after; the migration was applied a second time and added no duplicate indexes. Incoming-queue and own-search examples use ID as a tie-breaker for equal timestamps.

These figures exclude HTTP rendering, network/proxy latency, concurrent users, cold-cache reads, real document sizes and write overhead. The optimizer may choose scans on small tables or different data distributions. New indexes add storage and write-maintenance costs. Do not promise these ratios on production.

Reproduce with `php database/test_database_performance.php` against a local test-capable database. Full synthetic query plans are in [database-performance.json](database-performance.json).
