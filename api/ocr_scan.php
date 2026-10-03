<?php
/**
 * AJAX OCR scan endpoint.
 *
 * Accepts a file upload via POST, runs OCR extraction, and returns the
 * recognized text as JSON. Used by encoding.php's "Scan" button so users
 * can preview OCR results before submitting the form.
 *
 * POST multipart/form-data: file=<uploaded file>
 * Response: { "text": "...", "filename": "..." } or { "error": "..." }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ocr.php';
require_once __DIR__ . '/../includes/entities.php';
require_once __DIR__ . '/../includes/templates.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/upload_validation.php';

header('Content-Type: application/json');

// Must be logged in
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
if (!empty(current_user()['must_change_password'])) security_reject(403,'Change your password before scanning documents.');
if (!has_permission('encoding','create')) security_reject(403,'Document intake permission is required.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); security_reject(405,'Use POST.'); }
if (!validate_csrf()) security_reject(403,'Security token expired. Refresh and try again.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['scan_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['scan_file'];
if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null) || !is_int($file['error'] ?? null)) security_reject(422,'Choose one valid file.');
if (!is_uploaded_file($file['tmp_name'])) security_reject(422,'Choose a valid uploaded file.');
if (($uploadError = document_upload_error($file['tmp_name'],$file['name'],true)) !== null) security_reject(422,$uploadError);
$docType = $_POST['doc_type'] ?? 'Ordinance';
if (!in_array($docType,['Ordinance','Resolution','Committee Report','Minutes','Other'],true)) security_reject(422,'Invalid document type.');

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'File upload failed (error code: ' . $file['error'] . ').']);
    exit;
}

// Save to a temp location, run OCR, then clean up
$tmpDir = sys_get_temp_dir() . '/lrdms_ocr_' . bin2hex(random_bytes(8));
if (!mkdir($tmpDir, 0700, true)) security_reject(503,'Scanning is temporarily unavailable.');

$safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file['name']);
$dest = $tmpDir . '/' . $safeName;

if (move_uploaded_file($file['tmp_name'], $dest)) {
    try { $text = ocr_extract($dest, $file['name']); }
    finally { @unlink($dest); @rmdir($tmpDir); }
    $entities = detect_entities($text);
    $fields = parse_document_fields($docType, $text);
    echo json_encode(['text' => $text, 'filename' => $file['name'], 'entities' => $entities, 'fields' => $fields]);
} else {
    @rmdir($tmpDir);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file for scanning.']);
}
