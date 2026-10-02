<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('LRDMS_BACKUP_CLI',true);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/backup_schedule.php';
set_time_limit(0); $once=in_array('--once',$argv,true);
if (env_optional('BACKUP_ENABLED','0')!=='1') {
    echo "Automatic backup disabled. Configure persistent BACKUP_DIR, then set BACKUP_ENABLED=1.\n";
    if ($once) exit;
    while (true) sleep(60);
}
// An explicitly configured destination avoids treating the container layer as durable backup storage.
if (env_optional('BACKUP_DIR','')==='') { fwrite(STDERR,"Set BACKUP_DIR to a secured persistent volume before enabling automatic backup.\n"); exit(1); }
do {
    try {
        $directory=backup_directory();
        $result=backup_schedule_tick($directory,new DateTimeImmutable(),env_optional('BACKUP_TIME','02:00'),static function() use($directory) {
            $lock=records_maintenance_lock(true);
            try { return backup_create(get_db(),dirname(__DIR__).'/uploads',$directory); }
            finally { flock($lock,LOCK_UN); fclose($lock); }
        },DB_NAME);
        if ($result['status']==='complete') echo date('c').' '.json_encode($result)."\n";
        elseif ($once) echo $result['status']."\n";
    } catch (Throwable $e) { error_log('Automatic backup: '.$e->getMessage()); if ($once) exit(1); }
    if (!$once) sleep(60);
} while (!$once);
