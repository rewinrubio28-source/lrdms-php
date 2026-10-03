<?php
if (PHP_SAPI!=='cli') exit;
if (isset($argv[1])) {
    session_start(['save_path'=>sys_get_temp_dir()]);
    require_once __DIR__.'/../includes/auth.php';
    if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local test only.');
    $pdo=get_db();
    $pdo->exec('CREATE TEMPORARY TABLE permissions (id INT, module VARCHAR(100), action VARCHAR(100))');
    $pdo->exec('CREATE TEMPORARY TABLE role_permissions (role_id INT, permission_id INT)');
    $pdo->exec("INSERT INTO permissions VALUES (1,'encoding','create')");
    $pdo->exec('INSERT INTO role_permissions VALUES (987,1)');
    $case=$argv[1];
    $_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']='/api/ocr_scan.php';
    $_SERVER['REQUEST_METHOD']=$case==='method'?'GET':'POST';
    $_SESSION['csrf_token']='fixture-token';
    $_POST['csrf_token']=$case==='csrf'?['invalid']:'fixture-token';
    $_FILES['scan_file']=['name'=>['bad'],'error'=>0,'tmp_name'=>''];
    $GLOBALS['__lrdms_current_user']=$case==='guest'?null:['id'=>987,'role_id'=>$case==='denied'?988:987,'must_change_password'=>$case==='forced-password'?1:0];
    register_shutdown_function(static function() { fwrite(STDERR,'STATUS='.(http_response_code()?:200)); });
    require __DIR__.'/../api/ocr_scan.php'; exit;
}
foreach (['guest'=>401,'denied'=>403,'forced-password'=>403,'method'=>405,'csrf'=>403,'malformed'=>422] as $case=>$status) {
    $p=proc_open([PHP_BINARY,__FILE__,$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    if ($exit!==0 || !str_contains($err,'STATUS='.$status) || !is_array(json_decode($out,true))) throw new RuntimeException('OCR '.$case.' failed: '.$err);
    echo "PASS: OCR $case rejected with JSON/$status\n";
}
