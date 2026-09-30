<?php
// Temporary tables shadow the application tables only for this connection.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/ocr_jobs.php';
if (!in_array(DB_HOST, ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local test only.');
$pdo = get_db();
$pdo->exec('CREATE TEMPORARY TABLE documents (id INT PRIMARY KEY, doc_number VARCHAR(60), file_path TEXT, ocr_text LONGTEXT) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE document_attachments (id INT PRIMARY KEY, document_id INT, file_path TEXT, sort_order INT) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE audit_log (user_id INT NULL, username_snapshot VARCHAR(100), module VARCHAR(100), action VARCHAR(100), detail TEXT) ENGINE=InnoDB');
$ddl = file_get_contents(__DIR__ . '/../sql/ocr_jobs.sql');
$ddl = preg_replace('/,\s*CONSTRAINT fk_ocr_document[^\n]+/', '', $ddl);
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $ddl));
function check_ocr(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function reset_ocr(PDO $pdo): void {
    $pdo->exec('DELETE FROM ocr_jobs');
    $pdo->exec('DELETE FROM document_attachments');
    $pdo->exec('DELETE FROM documents');
    $pdo->exec("INSERT INTO documents VALUES (1,'OCR-TEST','uploads/test.pdf','Previous text')");
}
reset_ocr($pdo);
ocr_job_enqueue($pdo, 1, 7);
$first = ocr_job_get($pdo, 1);
ocr_job_enqueue($pdo, 1, 8);
check_ocr(ocr_job_get($pdo, 1)['token'] === $first['token'], 'Repeated clicks reuse the queued job');
$job = ocr_job_claim($pdo);
check_ocr(ocr_job_claim($pdo) === null, 'An active job cannot be claimed twice');
ocr_job_execute($pdo, $job, function ($path, $progress) use ($pdo) {
    $progress(1, 2);
    $current = ocr_job_get($pdo, 1);
    check_ocr((int)$current['pages_done'] === 1 && (int)$current['pages_total'] === 2, 'Page progress is persisted');
    $progress(2, 2);
    return "    Heading\n\n[PAGE BREAK]\n\n    Second page";
});
check_ocr(ocr_job_get($pdo, 1)['status'] === 'completed', 'Successful job completes');
check_ocr($pdo->query('SELECT ocr_text FROM documents')->fetchColumn() === "    Heading\n\n[PAGE BREAK]\n\n    Second page", 'Layout and page breaks survive storage');

reset_ocr($pdo);
$pdo->exec("INSERT INTO document_attachments VALUES (1,1,'uploads/one.pdf',0),(2,1,'uploads/two.pdf',1)");
ocr_job_enqueue($pdo, 1, 7);
$job = ocr_job_claim($pdo);
ocr_job_execute($pdo, $job, function ($path, $progress) { $progress(1,1); return str_contains($path,'two') ? '[OCR failed] unavailable' : 'Partial text'; });
check_ocr(ocr_job_get($pdo, 1)['status'] === 'failed' && $pdo->query('SELECT ocr_text FROM documents')->fetchColumn() === 'Previous text', 'Failed attachment never saves partial results');
ocr_job_enqueue($pdo, 1, 7);
check_ocr(ocr_job_get($pdo, 1)['status'] === 'queued', 'Failed jobs can be retried');
$job = ocr_job_claim($pdo);
ocr_job_execute($pdo, $job, function ($path, $progress) use ($pdo) {
    $pdo->exec("UPDATE documents SET ocr_text='Newer edit' WHERE id=1");
    return 'Stale output';
});
check_ocr(ocr_job_get($pdo, 1)['status'] === 'failed' && $pdo->query('SELECT ocr_text FROM documents')->fetchColumn() === 'Newer edit', 'Concurrent text edits are not overwritten');

reset_ocr($pdo);
ocr_job_enqueue($pdo, 1, 7);
$old = ocr_job_claim($pdo);
$pdo->exec('UPDATE ocr_jobs SET heartbeat_at=DATE_SUB(NOW(), INTERVAL 11 MINUTE)');
$new = ocr_job_claim($pdo);
check_ocr($new !== null && $new['token'] !== $old['token'], 'Interrupted worker is reclaimed with a new token');
ocr_job_execute($pdo, $old, static fn($path, $progress) => 'Old worker output');
check_ocr(ocr_job_get($pdo,1)['status'] === 'running' && $pdo->query('SELECT ocr_text FROM documents')->fetchColumn() === 'Previous text', 'Old worker cannot save or fail the new attempt');
$pdo->exec('UPDATE ocr_jobs SET attempts=3,heartbeat_at=DATE_SUB(NOW(), INTERVAL 11 MINUTE)');
check_ocr(ocr_job_claim($pdo) === null && ocr_job_get($pdo,1)['status'] === 'failed', 'Repeated interrupted attempts stop with a retryable failure');

reset_ocr($pdo);
ocr_job_enqueue($pdo, 1, 7);
$job = ocr_job_claim($pdo);
ocr_job_execute($pdo, $job, function ($path, $progress) use ($pdo) {
    $pdo->exec("INSERT INTO document_attachments VALUES (1,1,'uploads/corrected.pdf',0)");
    return 'Outdated attachment text';
});
check_ocr(ocr_job_get($pdo,1)['status'] === 'failed' && $pdo->query('SELECT ocr_text FROM documents')->fetchColumn() === 'Previous text', 'Changed attachments invalidate old OCR output');
echo "All queue checks passed; existing records were untouched.\n";
