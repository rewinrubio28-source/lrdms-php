<?php
/** Hold a shared lock for the entire request, including file writes. */
function records_maintenance_lock(bool $exclusive = false) {
    $handle = fopen(__DIR__ . '/../.runtime/records.lock', 'c');
    if (!$handle || !flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
        if ($handle) fclose($handle);
        throw new RuntimeException('Records maintenance is in progress or requests are still active. Please retry shortly.');
    }
    return $handle;
}

if (!defined('LRDMS_BACKUP_CLI')) {
    try {
        $GLOBALS['records_request_lock'] = records_maintenance_lock();
    } catch (RuntimeException $e) {
        http_response_code(503);
        header('Retry-After: 30');
        exit($e->getMessage());
    }
}
