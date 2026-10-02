<?php
if (PHP_SAPI!=='cli') exit;
define('LRDMS_BACKUP_CLI',true);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/backup_schedule.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Recovery drill is local-only.');
function recovery_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
function recovery_reject(callable $fn,string $message): void { $failed=false; try {$fn();} catch (Throwable $e) {$failed=true;} recovery_check($failed,$message); }
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false];
$server=new PDO('mysql:host='.DB_HOST.';charset=utf8mb4',DB_USER,DB_PASS,$options);
$suffix=bin2hex(random_bytes(6)); $source='lrdms_test_s6_'.$suffix; $restored='lrdms_restore_s6_'.$suffix; $badRestore='lrdms_restore_bad_'.$suffix;
$created=[]; $root=__DIR__.'/../.runtime/recovery-'.$suffix; mkdir($root,0700,true); mkdir($root.'/uploads'); mkdir($root.'/sets');
$started=microtime(true);
try {
    // CREATE without IF NOT EXISTS: no existing database can be replaced.
    $server->exec('CREATE DATABASE '.backup_identifier($source).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created[]=$source;
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.$source.';charset=utf8mb4',DB_USER,DB_PASS,$options);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (get_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec(get_db()->query('SHOW CREATE TABLE '.backup_identifier($table))->fetch(PDO::FETCH_NUM)[1]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Synthetic recovery role')");
    $pdo->exec("INSERT INTO users (id,username,full_name,password_hash,role_id) VALUES (1,'recovery-fixture','Synthetic User','not-a-real-password',1)");
    file_put_contents($root.'/uploads/original.txt',"Synthetic original — ñ\n"); file_put_contents($root.'/uploads/request.txt','Synthetic access request letter'); file_put_contents($root.'/uploads/.htaccess','Require all denied');
    $pdo->exec("INSERT INTO documents (id,doc_number,title,doc_type,owner_id,file_path) VALUES (1,'RECOVERY-1','Unicode ñ original','Ordinance',1,'uploads/original.txt'),(2,'RECOVERY-2','Revision','Ordinance',1,NULL)");
    $pdo->exec('UPDATE documents SET next_version_id=2 WHERE id=1'); $pdo->exec('UPDATE documents SET previous_version_id=1 WHERE id=2');
    $pdo->exec("INSERT INTO document_attachments (document_id,file_path,display_name) VALUES (1,'uploads/original.txt','Original')");
    $pdo->exec("INSERT INTO access_requests (document_id,requester_id,requester_name,requester_email,purpose,request_letter_path) VALUES (1,1,'Fixture','fixture@example.invalid','Recovery fixture','uploads/request.txt')");
    $pdo->prepare("INSERT INTO user_profile_photos (user_id,mime_type,image_data) VALUES (1,'image/png',?)")->execute(["\x00\xFF\x01binary fixture"]);
    recovery_reject(fn()=>$pdo->exec("INSERT INTO document_attachments (document_id,file_path,display_name) VALUES (999,'uploads/original.txt','Orphan')"),'Foreign key rejects an orphan attachment');
    recovery_reject(fn()=>$pdo->exec('DELETE FROM documents WHERE id=1'),'Foreign key prevents deletion of a referenced original');
    $fkCount=backup_assert_integrity($pdo); recovery_check($fkCount>=63,'All declared foreign-key relationships pass on the source fixture');
    $backupStart=microtime(true); $set=backup_create($pdo,$root.'/uploads',$root.'/sets'); $backupSeconds=microtime(true)-$backupStart;
    $manifest=backup_verify($root.'/sets/'.$set); recovery_check(count($manifest['tables'])===38,'Complete backup contains all 38 application tables');
    $restoreStart=microtime(true);
    $result=backup_restore($server,$root.'/sets/'.$set,$restored,$root.'/sets/'.$restored,DB_NAME); $created[]=$restored; $restoreSeconds=microtime(true)-$restoreStart;
    recovery_check($result['foreign_keys']===$fkCount && is_file($root.'/sets/'.$restored.'/restore-complete.json'),'Restore verifies every table/row and foreign key before success marker');
    recovery_check($server->query('SELECT image_data FROM user_profile_photos WHERE user_id=1')->fetchColumn()==="\x00\xFF\x01binary fixture",'Binary fields survive the backup/restore round trip');
    recovery_check(hash_file('sha256',$root.'/uploads/original.txt')===hash_file('sha256',$root.'/sets/'.$restored.'/uploads/original.txt'),'Original uploaded bytes are restored exactly');
    recovery_check(file_get_contents($root.'/sets/'.$restored.'/uploads/request.txt')==='Synthetic access request letter','Access-request letter is included and referenced correctly');
    recovery_reject(fn()=>backup_restore($server,$root.'/sets/'.$set,$restored,$root.'/sets/'.$restored,DB_NAME),'Restore refuses an existing destination');
    recovery_reject(fn()=>backup_restore($server,$root.'/sets/'.$set,DB_NAME,$root.'/live-target',DB_NAME),'Restore refuses the active database');
    file_put_contents($root.'/sets/'.$set.'/uploads/original.txt','tampered');
    recovery_reject(fn()=>backup_verify($root.'/sets/'.$set),'Checksum verification rejects changed file content');
    copy($root.'/uploads/original.txt',$root.'/sets/'.$set.'/uploads/original.txt');
    unlink($root.'/uploads/request.txt');
    recovery_reject(fn()=>backup_create($pdo,$root.'/uploads',$root.'/sets'),'Missing request letter prevents a misleading complete backup');
    file_put_contents($root.'/uploads/request.txt','Synthetic access request letter');
    // A checksum-valid archive can still contain inconsistent relationships.
    $dataPath=$root.'/sets/'.$set.'/database.json'; $originalData=file_get_contents($dataPath); $data=json_decode($originalData,true);
    $data['document_attachments']['rows'][0]['document_id']=base64_encode('999'); backup_json($dataPath,$data);
    $manifest['files']['database.json']=hash_file('sha256',$dataPath); backup_json($root.'/sets/'.$set.'/manifest.json',$manifest);
    try { backup_restore($server,$root.'/sets/'.$set,$badRestore,$root.'/sets/'.$badRestore,DB_NAME); $created[]=$badRestore; throw new LogicException('Unexpected restore success.'); }
    catch (RuntimeException $e) { if (str_contains($e->getMessage(),'orphan')) $created[]=$badRestore; recovery_check(str_contains($e->getMessage(),'orphan'),'Explicit post-load validation rejects orphans even with matching checksums'); }
    recovery_check(!is_file($root.'/sets/'.$badRestore.'/restore-complete.json'),'Failed restore has no success marker');
    file_put_contents($dataPath,$originalData); $manifest['files']['database.json']=hash_file('sha256',$dataPath); backup_json($root.'/sets/'.$set.'/manifest.json',$manifest);
    $zone=new DateTimeZone('Asia/Manila'); $now=new DateTimeImmutable('2026-10-02 02:15:00',$zone);
    recovery_check(backup_schedule_slot($now,'02:00')==='2026-10-02' && backup_schedule_slot($now->setTime(3,0),'02:00')===null,'Daily 02:00 schedule is limited to its one-hour run window');
    recovery_check(backup_schedule_slot(new DateTimeImmutable('2026-10-03 00:15:00',$zone),'23:30')==='2026-10-02','Backup schedule handles a window crossing midnight');
    mkdir($root.'/scheduled'); $calls=0;
    $create=static function() use($pdo,$root,&$calls,$now) { $calls++; $name=backup_create($pdo,$root.'/uploads',$root.'/scheduled'); $file=$root.'/scheduled/'.$name.'/manifest.json'; $m=json_decode(file_get_contents($file),true); $m['created_utc']=$now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM); backup_json($file,$m); return $name; };
    $scheduled=backup_schedule_tick($root.'/scheduled',$now,'02:00',$create);
    recovery_check($scheduled['status']==='complete','Scheduled callback creates and verifies a real backup');
    recovery_check(backup_schedule_tick($root.'/scheduled',$now,'02:00',$create)['status']==='already-complete' && $calls===1,'Repeated scheduler pass does not duplicate the daily backup');
    unlink($root.'/scheduled/.scheduler-state.json');
    recovery_check(backup_schedule_tick($root.'/scheduled',$now,'02:00',$create)['status']==='already-complete' && $calls===1,'Scheduler recovers completion from a verified set after state loss');
    printf("Recovery drill: backup %.3f s; restore %.3f s; %d tables; %d foreign keys. Synthetic data only.\n",$backupSeconds,$restoreSeconds,$result['tables'],$fkCount);
    $evidence=['tables'=>$result['tables'],'foreign_keys'=>$fkCount,'backup_seconds'=>round($backupSeconds,3),'restore_seconds'=>round($restoreSeconds,3),'synthetic'=>true,'completed_utc'=>gmdate(DATE_ATOM)];
    if (!is_dir(__DIR__.'/../.runtime/database-test')) mkdir(__DIR__.'/../.runtime/database-test',0700,true);
    backup_json(__DIR__.'/../.runtime/database-test/recovery.json',$evidence);
} finally {
    $pdo=null;
    // Only exact fresh names allocated by this drill; never configured DB_NAME.
    foreach (array_unique($created) as $database) {
        if (!in_array($database,[$source,$restored,$badRestore],true) || $database===DB_NAME) throw new RuntimeException('Unsafe fixture cleanup target.');
        $server->exec('DROP DATABASE '.backup_identifier($database));
    }
}
echo "Synthetic database fixtures removed. Local test artifacts retained under .runtime/recovery-$suffix.\n";
