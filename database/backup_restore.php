<?php
/** CLI-only backup/restore. Restore always creates a new database and files. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('LRDMS_BACKUP_CLI',true);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/backup_tools.php';
try {
    $command=$argv[1]??'';
    if (!in_array($command,['backup','verify','restore'],true)) {
        echo "Usage: php database/backup_restore.php backup | verify SET | restore SET lrdms_restore_NAME\n";
        exit;
    }
    $root=dirname(__DIR__); $directory=backup_directory();
    if ($command==='backup') {
        $lock=records_maintenance_lock(true);
        try { $name=backup_create(get_db(),$root.'/uploads',$directory); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
        echo "Backup complete: $name\n";
    } else {
        $name=$argv[2]??'';
        if (!preg_match('/^records-[A-Za-z0-9-]+$/D',$name)) throw new RuntimeException('Provide a completed backup set name.');
        if ($command==='verify') { backup_verify($directory.'/'.$name); echo "Checksums verified: $name\n"; exit; }
        $target=$argv[3]??'';
        $server=new PDO('mysql:host='.DB_HOST.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
        $result=backup_restore($server,$directory.'/'.$name,$target,$directory.'/'.$target,DB_NAME);
        echo "Restore complete. New database: $target\nMatching uploads: $directory/$target/uploads\nForeign keys checked: ".$result['foreign_keys']."\nLive database and uploads were not replaced.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR,'FAILED: '.$e->getMessage()."\nPartial sets and failed restore targets are retained for inspection; do not activate them.\n"); exit(1);
}
