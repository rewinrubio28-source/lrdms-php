<?php
if (PHP_SAPI!=='cli') exit;
session_start(['save_path'=>sys_get_temp_dir()]);
require_once __DIR__.'/../includes/report_schedules.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local tests only.');
$pdo=get_db();
function report_check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
function report_reject(callable $fn,string $label): void { $rejected=false; try {$fn();} catch (InvalidArgumentException $e) {$rejected=true;} report_check($rejected,$label); }
foreach (['documents','users','roles','permissions','role_permissions','audit_log'] as $table) {
    $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl); $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
foreach (report_schedule_schema() as $ddl) {
    $ddl=preg_replace('/^\s*FOREIGN KEY.*$/m','',$ddl); $ddl=preg_replace('/,\s*\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$pdo->exec("INSERT INTO roles (id,name) VALUES (900,'Report admin'),(901,'Own records'),(902,'Read only')");
$pdo->exec("INSERT INTO permissions (id,module,action) VALUES (1,'repository','view_all'),(2,'repository','view_own'),(3,'repository','download'),(4,'repository','print_record')");
$pdo->exec('INSERT INTO role_permissions VALUES (900,1),(900,3),(900,4),(901,2),(901,3),(902,1)');
$pdo->exec("INSERT INTO users (id,username,full_name,password_hash,role_id,is_active,must_change_password) VALUES (1,'report-test','Report Test','not-real',900,1,0),(2,'other','Other','not-real',901,1,0)");
$user=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
$GLOBALS['__lrdms_current_user']=$user;
$insert=$pdo->prepare("INSERT INTO documents (id,doc_number,title,doc_type,status,source_system,owner_id,is_public,verified_at,registered_at,created_at) VALUES (?,?,?,?,'Enacted','Source test',?,?,?, ?,?)");
foreach ([[1,1,0,'2026-10-01 00:00:00',true,'Ordinance'],[2,2,0,'2026-10-01 23:59:59',true,'Resolution'],[3,2,1,'2026-09-28 10:00:00',true,'Ordinance'],[4,1,0,'2026-10-01 12:00:00',false,'Ordinance'],[5,1,0,'2026-09-01 10:00:00',true,'Ordinance'],[6,2,1,'2026-10-02 00:00:00',true,'Ordinance']] as [$id,$owner,$public,$created,$verified,$type]) $insert->execute([$id,'R-'.$id,$id===1?'=Formula, ñ "quoted"':'Record '.$id,$type,$owner,$public,$verified?$created:null,$verified?$created:null,$created]);
$input=['from'=>'2026-10-01','to'=>'2026-10-01','sort'=>'doc_number','direction'=>'asc','columns'=>['doc_number','title','status']];
$report=record_report_build($pdo,$user,$input);
report_check($report['ids']===[1,2] && $report['count']===2 && count($report['headers'])===3,'Date bounds, registered-only scope, selected columns and sort reconcile');
$own=array_replace($user,['role_id'=>901]);
report_check(record_report_build($pdo,$own,$input)['ids']===[1],'Own-record role cannot see another owner private record');
report_check(record_report_build($pdo,$user,$input+['q'=>'R-2'])['ids']===[2],'Document search filter matches');
report_check(record_report_build($pdo,$user,$input+['type'=>'Resolution'])['ids']===[2],'Document type filter matches');
$group=record_report_build($pdo,$user,$input+['group'=>'doc_type']);
report_check($group['rows']===[['Ordinance',1],['Resolution',1]],'Grouped totals and ascending labels reconcile');
report_check(record_report_build($pdo,$user,array_replace($input,['direction'=>'desc']))['ids']===[2,1],'Descending sort is deterministic');
foreach ([['columns'=>[]],['columns'=>['body']],['sort'=>'id;DROP TABLE'],['group'=>'unknown'],['from'=>'2026-02-30'],['direction'=>[]]] as $bad) report_reject(fn()=>record_report_options(array_replace($input,$bad)),'Reject invalid report option');
report_reject(fn()=>record_report_options(['columns_present'=>'1']),'Reject a submitted form with all columns unchecked');
$csv=record_report_bytes($report,'csv'); $xlsx=record_report_bytes($report,'xlsx');
report_check(str_contains($csv,"'=Formula") && str_contains($csv,'ñ') && str_starts_with($xlsx,'PK'),'CSV quoting/formula protection and native XLSX output');
$html=record_report_html($report,true);
report_check(str_contains($html,'data:image/png;base64,') && str_contains($html,'window.print()') && str_contains($html,'@media print'),'Report includes organization seal and dedicated print layout');
report_check(record_report_snapshot_allowed($pdo,$user,$report) && !record_report_snapshot_allowed($pdo,$own,$report),'Stored snapshot is revoked if any included record becomes inaccessible');
report_check(!record_report_allowed(array_replace($user,['role_id'=>902]),'export'),'Read-only role cannot export or schedule reports');
$zone=new DateTimeZone('Asia/Manila');
$now=new DateTimeImmutable('2026-10-02 09:00:00',$zone);
report_check(report_schedule_next('daily','08:00',$now)->format('Y-m-d H:i')==='2026-10-03 08:00','Daily schedule advances past the current time');
report_check(report_schedule_next('weekly','08:00',$now)->format('Y-m-d H:i')==='2026-10-05 08:00','Weekly schedule runs on Monday');
report_check(report_schedule_next('monthly','08:00',$now)->format('Y-m-d H:i')==='2026-11-01 08:00','Monthly schedule runs on first day');
report_check(report_schedule_period('weekly',$now)===['from'=>'2026-09-21','to'=>'2026-09-27'],'Weekly period uses the completed Monday-Sunday');
report_check(report_schedule_period('monthly',new DateTimeImmutable('2024-03-01',$zone))===['from'=>'2024-02-01','to'=>'2024-02-29'],'Monthly period handles leap year');
report_reject(fn()=>report_schedule_next('daily','25:00',$now),'Reject invalid scheduled time');
$id=report_schedule_create($pdo,$user,$input+['frequency'=>'daily','run_time'=>'08:00']);
$pdo->prepare('UPDATE report_schedules SET next_run_at=? WHERE id=?')->execute(['2026-10-02 08:00:00',$id]);
$directory=__DIR__.'/../.runtime/report-test'; if (!is_dir($directory)) mkdir($directory,0700,true);
$run=report_schedule_tick($pdo,$now); // Real PDF generation, no email or network.
$stored=$pdo->query('SELECT * FROM report_runs WHERE id='.(int)$run)->fetch();
report_check($stored['status']==='Ready' && str_starts_with($stored['pdf_bytes'],'%PDF-'),'Due worker generates and stores an actual PDF');
$snapshot=json_decode($stored['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
report_check($snapshot['count']===2 && $snapshot['options']['from']==='2026-10-01','Daily run covers yesterday with owner scope');
report_check(report_schedule_tick($pdo,$now)===null && (int)$pdo->query('SELECT COUNT(*) FROM report_runs')->fetchColumn()===1,'Repeated worker pass does not duplicate a completed slot');
$pdo->exec("UPDATE report_schedules SET next_run_at='2026-10-02 08:00:00'");
report_check(report_schedule_tick($pdo,$now)===$run && (int)$pdo->query('SELECT COUNT(*) FROM report_runs')->fetchColumn()===1,'Completed slot after a crash is not generated twice');
report_schedule_change($pdo,$user,$id,'pause');
$pdo->exec("UPDATE report_schedules SET next_run_at='2026-10-02 07:00:00'");
report_check(report_schedule_tick($pdo,$now)===null,'Paused schedule is not claimed');
report_schedule_change($pdo,$user,$id,'resume'); $pdo->exec("UPDATE report_schedules SET next_run_at='2026-10-02 07:00:00'"); $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
$skipped=report_schedule_tick($pdo,$now);
report_check($pdo->query('SELECT status FROM report_runs WHERE id='.$skipped)->fetchColumn()==='Skipped','Disabled schedule owner cannot generate reports');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1'); $pdo->exec("UPDATE report_schedules SET next_run_at='2026-10-02 06:00:00'");
$failed=report_schedule_tick($pdo,$now,static function(){throw new RuntimeException('Synthetic renderer failure');});
report_check($pdo->query('SELECT status FROM report_runs WHERE id='.$failed)->fetchColumn()==='Failed','Render failure is recorded without a ready or partial report');
report_reject(fn()=>report_schedule_change($pdo,['id'=>2,'role_id'=>901],$id,'pause'),'Another user cannot mutate a schedule');
$other=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$lockName='lrdms-reports-'.substr(hash('sha256',DB_NAME),0,32);
$query=$other->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lockName]);
try { report_check(report_schedule_tick($pdo,$now)===null,'An overlapping worker cannot claim a run while the database lock is held'); }
finally { $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
$pdo->exec("UPDATE report_schedules SET next_run_at='2026-10-02 05:00:00'");
$pdo->prepare("INSERT INTO report_runs (schedule_id,user_id,scheduled_for,status,attempts,created_at) VALUES (?,1,'2026-10-02 05:00:00','Processing',3,?)")->execute([$id,$now->format('Y-m-d H:i:s')]);
$interrupted=report_schedule_tick($pdo,$now);
report_check($pdo->query('SELECT status FROM report_runs WHERE id='.$interrupted)->fetchColumn()==='Failed','Repeated worker interruption is bounded to three attempts');
// Multipage report samples, using only synthetic records.
for ($i=100;$i<220;$i++) $insert->execute([$i,'PAGE-'.$i,'Synthetic record '.$i.' — report layout and page-break verification','Ordinance',1,0,'2026-10-01 12:00:00','2026-10-01 12:00:00','2026-10-01 12:00:00']);
$multi=record_report_build($pdo,$user,array_replace($input,['columns'=>array_keys(RECORD_REPORT_COLUMNS)]));
file_put_contents($directory.'/records.pdf',record_report_bytes($multi,'pdf'));
file_put_contents($directory.'/records.xlsx',record_report_bytes($multi,'xlsx'));
file_put_contents($directory.'/records.csv',record_report_bytes($multi,'csv'));
file_put_contents($directory.'/print.html',record_report_html($multi,true));
report_check($multi['count']===122,'Multipage PDF/Excel/CSV samples contain all 122 fixture records');
for ($i=220;$i<1100;$i++) $insert->execute([$i,'LIMIT-'.$i,'Limit fixture','Ordinance',1,0,'2026-10-01 12:00:00','2026-10-01 12:00:00','2026-10-01 12:00:00']);
report_reject(fn()=>record_report_build($pdo,$user,$input),'Oversized report is rejected instead of truncated');
echo "Existing records unchanged. Synthetic output: .runtime/report-test/\n";
