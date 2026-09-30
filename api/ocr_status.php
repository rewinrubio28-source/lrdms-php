<?php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/ocr_jobs.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error'=>'Authentication required.']); exit; }
$pdo = get_db();
$stmt = $pdo->prepare('SELECT * FROM documents WHERE id=?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$doc = $stmt->fetch();
if (!$doc || !can_view_document($user, $doc)) { http_response_code(404); echo json_encode(['error'=>'Document not found.']); exit; }
session_write_close();
try {
    $job = ocr_job_get($pdo, (int)$doc['id']);
    echo json_encode($job ? array_intersect_key($job, array_flip(['status','file_index','file_count','pages_done','pages_total','error_message'])) : ['status'=>'idle']);
} catch (PDOException $e) {
    http_response_code(503);
    echo json_encode(['error'=>'Background OCR is not ready. Ask the administrator to apply the database upgrade.']);
}
