<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/test_record_workflow.php'; // Temporary documents and audit tables only.
require_once __DIR__.'/../includes/source_history.php';
$ddl=file_get_contents(__DIR__.'/../sql/source_history.sql');
$ddl=preg_replace('/,?\s*FOREIGN KEY[^\n]+/','',$ddl);
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$ddl));
$actor=null;
foreach ($pdo->query('SELECT id FROM roles')->fetchAll(PDO::FETCH_COLUMN) as $roleId) {
    if (_role_has_permission((int)$roleId,'repository','edit_metadata')) { $actor=['id'=>1,'role_id'=>(int)$roleId,'committee_id'=>null]; break; }
}
check($actor!==null,'Need a metadata editor role');
$id=fixture($pdo,'SOURCE-HISTORY-DEMO');
$before=$pdo->query("SELECT * FROM documents WHERE id=$id")->fetch();
$input=['event_title'=>'Committee review','event_date'=>'2026-10-01','source_office'=>'Demo committee','remarks'=>'Simulated presentation event','evidence_type'=>'Simulated demo'];
source_history_add($pdo,$actor,$id,$input);
$row=$pdo->query('SELECT * FROM document_source_history')->fetch();
check($row['evidence_type']==='Simulated demo' && (int)$row['recorded_by']===1,'Explicit demo provenance and recorder');
check($before===$pdo->query("SELECT * FROM documents WHERE id=$id")->fetch(),'Adding history must not alter document status or metadata');
rejected(fn()=>source_history_values(array_replace($input,['event_date'=>'2026-02-30'])));
rejected(fn()=>source_history_values(array_replace($input,['event_date'=>"2026-01-\0"] )));
rejected(fn()=>source_history_values(array_replace($input,['event_date'=>'0000-01-01'])));
rejected(fn()=>source_history_values(array_replace($input,['remarks'=>"invalid\xFF"] )));
rejected(fn()=>source_history_values(array_replace($input,['event_title'=>['bad']])));
rejected(fn()=>source_history_values(array_replace($input,['remarks'=>str_repeat('x',2001)])));
rejected(fn()=>source_history_values(array_replace($input,['evidence_type'=>'Manual source record'])));
rejected(fn()=>source_history_add($pdo,['id'=>1,'role_id'=>0],$id,$input));
rejected(fn()=>source_history_add($pdo,$actor,2147483647,$input));
check((int)$pdo->query('SELECT COUNT(*) FROM document_source_history')->fetchColumn()===1,'Rejected writes leave history unchanged');
source_history_add($pdo,$actor,$id,array_replace($input,['evidence_type'=>'Manual source record','reference'=>'Report A, page 2']));
check((int)$pdo->query('SELECT COUNT(*) FROM document_source_history')->fetchColumn()===2,'Supported manual entry accepted');
echo "PASS: source history validation, provenance, authorization, missing record, rollback and unchanged document status. Real records untouched.\n";
