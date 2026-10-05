<?php
// All database writes use connection-local temporary tables. HTTP mode is only
// for PHP's local test server, with a per-run secret, to exercise real uploads.
$httpTest=PHP_SAPI==='cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    && getenv('LRDMS_SESSION_TEST_TOKEN') && hash_equals(getenv('LRDMS_SESSION_TEST_TOKEN'),$_SERVER['HTTP_X_TEST_TOKEN'] ?? '');
if (PHP_SAPI!=='cli' && !$httpTest) { http_response_code(404); exit; }
if ($httpTest) header('Content-Type: text/plain');
session_start(['save_path'=>sys_get_temp_dir()]);
require_once __DIR__.'/../includes/session_workflow.php';
require_once __DIR__.'/../includes/record_processing.php';
require_once __DIR__.'/../includes/retrieval.php';
$pdo=get_db();
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local database only.');
if ($httpTest && storage_enabled()) throw new RuntimeException('Upload tests require local storage.');
foreach (['documents','document_sessions','document_session_events','document_attachments','record_validation_history','integration_receipts','audit_log','notifications','users','user_committees','committees','document_copy_requests'] as $table) {
    $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
function test_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function test_reject(callable $fn): void { try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { return; } throw new RuntimeException('Expected rejected action.'); }
$roles=$pdo->query('SELECT name,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['Records Officer','Committee Secretary','Records Assistant'] as $role) test_check(isset($roles[$role]),'Missing test role '.$role);
$pdo->exec("INSERT INTO committees (id,name) VALUES (1,'Test Committee'),(2,'Unrelated Committee')");
$insert=$pdo->prepare('INSERT INTO users (id,username,full_name,password_hash,role_id,committee_id) VALUES (?,?,?,?,?,?)');
foreach ([[1,'officer','Records Officer',null],[2,'secretary','Committee Secretary',1],[3,'other-secretary','Committee Secretary',2],[4,'viewer','Records Assistant',null]] as [$id,$name,$role,$committee]) $insert->execute([$id,$name,$name,'unused-test-hash',$roles[$role],$committee]);
$user=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
$viewer=$pdo->query('SELECT * FROM users WHERE id=4')->fetch();
$GLOBALS['__lrdms_current_user']=$user;
function test_document(PDO $pdo,string $number): int {
    $pdo->prepare("INSERT INTO documents (doc_number,title,doc_type,owner_id,status,source_system,records_status,body,file_path) VALUES (?,?,'Ordinance',1,'Under Review','Test intake','Pending Validation','Preserved original','uploads/original-test.pdf')")->execute([$number,'Session workflow test']);
    return (int)$pdo->lastInsertId();
}
function test_step(PDO $pdo,array $user,int $id,string $action,?array $upload=null,int $committee=0): void {
    session_process($pdo,$user,$id,$action,(int)(session_state($pdo,$id)['revision'] ?? 0),'Test reference',$upload,$committee);
}
$id=test_document($pdo,'TEST-SESSION');
test_reject(fn()=>test_step($pdo,$viewer,$id,'send_agenda'));
test_reject(fn()=>test_step($pdo,$user,$id,'third_session'));
test_step($pdo,$user,$id,'send_agenda');
test_check(session_state($pdo,$id)['stage']==='agenda_pending','Agenda must be pending delivery.');
test_check($pdo->query("SELECT verified_at FROM documents WHERE id=$id")->fetchColumn()===null,'No early repository registration.');
test_reject(fn()=>process_record($pdo,$user,$id,'register_private'));
test_reject(fn()=>session_process($pdo,$user,$id,'confirm_delivery',0,'Stale form'));
test_step($pdo,$user,$id,'confirm_delivery'); test_step($pdo,$user,$id,'receive_session');
test_check((bool)$pdo->query("SELECT verified_at FROM documents WHERE id=$id")->fetchColumn(),'Receiving registers the record.');
test_reject(fn()=>test_step($pdo,$user,$id,'receive_session'));
test_reject(fn()=>test_step($pdo,$user,$id,'request_amendment',null,999999));
test_step($pdo,$user,$id,'request_amendment',null,1);
$state=session_state($pdo,$id);
test_check($state['stage']==='amendment','Amendment is in second session.');
test_check(strtotime($state['amendment_due_at'])-strtotime($state['amendment_started_at'])===15*86400,'15 calendar days.');
$recipients=$pdo->query("SELECT user_id FROM notifications WHERE type='amendment_day1' ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
test_check(array_map('intval',$recipients)===[1,2],'Notify officer and assigned secretary only.');
test_reject(fn()=>test_step($pdo,$user,$id,'third_session'));
test_check(session_send_overdue_reminders($pdo)===0,'No early overdue reminder.');
$pdo->exec("UPDATE document_sessions SET amendment_due_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE document_id=$id");
test_check(session_send_overdue_reminders($pdo)===1,'Overdue reminder generated.');
test_check(session_send_overdue_reminders($pdo)===0,'Overdue reminder deduplicated.');
test_step($pdo,$user,$id,'follow_up');
test_reject(fn()=>test_step($pdo,$user,$id,'receive_amendment'));
test_check(session_state($pdo,$id)['stage']==='amendment','Missing upload preserves stage.');
$pdf=$_FILES['fixture'] ?? null;
try {
    if ($pdf) {
        test_step($pdo,$user,$id,'receive_amendment',$pdf);
        test_check(session_state($pdo,$id)['stage']==='amendment_received','Received upload completes amendment.');
        test_check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE document_id=$id AND is_read=0")->fetchColumn()===0,'Receipt clears amendment reminders.');
        test_check(session_send_overdue_reminders($pdo)===0,'No reminder after receipt.');
        test_step($pdo,$user,$id,'third_session');
        test_reject(fn()=>test_step($pdo,$user,$id,'upload_signed',$pdf));
        $fake=$pdf; $fake['name']='wrong.txt'; test_reject(fn()=>test_step($pdo,$user,$id,'upload_final',$fake));
        test_step($pdo,$user,$id,'upload_final',$pdf);
        $firstFinal=session_state($pdo,$id)['final_attachment_id'];
        test_step($pdo,$user,$id,'upload_final',$pdf);
        test_check(session_state($pdo,$id)['final_attachment_id']!==$firstFinal,'Final replacement keeps previous file.');
        test_step($pdo,$user,$id,'upload_signed',$pdf);
        test_check(session_state($pdo,$id)['stage']==='signed_pending','Signed upload requires checking.');
        test_step($pdo,$user,$id,'verify_signed');
        test_check(session_state($pdo,$id)['stage']==='signed','Checked signed copy recorded.');
        test_reject(fn()=>test_step($pdo,$user,$id,'upload_final',$pdf));
        test_check((int)$pdo->query("SELECT COUNT(*) FROM document_attachments WHERE document_id=$id")->fetchColumn()===5,'Legacy original, amendment, both final versions and signed copy preserved.');
        $doc=$pdo->query("SELECT * FROM documents WHERE id=$id")->fetch();
        test_check($doc['body']==='Preserved original' && $doc['file_path']==='uploads/original-test.pdf' && $doc['status']==='Under Review' && !$doc['is_public'],'Original content and legal/public status preserved.');
        test_check(can_download_record($user,$doc) && !can_download_record($viewer,$doc),'Final and signed downloads respect existing permissions.');
        echo "PASS: real multipart amendment/final/signed uploads, file preservation, signed review and download permissions.\n";
    }
    $plain=test_document($pdo,'TEST-NO-AMENDMENT');
    foreach (['send_agenda','confirm_delivery','receive_session','second_session','third_session'] as $action) test_step($pdo,$user,$plain,$action);
    test_check(session_state($pdo,$plain)['stage']==='third_session','No-amendment route reaches third session.');
    test_reject(fn()=>test_step($pdo,$user,$plain,'verify_signed'));
    $doc=$pdo->query("SELECT d.*, 'Test Officer' AS owner_name FROM documents d WHERE id=$plain")->fetch();
    ob_start(); include __DIR__.'/../includes/session_tracking_view.php'; $html=ob_get_clean();
    test_check(str_contains($html,'Upload Final PDF') && !str_contains($html,'name="session_action" value="upload_signed"'),'Third session renders final PDF action before signed upload.');
    test_check(!$pdo->inTransaction(),'No dangling transactions.');
    echo "PASS: agenda delivery, receiving/registration, stale and invalid actions, permissions, amendment deadlines, notification recipients/deduplication, no-amendment route and rendered controls.\n";
} finally {
    // Only unique files generated by this test's temporary attachment rows.
    $root=realpath(__DIR__.'/../uploads');
    foreach ($pdo->query('SELECT file_path FROM document_attachments')->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if ($path==='uploads/original-test.pdf') continue;
        $resolved=realpath(__DIR__.'/../'.$path);
        if ($root && $resolved && str_starts_with(str_replace('\\','/',$resolved),str_replace('\\','/',$root).'/')) unlink($resolved);
    }
}
