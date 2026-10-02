<?php
/** CLI deployment upgrade; never seeds or replaces existing records. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('LRDMS_BACKUP_CLI', true);
require_once __DIR__ . '/../config/database.php';
$steps = ['migrate_revision_v5.php', 'migrate_organization_permissions.php', 'migrate_action_permissions.php', 'migrate_council_term.php', 'migrate_profile_photos.php', 'migrate_record_followups.php', 'migrate_retrieval.php', 'migrate_ocr_jobs.php', 'migrate_privacy.php', 'migrate_reports.php', 'migrate_database_indexes.php'];
try {
    $lock = records_maintenance_lock(true);
    $pdo = get_db();
    $required = ['documents' => ['id','verified_at','source_system'], 'users' => ['id','committee_id'], 'permissions' => ['id','module','action'], 'roles' => ['id'], 'committees' => ['id']];
    foreach ($required as $table => $columns) {
        $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($columns, $existing)) throw new RuntimeException('Apply the original application migrations first; required columns missing in ' . $table);
    }
    foreach ($steps as $step) {
        echo "Applying $step ...\n";
        // Isolate legacy functions/exit calls. Parent owns the maintenance lock.
        $code = "define('LRDMS_BACKUP_CLI', true); require " . var_export(__DIR__ . '/' . $step, true) . ';';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Migration failed: ' . $step . '. Fix the reported cause, then rerun this command.');
    }
    require __DIR__ . '/migrate_records_role_policy.php';
    foreach (['offices','divisions','positions','user_committees','record_validation_history','integration_receipts','user_profile_photos','record_followups','document_copy_requests','report_schedules','report_runs'] as $table) $pdo->query("SELECT 1 FROM `$table` LIMIT 0");
    $pdo->query('SELECT records_status, classification, originating_office, originating_division, council_term, registered_at FROM documents LIMIT 0');
    $pdo->query('SELECT office_id, division_id, position_id FROM users LIMIT 0');
    echo "UPGRADE COMPLETE: required tables and columns verified. No demo data added.\nReview new role permissions before using registration and retrieval actions.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'UPGRADE FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
