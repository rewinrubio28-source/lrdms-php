<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('LRDMS_BACKUP_CLI',true);
// RBAC loads the shared auth helpers; the worker does not use a browser session.
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/report_schedules.php';
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
set_time_limit(0);
$once=in_array('--once',$argv,true);
do {
    $lock=null; $run=null;
    try {
        $lock=records_maintenance_lock();
        $run=report_schedule_tick(get_db());
        if ($run) {
            $query=get_db()->prepare('SELECT status FROM report_runs WHERE id=?'); $query->execute([$run]);
            echo date('c').' Report run '.$run.': '.$query->fetchColumn()."\n";
        }
    } catch (Throwable $e) { error_log('Report worker: '.$e->getMessage()); if ($once) exit(1); }
    finally { if ($lock) { flock($lock,LOCK_UN); fclose($lock); } }
    if (!$once && !$run) sleep(30);
} while (!$once);
