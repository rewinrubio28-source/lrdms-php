<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Acquire the maintenance lock per job, not while waiting for work.
define('LRDMS_BACKUP_CLI', true);
require_once __DIR__ . '/../includes/ocr_jobs.php';
require_once __DIR__ . '/../includes/storage.php';
set_time_limit(0);
$once = in_array('--once', $argv, true);
do {
    $lock = records_maintenance_lock();
    try {
        $pdo = get_db();
        $job = ocr_job_claim($pdo);
        if ($job) ocr_job_execute($pdo, $job, 'storage_run_ocr');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$once && !$job) sleep(3);
} while (!$once);
