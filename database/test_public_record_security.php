<?php
if (PHP_SAPI!=='cli') exit;
if (isset($argv[1])) {
    require_once __DIR__.'/../config/database.php';
    if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local test only.');
    $pdo=get_db();
    foreach (['documents','document_attachments'] as $table) {
        $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
        $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
        $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
        $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
    }
    $pdo->exec("INSERT INTO documents (id,doc_number,title,doc_type,status,is_public,verified_at,owner_id,next_version_id) VALUES (1,'PUBLIC-ONE','Published original','Ordinance','Enacted',1,NOW(),1,2),(2,'HIDDEN-NEXT','Private version','Ordinance','Enacted',0,NOW(),1,NULL),(3,'UNREGISTERED','Not registered','Ordinance','Enacted',1,NULL,1,NULL)");
    $case=$argv[1]; $_GET['id']=$case==='private'?2:($case==='unregistered'?3:1);
    if ($case==='public-next') $pdo->exec('UPDATE documents SET is_public=1 WHERE id=2');
    if ($case==='unregistered-next') $pdo->exec('UPDATE documents SET is_public=1,verified_at=NULL WHERE id=2');
    register_shutdown_function(static function() { fwrite(STDERR,'STATUS='.(http_response_code()?:200)); });
    require __DIR__.'/../public_view.php'; exit;
}
foreach (['private-next','unregistered-next','public-next','private','unregistered'] as $case) {
    $p=proc_open([PHP_BINARY,__FILE__,$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    $status=in_array($case,['private','unregistered'],true)?404:200;
    if ($exit!==0 || !str_contains($err,'STATUS='.$status) || str_contains($err,'Warning')) throw new RuntimeException($case.' failed: '.$err);
    if (str_contains($out,'HIDDEN-NEXT')!==($case==='public-next')) throw new RuntimeException('Private next-version reference leaked or public link missing.');
    if ($case==='unregistered' && str_contains($out,'UNREGISTERED')) throw new RuntimeException('Unregistered document leaked.');
    echo "PASS: public reader $case\n";
}
