<?php
/**
 * REST endpoint used by the Ordinance & Resolution Lifecycle System
 * (System 1) and the Session Management System (System 2) to push
 * finalized documents into this repository.
 *
 * POST /api/upload_document.php
 * Header: X-API-Key: <shared secret>
 *
 * Accepts EITHER of two request shapes:
 *
 * 1) JSON body (the real System 1 / System 2 integration shape):
 *   {
 *     "title": "...",            required
 *     "doc_number": "...",       required
 *     "doc_type": "Ordinance",   optional, defaults to "Ordinance"
 *     "sponsor": "...",          optional
 *     "committee_id": 1,         optional
 *     "enactment_date": "2026-07-20", optional
 *     "source_system": "System 1 – Ordinance & Resolution Lifecycle",
 *     "is_public": true,         optional, defaults to true (see note below)
 *     "ocr_text": "..."          optional
 *   }
 *
 * 2) multipart/form-data with the same field names as regular POST fields,
 *    plus optional "attachment[]" file(s). Used by dev_test_incoming.php to
 *    simulate a push that includes real file(s) — no limit on how many.
 *    documents.file_path is set to the FIRST file, unchanged from before —
 *    every existing single-file code path keeps working as-is. When more
 *    than one file is sent, ALL of them (including the first) are also
 *    recorded in document_attachments, which the gallery/carousel views
 *    read from once a document has 2+ files. No OCR runs on any of them
 *    here; files are just stored as-is. (If OCR text is wanted for a test
 *    push, pass it directly via the ocr_text field, same as the JSON shape.)
 *
 * Per the integration boundary in README.md, this system is the system
 * of record for FINALIZED documents only — it receives them already
 * enacted. Draft/review workflow stays owned by System 1.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/storage.php';

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

if (!$input || empty($input['title']) || empty($input['doc_number'])) {
    http_response_code(422);
    echo json_encode(['error' => 'title and doc_number are required.']);
    exit;
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
$dupStmt->execute([$input['doc_number']]);
if ($dupStmt->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['error' => 'A document with this doc_number already exists.', 'doc_number' => $input['doc_number']]);
    log_action('encoding', 'api_ingest_rejected_duplicate', ($input['source_system'] ?? 'unknown source') . ' → ' . $input['doc_number']);
    exit;
}

// Verification gate: a document pushed by an upstream system is genuinely
// Enacted (that's the integration boundary — see docs/SCOPE_DECISION.md),
// but it is never made public sight-unseen. It stays is_public = 0, however
// the upstream request tags it, until a Records Officer verifies it in the
// Encoding module's "Documents Awaiting Verification" queue and releases it.
$isPublic = 0;
$sourceSystem = $input['source_system'] ?? 'System 1 – Ordinance & Resolution Lifecycle';

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
        if ($f['error'] !== UPLOAD_ERR_OK || empty($f['name'])) continue;
        $originalName = $f['name'];
        $safeName = date('Ymd_His') . '_' . substr(uniqid(), -5) . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
        // Saves to the S3 bucket when S3_* env vars are set, else to uploads/.
        $localReadable = null;
        $storedPath = storage_store_upload($f['tmp_name'], $safeName, $localReadable);
        if ($storedPath !== null) {
            $allFilePaths[] = ['file_path' => $storedPath, 'display_name' => $originalName];
            if ($filePath === null) $filePath = $storedPath; // first successful upload
        }
    }
}

$stmt = $pdo->prepare(
    'INSERT INTO documents
       (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public,
        source_system, enactment_date, ocr_text, file_path)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([
    $input['doc_number'],
    $input['title'],
    $input['doc_type'] ?? 'Ordinance',
    $input['sponsor'] ?? null,
    $input['committee_id'] ?? null,
    $systemUserId,
    'Enacted',
    $isPublic,
    $sourceSystem,
    $input['enactment_date'] ?? null,
    $input['ocr_text'] ?? null,
    $filePath,
]);
$newId = $pdo->lastInsertId();

if ($allFilePaths) {
    $attStmt = $pdo->prepare(
        'INSERT INTO document_attachments (document_id, file_path, display_name, sort_order) VALUES (?,?,?,?)'
    );
    foreach ($allFilePaths as $i => $f) {
        $attStmt->execute([$newId, $f['file_path'], $f['display_name'], $i]);
    }
}

log_action('encoding', 'api_ingest', $sourceSystem . ' → ' . $input['doc_number']);

// Alert Records Officers that something is waiting for them to verify.
require_once __DIR__ . '/../includes/workflow.php';
notify_incoming_document([
    'id' => $newId,
    'doc_number' => $input['doc_number'],
    'title' => $input['title'],
], $sourceSystem);

echo json_encode(['document_id' => (int)$newId, 'status' => 'Enacted', 'is_public' => false, 'file_path' => $filePath, 'attachment_count' => count($allFilePaths)]);
