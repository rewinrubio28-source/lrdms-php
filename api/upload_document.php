<?php
/**
 * Authenticated source-system intake: JSON or multipart attachment[].
 * Required: doc_number, title. Optional: status, source_status, provenance,
 * classification, council_term, previous_version_id, body and ocr_text.
 * Every submission starts private and Pending Validation. Registration is
 * performed by an authorized reviewer, never by this endpoint.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/intake_record.php';

header('Content-Type: application/json');

// Shared secret comes from the API_SHARED_KEY environment variable — set
// it in your local .env for testing, or in HostForge's Environment
// Variables tab for production. Never hardcode it here.
require_once __DIR__ . '/../config/env.php';
load_env_file();
define('API_SHARED_KEY', env_required('API_SHARED_KEY'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST.']);
    exit;
}

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(API_SHARED_KEY, $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key.']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    // is_public arrives as a checkbox/string in a form post, unlike JSON's
    // native boolean — normalize it the same way either shape ends up used.
    $input = $_POST;
    if (isset($input['is_public'])) {
        $input['is_public'] = filter_var($input['is_public'], FILTER_VALIDATE_BOOLEAN);
    }
} else {
    $input = json_decode(file_get_contents('php://input'), true);
}

try {
    if (!is_array($input)) throw new InvalidArgumentException('A valid document payload is required.');
    $recordValues = intake_record_values($input);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['error'=>$e->getMessage()]); exit;
}

$pdo = get_db();

// Documents pushed by upstream systems are attributed to a reserved
// "system integration" account created by database/seed.php.
$sysUserStmt = $pdo->prepare("SELECT id FROM users WHERE username = 'system.integration'");
$sysUserStmt->execute();
$systemUserId = $sysUserStmt->fetchColumn();
if (!$systemUserId) {
    http_response_code(500);
    echo json_encode(['error' => 'No system.integration account found. Run database/seed.php first.']);
    exit;
}

// Idempotency gate: doc_number is this system's real-world identity for a
// legislative document (not title — two measures can share a title, but a
// doc_number is only ever reused when the same push is retried or resent
// by mistake). Reject rather than silently insert a second row, and log
// the attempt so it's visible instead of a silent failure.
$dupStmt = $pdo->prepare('SELECT id FROM documents WHERE doc_number = ?');
$dupStmt->execute([$recordValues['doc_number']]);
if ($dupStmt->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['error' => 'A document with this doc_number already exists.', 'doc_number' => $input['doc_number']]);
    log_action('encoding', 'api_ingest_rejected_duplicate', ($input['source_system'] ?? 'unknown source') . ' → ' . $input['doc_number']);
    exit;
}

// Source status is retained; receipt never grants public access.
$isPublic = 0;
$sourceRecordId = $recordValues['source_record_id'];
$sourceStatus = $recordValues['source_status'];
$sourceSystem = $recordValues['source_system'];
if ($recordValues['previous_version_id']) {
    $previousQuery = $pdo->prepare('SELECT * FROM documents WHERE id=?');
    $previousQuery->execute([$recordValues['previous_version_id']]);
    $previous = $previousQuery->fetch();
    if (!$previous || !$previous['verified_at'] || $previous['next_version_id'] !== null || $previous['doc_type'] !== $recordValues['doc_type']) {
        http_response_code(422); echo json_encode(['error'=>'Previous version must be a registered current record of the same type.']); exit;
    }
}

// Optional attachment(s) — multipart requests only. No OCR is run here;
// files are just stored, same as everywhere else in LRDMS right now.
// document_attachments collects every file sent (including the first) —
// once the document row exists below, all of them get recorded there too.
// A document with only one file still gets exactly one row here — so "how
// many files does this document have" is always answerable from one place,
// and single-file docs render identically to before (a list of one).
$filePath = null;
$allFilePaths = []; // [['file_path' => ..., 'display_name' => ...], ...]
if ($isMultipart && isset($_FILES['attachment'])) {

    // $_FILES['attachment'] is an array (attachment[]) when the form sends
    // multiple files, but PHP gives it a flat (non-array) shape when only
    // one file is sent — normalize both into the same list to loop over.
    $names = $_FILES['attachment']['name'];
    $files = is_array($names)
        ? array_map(function ($i) {
            return [
                'name'     => $_FILES['attachment']['name'][$i],
                'tmp_name' => $_FILES['attachment']['tmp_name'][$i],
                'error'    => $_FILES['attachment']['error'][$i],
            ];
        }, array_keys($names))
        : [$_FILES['attachment']];

    foreach ($files as $f) {
        if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
        if ($f['error'] !== UPLOAD_ERR_OK || empty($f['name'])) { http_response_code(422); echo json_encode(['error'=>'Attachment upload failed.']); exit; }
        if (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['pdf','png','jpg','jpeg','gif','webp','doc','docx','txt'], true)) { http_response_code(422); echo json_encode(['error'=>'Unsupported attachment type.']); exit; }
        $originalName = $f['name'];
        $safeName = date('Ymd_His') . '_' . substr(uniqid(), -5) . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
        // Saves to the S3 bucket when S3_* env vars are set, else to uploads/.
        $localReadable = null;
        $storedPath = storage_store_upload($f['tmp_name'], $safeName, $localReadable);
        if ($storedPath !== null) {
            $allFilePaths[] = ['file_path' => $storedPath, 'display_name' => $originalName];
            if ($filePath === null) $filePath = $storedPath; // first successful upload
        } else {
            http_response_code(500); echo json_encode(['error'=>'Attachment storage failed; no record was registered.']); exit;
        }
    }
}

$pdo->beginTransaction();
try {
$recordValues += ['owner_id'=>$systemUserId, 'is_public'=>0, 'records_status'=>'Pending Validation', 'received_at'=>date('Y-m-d H:i:s'), 'pending_since'=>date('Y-m-d H:i:s'), 'status_last_synced'=>date('Y-m-d H:i:s'), 'file_path'=>$filePath];
$columns = array_keys($recordValues);
$stmt = $pdo->prepare('INSERT INTO documents (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
$stmt->execute(array_values($recordValues));
$newId = $pdo->lastInsertId();
$receipt = $pdo->prepare("INSERT INTO integration_receipts (source_system, external_reference_id, received_by, processing_status, lrdms_record_id) VALUES (?, ?, ?, 'Pending Validation', ?)");
$receipt->execute([$sourceSystem, $sourceRecordId ?: ($input['doc_number'] ?? null), $systemUserId, $newId]);

if ($allFilePaths) {
    $attStmt = $pdo->prepare(
        'INSERT INTO document_attachments (document_id, file_path, display_name, sort_order) VALUES (?,?,?,?)'
    );
    foreach ($allFilePaths as $i => $f) {
        $attStmt->execute([$newId, $f['file_path'], $f['display_name'], $i]);
    }
}

log_action('encoding', 'api_ingest', $sourceSystem . ' → ' . $input['doc_number']);

$pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Intake failed: ' . $e->getMessage());
    http_response_code($e instanceof PDOException && $e->getCode() === '23000' ? 409 : 500);
    echo json_encode(['error'=>'Intake could not be saved. Check document number and references before retrying.']); exit;
}
// Notifications must not roll back a successfully received record.
require_once __DIR__ . '/../includes/workflow.php';
try { notify_incoming_document(['id'=>$newId,'doc_number'=>$recordValues['doc_number'],'title'=>$recordValues['title']], $sourceSystem); }
catch (Throwable $e) { error_log('Intake notification: ' . $e->getMessage()); }

echo json_encode(['document_id' => (int)$newId, 'status' => $recordValues['status'], 'records_status' => 'Pending Validation', 'is_public' => false, 'file_path' => $filePath, 'attachment_count' => count($allFilePaths)]);
