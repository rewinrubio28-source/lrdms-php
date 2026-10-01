<?php
if (PHP_SAPI!=='cli') exit;
session_start(['save_path'=>sys_get_temp_dir()]);
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/../includes/dataset_import.php';
require_once __DIR__.'/../includes/csv_export.php';
require_once __DIR__.'/../vendor/autoload.php';
function dataset_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
function dataset_reject(callable $fn,string $message): void { $bad=false; try {$fn();} catch (InvalidArgumentException $e) {$bad=true;} dataset_check($bad,$message); }
$directory=__DIR__.'/../.runtime/import-test'; if (!is_dir($directory)) mkdir($directory,0700,true);
$path=$directory.'/input';
$sample=['doc_number'=>'000123','title'=>"Pag-aaral, \"Kalusugan\"\nIkalawang linya",'source_system'=>'External records','doc_type'=>'Resolution','enactment_date'=>'2026-10-01'];
$csv=fopen($path,'w'); fwrite($csv,"\xEF\xBB\xBF"); fputcsv($csv,array_keys($sample),',','"',''); fputcsv($csv,array_values($sample),',','"',''); fclose($csv);
$parsed=dataset_parse($path,'test.csv');
dataset_check($parsed[0]['doc_number']==='000123' && $parsed[0]['title']===$sample['title'],'CSV preserves leading zeros, Unicode, quotes, commas and newlines');
file_put_contents($path,json_encode([$sample]));
dataset_check(dataset_parse($path,'test.json')===$parsed,'JSON and CSV normalize to identical records');
$xlsx=(string)\Shuchkin\SimpleXLSXGen::fromArray([array_keys($sample),array_map(static fn($v)=>"\0".$v,array_values($sample))]);
file_put_contents($path,$xlsx);
dataset_check(dataset_parse($path,'test.xlsx')===$parsed,'Real Excel workbook preserves literal text and matches CSV/JSON');
foreach ([['test.exe','anything'],['test.csv',"doc_number,title,source_system\nA,\"unclosed,Source"],['test.csv',"doc_number,title,source_system\nA,B,Source,Extra"],['test.csv',"doc_number,title,title\nA,B,C"],['test.json','{bad'],['test.json','{}'],['test.json','[[]]'],['test.xlsx','not a workbook'],['test.csv',"\xFF\x00binary"],['test.json',str_repeat('x',DATASET_MAX_BYTES+1)]] as [$name,$bytes]) {
    file_put_contents($path,$bytes); dataset_reject(fn()=>dataset_parse($path,$name),'Reject malformed, wrong-format or oversized '.$name);
}
foreach ([['doc_number'=>'','title'=>'Missing number','source_system'=>'External'], $sample+['is_public'=>true], array_replace($sample,['status'=>'Invalid']), array_replace($sample,['source_system'=>'Manual Encoding']), array_replace($sample,['enactment_date'=>'2026-02-30']), array_replace($sample,['body'=>str_repeat('x',60001)])] as $bad) dataset_reject(fn()=>dataset_validate([$bad]),'Reject invalid record metadata or authority fields');
dataset_reject(fn()=>dataset_validate(array_fill(0,1001,$sample)),'Reject more than 1,000 records');
// Mutate a synthetic XLSX package to exercise hostile workbook boundaries.
foreach (['formula','entity','multiple sheets','expansion','external links'] as $case) {
    file_put_contents($path,$xlsx); $zip=new ZipArchive(); $zip->open($path);
    if ($case==='formula') { $xml=$zip->getFromName('xl/worksheets/sheet1.xml'); $xml=preg_replace('/(<c\b[^>]*>)/','$1<f>1+1</f>',$xml,1); $zip->addFromString('xl/worksheets/sheet1.xml',$xml); }
    elseif ($case==='entity') $zip->addFromString('xl/workbook.xml','<!DOCTYPE workbook [<!ENTITY x SYSTEM "file:///no-read">]><workbook>&x;</workbook>');
    elseif ($case==='multiple sheets') { $xml=$zip->getFromName('xl/workbook.xml'); $zip->addFromString('xl/workbook.xml',str_replace('</sheets>','<sheet name="Other" sheetId="2"/></sheets>',$xml)); }
    elseif ($case==='expansion') $zip->addFromString('oversize.xml',str_repeat('x',20971521));
    else $zip->addFromString('xl/externalLinks/externalLink1.xml','<externalLink/>');
    $zip->close(); dataset_reject(fn()=>dataset_parse($path,'test.xlsx'),'Reject workbook '.$case);
}

