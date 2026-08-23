<?php
/**
 * REST endpoint used by the Ordinance & Resolution Lifecycle System
 * (System 1) and the Session Management System (System 2) to push
 * finalized documents into this repository.
 *
 * POST /api/upload_document.php
 * Header: X-API-Key: <shared secret>
 * Body (JSON):
 *   {
 *     "title": "...",            required
 *     "doc_number": "...",       required
 *     "doc_type": "Ordinance",   optional, defaults to "Ordinance"
 *     "sponsor": "...",          optional
 *     "committee_id": 1,         optional
 *     "enactment_date": "2026-07-20", optional
 *     "source_system": "System 1 – Ordinance & Resolution Lifecycle",
 *     "is_public": true,         optional, defaults to true
 *     "ocr_text": "..."          optional
 *   }
 *
 * Per the integration boundary in README.md, this system is the system
 * of record for FINALIZED documents only — it receives them already
 * enacted. Draft/review workflow stays owned by System 1.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

// Move this to an environment variable / untracked config file before
// any real deployment — it's here in plain text only for this starter build.
define('API_SHARED_KEY', 'change-this-shared-key');

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

$input = json_decode(file_get_contents('php://input'), true);
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

$isPublic = array_key_exists('is_public', $input) ? (int)(bool)$input['is_public'] : 1;

$stmt = $pdo->prepare(
    'INSERT INTO documents
       (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public,
        source_system, enactment_date, ocr_text)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
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
    $input['source_system'] ?? 'System 1 – Ordinance & Resolution Lifecycle',
    $input['enactment_date'] ?? null,
    $input['ocr_text'] ?? null,
]);
$newId = $pdo->lastInsertId();

log_action('encoding', 'api_ingest', ($input['source_system'] ?? 'external system') . ' → ' . $input['doc_number']);

echo json_encode(['document_id' => (int)$newId, 'status' => 'Enacted']);
