<?php
// Local database, temporary tables and deliberately unavailable local AI service.
if (PHP_SAPI !== 'cli') exit;
putenv('BERT_SERVICE_URL=http://127.0.0.1:1');
putenv('API_SHARED_KEY=search-test-only');
require_once __DIR__ . '/../config/database.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local test only.');
$pdo=get_db();
$pdo->exec('CREATE TEMPORARY TABLE documents (id INT PRIMARY KEY, doc_number VARCHAR(60), title VARCHAR(500), doc_type VARCHAR(50), enactment_date DATE, status VARCHAR(30), is_public INT, verified_at DATETIME NULL, body TEXT, ocr_text TEXT)');
$pdo->exec('CREATE TEMPORARY TABLE search_log (user_id INT NULL, query TEXT, search_type VARCHAR(30), results_count INT)');
$pdo->exec('CREATE TEMPORARY TABLE audit_log (user_id INT NULL, username_snapshot VARCHAR(100), module VARCHAR(100), action VARCHAR(100), detail TEXT, ip_address VARCHAR(45))');
$pdo->exec("INSERT INTO documents VALUES
    (1,'TEST-1','Test public','Ordinance','2026-01-01','Enacted',1,NOW(),'',''),
    (2,'TEST-2','Test private','Ordinance','2026-01-01','Enacted',0,NOW(),'',''),
    (3,'TEST-3','Test unverified','Ordinance','2026-01-01','Enacted',1,NULL,'',''),
    (4,'TEST-4','Test draft','Ordinance','2026-01-01','Draft',1,NOW(),'',''),
    (5,'TEST-5','Test amended','Ordinance','2026-01-01','Amended',1,NOW(),'','')");
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['HTTP_X_API_KEY']='search-test-only';
foreach (['keyword','semantic'] as $requested) {
    $_GET=['query'=>'Test','mode'=>$requested];
    ob_start();
    require __DIR__ . '/../api/search.php';
    $response=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    $ids=array_column($response['results'],'id'); sort($ids);
    if ($ids!==[1,5]) throw new RuntimeException('Public search leaked private, draft or unverified records.');
    if ($response['mode']!==$requested || $response['effective_mode']!=='keyword' || $response['fallback']!==($requested==='semantic')) {
        throw new RuntimeException('Incorrect search execution metadata.');
    }
}
if ((int)$pdo->query("SELECT COUNT(*) FROM search_log WHERE search_type='keyword'")->fetchColumn()!==2) throw new RuntimeException('Incorrect search log mode.');
echo "PASS: keyword API excludes private, draft and unverified records; amended public records remain visible.\n";
echo "PASS: unavailable AI falls back to keyword results with accurate response metadata and search logs.\n";
echo "Existing records untouched; this does not measure AI accuracy.\n";
