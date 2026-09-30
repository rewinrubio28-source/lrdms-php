<?php
require_once __DIR__ . '/includes/retrieval.php';
require_once __DIR__ . '/includes/storage.php';
require_login();
$pdo = get_db(); $user = current_user();
$path = is_string($_GET['path'] ?? null) ? $_GET['path'] : '';
$query = $pdo->prepare('SELECT DISTINCT d.* FROM documents d LEFT JOIN document_attachments a ON a.document_id=d.id WHERE d.file_path=? OR a.file_path=?');
$query->execute([$path,$path]);
$allowed = false;
foreach ($query->fetchAll() as $doc) {
    if (!can_view_document($user, $doc)) continue;
    if ($doc['verified_at'] ? can_download_record($user, $doc) : has_permission('encoding', 'create')) { $allowed = true; break; }
}
if (!$allowed) { http_response_code(403); exit('Original file access requires download permission or an approved copy request.'); }
if (storage_is_remote($path)) {
    $base = rtrim((string)env_optional('S3_PUBLIC_URL', ''), '/');
    if (!$base || !str_starts_with($path, $base . '/') || parse_url($path, PHP_URL_SCHEME) !== 'https') { http_response_code(404); exit('File unavailable.'); }
    $stream = tmpfile();
    if (!$stream) { http_response_code(503); exit; }
    $curl = curl_init($path);
    curl_setopt_array($curl, [CURLOPT_FILE=>$stream, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>60, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $ok = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if (!$ok || $code !== 200) { fclose($stream); http_response_code(502); exit('Storage unavailable.'); }
    rewind($stream);
    header('Content-Type: ' . storage_content_type($path));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Security-Policy: sandbox');
    session_write_close(); fpassthru($stream); fclose($stream); exit;
}
$root = realpath(__DIR__ . '/uploads');
$resolved = realpath(__DIR__ . '/' . $path);
if (!$root || !$resolved || !str_starts_with(str_replace('\\','/',$resolved), str_replace('\\','/',$root).'/') || !is_file($resolved)) { http_response_code(404); exit('File unavailable.'); }
$type = storage_content_type($path, $resolved);
$inline = in_array($type, ['application/pdf','image/png','image/jpeg','image/gif','image/webp','text/plain'], true);
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: sandbox");
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/','_',basename($path)) . '"');
header('Content-Length: ' . filesize($resolved));
session_write_close();
readfile($resolved);
