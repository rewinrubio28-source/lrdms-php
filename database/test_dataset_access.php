<?php
if (PHP_SAPI!=='cli') exit;
$case=$argv[1]??null;
if ($case!==null) {
    session_start(['save_path'=>sys_get_temp_dir()]);
    $_SESSION=[]; $_GET=[]; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='import_records.php';
    require_once __DIR__.'/../includes/rbac.php';
    if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local tests only.');
    $pdo=get_db();
    foreach (['documents','integration_receipts','audit_log','users','roles','permissions','role_permissions','user_profile_photos'] as $table) {
        $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
        $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
        $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
        $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
    }
    if ($case!=='guest') {
        $GLOBALS['__lrdms_current_user']=['id'=>900,'username'=>'test-import','full_name'=>'Import test','role_id'=>900,'role_name'=>'Import test','must_change_password'=>0];
        if ($case!=='denied') {
            $pdo->exec("INSERT INTO permissions (id,module,action) VALUES (1,'encoding','create'),(2,'audit','view'),(3,'audit','export')");
            $pdo->exec('INSERT INTO role_permissions VALUES (900,1),(900,2),(900,3)');
        }
    }
    register_shutdown_function(static function() use($pdo) {
        fwrite(STDERR,'STATUS='.http_response_code().' DOCS='.$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn().' PENDING='.(isset($_SESSION['dataset_preview'])?'1':'0'));
    });
    if ($case==='csrf') { $_SERVER['REQUEST_METHOD']='POST'; $_POST=['action'=>'confirm','csrf_token'=>'wrong']; }
    if (in_array($case,['expired','replay','wrong-token','confirm'],true)) {
        require_once __DIR__.'/../includes/dataset_import.php';
        $_SERVER['REQUEST_METHOD']='POST'; $_SESSION['csrf_token']='test'; $_POST=['action'=>'confirm','csrf_token'=>'test','preview_token'=>'test-preview','is_public'=>'1','owner_id'=>1234];
        if ($case!=='replay') $_SESSION['dataset_preview']=['user_id'=>900,'expires'=>time()+($case==='expired'?-10:900),'token'=>'test-preview','rows'=>dataset_validate([['doc_number'=>'CONFIRM-1','title'=>'Sample','source_system'=>'External']])];
        if ($case==='wrong-token') $_POST['preview_token']='wrong';
    }
    if ($case==='template') $_GET['template']='xlsx';
    if ($case==='preview') {
        require_once __DIR__.'/../includes/dataset_import.php';
        $_SESSION['dataset_preview']=['user_id'=>900,'expires'=>time()+900,'token'=>'test-preview','rows'=>dataset_validate([['doc_number'=>'PREVIEW-1','title'=>'Sample imported record','source_system'=>'External source']])];
    }
    if ($case==='export' || $case==='export-invalid') {
        $_SERVER['PHP_SELF']='api/export_audit.php';
        if ($case==='export-invalid') $_GET['module']=[];
        else {
            $stmt=$pdo->prepare("INSERT INTO audit_log (username_snapshot,module,action,detail) VALUES ('test','encoding','test',?)");
            for ($i=1;$i<=1005;$i++) $stmt->execute(['Audit row '.$i]);
            $_GET['module']='encoding';
            $pdo->exec("INSERT INTO audit_log (username_snapshot,module,action,detail) VALUES ('hidden','access','test','not in export')");
        }
        require __DIR__.'/../api/export_audit.php'; exit;
    }
    require __DIR__.'/../import_records.php'; exit;
}
foreach (['guest','denied','csrf','expired','replay','wrong-token','confirm','template','preview','export','export-invalid'] as $case) {
    $process=proc_open([PHP_BINARY,'-d','extension=zip',__FILE__,$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start endpoint test.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
    if ($exit!==0 || !str_contains($err,'DOCS='.($case==='confirm'?'1':'0')) || str_contains($err,'Warning') || str_contains($out,'Warning:')) throw new RuntimeException($case.' failed: '.$err);
    $ok=match($case) {
        'guest'=>str_contains($err,'STATUS=302') && !str_contains($out,'Dataset file'),
        'denied'=>str_contains($err,'STATUS=403') && !str_contains($out,'Dataset file'),
        'csrf'=>str_contains($out,'Security token expired.'),
        'expired','replay','wrong-token'=>str_contains($out,'Preview expired or already used.'),
        'confirm'=>str_contains($err,'STATUS=302') && str_contains($err,'PENDING=0'),
        'template'=>str_starts_with($out,'PK'),
        'preview'=>str_contains($out,'Review 1 records') && str_contains($out,'PREVIEW-1'),
        'export'=>str_starts_with($out,"\xEF\xBB\xBF") && substr_count($out,'Audit row ')===1005 && !str_contains($out,'not in export'),
        'export-invalid'=>str_contains($err,'STATUS=422'),
    };
    if (!$ok) throw new RuntimeException('Endpoint assertion failed: '.$case.' '.$err);
    if ($case==='template') {
        require_once __DIR__.'/../includes/dataset_import.php';
        $path=__DIR__.'/../.runtime/import-test/template.xlsx'; file_put_contents($path,$out);
        $rows=dataset_parse($path,'template.xlsx');
        if ($rows[0]['enactment_date']!=='2026-01-15') throw new RuntimeException('Template date did not round trip.');
    }
    if ($case==='preview' || $case==='csrf') {
        $base='file:///'.str_replace('\\','/',dirname(__DIR__)).'/';
        file_put_contents(__DIR__.'/../.runtime/import-test/'.$case.'.html',str_replace('<head>','<head><base href="'.$base.'">',$out));
    }
    echo 'PASS: import/export endpoint '.$case."\n";
}
