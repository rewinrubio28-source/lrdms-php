<?php
// Read-only connectivity probe. Does not send documents or change records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ocr.php';
$parts = parse_url(OCR_SERVICE_URL);
if (!$parts || !in_array($parts['scheme'] ?? '', ['http','https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
    fwrite(STDERR, "OCR_SERVICE_URL must be an HTTP(S) service URL without credentials or query parameters.\n"); exit(1);
}
$health = substr(OCR_SERVICE_URL, 0, -4) . '/health';
echo 'OCR endpoint: ' . OCR_SERVICE_URL . "\nHealth endpoint: $health\n";
$curl = curl_init($health);
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false]);
$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_errno($curl);
curl_close($curl);
echo "HTTP status: $status\n";
if ($response === false) { echo "Connection failed (cURL error $error). Check hostname, port, DNS and network access from the lrdms container.\n"; exit(1); }
$json = json_decode($response, true);
if ($status === 200 && is_array($json) && ($json['status'] ?? '') === 'ok') { echo "PASS: OCR health endpoint reachable. This checks connectivity only, not document recognition.\n"; exit(0); }
if ($status === 503) echo "Service gateway returned unavailable/stopped. Verify the domain belongs to the active OCR deployment and inspect its runtime logs.\n";
else echo "Expected OCR health JSON was not returned. Verify the service URL, port, routing and runtime logs.\n";
exit(1);
