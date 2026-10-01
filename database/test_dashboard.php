<?php
if (PHP_SAPI!=='cli') exit;
session_start(['save_path'=>sys_get_temp_dir()]);
ob_start();
require_once __DIR__.'/../includes/dashboard_data.php';
require_once __DIR__.'/../includes/dashboard_reports.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local tests only.');
$pdo=get_db();
foreach (['documents','users','search_log','audit_log'] as $table) {
    $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE roles (id INT PRIMARY KEY,name VARCHAR(100))');
$pdo->exec("INSERT INTO roles VALUES (900,'Super Admin'),(901,'Own records'),(902,'Public records'),(903,'No scope')");
$pdo->exec('CREATE TEMPORARY TABLE permissions (id INT PRIMARY KEY,module VARCHAR(80),action VARCHAR(80))');
$pdo->exec("INSERT INTO permissions VALUES (1,'repository','view_all'),(2,'repository','view_own'),(3,'repository','view_public'),(4,'search','run'),(5,'encoding','create'),(6,'audit','view'),(7,'access','manage_users')");
$pdo->exec('CREATE TEMPORARY TABLE role_permissions (role_id INT,permission_id INT)');
$pdo->exec('INSERT INTO role_permissions VALUES (900,1),(900,4),(900,5),(900,6),(900,7),(901,2),(901,4),(901,5),(902,3)');
$pdo->exec("INSERT INTO users (id,username,full_name,password_hash,role_id,is_active) VALUES (1,'admin','Admin','not-a-real-hash',900,1),(2,'staff','Staff','not-a-real-hash',901,1),(3,'disabled','Disabled','not-a-real-hash',901,0)");
$insert=$pdo->prepare("INSERT INTO documents (id,doc_number,title,doc_type,status,owner_id,is_public,verified_at,created_at,source_system) VALUES (?, ?, ?, ?, 'Enacted', ?, ?, ?, ?, 'Test source')");
foreach ([[1,1,0,'2026-01-01 00:00:00','Ordinance',true],[2,2,0,'2026-01-15 12:00:00','Ordinance',true],[3,2,1,'2026-03-31 23:59:59','Resolution',true],[4,1,0,'2026-01-12 00:00:00','Ordinance',false],[5,1,0,'2025-12-31 23:59:59','Ordinance',true],[6,1,0,'2026-01-31 23:59:59','Ordinance',true],[7,1,0,'2026-04-01 00:00:00','Ordinance',true]] as [$id,$owner,$public,$created,$type,$verified]) $insert->execute([$id,'DASH-'.$id,'Test '.$id,$type,$owner,$public,$verified?'2026-01-01 00:00:00':null,$created]);
$pdo->exec('UPDATE documents SET previous_version_id=1 WHERE id=6'); $pdo->exec('UPDATE documents SET next_version_id=6 WHERE id=1');
$pdo->exec("INSERT INTO search_log (user_id,query,search_type,results_count,created_at) VALUES (1,'own keyword','keyword',1,'2026-01-01'),(1,'own semantic','semantic',1,'2026-03-01'),(2,'other private query','semantic',1,'2026-01-01'),(NULL,'external query','keyword',1,'2026-01-01')");
function dashboard_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
$filters=dashboard_filters(['from'=>'2026-01-01','to'=>'2026-03-31']);
foreach ([['from'=>[]],['from'=>'2026-02-30'],['from'=>'2026-03-01','to'=>'2026-01-01'],['from'=>'2020-01-01','to'=>'2026-01-01'],['type'=>'bad'],['status'=>[]]] as $invalid) {
    $rejected=false; try { dashboard_filters($invalid); } catch (InvalidArgumentException $e) { $rejected=true; }
    dashboard_check($rejected,'Reject malformed or excessive filter range');
}
$admin=['id'=>1,'role_id'=>900,'committee_id'=>null,'full_name'=>'=HYPERLINK("test")'];
$data=dashboard_snapshot($pdo,$admin,$filters);
dashboard_check($data['totalDocs']===4 && $data['enactedCount']===4 && $data['pubCount']===1,'KPI totals match fixture records, inclusive end date and verification rules');
dashboard_check(array_column($data['monthData'],'n')===[3,0,1],'Monthly chart includes zero-count months');
dashboard_check(array_sum(array_column($data['typeCounts'],'n'))===4 && array_sum($data['statusCounts'])===4,'Type, status and total metrics reconcile');
dashboard_check($data['totalRevisions']===1 && $data['versionChains']===1,'Revision metrics distinguish roots from revision heads');
dashboard_check($data['awaitingVerificationCount']===1,'Unverified intake is separate from registered record counts');
dashboard_check($data['activeUsers']===1 && $data['inactiveUsers']===1 && array_sum(array_column($data['usersByRole'],'n'))===2,'Account metrics share directory visibility');
dashboard_check($data['totalSearches']===4,'Audit-authorized user sees period search totals');
$own=dashboard_snapshot($pdo,['id'=>1,'role_id'=>901,'committee_id'=>null],$filters);
dashboard_check($own['totalDocs']===3 && $own['totalSearches']===2 && count($own['recentSearches'])===2,'Limited role sees own/public records and only own search history');
$public=dashboard_snapshot($pdo,['id'=>2,'role_id'=>902,'committee_id'=>null],$filters);
dashboard_check($public['totalDocs']===1 && !$public['canSearch'] && !$public['canAccess'],'Public-only role cannot receive private or admin metrics');
$none=dashboard_snapshot($pdo,['id'=>2,'role_id'=>903,'committee_id'=>null],$filters);
dashboard_check($none['totalDocs']===0,'Role with no repository scope receives zero record counts');
$typeFilters=$filters; $typeFilters['type']='Resolution';
$resolution=dashboard_snapshot($pdo,$admin,$typeFilters);
[$clause,$params]=dashboard_record_scope($admin,$typeFilters); $stmt=$pdo->prepare("SELECT COUNT(*) FROM documents d WHERE $clause"); $stmt->execute($params);
dashboard_check($resolution['totalDocs']===1 && (int)$stmt->fetchColumn()===1,'Type-filtered KPI and drill-down count match');
$rows=dashboard_report_rows($data,$admin);
$csv=dashboard_report_bytes($rows,'csv'); $xlsx=dashboard_report_bytes($rows,'xlsx'); $pdf=dashboard_report_bytes($rows,'pdf');
dashboard_check(str_contains($csv,"'=HYPERLINK") && str_contains($csv,'2026-02'),'CSV preserves history and protects spreadsheet formulas');
dashboard_check(str_starts_with($xlsx,'PK') && str_starts_with($pdf,'%PDF-'),'Native XLSX and PDF generated');
$directory=__DIR__.'/../.runtime/dashboard-test'; if (!is_dir($directory)) mkdir($directory,0700,true);
file_put_contents($directory.'/report.xlsx',$xlsx); file_put_contents($directory.'/report.pdf',$pdf); file_put_contents($directory.'/report.csv',$csv);
$user=$admin; extract($data,EXTR_SKIP); ob_start(); include __DIR__.'/../includes/dashboard_panels.php'; $html=ob_get_clean();
dashboard_check(str_contains($html,'dashboard_records.php?') && str_contains($html,'2026-02'),'Server-rendered panels expose month drill-down links');
dashboard_check(!$pdo->inTransaction(),'Snapshot transaction closes cleanly');
echo "Existing records untouched. Synthetic report artifacts: .runtime/dashboard-test/\n";
$testOutput=ob_get_clean();
if (in_array('--preview',$argv,true)) {
    $pdo->exec('CREATE TEMPORARY TABLE user_profile_photos (user_id INT)');
    $GLOBALS['__lrdms_current_user']=$admin+['username'=>'dashboard-test','role_name'=>'Super Admin','must_change_password'=>0];
    $GLOBALS['__lrdms_current_user']['full_name']='Dashboard Test';
    $_GET=$filters; $_SERVER['PHP_SELF']='dashboard.php'; $_SERVER['REQUEST_METHOD']='GET';
    ob_start(); include __DIR__.'/../dashboard.php'; $pageHtml=ob_get_clean();
    $base='file:///'.str_replace('\\','/',dirname(__DIR__)).'/';
    $pageHtml=str_replace('<head>','<head><base href="'.htmlspecialchars($base).'">',$pageHtml);
    file_put_contents($directory.'/dashboard.html',$pageHtml);
}
echo $testOutput;
