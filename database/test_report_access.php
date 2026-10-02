<?php
if (PHP_SAPI!=='cli') exit;
$case=$argv[1]??null;
if ($case!==null) {
    session_start(['save_path'=>sys_get_temp_dir()]); $_SESSION=[];
    $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='reports.php'; $_GET=[]; $_POST=[];
    require_once __DIR__.'/../includes/report_schedules.php';
    if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local tests only.');
    $pdo=get_db();
    foreach (['documents','users','roles','permissions','role_permissions','audit_log','user_profile_photos'] as $table) {
        $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
        $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
        $ddl=preg_replace('/,\n\)/',"\n)",$ddl); $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
    }
    foreach (report_schedule_schema() as $ddl) { $ddl=preg_replace('/^\s*FOREIGN KEY.*$/m','',$ddl); $ddl=preg_replace('/,\s*\)/',"\n)",$ddl); $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl)); }
    $pdo->exec("INSERT INTO roles (id,name) VALUES (900,'Report administrator'),(901,'Own records'),(902,'Read only')");
    $pdo->exec("INSERT INTO permissions (id,module,action) VALUES (1,'repository','view_all'),(2,'repository','view_own'),(3,'repository','download'),(4,'repository','print_record')");
    $pdo->exec('INSERT INTO role_permissions VALUES (900,1),(900,3),(900,4),(901,2),(901,3),(902,1)');
    $pdo->exec("INSERT INTO users (id,username,full_name,password_hash,role_id,is_active,must_change_password) VALUES (1,'report-test','Report Test','not-real',900,1,0),(2,'other','Other','not-real',901,1,0)");
    $pdo->exec("INSERT INTO documents (id,doc_number,title,doc_type,status,source_system,owner_id,is_public,verified_at,created_at) VALUES (1,'REPORT-1','Sample registered record','Ordinance','Enacted','Test source',2,0,'2026-10-01','2026-10-01')");
    $user=$pdo->query('SELECT u.*,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=1')->fetch();
    if ($case!=='guest') $GLOBALS['__lrdms_current_user']=$user;
    if ($case==='readonly') $GLOBALS['__lrdms_current_user']['role_id']=902;
    if ($case==='revoked' || $case==='print-denied') $GLOBALS['__lrdms_current_user']['role_id']=901;
    if ($case==='forced-password') $GLOBALS['__lrdms_current_user']['must_change_password']=1;
    register_shutdown_function(static function() use($pdo) { fwrite(STDERR,'STATUS='.(http_response_code()?:200).' SCHEDULES='.$pdo->query('SELECT COUNT(*) FROM report_schedules')->fetchColumn()); });
    if (in_array($case,['ready','other-owner','expired','revoked'],true)) {
        $pdo->exec("INSERT INTO report_schedules (id,user_id,title,options_json,frequency,run_time,next_run_at) VALUES (1,1,'Test schedule','{}','daily','08:00','2026-10-03')");
        $snapshot=record_report_build($pdo,$user,['from'=>'2026-10-01','to'=>'2026-10-02']);
        $pdo->prepare("INSERT INTO report_runs (id,schedule_id,user_id,scheduled_for,status,created_at,snapshot_json,pdf_bytes) VALUES (1,1,?,?,'Ready',?,?,?)")->execute([$case==='other-owner'?2:1,date('Y-m-d H:i:s'),$case==='expired'?'2000-01-01':date('Y-m-d H:i:s'),json_encode($snapshot),'%PDF-synthetic-access-fixture']);
        $_GET['id']=1; require __DIR__.'/../api/download_scheduled_report.php'; exit;
    }
    if (in_array($case,['preview','csrf','create'],true)) {
        $_GET=['from'=>'2026-10-01','to'=>'2026-10-02','generate'=>'1','title'=>'Custom <report>'];
        if ($case==='csrf' || $case==='create') {
            $_SERVER['REQUEST_METHOD']='POST'; $_SESSION['csrf_token']='test-csrf'; $_POST=$_GET+['action'=>'schedule','frequency'=>'daily','run_time'=>'08:00','csrf_token'=>$case==='create'?'test-csrf':'wrong'];
        }
        require __DIR__.'/../reports.php'; exit;
    }
    if ($case==='method') $_SERVER['REQUEST_METHOD']='POST';
    $_GET=['format'=>$case==='print-denied'?'print':'csv','from'=>'2026-10-01','to'=>'2026-10-02'];
    require __DIR__.'/../api/export_records_report.php'; exit;
}
foreach (['guest','method','forced-password','readonly','print-denied','export','ready','other-owner','expired','revoked','preview','csrf','create'] as $case) {
    $process=proc_open([PHP_BINARY,__FILE__,$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start report endpoint test.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
    if ($exit!==0 || str_contains($err,'Warning') || str_contains($out,'Warning:')) throw new RuntimeException($case.' failed: '.$err);
    $expected=match($case){'guest','forced-password'=>401,'method'=>405,'readonly','print-denied','revoked'=>403,'other-owner','expired'=>404,'create'=>302,default=>200};
    if (!str_contains($err,'STATUS='.$expected)) throw new RuntimeException('Status failed: '.$case.' '.$err);
    if ($case==='csrf' && (!str_contains($out,'Security token expired') || !str_contains($err,'SCHEDULES=0'))) throw new RuntimeException('CSRF check failed.');
    if ($case==='create' && !str_contains($err,'SCHEDULES=1')) throw new RuntimeException('Schedule creation failed.');
    if ($case==='export' && (!str_starts_with($out,"\xEF\xBB\xBF") || !str_contains($out,'REPORT-1'))) throw new RuntimeException('CSV export failed.');
    if ($case==='ready' && !str_starts_with($out,'%PDF-')) throw new RuntimeException('Owner download failed.');
    if ($expected>=400 && str_starts_with($out,'%PDF-')) throw new RuntimeException('Protected PDF leaked.');
    if ($case==='preview') {
        if (!str_contains($out,'Custom &lt;report&gt;') || str_contains($out,'Custom <report>')) throw new RuntimeException('Preview escaping failed.');
        $directory=__DIR__.'/../.runtime/report-test'; if (!is_dir($directory)) mkdir($directory,0700,true);
        $base='file:///'.str_replace('\\','/',dirname(__DIR__)).'/'; file_put_contents($directory.'/preview.html',str_replace('<head>','<head><base href="'.$base.'">',$out));
    }
    echo 'PASS: report endpoint '.$case."\n";
}
