<?php
require_once __DIR__ . '/includes/retrieval.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/storage.php';
require_login();
$pdo = get_db();
$stmt = $pdo->prepare('SELECT * FROM documents WHERE id=?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$doc = $stmt->fetch();
if (!$doc || !can_download_record(current_user(), $doc)) { http_response_code(403); exit('This copy requires download permission or an approved copy request.'); }
$files = retrieval_attachments($pdo, $doc);
$file = null;
foreach ($files as $candidate) {
    if (!isset($_GET['attachment']) || (int)$_GET['attachment'] === (int)$candidate['id']) { $file = $candidate; break; }
}
if (!$file) { http_response_code(404); exit('Attachment not found.'); }
$path = $file['file_path'];
$stream = null;
if (storage_is_remote($path)) {
    $base = rtrim((string)env_optional('S3_PUBLIC_URL', ''), '/');
    if (!$base || !str_starts_with($path, $base . '/') || parse_url($path, PHP_URL_SCHEME) !== 'https') { http_response_code(503); exit('Attachment storage is not configured for download.'); }
    $stream = tmpfile();
    if (!$stream) { http_response_code(503); exit('Download unavailable.'); }
    $curl = curl_init($path);
    curl_setopt_array($curl, [CURLOPT_FILE=>$stream, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>60, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $ok = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!$ok || $code !== 200) { fclose($stream); http_response_code(502); exit('Unable to retrieve the attachment.'); }
    rewind($stream);
} else {
    $root = realpath(__DIR__ . '/uploads');
    $resolved = realpath(__DIR__ . '/' . $path);
    if (!$root || !$resolved || !str_starts_with(str_replace('\\','/',$resolved), str_replace('\\','/',$root) . '/') || !is_file($resolved)) { http_response_code(404); exit('Attachment unavailable.'); }
    $stream = fopen($resolved, 'rb');
}
if (!$stream) { http_response_code(404); exit('Attachment unavailable.'); }
$name = basename(parse_url($path, PHP_URL_PATH) ?: $path);
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
log_action('repository', 'downloaded_document', $doc['doc_number'] . ' — ' . $name);
session_write_close();
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
fpassthru($stream);
fclose($stream);
