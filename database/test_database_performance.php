<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/database_indexes.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Synthetic benchmark is local-only.');
$pdo=get_db();
foreach (['documents','audit_log','search_log'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.schema_identifier($table))->fetch(PDO::FETCH_NUM)[1];
    $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT') && !str_starts_with(trim($line),'FULLTEXT')));
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl); $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
// Make the before/after comparison reproducible even after migration is installed.
foreach (database_index_plan() as [$table,$name]) {
    $found=array_filter($pdo->query('SHOW INDEX FROM '.schema_identifier($table))->fetchAll(PDO::FETCH_ASSOC),static fn($r)=>$r['Key_name']===$name);
    if ($found) $pdo->exec('ALTER TABLE '.schema_identifier($table).' DROP INDEX '.schema_identifier($name));
}
$count=20000; $base=new DateTimeImmutable('2025-01-01');
$doc=$pdo->prepare("INSERT INTO documents (doc_number,title,doc_type,status,owner_id,verified_at,created_at) VALUES (?,?,'Ordinance','Enacted',1,?,?)");
$audit=$pdo->prepare('INSERT INTO audit_log (username_snapshot,module,action,detail,created_at) VALUES (?,?,?,?,?)');
$search=$pdo->prepare("INSERT INTO search_log (user_id,query,search_type,results_count,created_at) VALUES (?,?,'keyword',1,?)");
$pdo->beginTransaction();
for ($i=1;$i<=$count;$i++) {
    $date=$base->modify('+'.($i%365).' days')->format('Y-m-d').' 12:00:00';
    $doc->execute(['BENCH-'.$i,'Synthetic document '.$i,$i%10===0?null:$date,$date]);
    $audit->execute(['fixture',$i%20===0?'reports':'encoding','test','Synthetic event',$date]);
    $search->execute([$i%1000+1,'Synthetic query',$date]);
}
$pdo->commit();
$queries=[
    'Document-number duplicate check'=>['SELECT id FROM documents WHERE doc_number=?',['BENCH-19999']],
    'Date-filtered records'=>['SELECT id FROM documents WHERE created_at>=? AND created_at<? ORDER BY created_at,id',['2025-12-01','2025-12-02']],
    'Incoming queue'=>['SELECT id FROM documents WHERE verified_at IS NULL ORDER BY created_at,id LIMIT 50',[]],
    'Audit date export'=>['SELECT id FROM audit_log WHERE created_at>=? AND created_at<? ORDER BY created_at,id',['2025-12-01','2025-12-02']],
    'Audit module/date export'=>['SELECT id FROM audit_log WHERE module=? AND created_at>=? AND created_at<? ORDER BY created_at,id',['reports','2025-12-01','2025-12-08']],
    'Search date metrics'=>['SELECT search_type,COUNT(*) FROM search_log WHERE created_at>=? AND created_at<? GROUP BY search_type',['2025-12-01','2025-12-02']],
    'Own search history'=>['SELECT id FROM search_log WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 8',[19]],
];
$result=['environment'=>'Local synthetic temporary tables; warm-cache SQL only, not live request latency','server'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'rows_per_table'=>$count,'runs_per_query'=>10,'queries'=>[]];
foreach (['before','after'] as $phase) {
    if ($phase==='after') { $result['indexes_added']=database_add_indexes($pdo); if (database_add_indexes($pdo)!==[]) throw new RuntimeException('Index migration is not idempotent.'); }
    foreach ($queries as $label=>[$sql,$params]) {
        $explain=$pdo->prepare('EXPLAIN '.$sql); $explain->execute($params); $plan=$explain->fetchAll(PDO::FETCH_ASSOC);
        $stmt=$pdo->prepare($sql); $stmt->execute($params); $baseline=$stmt->fetchAll(PDO::FETCH_NUM); $times=[];
        for ($run=0;$run<10;$run++) { $start=hrtime(true); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_NUM); $times[]=(hrtime(true)-$start)/1e6; }
        sort($times); $fingerprint=hash('sha256',json_encode($rows));
        if ($phase==='after' && $result['queries'][$label]['before']['result_hash']!==$fingerprint) throw new RuntimeException('Index changed query results: '.$label);
        $result['queries'][$label][$phase]=['median_ms'=>round(($times[4]+$times[5])/2,3),'result_rows'=>count($rows),'result_hash'=>$fingerprint,'explain'=>$plan];
    }
}
$directory=__DIR__.'/../.runtime/database-test'; if (!is_dir($directory)) mkdir($directory,0700,true);
file_put_contents($directory.'/performance.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
foreach ($result['queries'] as $label=>$phases) echo $label.': '.$phases['before']['median_ms'].' ms -> '.$phases['after']['median_ms']." ms; results identical\n";
echo "PASS: seven query results preserved; index migration idempotent. Evidence: .runtime/database-test/performance.json\n";
