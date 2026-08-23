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

header('Content-Type: application/json');

// Must be logged in
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['scan_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['scan_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'File upload failed (error code: ' . $file['error'] . ').']);
    exit;
}

// Save to a temp location, run OCR, then clean up
$tmpDir = sys_get_temp_dir() . '/lrdms_ocr_' . bin2hex(random_bytes(8));
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

$safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file['name']);
$dest = $tmpDir . '/' . $safeName;

if (move_uploaded_file($file['tmp_name'], $dest)) {
    $text = ocr_extract($dest, $file['name']);
    @unlink($dest);          // clean up the file
    @rmdir($tmpDir);         // clean up the temp dir
    $docType = $_POST['doc_type'] ?? 'Ordinance';
    $entities = detect_entities($text);
    $fields = parse_document_fields($docType, $text);
    echo json_encode(['text' => $text, 'filename' => $file['name'], 'entities' => $entities, 'fields' => $fields]);
} else {
    @rmdir($tmpDir);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file for scanning.']);
}
