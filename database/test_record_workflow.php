<?php
// CLI tests use connection-local temporary tables; no real document is modified.
if (PHP_SAPI !== 'cli') exit;
session_start(['save_path'=>sys_get_temp_dir()]);
require_once __DIR__ . '/../includes/record_processing.php';
require_once __DIR__ . '/../includes/intake_record.php';
$pdo = get_db();
if (!in_array(DB_HOST, ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local test only.');
foreach (['documents','record_validation_history','integration_receipts','audit_log'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl = implode("\n", array_filter(explode("\n", $ddl), static function ($line) { return !str_starts_with(trim($line), 'CONSTRAINT') && !str_starts_with(trim($line), 'FULLTEXT'); }));
    $ddl = preg_replace('/,\n\)/', "\n)", $ddl);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
}
$role = $pdo->query("SELECT id FROM roles WHERE name='Records Validator'")->fetchColumn();
if (!$role) throw new RuntimeException('Records Validator role is required for these tests.');
$user = ['id'=>1,'role_id'=>$role,'committee_id'=>null];
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $fn) { try { $fn(); } catch (RuntimeException | InvalidArgumentException $e) { return; } throw new RuntimeException('Expected rejection.'); }
function fixture(PDO $pdo, string $number, string $state='Pending Validation', ?int $previous=null): int {
    $pdo->prepare("INSERT INTO documents (doc_number,title,doc_type,owner_id,status,source_system,records_status,previous_version_id) VALUES (?,?,'Ordinance',1,'Submitted','Test source',?,?)")->execute([$number,'Test record',$state,$previous]);
    return (int)$pdo->lastInsertId();
}
$normalized = intake_record_values(['title'=>'Sample','doc_number'=>'TEST','source_status'=>'Under Review','originating_office'=>'Records','classification'=>'RESTRICTED']);
foreach (['Final minutes','Approved report','Withdrawn'] as $sourceStatus) {
    $sourceRecord=intake_record_values(['title'=>'Demo source record','doc_number'=>'DEMO-SOURCE','source_system'=>'Demo source','source_status'=>$sourceStatus]);
    check($sourceRecord['source_status']===$sourceStatus && $sourceRecord['status']==='Submitted','Preserve source vocabulary without manufacturing enactment');
}
check($normalized['status']==='Under Review' && $normalized['originating_office']==='Records','Intake normalization');
$payload = $normalized + ['owner_id'=>1,'records_status'=>'Pending Validation','is_public'=>0,'received_at'=>date('Y-m-d H:i:s')];
$insert = $pdo->prepare('INSERT INTO documents (`' . implode('`,`',array_keys($payload)) . '`) VALUES (' . implode(',',array_fill(0,count($payload),'?')) . ')');
$insert->execute(array_values($payload));
check((int)$pdo->lastInsertId()>0,'Normalized intake insert matches database schema');
rejected(fn()=>intake_record_values(['title'=>'Sample','doc_number'=>'TEST','status'=>'Enacted','source_status'=>'Draft']));
rejected(fn()=>intake_record_values(['title'=>'Sample','doc_number'=>'TEST','enactment_date'=>'2026-02-30']));
$id=fixture($pdo,'TEST-1');
$pdo->exec("UPDATE documents SET body='Original legislative text', file_path='uploads/test-original.pdf', ocr_text='Original OCR text' WHERE id=$id");
process_record($pdo,$user,$id,'Returned for Correction','Missing reference.');
rejected(fn()=>process_record($pdo,$user,$id,'register_private'));
process_record($pdo,$user,$id,'Validated','Corrections checked.');
process_record($pdo,$user,$id,'register_private');
$row=$pdo->query("SELECT * FROM documents WHERE id=$id")->fetch();
check($row['verified_at'] && $row['registered_at'] && !$row['is_public'],'Registration stamps');
rejected(fn()=>process_record($pdo,$user,$id,'register_private'));
check((int)$pdo->query("SELECT COUNT(*) FROM record_validation_history WHERE document_id=$id AND action='Registered'")->fetchColumn() === 1, 'Repeat registration must not add a second event');
$original = $pdo->query("SELECT * FROM documents WHERE id=$id")->fetch();
$revision=fixture($pdo,'TEST-2','Pending Validation',$id);
check($pdo->query("SELECT next_version_id FROM documents WHERE id=$id")->fetchColumn()===null,'No early link');
process_record($pdo,$user,$revision,'register_private');
check((int)$pdo->query("SELECT next_version_id FROM documents WHERE id=$id")->fetchColumn()===$revision,'Registration links revision');
$preserved = $pdo->query("SELECT * FROM documents WHERE id=$id")->fetch();
unset($original['next_version_id'], $preserved['next_version_id']);
check($original === $preserved, 'Registering a revision preserves all original record fields');
$stale=fixture($pdo,'TEST-3','Pending Validation',$id);
rejected(fn()=>process_record($pdo,$user,$stale,'register_private'));
check($pdo->query("SELECT verified_at FROM documents WHERE id=$stale")->fetchColumn() === null, 'Stale revision remains unregistered');
$stamped = fixture($pdo, 'TEST-STAMPED');
$pdo->exec("UPDATE documents SET registered_at=NOW() WHERE id=$stamped");
rejected(fn()=>process_record($pdo,$user,$stamped,'register_private'));
$self = fixture($pdo, 'TEST-SELF');
$pdo->exec("UPDATE documents SET previous_version_id=$self WHERE id=$self");
rejected(fn()=>process_record($pdo,$user,$self,'register_private'));
foreach (['Duplicate','Unauthorized Submission'] as $state) {
    $closed=fixture($pdo,'TEST-'.$state);process_record($pdo,$user,$closed,$state,'Disposition confirmed.');
    rejected(fn()=>process_record($pdo,$user,$closed,'register_private'));
}
$invalid=fixture($pdo,'TEST-INVALID');$pdo->exec("UPDATE documents SET title='' WHERE id=$invalid");
rejected(fn()=>process_record($pdo,$user,$invalid,'register_private'));
rejected(fn()=>process_record($pdo,['id'=>1,'role_id'=>0],$invalid,'register_private'));
check(!$pdo->inTransaction(),'Transactions closed after errors');
echo "PASS: intake validation, returned/corrected flow, duplicate and unauthorized blocking, registration stamps, revision linking, stale revision rollback, repeat registration and permission denial.\n";