if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local test database only.');
$pdo=get_db();
foreach (['documents','integration_receipts','audit_log'] as $table) {
    $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE permissions (id INT,module VARCHAR(80),action VARCHAR(80))');
$pdo->exec('CREATE TEMPORARY TABLE role_permissions (role_id INT,permission_id INT)');
$pdo->exec("INSERT INTO permissions VALUES (1,'encoding','create')"); $pdo->exec('INSERT INTO role_permissions VALUES (900,1)');
$user=['id'=>1,'username'=>'import-test','role_id'=>900];
$start=microtime(true); $batch=[];
for ($i=1;$i<=1000;$i++) $batch[]=array_replace($sample,['doc_number'=>'IMPORT-'.$i]);
dataset_check(dataset_save($pdo,$user,dataset_validate($batch))===1000,'Import 1,000 independent records in one transaction');
$duration=microtime(true)-$start;
dataset_check((int)$pdo->query("SELECT COUNT(*) FROM documents WHERE is_public=0 AND verified_at IS NULL AND registered_at IS NULL AND records_status='Pending Validation' AND owner_id=1")->fetchColumn()===1000,'Every imported record stays private, unregistered and owned by the submitting user');
dataset_check((int)$pdo->query('SELECT COUNT(*) FROM integration_receipts')->fetchColumn()===1000 && (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===1000,'Every imported record has a receipt and audit event');
dataset_reject(fn()=>dataset_save($pdo,$user,dataset_validate([array_replace($sample,['doc_number'=>'FRESH']),array_replace($sample,['doc_number'=>'IMPORT-1'])])),'Existing duplicate rolls back preceding inserted rows');
dataset_check((int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn()===1000 && (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===1000,'Rejected import leaves record and audit counts unchanged');
dataset_reject(fn()=>dataset_save($pdo,$user,dataset_validate([array_replace($sample,['doc_number'=>'REPEAT']),array_replace($sample,['doc_number'=>'repeat'])])),'Database collation detects within-batch duplicates and rolls back');
$pdo->exec("ALTER TABLE integration_receipts ADD CONSTRAINT test_reject_receipt CHECK (external_reference_id <> 'FAIL-RECEIPT')");
$failed=false;
try { dataset_save($pdo,$user,dataset_validate([array_replace($sample,['doc_number'=>'BEFORE-FAILURE']),array_replace($sample,['doc_number'=>'FAILURE','source_record_id'=>'FAIL-RECEIPT'])])); } catch (PDOException $e) { $failed=true; }
dataset_check($failed && (int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn()===1000 && (int)$pdo->query('SELECT COUNT(*) FROM integration_receipts')->fetchColumn()===1000 && (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===1000,'Database failure rolls back records, receipts and audit entries together');
$denied=false; try {dataset_save($pdo,array_replace($user,['role_id'=>901]),$parsed);} catch (RuntimeException $e) {$denied=true;}
dataset_check($denied && !$pdo->inTransaction(),'Unauthorized role cannot import');
// More rows than the former audit cap, with cells that need quoting/protection.
$pdo->exec("INSERT INTO audit_log (username_snapshot,module,action,detail) VALUES ('=formula','encoding','test','Unicode ñ, comma')");
$out=fopen('php://temp','w+');
$stmt=$pdo->query('SELECT a.*, NULL AS actor_full_name,NULL AS actor_role FROM audit_log a ORDER BY created_at DESC,id DESC');
$count=write_audit_csv($out,$stmt); rewind($out); $bom=fread($out,3); $header=fgetcsv($out,0,',','"',''); $first=fgetcsv($out,0,',','"',''); $read=1; while(fgetcsv($out,0,',','"','')!==false) $read++; fclose($out);
dataset_check($count===1001 && $read===1001,'Audit CSV exports all 1,001 matching rows without truncation');
dataset_check($bom==="\xEF\xBB\xBF" && $first[1]==="'=formula" && $first[6]==='Unicode ñ, comma','Audit CSV uses UTF-8, safe formulas and correct quoting');
printf("Bulk import: 1,000 records in %.3f seconds on local temporary tables (not a production load benchmark).\n",$duration);
echo "Existing records unchanged: all database fixtures used connection-temporary tables.\n";
